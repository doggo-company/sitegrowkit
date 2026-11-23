<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

use Generator;
use XMLReader;

/**
 * WXR を流し読みする（Phase 9.14 / STAGE 27B・SPEC §B.2・§B.3・§B.4）。
 *
 * ## 外部実体を必ず切る（**最重要**）
 *
 * WXR は**外から受け取るファイル**である。外部実体を許すと、
 * `.env` や `/etc/passwd` を読み出す XXE、内部ホストへの SSRF、
 * 展開爆弾（billion laughs）がそのまま成立する。
 * `LIBXML_NONET` だけでは**ローカルファイルの読み出しが止まらない**ので、
 * `libxml_set_external_entity_loader()` で**実体の解決そのものを断つ**。
 *
 * ## 一括で読まない
 *
 * 現実の WXR は数百 MB になる。`simplexml_load_file()` は全部をメモリへ載せるので、
 * **移行の入口が「大きすぎて動かない」で止まる**。`XMLReader` で `<item>` を
 * 1 件ずつ取り出し、その 1 件だけを組み立てる。
 *
 * ## 黙って直さない
 *
 * 不正なバイト列を `?` へ置換して取り込むと、**文字化けした記事が 1 万件**
 * できてから気づくことになる。読めないものは**その場で止めて、どこかを言う**。
 */
final class WxrReader
{
    /**
     * **対応するのは WXR 1.2 だけ**（V1）。
     *
     * 1.0 / 1.1 は要素の集合と名前空間 URI が違う。前方一致で「読めたつもり」に
     * すると、**取れなかった値が空として静かに入る**（`wp:post_id` が空、
     * コメントが 0 件、など）。読めない版は**読めないと言って止める**。
     */
    public const SUPPORTED_VERSION = '1.2';

    /** 版の部分を除いた WXR 名前空間の根（版の判定に使う） */
    private const NS_WP_BASE = 'http://wordpress.org/export/';

    private const NS_WP = self::NS_WP_BASE.self::SUPPORTED_VERSION.'/';

    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const NS_EXCERPT = self::NS_WP_BASE.self::SUPPORTED_VERSION.'/excerpt/';

    /** 1 件の本文がこれを超えたら報告する（取り込みはする） */
    public const LARGE_BODY_BYTES = 1024 * 1024;

    /**
     * id の形。**1 以上の 10 進の整数だけ**を受ける。
     *
     * `abc` を受けると、そのまま台帳の `external_id` に入り、
     * **再実行と取り消しの鍵が壊れる**。桁も抑える（20 桁は bigint の幅で、
     * WordPress の id がこれを超えることはない）。
     *
     * `0` を弾くのは、WordPress が `0` を「無い」の意味で使うからである
     * （`post_parent=0` は親なし）。識別子として受けると、
     * **「親が無い」と「id が 0 の記事」が同じ**になる。
     * 先頭の `0` も弾く（`01` と `1` が別物として台帳へ入る）。
     */
    private const ID_PATTERN = '/^[1-9][0-9]{0,19}$/';

    /**
     * `<channel>` 直下で分類項目を定義する 3 つの書き方。
     *
     * WordPress は分類ごとに要素名も子要素名も変える。`<wp:category>` は
     * 分類名を持たず category と決まっており、`<wp:tag>` は post_tag、
     * `<wp:term>` だけが `wp:term_taxonomy` で分類名を名乗る。
     *
     * @var list<string>
     */
    private const TERM_ELEMENTS = ['category', 'tag', 'term'];

    public function __construct(private readonly string $path) {}

    /**
     * `<item>` を 1 件ずつ返す。
     *
     * @return Generator<int, WxrItem>
     *
     * @throws WxrError
     */
    public function items(): Generator
    {
        /*
         * **重複の検査はここに置く。** 集計側（`WxrSummary`）に置くと、
         * plan や apply が reader を直接回したときに検査を素通りできてしまう。
         * 読む経路が 1 本なら、迂回できる道も無い。
         *
         * @var array<string, true> $seen
         */
        $seen = [];
        // **コメントの id はファイル全体で一意**（記事をまたいで重複しない）
        $seenComments = [];

        foreach ($this->walkChannel() as [$reader, $version]) {
            if ($reader->localName !== 'item' || (string) $reader->namespaceURI !== '') {
                continue;
            }

            // **版を確かめる前に item を読まない**（読めたつもりで空が入る）
            $this->assertSupported($version);

            // **過去のエラーを今の失敗として読まない**（誤った理由を出さない）
            libxml_clear_errors();

            $xml = $reader->readOuterXml();

            if ($xml === '') {
                // **「読めなかった」だけでは次の手が無い。** libxml の理由を渡す
                throw self::couldNotRead('item');
            }

            $item = $this->parseItem($xml, $seenComments);

            if (array_key_exists($item->postId, $seen)) {
                // どちらが正しいか決められない。**黙って片方を採らない**
                throw new WxrError(
                    "wp:post_id [{$item->postId}] が 2 回出てきます。",
                    '同じ id の item がある WXR は、どちらを取り込むか決められません。'
                        .'書き出しをやり直してください。',
                );
            }

            $seen[$item->postId] = true;

            yield $item;
        }
    }

    /**
     * `<channel>` 直下の分類項目の**定義**を 1 件ずつ返す（SPEC §C.3）。
     *
     * 親子は**ここにしか無い**。item の中の `<category>` は割り当てであって、
     * 親が誰かは書かれていない。ここを読まないと入れ子だった分類が
     * **全部フラットに並ぶ**（しかも取り込みは成功に見える）。
     *
     * **別口だけ緩くしない。** `items()` と同じ `walkChannel()` を通るので、
     * DOCTYPE の拒否も外部実体の遮断も root>channel の検査も版の検査も同じである。
     *
     * @return Generator<int, WxrTermDefinition>
     *
     * @throws WxrError
     */
    public function termDefinitions(): Generator
    {
        foreach ($this->walkChannel() as [$reader, $version]) {
            $localName = $reader->localName;

            if (! in_array($localName, self::TERM_ELEMENTS, true)) {
                continue;
            }

            // 版の厳密一致は walkChannel が済ませている（別口だけ緩くしない）
            if (self::versionFromNamespace((string) $reader->namespaceURI) === null) {
                continue;
            }

            // **版を確かめる前に分類定義を読まない**（item と同じ契約）
            $this->assertSupported($version);

            // **過去のエラーを今の失敗として読まない**（誤った理由を出さない）
            libxml_clear_errors();

            $xml = $reader->readOuterXml();

            if ($xml === '') {
                /*
                 * **`$reader->name` はファイルが決める名前**（接頭辞込み）で、
                 * まだ何も確かめていない。ここで使うと、外から来た値が
                 * 理由の文章へそのまま入る。既に許した名前だけを使う。
                 */
                throw self::couldNotRead('<'.$localName.'>');
            }

            yield $this->parseTermDefinition($xml, $localName);
        }
    }

    /**
     * `<channel>` の直下を 1 つずつ渡す。**形の検査はここ 1 か所に置く。**
     *
     * `items()` と `termDefinitions()` が同じ入口を通るようにするための私設の口である。
     * 分けると、片方だけ DOCTYPE を通す・片方だけ版を確かめない、が起きる。
     *
     * 渡すのは depth 2 の要素で、版の要素だけは自分で処理して渡さない。
     * 一緒に「その時点で分かっている版」を渡す。
     *
     * **版より前に出てきた item や分類定義は読まない、が契約である。**
     * 受け取った側は使う直前に `assertSupported()` を呼ぶので、
     * `wp:wxr_version` より前に置かれたものは「WXR ではありません」で止まる。
     * 版が分かる前に中身を読むと、**取れなかった値が空のまま入る**。
     * WordPress の書き出しは `wp:wxr_version` を先に置く。
     *
     * @return Generator<int, array{0: XMLReader, 1: ?string}>
     *
     * @throws WxrError
     */
    private function walkChannel(): Generator
    {
        [$reader, $previousInternalErrors] = $this->open();

        $version = null;
        $channels = 0;

        try {
            while ($this->advance($reader)) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    // DOCTYPE がある時点で、実体の解決を当てにした文書である
                    throw new WxrError(
                        'この WXR には DOCTYPE が含まれています。取り込めません。',
                        'DOCTYPE は外部ファイルの読み出しや展開爆弾に使えるため、受け付けません。'
                            .'WordPress の標準の書き出しには DOCTYPE は含まれません。',
                    );
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                /*
                 * **接頭辞で探さない。** `wp:` は文書が決める名前で、別の接頭辞でも
                 * 同じ名前空間なら同じ意味である。要素名は `localName`、
                 * どの語彙かは `namespaceURI` で見る。
                 *
                 * **そして「文書のどこかにあるか」でも探さない。** 位置を見ずに拾うと、
                 * `<catalog><rss>…</rss></catalog>` や channel の無い文書が WXR として
                 * 通り、channel の外にある item は黙って落ちる。**取り違えと取りこぼしは
                 * どちらも「成功した」ように見える**ので、形を `depth` で確かめる。
                 */
                $depth = $reader->depth;
                $localName = $reader->localName;
                $namespace = (string) $reader->namespaceURI;
                $unnamespaced = $namespace === '';

                if ($depth === 0) {
                    if (! $unnamespaced || $localName !== 'rss') {
                        throw new WxrError(
                            'WXR（WordPress の書き出し XML）に見えません。',
                            "root 要素が <rss> ではありません（<{$reader->name}> でした）。"
                                .'文書のどこかに <rss> があるだけでは WXR になりません。'
                                .'WordPress の「ツール → エクスポート」で書き出した .xml を渡してください。',
                        );
                    }

                    continue;
                }

                if ($depth === 1) {
                    if (! $unnamespaced || $localName !== 'channel') {
                        throw new WxrError(
                            'WXR（WordPress の書き出し XML）として読めません。',
                            "<rss> の直下は <channel> だけです（<{$reader->name}> がありました）。"
                                .'wp:wxr_version と <item> は <channel> の直下にある必要があります。',
                        );
                    }

                    $channels++;

                    if ($channels > 1) {
                        // どちらを取り込むか決められない。**黙って片方を採らない**
                        throw new WxrError(
                            '<channel> が 2 つ以上あります。',
                            'どの channel を取り込むか決められません。'
                                .'WordPress の書き出しに <channel> は 1 つだけです。',
                        );
                    }

                    continue;
                }

                /*
                 * **前方一致で名前空間を受けない。** `/export/` で始まるだけの URI を
                 * 通すと、`/export/1.1/` の `wxr_version` が本文 1.2 を名乗る文書が
                 * 対応版として抜ける。名前空間は要素の集合そのものなので、
                 * 版の部分まで厳密に取り出す。
                 */
                $exportVersion = self::versionFromNamespace($namespace);

                $isVersion = $localName === 'wxr_version' && $exportVersion !== null;
                $isItem = $localName === 'item' && $unnamespaced;
                $isTerm = $exportVersion !== null && in_array($localName, self::TERM_ELEMENTS, true);

                /*
                 * **別口で版の食い違いを再発させない。**
                 *
                 * 文書が 1.2 を名乗っていても、`<old:category xmlns:old=…/1.1/>` は
                 * 名前空間としては別の語彙である。無視すると**その分類が黙って落ち**、
                 * 拾うと 1.1 の要素名を 1.2 として読むことになる。どちらも
                 * 「取り込めたつもり」で終わるので、見つけた時点で言う。
                 */
                if ($isTerm && $exportVersion !== self::SUPPORTED_VERSION) {
                    throw new WxrError(
                        "分類の定義 <{$reader->name}> が WXR {$exportVersion} の名前空間にあります"
                            .'（この文書は '.self::SUPPORTED_VERSION.' です）。',
                        '版が違うと要素の集合が変わるため、同じ読み方はできません。'
                            .'黙って読み飛ばすと、その分類だけが落ちたことに気づけません。'
                            .'書き出しをやり直してください。',
                    );
                }

                /*
                 * depth 1 は channel だけと決めたので、depth 2 の親は必ず channel である。
                 * それより深い版・item・分類定義は**別のものの中身**なので、
                 * 拾えば取り違えになる。
                 */
                if (($isVersion || $isItem || $isTerm) && $depth !== 2) {
                    throw new WxrError(
                        "<{$reader->name}> が <channel> の直下にありません。",
                        'wp:wxr_version・<item>・分類の定義は <channel> の直下にある必要があります。'
                            .'別の要素の中にあるものを拾うと、関係のない値を取り込みます。',
                    );
                }

                if ($depth !== 2) {
                    continue;
                }

                $namespaceVersion = $isVersion ? $exportVersion : null;

                if ($isVersion) {
                    if ($version !== null) {
                        // どちらの版で読むか決められない。**先に見つけた方を採らない**
                        throw new WxrError(
                            'wp:wxr_version が 2 つ以上あります。',
                            'どの版として読むか決められません。'
                                .'WordPress の書き出しに wp:wxr_version は 1 つだけです。',
                        );
                    }

                    $declared = trim((string) $reader->readString());

                    /*
                     * **版は 2 か所で名乗られる。** 名前空間 URI（要素の集合を決める）と
                     * 本文（書き出しが名乗る版）である。食い違ったまま片方で読むと、
                     * もう一方の名前空間の値が**空のまま静かに入る**。
                     */
                    if ($declared !== $namespaceVersion) {
                        throw new WxrError(
                            'wp:wxr_version の版が名前空間と食い違っています'
                                .'（名前空間は '.WxrError::token((string) $namespaceVersion)
                                .'、本文は '.WxrError::token($declared).'）。',
                            'どちらの要素集合で読めばよいか決められません。'
                                .'一方に合わせて読むと、もう一方の値が空のまま入ります。'
                                .'書き出しをやり直してください。',
                        );
                    }

                    $version = $declared;
                    $this->assertSupported($version);

                    continue;
                }

                yield [$reader, $version];
            }

            // 0 件の WXR は正しい。**WXR ではないファイル**と取り違えない
            $this->assertSupported($version);
        } finally {
            $reader->close();
            $this->restoreLibxml($previousInternalErrors);
        }
    }

    /**
     * 対応する版か。
     *
     * `null`（`wp:wxr_version` が無い）は **WXR ではない**。
     * 整形式の XML なら何でも「item 0 件の WXR」として通す、を作らない。
     *
     * @throws WxrError
     */
    private function assertSupported(?string $version): void
    {
        if ($version === self::SUPPORTED_VERSION) {
            return;
        }

        if ($version === null || $version === '') {
            throw new WxrError(
                'WXR（WordPress の書き出し XML）ではありません。',
                'wp:wxr_version が見つかりません。'
                    .'WordPress の「ツール → エクスポート」で書き出した .xml を渡してください。',
            );
        }

        throw new WxrError(
            "WXR {$version} には対応していません（対応するのは ".self::SUPPORTED_VERSION.' だけです）。',
            '版が違うと要素の集合も名前空間も変わります。'
                .'読めたつもりで空の値を取り込むより、読めないと言って止めます。',
        );
    }

    /**
     * libxml の設定を**呼ばれる前の状態へ戻す**。
     *
     * `libxml_use_internal_errors()` も外部実体の loader も**プロセス全体の設定**で、
     * ここで変えたまま返すと、**このクラスと関係のない XML 処理の挙動が変わる**。
     * 成功・例外・generator の途中終了のどれでも通る `finally` から呼ぶ。
     *
     * loader は `null`（既定＝実体を解決する）へ戻す。PHP に「元の loader」を
     * 読み出す手段が無いためで、**遮断が要るのはこのクラスが読んでいる間だけ**である。
     */
    private function restoreLibxml(bool $previousInternalErrors): void
    {
        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);
        libxml_set_external_entity_loader(null);
    }

    /**
     * 1 つ進む。**libxml が黙って止まるのを見逃さない。**
     *
     * 不正なバイト列や壊れた XML に当たると `read()` は `false` を返すだけで、
     * そのままだと「item が 0 件のファイル」として通ってしまう。
     * **読めていないことと、読み終わったことを取り違えない。**
     *
     * @throws WxrError
     */
    private function advance(XMLReader $reader): bool
    {
        libxml_clear_errors();

        $moved = @$reader->read();

        if ($moved) {
            return true;
        }

        $errors = array_filter(
            libxml_get_errors(),
            static fn (\LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR,
        );
        libxml_clear_errors();

        $first = reset($errors);

        if ($first === false) {
            return false;
        }

        throw new WxrError(
            'XML を読めませんでした: '.trim($first->message),
            'バイト列が UTF-8 として壊れているか、XML の形が崩れています。'
                .'黙って読み飛ばすと、取り込めなかったことに気づけません。',
            sourceLine: $first->line,
        );
    }

    /**
     * ファイルとして成立しているかだけを見る（DB を 1 行も読まない）。
     *
     * @throws WxrError
     */
    public function assertReadable(): void
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new WxrError("ファイルを読めません: {$this->path}");
        }

        $head = (string) file_get_contents($this->path, false, null, 0, 32768);

        if ($head === '') {
            throw new WxrError('ファイルが空です。');
        }

        if (preg_match('/<!DOCTYPE/i', $head) === 1) {
            throw new WxrError(
                'この WXR には DOCTYPE が含まれています。取り込めません。',
                '外部ファイルの読み出しや展開爆弾に使えるため、受け付けません。',
            );
        }

        /*
         * **XML 宣言があるだけでは通さない。**
         *
         * それだけを見ていると、`<rss>` も `<channel>` も無い整形式の XML が
         * 「item が 0 件の WXR」として成功してしまう。取り込んだつもりで
         * 何も入っていない、が最も気づきにくい失敗である。
         *
         * **ただしここは安価な足切りであって、形の保証ではない。** 先頭 32KB を
         * 正規表現で見るだけなので、`<catalog><rss>…</rss></catalog>` のように
         * root でない `<rss>` も通る。root・channel・item の位置を保証するのは
         * `items()` の `depth` 判定であり、読む経路はそこ 1 本しかない。
         * 版も `wp:wxr_version` を実際に読んで確かめる（`assertSupported()`）
         * — 先頭 32KB に収まる保証が無いため。
         */
        if (preg_match('/<rss[\s>]/i', $head) !== 1) {
            throw new WxrError(
                'WXR（WordPress の書き出し XML）に見えません。',
                'root 要素が <rss> ではありません。'
                    .'WordPress の「ツール → エクスポート」で書き出した .xml を渡してください。',
            );
        }

        /*
         * **版を決め打ちで探さない。**
         *
         * `/export/1.2/` だけを探すと、実物の 1.1（名前空間も `/export/1.1/`）が
         * 「WXR に見えません」で落ちる。正しくは「その版には対応していません」である。
         * **読めない理由を取り違えると、利用者は次の手を間違える。**
         */
        if (preg_match('#'.preg_quote(self::NS_WP_BASE, '#').'(\d+\.\d+)/#', $head, $matches) !== 1) {
            throw new WxrError(
                'WXR（WordPress の書き出し XML）に見えません。',
                '名前空間 '.self::NS_WP_BASE.'<版>/ の宣言がありません。'
                    .'WordPress の「ツール → エクスポート」で書き出した .xml を渡してください。',
            );
        }

        // 版が違うと分かった時点で、その言葉で止める
        $this->assertSupported($matches[1]);
    }

    /**
     * WXR の版名前空間から版だけを取り出す（違えば `null`）。
     *
     * 受けるのは `http://wordpress.org/export/<版>/` ちょうどである。
     * `…/excerpt/` のような下位の語彙や、`/export/` で始まるだけの別物は通さない。
     * **接頭辞は見ない**（`wp:` は文書が決める名前で、意味を持つのは URI の方）。
     */
    private static function versionFromNamespace(string $namespace): ?string
    {
        $matched = preg_match(
            '#^'.preg_quote(self::NS_WP_BASE, '#').'(\d+\.\d+)/$#',
            $namespace,
            $matches,
        );

        return $matched === 1 ? $matches[1] : null;
    }

    /** ファイルの中身の指紋（同じファイルの再実行を見分ける） */
    public function fingerprint(): string
    {
        $hash = hash_file('sha256', $this->path);

        if ($hash === false) {
            throw new WxrError("ファイルを読めません: {$this->path}");
        }

        return $hash;
    }

    // --- 内部 ----------------------------------------------------------

    /**
     * @return array{0: XMLReader, 1: bool} reader と、`libxml_use_internal_errors` の直前値
     */
    private function open(): array
    {
        $this->assertReadable();

        /*
         * **実体の解決そのものを断つ。**
         *
         * `LIBXML_NONET` はネットワークだけを止めるので、`file:///etc/passwd` は通ってしまう。
         * そして `LIBXML_NOENT` は名前に反して**実体の展開を有効にする**フラグなので、
         * ここでは**渡さない**（読みが逆になりやすい典型なので、名前で判断しない）。
         */
        libxml_set_external_entity_loader(static fn (): null => null);
        // **直前の値を持って帰る**（プロセス全体の設定を残さない）
        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new XMLReader;

        if (! @$reader->open($this->path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            $this->restoreLibxml($previousInternalErrors);

            throw new WxrError("XML として開けません: {$this->path}");
        }

        return [$reader, $previousInternalErrors];
    }

    /**
     * @param  array<string, true>  $seenComments  ファイル全体で共有するコメント id の集合
     *
     * @throws WxrError
     */
    private function parseItem(string $xml, array &$seenComments): WxrItem
    {
        $element = $this->load($xml, 'item');

        /*
         * 殻をかぶせてあるので、**`item` へ降りてから**名前空間を引く。
         * `$element` は `<rss>` である（ここを間違えると全部が空で返る）。
         */
        $item = $element->item;

        if ($item === null) {
            throw new WxrError('item を組み立て直せませんでした。');
        }

        $wp = $item->children(self::NS_WP);
        $content = $item->children(self::NS_CONTENT);
        $excerpt = $item->children(self::NS_EXCERPT);

        $postId = trim((string) $wp->post_id);

        if ($postId === '') {
            throw new WxrError('wp:post_id が空の item があります。どれと同じものか決められません。');
        }

        // **識別子として使える形か**を、台帳へ入る前に確かめる
        self::assertIdentity($postId, 'wp:post_id');

        return new WxrItem(
            postId: $postId,
            postType: trim((string) $wp->post_type),
            status: trim((string) $wp->status),
            title: trim((string) $item->title),
            slug: trim((string) $wp->post_name),
            content: (string) $content->encoded,
            excerpt: (string) $excerpt->encoded,
            publishedAt: self::date((string) $wp->post_date_gmt, (string) $wp->post_date),
            parentId: self::parentId((string) $wp->post_parent, 'wp:post_parent', $postId),
            attachmentUrl: self::text((string) $wp->attachment_url),
            commentStatus: self::text((string) $wp->comment_status),
            terms: self::terms($item, $postId),
            meta: self::meta($wp, $postId),
            comments: self::comments($wp, $postId, $seenComments),
        );
    }

    /**
     * `<channel>` 直下の分類定義 1 件を組み立てる（SPEC §C.3）。
     *
     * **必須の値が空なら、ここで止める。** id と slug と名前のどれかが欠けた項目を
     * 通すと、`plan` は「作れる」と言っておいて `apply` が 1 件ずつ落ちる。
     * **plan で全部出す**という約束が壊れるので、読む時点で断る。
     *
     * @throws WxrError
     */
    private function parseTermDefinition(string $xml, string $localName): WxrTermDefinition
    {
        $element = $this->load($xml, "<{$localName}>");
        $node = $element->children(self::NS_WP)->{$localName};

        if ($node === null) {
            throw new WxrError("<{$localName}> を組み立て直せませんでした。");
        }

        $wp = $node->children(self::NS_WP);

        // 書き方ごとに子要素の名前が違う（分類名は term だけが名乗る）
        [$slugKey, $nameKey, $parentKey, $taxonomy] = match ($localName) {
            'category' => ['category_nicename', 'cat_name', 'category_parent', 'category'],
            'tag' => ['tag_slug', 'tag_name', null, 'post_tag'],
            default => ['term_slug', 'term_name', 'term_parent', null],
        };

        if ($taxonomy === null) {
            $taxonomy = trim((string) $wp->term_taxonomy);

            if ($taxonomy === '') {
                throw new WxrError(
                    '<term> に wp:term_taxonomy がありません。',
                    'どの分類の項目なのか決められません。'
                        .'受け皿を選べないので、取り込み先も決められません。',
                );
            }
        }

        $termId = trim((string) $wp->term_id);
        $slug = trim((string) $wp->{$slugKey});
        $name = trim((string) $wp->{$nameKey});

        if ($termId === '') {
            throw new WxrError(
                "<{$localName}> に wp:term_id がありません（".WxrError::token($taxonomy).'）。',
                '同じ項目を指しているかどうかを id で見分けます。'
                    .'id が無いと、再実行のたびに同じ項目が増えます。',
            );
        }

        // **識別子として使える形か**を、台帳へ入る前に確かめる
        self::assertIdentity($termId, 'wp:term_id');

        if ($slug === '') {
            throw new WxrError(
                "<{$localName}> に slug がありません（".WxrError::token($taxonomy).' / wp:term_id '.WxrError::token($termId).'）。',
                'slug はこちら側で項目を見分ける鍵です。'
                    .'空のまま作ると、名前だけ同じ別物が増えていきます。',
            );
        }

        if ($name === '') {
            throw new WxrError(
                "<{$localName}> に表示名がありません（".WxrError::token($taxonomy).' / '.WxrError::token($slug).'）。',
                '名前の無い項目は作れません。'
                    .'ここで止めずに通すと、plan は「作れる」と言っておいて'
                    .'apply が 1 件ずつ落ちます。',
            );
        }

        $parent = $parentKey === null ? '' : trim((string) $wp->{$parentKey});

        return new WxrTermDefinition(
            termId: $termId,
            taxonomy: $taxonomy,
            slug: $slug,
            name: $name,
            // WXR 1.2 の親は id ではなく slug
            parentSlug: $parent === '' ? null : $parent,
        );
    }

    /**
     * 切り出した断片を読む。**UTF-8 として壊れていたら黙って直さない。**
     *
     * @throws WxrError
     */
    private function load(string $xml, string $what): \SimpleXMLElement
    {
        if (! mb_check_encoding($xml, 'UTF-8')) {
            throw new WxrError(
                'UTF-8 として読めないバイト列が含まれています。',
                '書き出し元の文字コードを確認してください。'
                    .'黙って置き換えると、文字化けした記事が大量にできてから気づくことになります。',
            );
        }

        libxml_clear_errors();

        $element = @simplexml_load_string(
            $this->wrap($xml),
            'SimpleXMLElement',
            LIBXML_NONET | LIBXML_COMPACT,
        );

        if ($element === false) {
            $first = libxml_get_errors()[0] ?? null;
            libxml_clear_errors();

            throw new WxrError(
                "{$what} の XML が壊れています: ".trim((string) ($first->message ?? '')),
                sourceLine: $first?->line,
            );
        }

        return $element;
    }

    /**
     * 切り出した断片だけでは名前空間が解決できないので、宣言つきの殻をかぶせる。
     *
     * `readOuterXml()` は切り出した要素に、その場で効いていた名前空間の宣言を
     * 付けて返すので、実際にはそちらが優先される。ここの宣言は**保険**である。
     */
    private function wrap(string $fragment): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"'
            .' xmlns:content="'.self::NS_CONTENT.'"'
            .' xmlns:excerpt="'.self::NS_EXCERPT.'"'
            .' xmlns:wp="'.self::NS_WP.'"'
            .' xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .$fragment
            .'</rss>';
    }

    /**
     * item に書かれた**割り当て**（SPEC §C.3）。
     *
     * **親は持たない。** item の `<category>` は「この記事はこの項目に属する」しか
     * 言っておらず、その項目の親が誰かは書かれていない。親子は channel 直下の
     * 定義（`termDefinitions()`）にしか無い。
     * 常に空になるキーを置くと、下流は**階層を扱えているように見えて扱えていない**。
     *
     * @return list<array{domain: string, nicename: string, name: string}>
     */
    private static function terms(\SimpleXMLElement $element, string $postId): array
    {
        $terms = [];

        foreach ($element->category as $category) {
            $attributes = $category->attributes();
            $domain = trim((string) ($attributes['domain'] ?? ''));

            if ($domain === '') {
                /*
                 * **黙って捨てない**（§A.3）。どの分類への割り当てか決められない。
                 * 捨てると、その記事だけ分類が付かないまま「成功」で終わる。
                 * 定義側を fail-closed にしておいて、割り当て側だけ不可視にしない。
                 */
                throw new WxrError(
                    "domain の無い <category> があります（wp:post_id {$postId}）。",
                    'どの分類への割り当てか決められません。'
                        .'黙って読み飛ばすと、その記事だけ分類が付かないまま成功します。',
                );
            }

            $nicename = trim((string) ($attributes['nicename'] ?? ''));
            $label = trim((string) $category);

            if ($nicename === '') {
                // slug が無ければ、どの項目への割り当てか決められない
                throw new WxrError(
                    'nicename の無い <category> があります（wp:post_id '.WxrError::token($postId).'）。',
                    'どの項目への割り当てか決められません。'
                        .'名前だけで結び付けると、同じ名前の別の項目に付きます。',
                );
            }

            if ($label === '') {
                throw new WxrError(
                    '表示名の無い <category> があります（wp:post_id '.WxrError::token($postId).'）。',
                    '受け皿にまだ無い項目だったとき、名前が無いと作れません。'
                        .'ここで止めずに通すと、apply が 1 件ずつ落ちます。',
                );
            }

            $terms[] = [
                'domain' => $domain,
                'nicename' => $nicename,
                'name' => $label,
            ];
        }

        return $terms;
    }

    /**
     * `_` で始まる meta は WP の内部用なので捨てる（SPEC §C.7）。
     *
     * @return array<string, string>
     */
    private static function meta(\SimpleXMLElement $wp, string $postId): array
    {
        $meta = [];

        foreach ($wp->postmeta as $entry) {
            $key = trim((string) $entry->meta_key);

            // `_` で始まるものは WP の内部用。**捨てる決まりがある**（§C.7）
            if (str_starts_with($key, '_')) {
                continue;
            }

            if ($key === '') {
                // 名前の無い値は §C.7 の object へ入れられない。捨てる決まりも無い
                throw new WxrError(
                    "名前の無い wp:postmeta があります（wp:post_id {$postId}）。",
                    'どの名前で保存すればよいか決められません。'
                        .'黙って読み飛ばすと、その値だけが理由も無く消えます。',
                );
            }

            if (array_key_exists($key, $meta)) {
                /*
                 * WordPress の postmeta は**同じ key を複数行持てる**。
                 * §C.7 の保存形は 1 か所の object なので、複数値の意味が無い。
                 * 後から来た値で上書きすると、**片方が理由も無く消える**。
                 * どちらを採るかは決められないので止める。
                 *
                 * **値は出さない。** 本文が画面と台帳へ流れる経路にしない。
                 */
                throw new WxrError(
                    'wp:meta_key ['.WxrError::token($key).'] が 2 回出てきます（wp:post_id '.WxrError::token($postId).'）。',
                    '同じ名前の値が 2 つあると、どちらを保存するか決められません。'
                        .'後から来た方を採ると、もう一方が理由も無く消えます。',
                );
            }

            // **serialize は解釈しない。** 文字列のまま持つ（壊れていても落ちない）
            $meta[$key] = (string) $entry->meta_value;
        }

        return $meta;
    }

    /**
     * `$seen` は**ファイル全体で共有する**。記事をまたいで同じ id があると、
     * 台帳（`(kind, target_type, external_id)` で一意）に入るのは 1 件だけで、
     * **2 件目が黙って飛ばされる**。
     *
     * @param  array<string, true>  $seen
     * @return list<WxrComment>
     */
    private static function comments(\SimpleXMLElement $wp, string $postId, array &$seen): array
    {
        $comments = [];

        foreach ($wp->comment as $comment) {
            $id = trim((string) $comment->comment_id);

            if ($id === '') {
                /*
                 * **黙って捨てない**（§A.3）。コメントは他人が書いた文章で、
                 * 落ちたことは移行が終わってから気づく。
                 * そして `comment_id` は台帳の鍵でもあるので（§D.2）、
                 * 無いまま入れると**冪等性も取り消しも成立しない**。
                 */
                throw new WxrError(
                    "wp:comment_id の無いコメントがあります（wp:post_id {$postId}）。",
                    'コメントは取り込んだあとに 1 件ずつ見分ける必要があり、'
                        .'id が無いと再実行で二重に増え、取り消しでも特定できません。'
                        .'書き出しをやり直してください。',
                );
            }

            self::assertIdentity($id, 'wp:comment_id', $postId);

            if (isset($seen[$id])) {
                // どちらが本物か決められない。**先に見つけた方を採らない**
                throw new WxrError(
                    "wp:comment_id [{$id}] が 2 回出てきます（wp:post_id {$postId}）。",
                    '同じ id のコメントがあると、どちらを取り込むか決められません。'
                        .'台帳は id で 1 件ずつ覚えるので、2 件目は黙って飛ばされます。',
                );
            }

            $seen[$id] = true;

            $comments[] = new WxrComment(
                commentId: $id,
                parentId: self::parentId((string) $comment->comment_parent, 'wp:comment_parent', $postId),
                author: trim((string) $comment->comment_author),
                email: self::text((string) $comment->comment_author_email),
                url: self::text((string) $comment->comment_author_url),
                content: (string) $comment->comment_content,
                approved: trim((string) $comment->comment_approved),
                // **IP はここで捨てる。** 形に入れない
                createdAt: self::date(
                    (string) $comment->comment_date_gmt,
                    (string) $comment->comment_date,
                ),
            );
        }

        return $comments;
    }

    /**
     * WXR の日時を、**こちらが保存できる時刻**へ直す。
     *
     * ## 9 時間ずれていた（2026-09-06 実測 / F-17）
     *
     * `wp:post_date_gmt` は **UTC** である。ここは前まで**変換せずそのまま**返しており、
     * 受け取った側（Eloquent / Carbon）は時間帯の付かない文字列を**アプリの時間帯**で
     * 解釈する。`APP_TIMEZONE=Asia/Tokyo` の既定では、WordPress で 10:00 に出した記事が
     * 管理画面に **01:00** と出た。
     *
     * **エラーは出ない。** 表示も保存も同じずれ方をするので、再保存しても二重には
     * ずれない — つまり**一方向の恒久ずれ**で、日付を覚えている人にしか見つからない。
     *
     * ## 直し方
     *
     * GMT の値は **UTC として読み、アプリの時間帯へ移してから**渡す。移す先は
     * `date_default_timezone_get()` — Laravel が起動時に `app.timezone` から設定する値で、
     * **Carbon が時間帯の付かない文字列を解釈するのと同じ時間帯**である。ここで別の
     * 出どころを見ると、**書いた時刻と読む時刻が違う時間帯**になる。
     *
     * `wp:post_date`（GMT の付かない方）は**既にサイトの現地時刻**なので変換しない。
     * WXR は現地時刻がどの時間帯かを持っておらず、推測すると当てずっぽうで動かすことになる。
     *
     * `0000-00-00` と空は「無い」。GMT が日時として読めないときも**そのまま返さず**
     * 現地時刻へ落とす（読めない値を UTC と言い張らない）。
     */
    private static function date(string $gmt, string $local): ?string
    {
        $gmt = trim($gmt);

        if ($gmt !== '' && ! str_starts_with($gmt, '0000-00-00')) {
            $converted = self::fromUtc($gmt);

            if ($converted !== null) {
                return $converted;
            }
        }

        $local = trim($local);

        if ($local !== '' && ! str_starts_with($local, '0000-00-00')) {
            return $local;
        }

        return null;
    }

    /**
     * `Y-m-d H:i:s` の UTC を、アプリの時間帯の同じ書き方へ。読めなければ null。
     *
     * **書き方をゆるめない。** WordPress の書き出しは常にこの形で、ここを緩めると
     * 「読めたつもり」で別の時刻が入る。
     */
    private static function fromUtc(string $value): ?string
    {
        $utc = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC'),
        );

        if ($utc === false) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $utc
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    /**
     * 切り出しが空で返ったときの理由。
     *
     * `readOuterXml()` は**理由を返さずに空文字を返す**。実際には
     * libxml の既定の上限（1 つの text node が大きすぎる、など）に当たっている
     * ことがあり、「読めませんでした」だけでは**利用者に次の手が無い**。
     * その場に残っている libxml の言い分を拾って渡す。
     */
    private static function couldNotRead(string $what): WxrError
    {
        $errors = array_filter(
            libxml_get_errors(),
            static fn (\LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR,
        );
        libxml_clear_errors();

        $first = reset($errors);

        if ($first === false) {
            return new WxrError(
                "{$what} を読めませんでした。",
                'libxml は理由を返しませんでした。'
                    .'1 つの要素が大きすぎる場合に起きることがあります。'
                    .'書き出しを分割してみてください。',
            );
        }

        return new WxrError(
            "{$what} を読めませんでした: ".WxrError::token(trim($first->message)),
            'libxml の既定の上限に当たっている可能性があります'
                .'（1 つの要素や text node が大きすぎる場合に起きます）。'
                .'書き出しを分割してみてください。',
            sourceLine: $first->line,
        );
    }

    /**
     * @throws WxrError
     */
    private static function assertIdentity(string $value, string $element, ?string $inPost = null): string
    {
        if (preg_match(self::ID_PATTERN, $value) === 1) {
            return $value;
        }

        /*
         * **不正な値そのものは出さない。** 本文級の長さで来ることも、
         * 見えない文字を含むこともある。どこが悪いかは要素の名前で足りる。
         *
         * 文脈として出す記事の id は、**既に検証を通ったもの**だけである。
         * `wp:post_id` 自身が不正なときは文脈が無い（出せる id がまだ無い）。
         */
        $where = $inPost === null ? '' : '（wp:post_id '.$inPost.'）';

        throw new WxrError(
            "{$element} が id の形ではありません{$where}。",
            'WordPress の id は 1 以上の 10 進の整数です'
                .'（0・先頭が 0・数字でない値・長すぎる値は受けません）。'
                .'id は取り込みの記録の鍵なので、形が違うと'
                .'再実行でも取り消しでも、その 1 件を見つけられなくなります。',
        );
    }

    /**
     * 親の id。`0` と空は「親なし」（**変換する前に形を確かめる**）。
     *
     * @throws WxrError
     */
    private static function parentId(string $value, string $element, string $inPost): ?string
    {
        $value = trim($value);

        // **親だけが 0 を許す**（WordPress の「親なし」の書き方）
        if ($value === '' || $value === '0') {
            return null;
        }

        return self::assertIdentity($value, $element, $inPost);
    }

    private static function text(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

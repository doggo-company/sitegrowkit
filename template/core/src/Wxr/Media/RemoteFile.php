<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Media;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * メディアを 1 件取ってくる（Phase 9.14 / STAGE 27E-3・SPEC §C.6）。
 *
 * ## 外へ出る口はここだけ
 *
 * `--media` を付けたときにしか呼ばれない。付けていないときに**1 度も接続しない**
 * ことは、この 1 か所を呼ぶかどうかで決まる（DoD 12）。
 *
 * ## 判定した IP へ固定して繋ぐ
 *
 * `AddressGuard` が返した IP を curl の名前解決表へ入れる。**接続の直前に
 * もう一度名前を引かない** — 引けば、判定の後で別の答えを返す隙ができる
 * （DNS rebinding）。解決は 1 回、判定はその結果に対して、接続もその結果へ。
 *
 * ## リダイレクトは追わない
 *
 * 追えば追った先で同じ検査をやり直す必要があり、経路が増える。
 * 代償として**移転済みのメディアは取れない** — その URL は本文に元のまま残るので、
 * 壊れはしない（§C.6）。
 */
final class RemoteFile
{
    /** curl が「大きすぎる」と言うときの番号（`CURLE_FILESIZE_EXCEEDED`） */
    private const CURLE_FILESIZE_EXCEEDED = 63;

    /** 取得の上限。既存の upload の上限と同じ値を使う（先に大きく落として後で弾かない） */
    private const FALLBACK_KILOBYTES = 20480;

    public function __construct(private readonly AddressGuard $guard) {}

    /**
     * 繋ぐ先を固定するための curl の名前解決表の 1 行（`host:port:ip`）。
     *
     * **`AddressGuard` が返した IP がそのままここへ来ることが要点**なので、
     * 組み立てを取り出して試験できるようにしてある。
     */
    public static function pin(string $url, string $address): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ($parts['host'] ?? '').':'.$port.':'.$address;
    }

    /**
     * 大きすぎるかどうか。**流れている途中で何度も訊かれる**（進捗 callback から）。
     *
     * 純粋な判定として出してあるのは、curl を通さずに試験から直接叩けるようにするため。
     */
    public static function exceeds(int $downloadedBytes, int $maxBytes): bool
    {
        return $downloadedBytes > $maxBytes;
    }

    /**
     * 繋ぎ方の指定。**中身を試験から見えるところへ出してある**（F-14）。
     *
     * `pin()` が正しい文字列を作ることは単体で見ていたが、**それが curl へ渡っている
     * ことは誰も見ていなかった** — 部品は正しいのに繋がりを見ていない、という形である。
     * 組み立てをここへ出して、固定の行が入っていることを試験が押さえる。
     *
     * ## 大きさは、**載せる前に**止める
     *
     * かつては `$response->body()` で全部メモリへ載せてから大きさを見ていた。
     * 上限は 10 MB なのに、**相手が 5 GB 返せば 5 GB 載ってから断っていた**。
     *
     * 受け取ったものは `sink` で**直接ファイルへ**流す。メモリには載らない。
     *
     * ### 止め方を 2 つ入れている理由（実測つき）
     *
     * 当初は「`CURLOPT_MAXFILESIZE` は大きさを宣言しない相手には効かないので、
     * そこは進捗 callback でしか止まらない」と考えていた。**実測すると違った**
     * （libcurl 8.7.1・2026-08-19）:
     *
     * | 応答 | `--max-filesize` だけ | 落ちた量 |
     * | --- | --- | --- |
     * | `Content-Length` あり | 中止（exit 63） | **0 バイト**（落とす前に切れる） |
     * | 宣言なし | 中止（exit 63） | **上限ちょうど**（そこまでは落ちる） |
     *
     * つまり**この libcurl では MAXFILESIZE だけでも両方止まる**。早く切れるかどうかが
     * 違うだけである。それでも進捗 callback を残すのは、**libcurl の版に依らせない**
     * ためである — 「宣言が無ければ効かない」は長く文書に書かれていた振る舞いで、
     * 配った先の libcurl が古ければそちらに当たる。
     *
     * **正直に書いておく**: この環境では callback は**外しても止まる**。
     * 効いていることを本物の curl で示せるのは MAXFILESIZE の側だけで、
     * callback は「指定に入っていること」と「呼べば中止を返すこと」までしか
     * 押さえられていない（`ApplyWxrMediaTest`）。
     *
     * ## この試験が守れる範囲
     *
     * 押さえられるのは「`options()` が何を返すか」までで、**`withOptions()` の呼び出しごと
     * 消せば緑に戻る**。呼ぶ場所が 1 か所しかないので穴は狭いが、無いわけではない。
     * **本物の curl で効くこと**は `RemoteFileLimitTest`（env で明示したときだけ走る）が見る。
     *
     * @return array<string, mixed>
     */
    public static function options(string $url, string $address, string $sink, int $maxBytes): array
    {
        return [
            // **追わない。** 3xx はそのまま「取れなかった」として返す
            'allow_redirects' => false,
            // **メモリに載せない。** 受け取った端からファイルへ流す
            'sink' => $sink,
            'curl' => [
                /*
                 * **判定した IP へ固定する。** ここへ渡るのが `AddressGuard` の答えそのもの
                 * であることが要点で、接続の直前に名前を引き直さない（DNS rebinding）。
                 */
                CURLOPT_RESOLVE => [self::pin($url, $address)],
                // 大きさを宣言してきた相手は、**落とす前に**断る
                CURLOPT_MAXFILESIZE => $maxBytes,
                /*
                 * 宣言してこない相手（chunked）は、**流れている途中で**断る。
                 * 0 以外を返すと curl は転送を中止する。
                 */
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static fn (
                    mixed $resource,
                    int $expected,
                    int $downloaded,
                ): int => self::exceeds($downloaded, $maxBytes) ? 1 : 0,
            ],
        ];
    }

    /**
     * 取れたら中身、駄目なら理由。**例外は投げない**（1 件で本編を止めない）。
     *
     * @return array{0: FetchedFile|null, 1: MediaFailure|null}
     */
    public function fetch(string $url): array
    {
        [$address, $refusal] = $this->guard->allow($url);

        if ($address === null) {
            return [null, $refusal ?? MediaFailure::Fetch];
        }

        $maxBytes = $this->maxKilobytes() * 1024;
        $path = (string) tempnam(sys_get_temp_dir(), 'wxr-media-');

        try {
            $response = Http::withOptions(self::options($url, $address, $path, $maxBytes))
                ->connectTimeout(5)
                ->timeout(20)
                ->get($url);
        } catch (Throwable $error) {
            /*
             * 繋がらない・時間切れ・**大きすぎて中止した**。
             * 理由の文面は運ばない（他人のサーバーの応答が流れる）。
             */
            $failure = self::tooLarge($error, $this->discard($path), $maxBytes)
                ? MediaFailure::TooLarge
                : MediaFailure::Fetch;

            return [null, $failure];
        }

        if ($response->redirect()) {
            // **3xx の本文も sink は書く。** 使わないものを置き去りにしない
            $this->discard($path);

            return [null, MediaFailure::Redirected];
        }

        if (! $response->successful()) {
            $this->discard($path);

            return [null, MediaFailure::Fetch];
        }

        /*
         * **重ねの守り。** ここまでで curl が 2 度止める機会を持っているが、
         * 止められなかった場合に備えて、ディスク上の実際の大きさをもう一度見る。
         * 中止は転送の途中で起きるので、上限ちょうどを少し超えることもある。
         */
        if (filesize($path) > $maxBytes) {
            $this->discard($path);

            return [null, MediaFailure::TooLarge];
        }

        return [new FetchedFile($path, $this->filename($url)), null];
    }

    /**
     * 中止の理由は「大きすぎた」か。
     *
     * ## 大きさでは見分けられない
     *
     * 最初は「書きかけが上限を**超えて**いるか」で見ていた。**実測すると、
     * どちらの経路でも偽になる**（libcurl 8.7.1）:
     *
     * - 宣言あり … 落とす前に切れるので **0 バイト**
     * - 宣言なし … **上限ちょうど**で止まる（超えない）
     *
     * つまり「大きすぎた」は利用者に**一度も出ていなかった**。取れなかったとしか
     * 言えず、§A.3 の「何が起きたかを言う」が 1 段落ちていた（F-21）。
     *
     * だから **curl が言った理由（`errno` 63 = `CURLE_FILESIZE_EXCEEDED`）を一次にする**。
     * 大きさは二段目で、`>=` で見る（ちょうどで止まるため）。
     *
     * ▷ **残る隙**: errno を取れない環境では、宣言ありの経路（0 バイト）は
     * 「取れなかった」に落ちる。0 バイトは繋がらなかった場合と見分けがつかない。
     */
    public static function tooLarge(?Throwable $error, int $written, int $maxBytes): bool
    {
        if (self::curlErrno($error) === self::CURLE_FILESIZE_EXCEEDED) {
            return true;
        }

        return $written >= $maxBytes;
    }

    /** 例外の連なりから curl の errno を拾う（無ければ null） */
    private static function curlErrno(?Throwable $error): ?int
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if (! method_exists($current, 'getHandlerContext')) {
                continue;
            }

            $context = $current->getHandlerContext();

            if (is_array($context) && isset($context['errno'])) {
                return (int) $context['errno'];
            }
        }

        return null;
    }

    /**
     * 使わない書きかけを消し、**消す前の大きさ**を返す。
     *
     * 大きさを返すのは、中止の理由（大きすぎたのか、繋がらなかったのか）を
     * ここでしか見分けられないためである。
     */
    private function discard(string $path): int
    {
        $size = is_file($path) ? (int) filesize($path) : 0;

        if (is_file($path)) {
            @unlink($path);
        }

        return $size;
    }

    private function maxKilobytes(): int
    {
        $configured = (int) config('agentcms.uploads.max_kilobytes');

        return $configured > 0 ? $configured : self::FALLBACK_KILOBYTES;
    }

    /**
     * URL から見えるファイル名。**経路の記号は落とす。**
     *
     * `../` を含む名前をそのまま渡すと、保存側が名前を使う日に効いてくる
     * （今は UUID で作り直すが、その前提はここでは確かめられない）。
     */
    private function filename(string $url): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';

        return $name === '' || $name === '.' || $name === '..' ? 'media' : $name;
    }
}

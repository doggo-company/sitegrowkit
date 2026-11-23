<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Taxonomy\TermSlug;
use AgentCms\Core\Wxr\WxrTermDefinition;
use LogicException;

/**
 * 分類項目の親子と重複を、入れる前に確かめる（Phase 9.14 / STAGE 27C・SPEC §C.3）。
 *
 * ## 入れてからでは直せない
 *
 * 親が居ない・自分が親・辿ると戻る、のどれも取り込み自体は「動く」。
 * 動いたうえで**入れ子だったものが十年分フラットに並ぶ**か、
 * 親を辿る処理が止まらなくなる。入れ終わってからでは元の形が分からない。
 *
 * ## 2 段階で読む
 *
 * 定義と item は**別々の走査**で読む（`WxrReader::termDefinitions()` と
 * `items()`）。定義の走査を終えてから**封をして**しまえば、割り当ては
 * 1 件も抱えずにその場で照合できる。抱えると、割り当ての種類の数だけ
 * メモリが増える。
 *
 * **XML の中で定義と item がどちらの順で書かれていても同じである。**
 * 順序に頼ると、item が先に書かれた WXR で全部の割り当てが
 * 「定義が無い」になる。
 *
 * ## 元の文字列を抱えない
 *
 * 十万件分の名前と綴りを配列で持てば、それだけでメモリが尽きる。
 * それ以上に、**他人のサイトの分類名には個人名も屋号も入る**。
 * ここに残すのは
 *
 * - 突き合わせ用の 32 byte の要約（元へは戻らない）
 * - WXR の `wp:term_id`
 * - こちらで保存する綴り（URL に出る値だけ）
 *
 * の 3 つで、名前そのものは畳んだ直後に手放す。
 *
 * ## 受け皿ごとに分ける
 *
 * 対応先の分類が違えば、同じ綴りでも別の項目である。
 * 受け皿を鍵の中へ混ぜ込むと、十万件分だけ受け皿名の複製ができる。
 * 外側で 1 度だけ持つ。
 */
final class TermGraph
{
    /**
     * ここまでは確かめ切る（§C.3）。
     *
     * 越えたら**他の理由を出さずに止める**。中途半端に確かめた結果を出すと、
     * 「循環は無い」と言えないのに循環の理由が出ないので、
     * 利用者は確かめたうえで無かったのだと読む。
     */
    public const MAX_DEFINITIONS = 100_000;

    /**
     * `taxonomy_terms.name` の桁（varchar(120)）。
     *
     * **綴りの上限と同じ数でも、別の約束である。** `TermSlug::MAX_LENGTH` に
     * 結び付けると、URL の規則を変えただけで名前の可否まで動く。
     */
    private const NAME_MAX = 120;

    /**
     * 要約は**用途ごとに別の鍵で作る**。
     *
     * 綴りの要約と名前の要約が同じ入力から同じ値になると、
     * 「綴りは違うが名前が同じ」と「その逆」を取り違える。
     */
    private const SLUG_DOMAIN = "wxr-term-slug\0";

    private const NAME_DOMAIN = "wxr-term-name\0";

    /** 明細に出す元の種類。**元の分類名は出さない**（個人名や屋号が入る） */
    private const SOURCE_TYPE = 'term';

    /** 畳んだ記録の中の印（作った綴り / 名前が長すぎる / 自分が親） */
    private const FLAG_COUNT = 3;

    private const FLAG_GENERATED = 0;

    private const FLAG_NAME_TOO_LONG = 1;

    private const FLAG_PARENT_SELF = 2;

    /** @var array<string, array<string, string>> 受け皿 => 綴りの要約 => 畳んだ記録 */
    private array $terms = [];

    /**
     * 突き合わせ用の名前の要約。**封をしたら手放す。**
     *
     * @var array<string, array<string, string>> 受け皿 => 綴りの要約 => 名前の要約
     */
    private array $names = [];

    /** @var array<string, true> WXR 側の term_id は、受け皿をまたいでも 1 度しか出てこない */
    private array $termIds = [];

    /** @var list<string> 同じ term_id が 2 度出てきたときの、2 度目の id */
    private array $duplicateIds = [];

    /** @var list<string> 先に来た定義と食い違うもの */
    private array $conflictIds = [];

    /**
     * 先に来た定義と id 以外すべて一致するもの。
     *
     * **捨てずに対応を残す。** 台帳は WXR の term_id ごとに 1 行を持つので、
     * 「この id は代表の id と同じ項目である」を失うと、
     * 後から**別の項目として作り直す**か、取り消しで片方が残る。
     *
     * @var array<string, string> 重なった term_id => 代表の term_id（WXR の先頭）
     */
    private array $aliases = [];

    private int $count = 0;

    /** 越えた時点の term_id。**越えたら他は数えない** */
    private ?string $overflowedAt = null;

    private bool $sealed = false;

    /**
     * こちらで**保管されている**項目の綴りの要約。
     *
     * 綴りそのものは持たない（他人のサイトの値を運ばない）。
     * 割り当てがここを指したら、その記事は分類を失う。
     *
     * @var array<string, array<string, true>> 受け皿 => 綴りの要約 => true
     */
    private array $archived = [];

    /**
     * `<channel>` 直下の定義を 1 件畳む。
     *
     * `$target` は**読み替えた後**の受け皿である。読み替える前の名前で入れると、
     * 割り当ての側だけが読み替わって、同じ項目が 2 つに見える。
     */
    public function define(string $target, WxrTermDefinition $definition): void
    {
        if ($this->sealed) {
            /*
             * 封の後に足せると、**確かめていない定義が受け皿に載る**。
             * WXR の中身ではなく組み立ての順番の誤りなので、数えずに投げる。
             */
            throw new LogicException('封をした後に分類項目の定義は足せません。');
        }

        if ($this->overflowedAt !== null) {
            return;
        }

        if (++$this->count > self::MAX_DEFINITIONS) {
            $this->overflow($definition->termId);

            return;
        }

        if (isset($this->termIds[$definition->termId])) {
            /*
             * **中身が同じでも止める。受け皿が違っても止める。**
             *
             * 台帳は `external_id` で 1 件ずつ覚えるので、同じ id の 2 定義を
             * 「同じものだから」と通すと **2 件目は台帳から不可視**になる。
             * 取り消しでも再実行でも、その 1 件だけ扱えなくなる。
             *
             * 重複としては数え直さない。1 つの間違いを 2 か所で探させない。
             */
            $this->duplicateIds[] = $definition->termId;

            return;
        }

        $this->termIds[$definition->termId] = true;

        $canonical = TermSlug::canonicalize($definition->slug);
        $slugKey = self::digest(self::SLUG_DOMAIN, $canonical);
        $nameKey = self::digest(self::NAME_DOMAIN, trim($definition->name));
        $parentKey = self::parentKey($definition);

        if (isset($this->terms[$target][$slugKey])) {
            $this->compareWithFirst($target, $slugKey, $definition->termId, $nameKey, $parentKey);

            return;
        }

        $this->names[$target][$slugKey] = $nameKey;
        $this->terms[$target][$slugKey] = self::fold($definition, $canonical, $slugKey, $parentKey);
    }

    /**
     * 定義を確かめて**封をする**。見つけたものは全部 findings へ入れる（投げない — §A.3）。
     *
     * 1 件目で投げると、利用者は「直しては実行し、また別の理由で止まる」を
     * 何十回も繰り返すことになる。十年分の移行でそれをやらせるのは、
     * 実質的に移行させないのと同じである。
     *
     * **2 度呼んでも同じことを 2 度言わない。**
     */
    public function seal(PlanFindings $findings): void
    {
        if ($this->sealed) {
            return;
        }

        $this->sealed = true;

        if ($this->overflowedAt !== null) {
            $findings->add(PlanIssue::TooManyTermDefinitions, $this->overflowedAt, self::SOURCE_TYPE);

            return;
        }

        $this->report($findings, PlanIssue::TermIdDuplicate, $this->duplicateIds);
        $this->report($findings, PlanIssue::TermDefinitionConflict, $this->conflictIds);
        /*
         * 代表との対応は**捨てない**（台帳がこの先で要る）。
         *
         * term_id は数字だけの文字列なので、鍵として持つと PHP が整数へ直す。
         * 明細へ出すときに文字へ戻す — **数として比べられる形で外へ出さない**。
         */
        $this->report(
            $findings,
            PlanIssue::TermDefinitionRepeated,
            array_map(strval(...), array_keys($this->aliases)),
        );

        $this->duplicateIds = [];
        $this->conflictIds = [];

        /*
         * **突き合わせが済んだら手放す。**
         *
         * この先で要るのは「その綴りの定義が在るか」だけで、
         * 名前の要約も term_id の一覧も、item を読む間ずっと抱える理由が無い。
         */
        $this->names = [];
        $this->termIds = [];

        foreach ($this->terms as $terms) {
            $this->verifyEach($terms, $findings);
            $this->verifyParents($terms, $findings);
            $this->verifyStorageSlugs($terms, $findings);
        }
    }

    /**
     * こちらで保管されている項目の枠を控える（§C.3）。
     *
     * **止めるのは割り当てが実際に向いたときだけ**なので、ここでは数えない。
     * 誰も指していない保管項目で止めると、記事は 1 本も失われないのに移行だけが止まり、
     * しかも直す先が無い。
     */
    public function markArchived(string $target, string $storageSlug): void
    {
        $this->archived[$target][self::digest(self::SLUG_DOMAIN, $storageSlug)] = true;
    }

    /**
     * item の `<category domain nicename>`。**親は書かれていない**（§C.3）。
     *
     * **1 件も抱えない。** 定義の走査は別に済ませて封をしてあるので、
     * その場で照合できる。抱えると、割り当ての種類の数だけメモリが増える。
     *
     * 定義が無いことを言うときは、その定義の id は無いのだから
     * **その割り当てを書いた記事の側**（`wp:post_id`）で指す。
     * 同じ綴りを 100 本の記事が指していれば 100 件と数える。
     * 「直す先は 1 つだから 1 件」に畳むと、**何本の記事が分類を失うのか**が出ない。
     */
    public function assign(string $target, string $nicename, string $postId, PlanFindings $findings): void
    {
        if (! $this->sealed) {
            /*
             * 封の前に照合すると、**まだ読んでいない定義を「無い」と言う**。
             * 十年分の割り当てが一斉に「定義が無い」になる。
             */
            throw new LogicException('分類項目の定義に封をする前に割り当ては照合できません。');
        }

        if ($this->overflowedAt !== null) {
            // 越えたら守るのは 1 つの理由だけ（確かめ切れていないものを言わない）
            return;
        }

        $slugKey = self::digest(self::SLUG_DOMAIN, TermSlug::canonicalize($nicename));

        if (! isset($this->terms[$target][$slugKey])) {
            $findings->add(PlanIssue::TermDefinitionMissing, $postId, self::SOURCE_TYPE);

            return;
        }

        if (isset($this->archived[$target][self::digest(self::SLUG_DOMAIN, self::storageOf($this->terms[$target][$slugKey]))])) {
            /*
             * こちらで保管されている項目へ割り当てが向いた。
             * **記事の側**で数える — 何本の記事が分類を失うかが出る。
             */
            $findings->add(PlanIssue::TermArchived, $postId, self::SOURCE_TYPE);
        }
    }

    /**
     * 作る対象ごとに 1 件（代表）。**封をした後だけ読める。**
     *
     * ## なぜ当初は口が無かったのか
     *
     * 27C の時点では `plan` の外へ出す用がなく、**要らない口は開けない**という
     * 判断だった（代表の観測口を本番へ公開しない）。
     *
     * ## なぜ開けたのか
     *
     * 27D-1 で分類項目の行動を `plan` へ積むことになり、
     * 「何を作り、何を使い回すか」を決めるのに
     * **受け皿・保存する綴り・WXR 側の id** が要るようになったためである。
     *
     * ## 返さないもの
     *
     * 名前も元の綴りも返さない。他人のサイトの分類名には個人名も屋号も入る。
     * 行動を積むのに要らないものを、外へ出す口にしない。
     *
     * @return list<array{termId: string, target: string, storageSlug: string}>
     */
    public function representatives(): array
    {
        $this->assertSealed();

        $representatives = [];

        foreach ($this->terms as $target => $terms) {
            foreach ($terms as $record) {
                $representatives[] = [
                    'termId' => self::termIdOf($record),
                    'target' => (string) $target,
                    'storageSlug' => self::storageOf($record),
                ];
            }
        }

        return $representatives;
    }

    /**
     * **作る順**（親が先）。封をした後だけ読める。
     *
     * ## なぜ報告の順と別の口なのか
     *
     * `representatives()` は WXR に出てきた順で、`plan` の行動の並びに使う。
     * その並びは畳んで指紋になっているので、**動かすと同じ WXR から違う plan が出る**。
     *
     * `apply` が要るのは**依存の順**である。`CreateTerm` が受け取る親は
     * こちらの ULID なので、**親を先に作って id を覚えていなければ**子を作れない（§C.3）。
     *
     * 目的が別なので、口も別にする。どちらも決定的で、同じ WXR なら同じ順になる。
     *
     * ## 再帰しない
     *
     * 十年分の分類は一本の鎖になり得る。再帰で辿ると鎖が長いだけで落ちる
     * （`verifyParents()` と同じ理由）。
     *
     * @return list<array{termId: string, target: string, storageSlug: string, parentTermId: string|null}>
     */
    public function creationOrder(): array
    {
        $this->assertSealed();

        $order = [];

        foreach ($this->terms as $target => $terms) {
            /** @var array<string, true> 出し終えたもの */
            $placed = [];

            foreach ($terms as $slugKey => $_) {
                /*
                 * **自分から親へ遡って、上から順に出す。**
                 * 既に出したところまで来たら、そこで折り返す。
                 */
                $chain = [];
                $current = $slugKey;

                while (! isset($placed[$current])) {
                    $chain[] = $current;
                    $placed[$current] = true;

                    $record = $terms[$current];

                    // 自分が親のものと最上位は、そこで終わり（`verifyParents()` と同じ扱い）
                    $parent = self::flag($record, self::FLAG_PARENT_SELF) ? '' : self::parentOf($record);

                    if ($parent === '' || ! isset($terms[$parent])) {
                        break;
                    }

                    $current = $parent;
                }

                // 遡った分を**逆順に**出す（親が先）
                foreach (array_reverse($chain) as $key) {
                    $record = $terms[$key];
                    $parent = self::flag($record, self::FLAG_PARENT_SELF) ? '' : self::parentOf($record);

                    $order[] = [
                        'termId' => self::termIdOf($record),
                        'target' => (string) $target,
                        'storageSlug' => self::storageOf($record),
                        'parentTermId' => $parent !== '' && isset($terms[$parent])
                            ? self::termIdOf($terms[$parent])
                            : null,
                    ];
                }
            }
        }

        return $order;
    }

    /**
     * 重なった term_id と、その代表の term_id。**封をした後だけ読める。**
     *
     * 台帳は term_id ごとに 1 行を持つので、重なった id にも行が要る。
     * だが**対象を作るのは代表 1 件だけ**なので、作る側の一覧には出さない。
     *
     * @return array<string, string> 重なった term_id => 代表の term_id
     */
    public function aliases(): array
    {
        $this->assertSealed();

        $aliases = [];

        // term_id は数字だけの文字列なので、鍵として持つと PHP が整数へ直す
        foreach ($this->aliases as $termId => $representative) {
            $aliases[(string) $termId] = $representative;
        }

        return $aliases;
    }

    /**
     * 封の前に読めると、**確かめていない代表**が外へ出る。
     *
     * 保存する綴りは封をするときに決まる（重複を畳み、当てる綴りを決める）ので、
     * その前の値は代表ですらない。組み立ての順番の誤りなので、数えずに投げる。
     */
    /**
     * 割り当て（item の `<category nicename>`）が指す**代表の `wp:term_id`**。
     *
     * ## 引くのは元の綴りで
     *
     * item が持っているのは**元の綴りだけ**である。こちらが当てた綴りで引く形にすると、
     * 当てが働いた分類の割り当てが丸ごと引けなくなる —
     * 日本語のサイトではそれがほとんどになる（`Str::slug('お知らせ')` は空）。
     *
     * ## 返すのは id であって対象ではない
     *
     * こちら側の id は**台帳が持っている**（`TermImporter` が書いた行）。
     * ここから先は台帳を引く。「どこへ入ったか」の正本を 2 つ持たない。
     *
     * ## 出す口ではなく引く口である
     *
     * 綴りも名前も**受け取るだけで返さない**。呼び手は既にその綴りを持っている
     * （item から読んだ値である）。一覧を返す口にすると、
     * 他人のサイトの分類名の出どころになる。
     */
    public function representativeFor(string $target, string $nicename): ?string
    {
        $this->assertSealed();

        if ($this->overflowedAt !== null) {
            /*
             * 確かめ切れていないので代表も無い。ここで返すと、
             * **確かめていない代表へ記事を結び付ける**ことになる。
             */
            return null;
        }

        $record = $this->terms[$target][self::digest(self::SLUG_DOMAIN, TermSlug::canonicalize($nicename))] ?? null;

        return $record === null ? null : self::termIdOf($record);
    }

    private function assertSealed(): void
    {
        if (! $this->sealed) {
            throw new LogicException('分類項目の定義に封をする前に、代表は読めません。');
        }
    }

    /**
     * 越えたら**抱えているものを手放す**。
     *
     * ここから先は 1 つの理由しか出さないので、十万件分の記録を持ち続ける
     * 意味が無い。この後に読む item の側にメモリを空けておく。
     */
    private function overflow(string $termId): void
    {
        $this->overflowedAt = $termId;
        $this->terms = [];
        $this->names = [];
        $this->termIds = [];
        $this->duplicateIds = [];
        $this->conflictIds = [];
        $this->aliases = [];
    }

    /**
     * 先に来た定義と突き合わせる。
     *
     * 受け皿と綴りは（鍵が同じ時点で）一致している。残るのは名前と親で、
     * **1 つでも違えば同じ項目だとは決められない**。どちらを採っても、
     * 採らなかった方の記事の分類が変わる。
     */
    private function compareWithFirst(
        string $target,
        string $slugKey,
        string $termId,
        string $nameKey,
        string $parentKey,
    ): void {
        $sameName = $this->names[$target][$slugKey] === $nameKey;
        $sameParent = self::parentOf($this->terms[$target][$slugKey]) === $parentKey;

        // 代表は WXR の先頭。**後から来た方**を指す（直す先が分かる）
        if ($sameName && $sameParent) {
            $this->aliases[$termId] = self::termIdOf($this->terms[$target][$slugKey]);

            return;
        }

        $this->conflictIds[] = $termId;
    }

    /**
     * 1 件を**必要な安全値だけ**へ畳む。
     *
     * 保存する綴りはここで決まる。名前が要るのはこの 1 行のためだけなので、
     * 決めた直後に名前を手放せる。
     */
    private static function fold(
        WxrTermDefinition $definition,
        string $canonical,
        string $slugKey,
        string $parentKey,
    ): string {
        $name = trim($definition->name);

        /*
         * `Str::slug('お知らせ')` は**空文字**を返す。そのままでは保存できないので
         * term_id 由来の綴りを当てる（`TermSlug::generate()`）。
         * 当てたことは後で必ず言う — 黙って別の URL にしない。
         */
        $generated = ! TermSlug::isValid($canonical);
        $storage = $generated ? TermSlug::generate($name, $definition->termId) : $canonical;

        $flags = ($generated ? '1' : '0')
            .(mb_strlen($name) > self::NAME_MAX ? '1' : '0')
            .($parentKey === $slugKey ? '1' : '0');

        return $definition->termId."\0".$storage."\0".$flags.$parentKey;
    }

    /** 親の綴りの要約。最上位なら空 */
    private static function parentKey(WxrTermDefinition $definition): string
    {
        if ($definition->parentSlug === null || trim($definition->parentSlug) === '') {
            return '';
        }

        return self::digest(self::SLUG_DOMAIN, TermSlug::canonicalize($definition->parentSlug));
    }

    /**
     * 1 件ずつ見れば分かるもの。
     *
     * @param  array<string, string>  $terms
     */
    private function verifyEach(array $terms, PlanFindings $findings): void
    {
        foreach ($terms as $record) {
            $termId = self::termIdOf($record);

            if (self::flag($record, self::FLAG_PARENT_SELF)) {
                /*
                 * **循環としては数えない。** 1 件の間違いが 2 つの理由で出ると、
                 * 直す側は 2 か所を探すことになる。
                 */
                $findings->add(PlanIssue::TermParentSelf, $termId, self::SOURCE_TYPE);
            }

            if (self::flag($record, self::FLAG_NAME_TOO_LONG)) {
                // `taxonomy_terms.name` は varchar(120)。**切り詰めて入れない**
                $findings->add(PlanIssue::TermNameTooLong, $termId, self::SOURCE_TYPE);
            }

            if (self::flag($record, self::FLAG_GENERATED)) {
                $findings->add(PlanIssue::TermSlugGenerated, $termId, self::SOURCE_TYPE);
            }
        }
    }

    /**
     * 親を辿る。**再帰しない。**
     *
     * 十年分の分類は一本の鎖になり得る。再帰で辿ると鎖が長いだけで
     * 呼び出しが積み上がって落ち、落ちた plan は「確かめられなかった」ではなく
     * ただ死んだように見える。
     *
     * @param  array<string, string>  $terms
     */
    private function verifyParents(array $terms, PlanFindings $findings): void
    {
        /** @var array<string, true> 一度たどり着いた先は二度見ない */
        $settled = [];

        foreach ($terms as $slugKey => $_) {
            if (isset($settled[$slugKey])) {
                continue;
            }

            /** @var list<string> いま辿っている道 */
            $path = [];

            /** @var array<string, int> その道の何番目に居るか */
            $at = [];

            $current = $slugKey;

            while (true) {
                if (isset($settled[$current])) {
                    // 既に確かめ終えた先へ入った。新しい輪ではない
                    break;
                }

                if (isset($at[$current])) {
                    // 辿った道の中へ戻った。**戻った所から先が輪**である
                    for ($i = $at[$current], $n = count($path); $i < $n; $i++) {
                        $findings->add(
                            PlanIssue::TermParentCycle,
                            self::termIdOf($terms[$path[$i]]),
                            self::SOURCE_TYPE,
                        );
                    }

                    break;
                }

                $at[$current] = count($path);
                $path[] = $current;

                $record = $terms[$current];

                // 自分が親のものは別の理由で出す（ここでは最上位として扱う）
                $parent = self::flag($record, self::FLAG_PARENT_SELF) ? '' : self::parentOf($record);

                if ($parent === '') {
                    break;
                }

                if (! isset($terms[$parent])) {
                    $findings->add(
                        PlanIssue::TermParentMissing,
                        self::termIdOf($record),
                        self::SOURCE_TYPE,
                    );

                    break;
                }

                $current = $parent;
            }

            foreach ($path as $key) {
                $settled[$key] = true;
            }
        }
    }

    /**
     * 同じ受け皿の中で、保存する綴りがぶつからないこと。
     *
     * 当てる綴りは term_id の**下 8 桁**から作るので、`12345678` と
     * `10012345678` は同じ綴りになる。名前から作る場合も、
     * 違う名前が同じ綴りになることがある。どちらも**片方が消える**。
     *
     * @param  array<string, string>  $terms
     */
    private function verifyStorageSlugs(array $terms, PlanFindings $findings): void
    {
        /** @var array<string, true> */
        $taken = [];

        foreach ($terms as $record) {
            $termId = self::termIdOf($record);
            $storage = self::storageOf($record);

            if (isset($taken[$storage])) {
                $findings->add(PlanIssue::TermTargetSlugConflict, $termId, self::SOURCE_TYPE);

                continue;
            }

            $taken[$storage] = true;

            if (! TermSlug::isValid($storage)) {
                /*
                 * `TermSlug::generate()` は必ず保存できる綴りを返す約束なので、
                 * ここは通らない。**約束が破れたときに黙って壊れない**ようにする。
                 */
                $findings->add(PlanIssue::TermStorageSlugInvalid, $termId, self::SOURCE_TYPE);
            }
        }
    }

    /**
     * @param  list<string>  $termIds
     */
    private function report(PlanFindings $findings, PlanIssue $reason, array $termIds): void
    {
        foreach ($termIds as $termId) {
            $findings->add($reason, $termId, self::SOURCE_TYPE);
        }
    }

    /** 元へは戻らない 32 byte の要約 */
    private static function digest(string $domain, string $value): string
    {
        return hash('sha256', $domain.$value, true);
    }

    private static function termIdOf(string $record): string
    {
        return substr($record, 0, (int) strpos($record, "\0"));
    }

    private static function storageOf(string $record): string
    {
        return explode("\0", $record, 3)[1];
    }

    private static function parentOf(string $record): string
    {
        return substr(explode("\0", $record, 3)[2], self::FLAG_COUNT);
    }

    private static function flag(string $record, int $which): bool
    {
        return explode("\0", $record, 3)[2][$which] === '1';
    }
}

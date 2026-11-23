<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\FlatTermDefinitionSource;
use AgentCms\Core\Wxr\Ledger\ExistingTermLookup;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Ledger\LedgerState;
use AgentCms\Core\Wxr\Ledger\TermSlot;
use AgentCms\Core\Wxr\Status\CommentSetting;
use AgentCms\Core\Wxr\Status\ContentStatus;
use AgentCms\Core\Wxr\Status\WxrCommentStatus;
use AgentCms\Core\Wxr\Status\WxrStatus;
use AgentCms\Core\Wxr\WxrError;
use AgentCms\Core\Wxr\WxrItem;
use AgentCms\Core\Wxr\WxrReader;

/**
 * 「これから何が起きるか」を組み立てる（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## DB を読むが 1 行も書かない
 *
 * `plan` を見ただけでサイトの状態が変わってはいけない。
 *
 * ## 1 件目で止めない
 *
 * 取り込めない理由は**全部集めてから**返す。見つけた順に例外を投げると、
 * 利用者は「直しては実行し、また別の理由で止まる」を何十回も繰り返す。
 * 十年分の移行でそれをやらせるのは、実質的に移行させないのと同じである（§A.3）。
 *
 * resolver も同じ約束で、投げずに全部返す（`TargetStateResolver::inspect()`）。
 *
 * ## ファイルが途中で差し替わったら止める
 *
 * 中身の指紋と item の読み取りは別々に開く。その間に差し替えられると
 * **「指紋は前のファイル・中身は後のファイル」の plan** ができる。
 * それを `apply` へ渡せば、照合は通るのに入るものが違う。
 */
class PlanBuilder
{
    /**
     * **仕様として入れないと決まっている** post_type（§A.2・§C.6）。
     *
     * 対応先が無くて入れられないのとは別に数える。
     * 同じ数え方にすると、設定を直せば入るのか元から対象外なのかが読めない。
     */

    /** 添付は投稿タイプではなく Media として扱う（§C.1） */

    /** 台帳での Media の行き先（`target_type`） */

    /** 台帳での コメント の行き先（`target_type`） */
    private const COMMENT_TARGET = 'comment';

    /**
     * 「同じ取り込みの代表が作る」を表す印。
     *
     * 対象の id ではないので、そのまま digest へは載せない。
     * `reuse` かどうかの判定にだけ使う。
     */
    private const ALIAS = 'alias';

    /** `wp:postmeta` の入れ先（§C.7 — `{"wp_meta": {...}}`） */
    private const META_FIELD = 'wp_meta';

    /** 台帳の桁（`import_records.external_id` / `target_type`） */
    /**
     * 既に数えた「対応の無い必須の欄」。**型ごとに 1 件**にするため。
     *
     * @var array<string, true>
     */
    private array $unmappable = [];

    public function __construct(
        private readonly ContentTypeResolver $types,
        private readonly TargetStateResolver $targets,
        private readonly LedgerReconciler $ledger,
        private readonly ItemTarget $items,
        private readonly ExistingTermLookup $existing,
        private readonly FlatTermDefinitionSource $flatTerms,
        private readonly PageFields $pages,
    ) {}

    /**
     * @throws WxrError 読めないファイルそのもの（形が WXR でない、など）
     */
    public function build(string $path, ImportOptions $options): WxrPlan
    {
        $reader = new WxrReader($path);
        $findings = new PlanFindings;

        // **解析の前に指紋を取る**（後でもう一度取って、変わっていないか見る）
        $before = $reader->fingerprint();

        $scan = $this->scan($reader, $options, $findings);
        $state = $this->resolveTargets($scan, $options, $findings);

        /*
         * **読み終えた「後」にもう一度確かめる。**
         *
         * ファイルを読む間だけでなく、DB を引いて対象の状態を決める間も、
         * 元のファイルは差し替えられ得る。scan の直後に見ると、
         * **その後の読み取り中に起きた差し替えを見逃す**。
         *
         * ここから先は外の状態を読まず、集めたものだけで hash を組む。
         *
         * どちらの指紋も出さない。出すと「正しい方を貼れば通る」ゲートになる。
         */
        if ($reader->fingerprint() !== $before) {
            $findings->add(PlanIssue::SourceChangedDuringPlan, 'source', 'file');
        }

        if ($findings->blocksPlan() || $state === null) {
            // **警告つきの hash を作らない**（貼れば通る plan を存在させない）
            return new WxrPlan($findings, $scan->actions, null);
        }

        return new WxrPlan(
            $findings,
            $scan->actions,
            PlanFingerprint::of(
                new PlanIdentity($before, $path, $scan->effective($options)),
                $state,
                $scan->actions,
            ),
        );
    }

    /**
     * item を 1 件読み終えるたびに呼ばれる。
     *
     * **本番では何もしない。** 読んでいる最中にファイルが差し替わる状況を
     * 試すためだけの差し込み点で、`Closure` を公開の引数にすると
     * **本番の口にテスト用の穴が開く**ので、こちらに寄せてある。
     */
    protected function afterItem(): void {}

    /**
     * WXR を読んで、必要なものを集める。
     *
     * 走査は 2 つある — `<channel>` 直下の定義と、item である。
     * 定義を読み終えたところで封をするので、**item を読む間に
     * 割り当てを溜める必要が無い**。
     *
     * **中身は溜めない。** 十年分を配列に持つとメモリが持たないので、
     * 「何を・どこへ・どうするか」だけを畳んでいく。
     */
    private function scan(
        WxrReader $reader,
        ImportOptions $options,
        PlanFindings $findings,
    ): PlanScan {
        $scan = new PlanScan;

        // 1 回の plan ごとに数え直す（builder は使い回される）
        $this->unmappable = [];

        /*
         * 🔴 **読み替えた後の受け皿（target）で持つ。** 定義そのものの `->taxonomy`
         * （読み替え前）で持つと、`--taxonomy=category:news_category` を付けたとき、
         * 定義が `wp:term_taxonomy` に直接 `news_category` と書かれている一方、
         * 割り当ては `domain="category"` なので、**同じ受け皿を指しているのに
         * 別物として見えて、定義があるのに平らな定義まで作って衝突する**
         * （実測 2026-09-04・`RollbackWxrTest` で踏んだ）。
         *
         * @var array<string, true> 読み替えた後の受け皿のうち、`<channel>` 直下に定義があるもの
         */
        $definedTaxonomies = [];

        foreach ($reader->termDefinitions() as $definition) {
            // 割り当てと同じ規則で読み替える（片方だけ source 名のままにしない）
            $target = $options->taxonomies[$definition->taxonomy] ?? $definition->taxonomy;

            $definedTaxonomies[$target] = true;

            $scan->addTermDefinition($definition, $target);
        }

        /*
         * **定義が 1 件も無い分類だけ、記事側から平らな定義を補う**
         * （`FlatTermDefinitionSource`・WP 一周 2026-09-04）。
         *
         * 定義が一部でもある分類は対象にしない — そちらは従来どおり
         * `TermGraph::assign()` に `term_definition_missing` を出させる
         * （書き出しが壊れている可能性の方が高い）。
         */
        if ($options->flattenMissingTermDefinitions) {
            foreach ($this->flatTerms->forUndefinedTaxonomies($reader, $options, $definedTaxonomies) as $definition) {
                $target = $options->taxonomies[$definition->taxonomy] ?? $definition->taxonomy;

                $scan->addTermDefinition($definition, $target);
                $scan->useFlatTermDefinition();

                /*
                 * **止めない。だが黙って作らない。** 何件・どの分類を
                 * 平らにしたかを、id ではなく分類の名前（`bySource`）で言う。
                 * id は `flat-` から始まる合成値で、利用者には意味が無い。
                 */
                $findings->add(
                    PlanIssue::FlatTaxonomyTermCreated,
                    $definition->termId,
                    'term',
                    $definition->taxonomy,
                );
            }
        }

        /*
         * **定義の走査を終えてから封をする。**
         *
         * 定義と item は別々に走査するので、XML の中でどちらが先に
         * 書かれていても、ここに来る時点で定義は出そろっている。
         * 封をした後は、item の割り当てを**1 件も抱えずに**照合できる。
         */
        $scan->terms->seal($findings);

        /*
         * **封の直後に分類項目の行動を積む。**
         *
         * 保存する綴りは封をしないと決まらないので、ここより前には出せない。
         * item より先に出すのは、**親から先に作る**（§C.3）を行動の並びでも
         * 表しておくためである。並びは決定的で、同じ WXR なら同じ順になる。
         */
        $this->holdTerms($scan, $findings);

        foreach ($reader->items() as $item) {
            $this->scanItem($item, $options, $findings, $scan);

            $this->afterItem();
        }

        /*
         * **端数を必ず流す。**
         *
         * 窓は 1000 件で切るが、割り切れることの方が稀である。
         * ここを忘れると、**最後の最大 999 件が黙って行動から抜ける**。
         * 抜けた分は数にも hash にも出ないので、誰も気づかない。
         */
        $this->flush($scan, $findings);

        return $scan;
    }

    private function scanItem(
        WxrItem $item,
        ImportOptions $options,
        PlanFindings $findings,
        PlanScan $scan,
    ): void {
        $sourceType = $item->postType;

        $scan->useSourceType($sourceType);

        /*
         * **どこへ入るかは `ItemTarget` が決める。**
         *
         * `apply` も同じものを呼ぶ。ここで自前に判定を持つと、
         * **plan が言ったのと違う場所へ入る**ことが起こり得る。
         * しかも plan hash はそれを見つけられない — hash は plan 側の判断から
         * 作るので、両方が同じ誤りをすれば一致してしまう。
         */
        $target = $this->items->of($item, $options);

        if ($target === null) {
            /*
             * **入らない理由を数えるのは plan の仕事**（`ItemTarget` は理由を返さない）。
             * ここで同じ順に確かめ直す。順が `ItemTarget` とずれると、
             * 「入らないのに理由が出ない」「入るのに理由が出る」が起きるので、
             * `ItemTargetParityTest` が両者の一致を固定している。
             */
            $findings->add($this->reasonFor($item, $options), $item->postId, $sourceType);

            return;
        }

        if ($target['kind'] === ImportKind::Media) {
            /*
             * 添付は**投稿タイプへは入らない**。Media として扱う（§C.1・§C.6）。
             * **実際に取りに行くのは 27E。** plan は外へ 1 度も出ない
             * （URL も、ここでは hash にも診断にも出さない）。
             */
            $this->hold($scan, ImportKind::Media, $item->postId, $target['target'], $findings);

            return;
        }

        if ($target['kind'] === ImportKind::Page) {
            /*
             * ページは**記事の型へは入らない**。`pages` / `page_versions` へ入る
             * （release-20260909 / wxr-pages）。分類・コメントは Page に無いので見ない —
             * ただし付いていたなら、黙って落とさず数える。
             */
            $this->reportPage($item, $sourceType, $findings);

            $this->hold($scan, ImportKind::Page, $item->postId, $target['target'], $findings);

            return;
        }

        $target = $target['target'];

        $scan->useTarget($sourceType, $target);

        $this->reportContent($item, $target, $sourceType, $findings);

        /** @var array<string, true> この記事が割り当てた受け皿（読み替えた後） */
        $assigned = [];

        foreach ($item->terms as $assignment) {
            $assigned[$this->scanAssignment($assignment, $item->postId, $target, $options, $scan, $findings)] = true;
        }

        $this->reportRequiredTaxonomies($item->postId, $target, $sourceType, $assigned, $findings);

        if ($item->comments !== []) {
            $scan->commentsFor($target);

            $this->reportComments($item, $sourceType, $findings);
        }

        /*
         * **コメントが 1 件も無くても見る。** 受付の設定は記事の属性であって、
         * コメントの有無とは関係が無い（閉じている記事にコメントが無いのは普通である）。
         */
        if ($item->commentStatus !== null && ! CommentSetting::isKnown($item->commentStatus)) {
            $findings->add(PlanIssue::CommentSettingUnknown, $item->postId, $sourceType);
        }

        $this->hold($scan, ImportKind::Content, $item->postId, $target, $findings);

        /*
         * **記事の状態でコメントを決めない。**
         *
         * 半端に終わった取り込みを続けるとき、「記事は入っているが
         * コメントは入っていない」が普通に起こる。記事が済んでいるからと
         * まとめて飛ばすと、その分が黙って落ちる。
         */
        foreach ($item->comments as $comment) {
            $this->hold($scan, ImportKind::Comment, $comment->commentId, self::COMMENT_TARGET, $findings);
        }
    }

    /**
     * コメントについて、入れる前に言うこと（§C.4）。
     *
     * ## 他人が書いた文章である
     *
     * 記事は移行する人のものだが、コメントは**その人のものではない**。
     * 黙って落とす・黙って切る・黙って状態を変える、のどれもしない。
     *
     * ## 数えるのはコメントの側
     *
     * 明細の `external_id` は `wp:comment_id` である。記事の id で数えると、
     * 1 本の記事に 200 件のコメントが付いていたときに**どれが直す先か分からない**。
     */
    private function reportComments(WxrItem $item, string $sourceType, PlanFindings $findings): void
    {
        foreach ($item->comments as $comment) {
            if (! WxrCommentStatus::canRead($comment->approved)) {
                $findings->add(PlanIssue::CommentStatusUnreadable, $comment->commentId, $sourceType);
            }

            $field = CommentFields::tooLong($comment);

            if ($field !== null) {
                // **どの欄かは内訳で出す**（理由を欄ごとに分けない）
                $findings->add(PlanIssue::CommentFieldTooLong, $comment->commentId, $sourceType, $field);
            }
        }

        /*
         * 親子は**まとめて決める**。1 件ずつでは「辿ると戻る」を見つけられない。
         * 決め方は `apply` と同じ 1 つ（`CommentTree`）で、
         * ここで数えた件数がそのまま入る形になる。
         */
        $tree = CommentTree::of($item->comments);

        $this->reportEach($findings, PlanIssue::CommentParentCycle, $tree->cycles(), $sourceType);
        $this->reportEach($findings, PlanIssue::CommentParentMissing, $tree->missing(), $sourceType);
        $this->reportEach($findings, PlanIssue::CommentReplyFlattened, $tree->flattened(), $sourceType);
    }

    /**
     * @param  list<string>  $ids
     */
    private function reportEach(PlanFindings $findings, PlanIssue $reason, array $ids, string $sourceType): void
    {
        foreach ($ids as $id) {
            $findings->add($reason, $id, $sourceType);
        }
    }

    /**
     * なぜ入らないのか。**`ItemTarget::of()` が `null` を返したときだけ**呼ぶ。
     *
     * `ItemTarget` は「どこへ入るか」しか答えない。理由を返す口を持たせると、
     * `apply` にも理由が渡ることになり、**plan が既に出した理由を apply が二度数える**。
     *
     * 確かめる順は `ItemTarget::of()` と同じでなければならない。
     * ずれると、入らないのに理由が出ない（または逆）が起きる。
     * `ItemTargetParityTest` が、境界の形で両者の一致を固定している。
     */
    private function reasonFor(WxrItem $item, ImportOptions $options): PlanIssue
    {
        $sourceType = $item->postType;

        if ($sourceType === ItemTarget::ATTACHMENT_TYPE) {
            // ここへ来るのは `--media` が付いていない場合だけ（付いていれば入る）
            return PlanIssue::SkippedAttachment;
        }

        if (array_key_exists($sourceType, ItemTarget::EXCLUDED_TYPES)) {
            return ItemTarget::EXCLUDED_TYPES[$sourceType];
        }

        if ($sourceType === ItemTarget::PAGE_TYPE) {
            // ページが入らないのは id が台帳の桁を超えるときだけ（型は探さない）
            return PlanIssue::ExternalIdTooLong;
        }

        $target = $options->types[$sourceType] ?? $sourceType;

        if (! $this->types->knows($target)) {
            return PlanIssue::UnknownType;
        }

        if (strlen($item->postId) > ItemTarget::LEDGER_COLUMN_BYTES) {
            return PlanIssue::ExternalIdTooLong;
        }

        return PlanIssue::TargetTypeTooLong;
    }

    /**
     * ページについて、入れる前に言うこと（release-20260909 / wxr-pages）。
     *
     * 判定は全部 `PageFields` から読む — apply が書くものと同じ 1 か所。
     * 止めるもの（本文が大きい・題名が長い・表せない・予約日時が読めない）と、
     * 止めないが元と変わるもの（URL・階層・空を下書き・shortcode・SEO）を分けて数える。
     */
    private function reportPage(WxrItem $item, string $sourceType, PlanFindings $findings): void
    {
        if (! PageFields::titleFits($item)) {
            $findings->add(PlanIssue::PageTitleTooLong, $item->postId, $sourceType);
        }

        if (! PageFields::bodyFits($item)) {
            $findings->add(PlanIssue::PageBodyTooLarge, $item->postId, $sourceType);
        } else {
            $errors = $this->pages->bodyErrors($item);

            if ($errors !== []) {
                // 検証が言った 1 つ目の場所だけ載せる（本文そのものは載せない）
                $findings->add(PlanIssue::PageBodyUnrepresentable, $item->postId, $sourceType, (string) $errors[0]);
            }
        }

        if (PageFields::scheduleUnreadable($item)) {
            $findings->add(PlanIssue::PageScheduleUnreadable, $item->postId, $sourceType);
        }

        if (! WxrStatus::canPreserve($item->status)) {
            $findings->add(PlanIssue::SourceStatusUnreadable, $item->postId, $sourceType);
        }

        if ($this->pages->slugReserved($item)) {
            // URL が既存の画面に取られている。DB に入っても読めないので、別の URL で入れて言う
            // 内訳の鍵は 64 byte まで。長い綴りは id だけで数える（本文ではないが、形の約束は守る）
            $findings->add(PlanIssue::PageSlugReserved, $item->postId, $sourceType, strlen($item->slug) <= 64 ? $item->slug : null);
        } elseif (! $this->pages->slugUsable($item)) {
            $findings->add(PlanIssue::PageSlugGenerated, $item->postId, $sourceType);
        }

        if (PageFields::hasParent($item)) {
            $findings->add(PlanIssue::PageParentFlattened, $item->postId, $sourceType);
        }

        if (PageFields::wantsPublish($item) && PageFields::bodyIsEmpty($item)) {
            $findings->add(PlanIssue::PageEmptyKeptDraft, $item->postId, $sourceType);
        }

        if (PageFields::hasShortcode($item)) {
            $findings->add(PlanIssue::PageShortcodeKept, $item->postId, $sourceType);
        }

        if (! PageFields::seoDescriptionFits($item)) {
            $findings->add(PlanIssue::PageSeoDescriptionDropped, $item->postId, $sourceType);
        }

        // Page はコメントも分類も持たない。**黙って落とさず 1 件ずつ数える**
        foreach ($item->comments as $comment) {
            $findings->add(PlanIssue::PageCommentsDropped, $comment->commentId, $sourceType);
        }

        foreach ($item->terms as $assignment) {
            $domain = $assignment['domain'];

            $findings->add(
                PlanIssue::PageTermsDropped,
                $item->postId,
                $sourceType,
                preg_match('/^[A-Za-z0-9_.\-:]{1,64}$/', $domain) === 1 ? $domain : null,
            );
        }
    }

    /**
     * 取り込みはするが、**移行した後ではなく前に**言うもの。
     *
     * 「移行したら壊れていた」を避けるのが目的なので、
     * ここで数えるものは 1 件も止めない（§C.5・§C.7・§F）。
     */
    private function reportContent(
        WxrItem $item,
        string $target,
        string $sourceType,
        PlanFindings $findings,
    ): void {
        /*
         * shortcode は**文字として残す**（再実装しない — §C.5）。
         * だが移行先では動かないので、**移行する前に**言う。
         * 本文はここへ持ち込まない。あるかどうかだけを見る。
         */
        if (preg_match('/\[[a-z][a-z0-9_-]*[\s\]\/]/i', $item->content) === 1) {
            $findings->add(PlanIssue::ShortcodePresent, $item->postId, $sourceType);
        }

        /*
         * 本文が大きい記事は取り込むが報告する。
         * 「移行のあとで表示が重い」に、移行の前に気づけるようにする。
         */
        if (strlen($item->content) > WxrReader::LARGE_BODY_BYTES) {
            $findings->add(PlanIssue::LargeBody, $item->postId, $sourceType);
        }

        /*
         * `wp_meta` の受け皿が型に無ければ、その meta は入らない（§C.7）。
         * **黙って落とさない。** 何件落ちるかを先に言う。
         */
        if ($item->meta !== [] && ! $this->types->schema($target)->hasField(self::META_FIELD)) {
            $findings->add(PlanIssue::MetaDropped, $item->postId, $sourceType);
        }

        $this->reportStatus($item->status, $item->postId, $sourceType, $findings);
        $this->reportRequiredFields($item, $target, $sourceType, $findings);
    }

    /**
     * 必須の欄が埋まるかを、**入れる前に**確かめる（§F）。
     *
     * `CreateContent` は埋まらない記事を断るので、ここで見ないと
     * **十年分を流し始めてから 1 件目で落ちる**。§F は「止めるは plan で全部出す」である。
     *
     * ## 記事の話と型の話を分ける
     *
     * 対応する値があって空 → **その記事**の問題（直すのは WXR か、その 1 件）
     * 対応そのものが無い → **その型**の問題（どの記事も入らない。直すのは型の設計）
     * 同じ理由で数えると、十年分の件数が出るのに直す先が分からない。
     */
    private function reportRequiredFields(
        WxrItem $item,
        string $target,
        string $sourceType,
        PlanFindings $findings,
    ): void {
        $missing = false;

        foreach ($this->types->schema($target)->fields as $field) {
            if (! $field->required) {
                continue;
            }

            if (! in_array($field->key, ItemFields::known(), true)) {
                /*
                 * WXR に対応の無い必須の欄。**型ごとに 1 度だけ**数える
                 * （`PlanScan` が既に見た型を覚えている）。
                 */
                if ($this->firstTime($target, $field->key)) {
                    $findings->add(PlanIssue::UnmappableRequiredField, $target, 'type');
                }

                continue;
            }

            if (! ItemFields::fills($item, $field->key)) {
                $missing = true;
            }
        }

        if ($missing) {
            $findings->add(PlanIssue::RequiredFieldMissing, $item->postId, $sourceType);
        }
    }

    /**
     * その型のその欄を、まだ数えていないか。
     *
     * **型ごとに 1 件**なので、同じ型の記事が十年分あっても 1 度しか数えない。
     */
    private function firstTime(string $target, string $field): bool
    {
        $key = $target."\0".$field;

        if (isset($this->unmappable[$key])) {
            return false;
        }

        $this->unmappable[$key] = true;

        return true;
    }

    /**
     * 公開状態を写したときに何が起きるかを、**入れる前に**言う（§C.8）。
     *
     * ここでは列へ書かない。書くのは 27D で、plan は「何が起きるか」を出すだけである。
     */
    private function reportStatus(
        string $status,
        string $postId,
        string $sourceType,
        PlanFindings $findings,
    ): void {
        if (! WxrStatus::canPreserve($status)) {
            /*
             * **切り詰めない・置き換えない・黙って落とさない**（§C.8.4）。
             * 投げずに数えるのは、1 件目で止めると
             * 「何件・どの id が読めないか」を 1 回で出せないからである（§A.3）。
             */
            $findings->add(PlanIssue::SourceStatusUnreadable, $postId, $sourceType);

            return;
        }

        if (WxrStatus::map($status) === ContentStatus::Published) {
            return;
        }

        if ($status === ContentStatus::Draft->value) {
            /*
             * **落ちていないものを数えない**（§C.8.5）。
             * 元から下書きだったものを件数へ入れると、利用者が見るのは
             * 「この移行で失われた情報の量」ではなくただの下書きの総数になる。
             */
            return;
        }

        $findings->add(PlanIssue::StatusDowngradedToDraft, $postId, $sourceType, $status);
    }

    /**
     * item の `<category domain nicename>`。**これは割り当てで、親は書かれていない。**
     *
     * @param  array{domain: string, nicename: string, name: string}  $assignment
     */
    private function scanAssignment(
        array $assignment,
        string $postId,
        string $target,
        ImportOptions $options,
        PlanScan $scan,
        PlanFindings $findings,
    ): string {
        $domain = $assignment['domain'];
        $key = $options->taxonomies[$domain] ?? $domain;

        $scan->useSourceTaxonomy($domain);
        $scan->useTaxonomy($key, $target);

        /*
         * 定義と**同じ読み替え後の受け皿**で照合する。片方だけ元の名前で見ると、
         * `--taxonomy=category:news_category` を付けた途端に
         * 全部の割り当てが「定義が無い」になる。
         */
        $scan->terms->assign($key, $assignment['nicename'], $postId, $findings);

        // **読み替えた後の受け皿**を返す。必須の分類を見るのはこちら側である
        return $key;
    }

    /**
     * 型が必須にしている分類が、その記事に付くかを**入れる前に**確かめる（§C.3）。
     *
     * `ContentTaxonomyAssignments` は required 宣言の分類が渡って来なければ断るので、
     * ここで見ないと**十年分を流し始めてから 1 件目で落ちる**
     * （`reportRequiredFields()` と同じ形の穴が、欄ではなく分類の側に開く）。
     *
     * ## 記事ごとに数える
     *
     * 欄と違って、分類は「どの記事にも対応が無い」とは限らない。
     * 付いている記事と付いていない記事は同じ WXR の中に普通に混ざるので、
     * **何本の記事が入らないか**がそのまま直す単位になる。
     *
     * @param  array<string, true>  $assigned  読み替えた後の受け皿
     */
    private function reportRequiredTaxonomies(
        string $postId,
        string $target,
        string $sourceType,
        array $assigned,
        PlanFindings $findings,
    ): void {
        foreach ($this->types->schema($target)->taxonomies as $declaration) {
            if (! $declaration->required) {
                continue;
            }

            if (! isset($assigned[$declaration->key])) {
                $findings->add(PlanIssue::RequiredTaxonomyMissing, $postId, $sourceType);

                // **1 記事につき 1 件**。必須の分類が 2 つ欠けても、直す先はその記事である
                return;
            }
        }
    }

    /**
     * 行動を決めるために台帳を引く単位。
     *
     * 1 件ずつ引くと、十年分では数万回の問い合わせになる。
     * かといって全件を溜めれば、溜めた分だけメモリを食う。
     * **窓で区切る**（`LedgerLookup::MAX_WINDOW` と同値）。
     *
     * `protected` なのは、**窓の切れ目が結果を変えないこと**を試すためである。
     * 小さくして同じ WXR を通し、指紋が変わらないことを確かめる。
     * 本番の口に穴を開けたくないので、引数ではなくここに置いてある
     * （`afterItem()` と同じ考え方）。
     */
    protected const WINDOW = 1000;

    /**
     * 分類項目の行動を積む（§C.3）。
     *
     * ## `reuse` の出どころは 2 つある
     *
     * どちらも意味は同じ — **この取り込みがその項目を作ったわけではない**。
     * だから台帳の所有権を持たない（`created_by_import = false`）。
     *
     * 1. こちらの分類に**同じ綴りの項目が既にある**。§C.3 は「既存の分類へ
     *    項目だけ足す」なので、既にあるなら足さずに使う
     * 2. 同じ取り込みの中の**代表を指す alias**（重なった `wp:term_id`）。
     *    台帳は id ごとに 1 行を持つので alias にも行が要るが、
     *    対象を作ったのは代表 1 件だけである
     *
     * ## alias の対象 id は plan では分からない
     *
     * 1 の対象 id は既にあるので載せられるが、2 は**代表がこれから作られる**ので
     * まだ無い。plan には**その時点で確定している事実だけ**を載せる。
     * 先回りして埋めると、それは嘘になる。
     */
    private function holdTerms(PlanScan $scan, PlanFindings $findings): void
    {
        $representatives = $scan->terms->representatives();

        $existing = $this->existingTerms($representatives);

        foreach ($representatives as $at => $representative) {
            $term = $existing[$at];

            if ($term !== null && $term['archived']) {
                /*
                 * **分類の保管とは別の守り。**
                 *
                 * `ContentTaxonomyAssignments` は新しく付ける保管項目を拒むので、
                 * ここで控えておかないと**記事が 1 本ずつ検証エラーになる**。
                 * ただし止めるのは**割り当てが実際に向いたとき**だけで、
                 * それは item を読むときに分かる（`TermGraph::assign()`）。
                 */
                $scan->terms->markArchived($representative['target'], $representative['storageSlug']);
            }

            $this->hold(
                $scan,
                ImportKind::Term,
                $representative['termId'],
                $representative['target'],
                $findings,
                $term === null ? null : $term['id'],
            );
        }

        foreach ($scan->terms->aliases() as $termId => $representative) {
            // term_id は数字だけの文字列なので、鍵として持つと PHP が整数へ直す
            $termId = (string) $termId;

            /*
             * 代表と同じ対象を指すが、**作るのは代表だけ**。
             * 対象の id はまだ無いので渡さない（上の docblock）。
             */
            $this->hold(
                $scan,
                ImportKind::Term,
                $termId,
                $this->targetOf($representatives, $representative),
                $findings,
                self::ALIAS,
            );
        }
    }

    /**
     * 既にある項目を、**代表と同じ並びで**返す。
     *
     * @param  list<array{termId: string, target: string, storageSlug: string}>  $representatives
     * @return list<array{id: string, archived: bool}|null>
     */
    private function existingTerms(array $representatives): array
    {
        if ($representatives === []) {
            return [];
        }

        $slots = [];

        foreach ($representatives as $representative) {
            $slots[] = new TermSlot($representative['target'], $representative['storageSlug']);
        }

        $found = [];

        // 窓で切る（`ExistingTermLookup` の上限と同じ単位で渡す）
        foreach (array_chunk($slots, ExistingTermLookup::MAX_WINDOW) as $chunk) {
            foreach ($this->existing->find($chunk) as $term) {
                $found[] = $term;
            }
        }

        return $found;
    }

    /**
     * その代表がどの受け皿に居るか。
     *
     * @param  list<array{termId: string, target: string, storageSlug: string}>  $representatives
     */
    private function targetOf(array $representatives, string $termId): string
    {
        foreach ($representatives as $representative) {
            if ($representative['termId'] === $termId) {
                return $representative['target'];
            }
        }

        /*
         * 代表が見つからないのは、封の後に代表と alias が食い違っている場合だけで、
         * `TermGraph` がそれを許さない。**推測で別の受け皿へ入れない。**
         */
        throw new \LogicException('分類項目の代表が見つかりません。');
    }

    /**
     * 使い回す対象の id。**alias のときは渡さない。**
     *
     * alias が指す代表は**これから作られる**ので、plan の時点で id は無い。
     * 先回りして埋めると、それは嘘になる（`holdTerms()` の docblock）。
     */
    private static function reusableId(?string $existing): ?string
    {
        return $existing === self::ALIAS ? null : $existing;
    }

    /**
     * 行動を決めるのを、窓がいっぱいになるまで待つ。
     *
     * 溜めるのは種類・取り込み先・外部 id の 3 つだけである。
     * 十年分でも、窓 1 つ分（1000 件）しか同時には持たない。
     */
    private function hold(
        PlanScan $scan,
        ImportKind $kind,
        string $externalId,
        string $targetType,
        PlanFindings $findings,
        ?string $existingTargetId = null,
    ): void {
        $scan->holdForLedger(new LedgerKey($kind, $targetType, $externalId), $existingTargetId);

        if ($scan->windowIsFull(static::WINDOW)) {
            $this->flush($scan, $findings);
        }
    }

    /**
     * 溜めた分の行動を、**溜めた順のまま**積む。
     *
     * 並びを変えると畳んだ値が変わり、**同じ WXR から違う plan の指紋が出る**。
     * 窓の切れ目は読み取りの都合であって、取り込みの意味ではない。
     */
    private function flush(PlanScan $scan, PlanFindings $findings): void
    {
        // 取り出しと同時に空になる（同じ窓を二度積まない）
        [$keys, $found] = $scan->takeWindow();

        if ($keys === []) {
            return;
        }

        $verdicts = $this->ledger->reconcile($keys, $findings);

        foreach ($keys as $at => $key) {
            $verdict = $verdicts[$at];
            $existing = $found[$at];

            $scan->actions->add(
                $key->kind->value,
                $key->externalId,
                $key->targetType,
                $verdict->action($existing !== null),
                $verdict->usableTargetId() ?? self::reusableId($existing),
                new RelevantTargetState(hasImportRecord: $verdict->entry->state !== LedgerState::Absent),
            );
        }
    }

    /**
     * 取り込み先の状態を読む。
     *
     * `inspect()` は**投げない**。型と分類の問題を全部 findings へ入れて、
     * 使える状態だけを返す。1 件目で投げる作りだと、利用者は
     * 「最初に見つかった 1 件」を直しては実行し、また別の理由で止まる。
     */
    private function resolveTargets(PlanScan $scan, ImportOptions $options, PlanFindings $findings): ?TargetState
    {
        // 書き間違いの可能性が高いので言う（が、取り込みは成立する）
        foreach ($scan->unusedTypeMappings($options->types) as $source) {
            $findings->add(PlanIssue::UnusedTypeMapping, $source, 'type');
        }

        foreach (array_keys($options->types) as $source) {
            if (! array_key_exists($source, ItemTarget::EXCLUDED_TYPES)) {
                continue;
            }

            /*
             * §A.2 は利用者の指定より強いので、この指定は効かない。
             * だが**効かなかったことを言わない**と、利用者は入ったつもりで待つ（§A.3）。
             * `skipped_nav_menu_item` は「入れなかった」であって、指定の話ではない。
             */
            $findings->add(PlanIssue::ExcludedTypeMapping, (string) $source, 'type');
        }

        foreach ($scan->unusedTaxonomyMappings($options->taxonomies) as $source) {
            $findings->add(PlanIssue::UnusedTaxonomyMapping, $source, 'taxonomy');
        }

        /*
         * **理由を 1 つにまとめない。**
         *
         * 「定義が無い」と「同期していない」では**次にやることが違う**。
         * 前者はファイルを作るところから、後者は同期を走らせるだけでよい。
         * 混ぜると、既に在るファイルをもう一度作ろうとすることになる。
         */
        return $this->targets->inspect(
            $scan->typeTaxonomies(),
            $scan->taxonomyKeys(),
            $scan->commentsRelevantFor(),
            $findings,
        );
    }
}

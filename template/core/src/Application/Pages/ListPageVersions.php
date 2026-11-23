<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\PageVersion;

/**
 * 版の一覧（Phase 9.6 / P0-C6c・SPEC §4.12）。
 *
 * ## 版の中身は返さない
 *
 * ここが答えるのは**「いつ・誰が・どういう保存か」**である。`document` は木の全体で、
 * 実データ規模では 1 版が数十 KB になる。50 件返せば MB 単位になり、
 * **本文・見出し・binding が API と MCP の応答へ丸ごと流れる**。
 *
 * 中身を見る口は別に在る（復元してから見る）。**一覧で全部返さない**。
 *
 * ## 自動保存かどうかを返す
 *
 * 「勝手に増えた版」と「復元で作った版」を、利用者が見分けられないと
 * **どれに戻せばよいか決められない**（§4.11 の間引きとも噛み合う — 自動保存だけが間引かれる）。
 *
 * ## 続きは `before`（revision）で辿る。**件数で飛ばさない**
 *
 * `ListPages` などは「何件目から」で送る。**ここは作法を揃えない** — この一覧の下では
 * 間引きが行を消すからである（§4.11）。件数で飛ばすと、辿っている最中に前の方が
 * 消えたぶんだけ**版を飛ばすか、二度出す**。
 *
 * `revision` はページごとに単調増加し、行が消えても**番号は戻らない**。
 * 「この番号より古いものを N 件」で辿れば、間引きが走っても飛ばしも重複も起きない。
 *
 * **これを揃えようとしないこと。** 揃っていないのは、下で行が消える一覧だからである。
 *
 * ## 守られる版ほど、押し出されやすい
 *
 * 間引きが永久に残すのは復元・公開が作った版で、増え続けるのは自動保存である。
 * つまり**残る版ほど新しい 50 件から押し出される**（1〜2 日の編集で 50 手は超える）。
 * 辿れないと、**消えないのに二度と見えない版**ができる（F-16）。
 */
final class ListPageVersions implements Operation
{
    /**
     * 1 度に返す上限。
     *
     * **これは「応答の大きさの上限」であって、「到達できる範囲の上限」ではない。**
     * 続きは `before` で辿れる。ここを到達範囲と読むと、**古い版へ二度と行けない一覧**になる。
     *
     * 間引きの N（`agentcms:page-version-prune`）とも別の数である。意味が違うので揃えない。
     */
    public const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
    ) {}

    public function operationId(): string
    {
        return 'page.document.versions';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    /**
     * @param  int|null  $before  この revision より**古い**版を返す（null なら新しい方から）
     */
    public function execute(
        int $pageId,
        OperationContext $context,
        int $perPage = self::MAX_PER_PAGE,
        ?int $before = null,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $page;
        }

        $limit = max(1, min($perPage, self::MAX_PER_PAGE));

        $versions = PageVersion::query()
            ->where('page_id', $page->id)
            // **`document` を選ばない。** 選べば、返さないつもりでも一度は読み込む
            ->select(['id', 'revision', 'created_interface', 'created_by', 'autosave', 'created_at'])
            ->when($before !== null, fn ($query) => $query->where('revision', '<', $before))
            ->orderByDesc('revision')
            ->limit($limit)
            ->get();

        return Result::success(
            [
                'versions' => $versions->map(fn (PageVersion $version): array => [
                    'revision' => (int) $version->revision,
                    'createdAt' => $version->created_at?->toIso8601String(),
                    /*
                     * **id で返す。** 名前を引くと、版の数だけ利用者の表示名が
                     * 応答へ載る（`ListContentRevisions` も id で返している）。
                     * 誰かを画面に出すのは、出すと決めた場所の仕事である。
                     */
                    'createdBy' => $version->created_by === null ? null : (int) $version->created_by,
                    'createdInterface' => (string) $version->created_interface,
                    'autosave' => (bool) $version->autosave,
                    // いま編集している版・公開中の版は、一覧の側で印を付ける
                    'draft' => $version->id === $page->draft_version_id,
                    'published' => $version->id === $page->published_version_id,
                ])->all(),
            ],
            [
                'pageId' => $page->id,
                'perPage' => $limit,
                /*
                 * **続きの取り方をこちらから渡す。** 呼ぶ側に「最後の revision を
                 * 覚えておいて引き算する」を求めない。**返す版が無ければ `null`** で、
                 * それが「ここで終わり」の合図になる。
                 */
                'nextBefore' => $versions->count() === $limit
                    ? (int) $versions->last()?->revision
                    : null,
            ],
        );
    }
}

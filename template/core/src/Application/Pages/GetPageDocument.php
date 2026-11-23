<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Document\AccessibilityCheck;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Pages\PageVersion;

/**
 * PageDocument の取得（Phase 9.1D）。
 *
 * **subtree 指定ができる**。AI にページ全体を毎回渡さないための入口
 * （Phase 9.1C §34 — AI token efficiency をアーキテクチャの評価軸に置く）。
 */
final class GetPageDocument implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly PageRepository $pages,
        private readonly AccessibilityCheck $accessibility,
    ) {}

    public function operationId(): string
    {
        return 'page.document.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    /**
     * @param  string|null  $from  起点ノードの id / key（null なら全体）
     * @param  int|null  $depth  取得する深さ（null なら制限なし）
     * @param  bool  $published  true なら公開中の版、false なら編集中の版
     */
    public function execute(
        int $pageId,
        OperationContext $context,
        ?string $from = null,
        ?int $depth = null,
        bool $published = false,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $page;
        }

        $recovered = null;

        if ($published) {
            $version = $page->published_version_id !== null
                ? PageVersion::query()->find($page->published_version_id)
                : null;
        } else {
            // 読みは通す。**失われていたら、そう言う**（§4.11 / `PageRepository::draftForReading()`）
            [$version, $recovered] = $this->pages->draftForReading($page);
        }

        if (! $version instanceof PageVersion) {
            return Result::notFound('公開中のバージョンがありません');
        }

        $document = $version->document;

        // **検査は保存されている文書の全体に対して行う。**
        // 見出しの順序は文書単位の話（`PHASE9_1C_SPEC.md` §37）で、
        // 背景の判断にも祖先が要る。切り取ったあとに調べると、
        // 「前に 1 段目があるのに無い」「濃い背景の上なのに白地扱い」といった
        // **事実でない指摘**が生まれる
        $findings = $this->accessibility->inspect($document);

        if ($from !== null && $from !== '') {
            $node = DocumentTree::find($document, $from);

            if ($node === null) {
                return Result::notFound("ブロック [{$from}] は見つかりません");
            }

            $document = ['schemaVersion' => $version->schema_version, 'root' => $node];
        }

        if ($depth !== null) {
            $document['root'] = self::prune($document['root'], max(0, $depth));
        }

        return Result::success(
            ['document' => $document],
            [
                'pageId' => $page->id,
                'version' => $version->revision,
                'published' => $published,
                /*
                 * **編集中だったものが失われていたら、そう言う。**
                 *
                 * 黙って作り直すと、別物の上に書き足させることになる。
                 * 壊れていなければ `null` で、画面は何も出さない。
                 */
                'draftRecovered' => $recovered,
                // 編集中の気づき。**警告であって、保存も公開も止めない**（P0-C8）。
                // 返した範囲に無い節点の指摘は渡さない（直しに行けないものを見せない）
                'accessibility' => self::within($findings, $document),
            ],
        );
    }

    /**
     * 返した範囲に含まれる節点の指摘だけを残す。
     *
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private static function within(array $findings, array $document): array
    {
        $ids = DocumentTree::identifiers($document)['ids'];

        return array_values(array_filter(
            $findings,
            static fn (array $finding): bool => isset($ids[(string) $finding['nodeId']]),
        ));
    }

    /**
     * 指定の深さまでで木を切る。切った先は `childCount` として件数だけ残す
     * （AI が「まだ続きがある」と分かるようにするため）。
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function prune(array $node, int $depth): array
    {
        $children = $node['children'] ?? [];

        if (! is_array($children) || $children === []) {
            return $node;
        }

        if ($depth === 0) {
            $node['children'] = [];
            $node['childCount'] = count($children);

            return $node;
        }

        foreach ($children as $i => $child) {
            if (is_array($child)) {
                $children[$i] = self::prune($child, $depth - 1);
            }
        }

        $node['children'] = $children;

        return $node;
    }
}

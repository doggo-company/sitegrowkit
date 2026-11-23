<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Events\PageDuplicated;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 保存済みの本文とSEO設定を、新しいURLの下書きへ複製する。公開・予約・履歴は引き継がない。
 *
 * ページに付随する project 層の情報（取り込みの枠の結び付きなど）は、取引の中で
 * `PageDuplicated` を発火して project 側に写させる（core → project の依存を作らない）。
 */
final class DuplicatePage implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly PageRepository $pages,
        private readonly OperationAudit $outcome,
        private readonly Dispatcher $events,
    ) {}

    public function operationId(): string
    {
        return 'page.duplicate';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $pageId, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $result = DB::transaction(function () use ($pageId, $context): Result {
            // 同じページの同時複製を直列化し、読んだ版が別ページのものではないことも確認する。
            $source = Page::query()->lockForUpdate()->find($pageId);
            if ($source === null) {
                return Result::notFound('複製するページが見つかりません');
            }
            if (! $source->isDocumentManaged() || $source->status === PageStatus::Trashed) {
                return Result::conflict('ゴミ箱に入っていない、ブロックで管理されたページだけ複製できます');
            }

            $versionId = $source->draft_version_id ?? $source->published_version_id;
            $version = PageVersion::query()->where('page_id', $source->id)->lockForUpdate()->find($versionId);
            if ($version === null) {
                return Result::conflict('複製する本文が見つかりません。版の履歴から復元してください');
            }

            $base = Str::limit($source->slug, 160, '').'-copy';
            $slug = $base;
            for ($n = 2; Page::query()->where('slug', $slug)->exists(); $n++) {
                $slug = $n < 50 ? $base.'-'.$n : $base.'-'.Str::lower(Str::random(12));
            }

            $copy = Page::query()->create([
                'title' => Str::limit($source->title, 190, '').'（コピー）',
                'slug' => $slug,
                'status' => PageStatus::Draft->value,
                'managed_by' => Page::MANAGED_BY_DOCUMENT,
                'seo' => $source->seo,
            ]);
            $this->pages->createVersion($copy, $version->document, $context->interface->value, $context->actorId);

            /*
             * **付随情報を写すのは project 層**（取り込みの枠の結び付きなど）。
             * core はここで「複製した」と言うだけ。取引の内側で同期に呼ぶので、
             * listener が失敗すれば複製ごと巻き戻る（`PageDuplicated` の宣言）。
             */
            $this->events->dispatch(new PageDuplicated($source, $copy->refresh()));

            return Result::success(['page' => GetPage::present($copy->refresh())]);
        });

        return $this->outcome->record($this, $context, $result,
            resourceType: 'page', resourceId: (string) ($result->data['page']['id'] ?? $pageId),
            metadata: ['sourcePageId' => $pageId],
        );
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Redirects\RedirectMatchType;
use AgentCms\Core\Redirects\RedirectPath;
use AgentCms\Core\Redirects\RedirectRule;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;

/**
 * ページのメタデータ更新（Phase 9.1D）。
 *
 * SEO は **PageDocument と分離**して Page 側に持つ（Phase 9.1C §22）。
 */
final class UpdatePageMetadata implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly ValidationFactory $validator,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param array<string, mixed> $attributes */
    public function execute(int $pageId, array $attributes, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $this->outcome->record($this, $context, $page, resourceType: 'page', resourceId: (string) $pageId);
        }

        $validator = $this->validator->make($attributes, [
            'title' => ['sometimes', 'string', 'max:200'],
            'slug' => ['sometimes', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page->id)],
            'seo' => ['sometimes', 'array'],
            'seo.description' => ['nullable', 'string', 'max:200'],
            'seo.noindex' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $this->outcome->record($this, $context, Result::validationError($errors, 'ページ設定の入力内容を確認してください'), resourceType: 'page', resourceId: (string) $page->id);
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $before = (string) $page->slug;
        // 公開中（予約ではない）のページの URL を変えるときだけ、前の URL から転送する（UX 一周 20 周目）
        $wasLive = $page->publishState() === PageStatus::Published->value;
        $page->fill($data)->save();

        if ($wasLive && isset($data['slug']) && $data['slug'] !== $before) {
            self::redirectOldUrl($before, (string) $data['slug'], (string) $page->title);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['page' => GetPage::present($page->refresh())]),
            resourceType: 'page',
            resourceId: (string) $page->id,
            metadata: ['fields' => array_keys($data)],
        );
    }

    /**
     * 前の URL（/before）から新しい URL（/after）へ 301 で転送する（UX 一周 20 周目）。
     * 公開中のページの URL を変えると、検索・ブックマーク・貼ったリンクが 404 になっていた。
     *
     * - 前の URL を指していた自動の転送は、新しい URL へ付け替える（転送が 2 段にならないように）
     * - 新しい URL から出ていた自動の転送は消す（戻したとき、自分自身へ転送しないように）
     */
    private static function redirectOldUrl(string $before, string $after, string $title): void
    {
        $source = RedirectPath::normalize('/'.$before);
        $target = '/'.$after;

        if ($source === null) {
            return;
        }

        $newPath = RedirectPath::normalize($target);

        if ($newPath !== null) {
            RedirectRule::query()
                ->where('created_reason', RedirectRule::REASON_SLUG_CHANGE)
                ->where('source_hash', RedirectPath::hash(RedirectMatchType::Exact, $newPath))
                ->delete();
        }

        RedirectRule::query()
            ->where('created_reason', RedirectRule::REASON_SLUG_CHANGE)
            ->where('target_url', '/'.$before)
            ->update(['target_url' => $target]);

        $hash = RedirectPath::hash(RedirectMatchType::Exact, $source);
        $rule = RedirectRule::query()->where('source_hash', $hash)->first() ?? new RedirectRule;
        $rule->forceFill([
            'match_type' => RedirectMatchType::Exact->value,
            'source_path' => $source,
            'source_hash' => $hash,
            'target_url' => $target,
            'status_code' => 301,
            'is_enabled' => true,
            'keep_query' => true,
            'created_reason' => RedirectRule::REASON_SLUG_CHANGE,
            'note' => "ページ「{$title}」の URL を変えたときに自動で作成",
        ]);
        $rule->save();
    }
}

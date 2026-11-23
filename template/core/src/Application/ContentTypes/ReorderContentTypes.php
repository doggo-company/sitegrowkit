<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 管理メニューの並べ替え（Phase 9.7 / STAGE 20C・SPEC §J.2）。
 *
 * **差分ではなく全件の順序を受け取る。** 「3 番目を 1 番目へ」を差分で送ると、
 * 送信の間に他者が並べ替えたときに意味が変わってしまう。
 *
 * 対象は投稿タイプだけで、`media` などの system facility は含まない
 * （メニューの別枠にあり、並べ替えの対象ではない）。
 * **code 管理の型も並べられる** — 利用者から見て「型は型」であり、
 * 並び順は schema の構造を 1 バイトも変えない。
 */
final class ReorderContentTypes implements Operation
{
    /** 1 回で受け付ける最大件数（型の数は多くて数十。無制限にしない） */
    private const MAX_ITEMS = 200;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly ContentTypeSettingsWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'content.type.reorder';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  list<mixed>  $items  先頭から順に 1, 2, 3, …（要素は `{id, lock_version}`）
     */
    public function execute(array $items, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        if (! $this->resolver->readiness()->allowsWrites()) {
            return $this->outcome->record($this, $context, Result::conflict(
                $this->resolver->readiness()->message() ?? '設定を読めませんでした',
            ));
        }

        if ($items === [] || count($items) > self::MAX_ITEMS) {
            return $this->outcome->record($this, $context, Result::validationError([
                'items' => ['並び順は 1〜'.self::MAX_ITEMS.' 件で送ってください'],
            ]));
        }

        $declared = $this->schemas->declared();
        $orders = [];
        $errors = [];
        $position = 0;

        foreach ($items as $item) {
            $id = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '';
            $schema = $declared[$id] ?? null;

            if ($schema === null || ! EditorialCollections::isReorderable($schema)) {
                $errors['items'][] = "並べ替えできない投稿タイプが含まれています（{$id}）";

                continue;
            }

            if (isset($orders[$id])) {
                $errors['items'][] = "投稿タイプ [{$id}] が重複しています";

                continue;
            }

            $orders[$id] = [
                // 1 始まりで振り直す。欠番を詰める処理を別に持たない
                'order' => ++$position,
                'lockVersion' => is_numeric($item['lock_version'] ?? null) ? (int) $item['lock_version'] : -1,
            ];
        }

        if ($errors !== []) {
            return $this->outcome->record($this, $context, Result::validationError($errors));
        }

        if (! $this->writer->reorder($orders)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作で並び順が変更されました。読み込み直してからやり直してください'),
                metadata: ['itemCount' => count($orders)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['order' => array_keys($orders)]),
            metadata: ['itemCount' => count($orders)],
        );
    }
}

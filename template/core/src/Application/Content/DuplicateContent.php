<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Schema\FieldStorage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 保存済みの記事を通常の作成処理へ渡し、新しい下書きと独立した履歴を作る。 */
final class DuplicateContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly GetContent $get,
        private readonly CreateContent $create,
        private readonly ContentTaxonomyReader $taxonomies,
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'content.duplicate';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(string $type, int $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        try {
            $result = DB::transaction(function () use ($type, $id, $context): Result {
                $source = $this->get->find($type, $id, $context);
                if ($source instanceof Result) {
                    return $source;
                }
                // 同じ元記事を使う複製・更新を直列化する。ゴミ箱は通常のscopeで除外する。
                $source = $source->newQuery()->whereKey($id)->lockForUpdate()->first();
                if ($source === null) {
                    return Result::notFound('複製する記事が見つかりません');
                }
                $schema = $this->resolver->schema($type);
                $hasStatus = false;
                foreach ($schema->fields as $field) {
                    $hasStatus = $hasStatus || $field->type === 'status';
                }
                if (! $hasStatus) {
                    return Result::conflict('下書きを持たない投稿タイプは複製できません。新規作成から内容を確認してください');
                }
                $sourceValues = GetContent::present($source);
                $attributes = [];
                // ID・版・作成者等の内部列はコピーせず、schemaが宣言した値だけを通常検証へ渡す。
                foreach ($schema->fields as $field) {
                    $value = $sourceValues[$field->key] ?? null;
                    if ($field->type === 'status') {
                        $value = 'draft';
                    } elseif ($field->key === 'published_at') {
                        $value = null;
                    } elseif ($field->type === 'slug') {
                        $max = $field->max ?? 190;
                        if ($max < 16) {
                            return Result::validationError([$field->key => ['URLの上限が短いため、安全に複製用URLを作れません']]);
                        }
                        $base = Str::limit((string) $value, $max - 14, '');
                        do {
                            $value = $base.'-copy-'.Str::lower(Str::random(8));
                        } while (DB::table($source->getTable())->where($field->storage() === FieldStorage::Json ? 'data->'.$field->key : $field->key, $value)->exists());
                    } elseif ($field->key === $schema->titleKey()) {
                        $suffix = '（コピー）';
                        $value = mb_substr((string) $value, 0, max(0, ($field->max ?? 200) - mb_strlen($suffix))).$suffix;
                    }
                    $attributes[$field->key] = $value;
                }
                $assigned = [];
                foreach ($this->taxonomies->forEntry($schema, $type, $id) as $key => $group) {
                    $assigned[$key] = array_column($group['terms'], 'id');
                }

                // 分類や参照先が無効になっていれば通常の検証で中断し、黙って値を落とさない。
                return $this->create->execute($type, $attributes, $context, TaxonomyPayload::of($assigned));
            });
        } catch (QueryException $e) {
            report($e);
            $result = Result::conflict('ほかの操作と重なったため複製できませんでした。もう一度お試しください');
        }

        return $this->outcome->record($this, $context, $result,
            resourceType: $type, resourceId: (string) ($result->data['item']['id'] ?? $id),
            metadata: ['sourceId' => $id],
        );
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Trash\TrashScope;
use Illuminate\Database\Eloquent\Model;

/** コンテンツ 1 件の取得（Phase 9.1B / STEP 4） */
final class GetContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly ContentTaxonomyReader $taxonomies,
        private readonly OperationPolicy $policy,
    ) {}

    public function operationId(): string
    {
        return 'content.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $type, int $id, OperationContext $context): Result
    {
        $model = $this->find($type, $id, $context);

        if ($model instanceof Result) {
            return $model;
        }

        // カラムと data JSON をまとめて 1 件ぶんの値として返す（AI / API は data の存在を意識しない）
        $item = self::present($model);

        // fields とは**別のキー**で分類を返す（§K.4）。fields の既存表現は変えない
        $item[ContentTaxonomyInput::RESPONSE_KEY] = $this->taxonomies->forEntry(
            $this->resolver->schema($type), $type, (int) $model->getKey(),
        );

        return Result::success(['item' => $item]);
    }

    /**
     * 一覧・監査・確認文言へ出す「そのコンテンツの名前」。
     *
     * schema が決める（`titleKey()`）。投稿タイプごとの分岐を書かない。
     * 名前が取れない型では ID を出す — 何を操作しようとしているか分からないまま
     * 確認を押させない。
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function titleOf(ContentSchema $schema, array $attributes): string
    {
        $key = $schema->titleKey();
        $value = $key === null ? null : ($attributes[$key] ?? null);

        return is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : 'ID: '.($attributes['id'] ?? '?');
    }

    /**
     * 複合フィールドを data JSON から引き上げた表現。
     *
     * @return array<string, mixed>
     */
    public static function present(Model $model): array
    {
        $attributes = $model->toArray();
        unset($attributes['data']);

        if (method_exists($model, 'schemaAttributes')) {
            foreach ($model->schemaAttributes() as $key => $value) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /** 失敗時は Result を返す（他の Content 操作から再利用する） */
    public function find(string $type, int $id, OperationContext $context): Model|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! $this->resolver->knows($type)) {
            return Result::notFound("コンテンツタイプ [{$type}] は存在しません");
        }

        $class = $this->resolver->modelClass($type);
        $item = $class::query()->find($id);

        if (! $item instanceof Model) {
            return Result::notFound("{$this->resolver->schema($type)->label}（ID: {$id}）は見つかりません");
        }

        return $item;
    }

    /**
     * **ゴミ箱の中も含めて**探す（Phase 9.8 / STAGE 21C）。
     *
     * 復元はゴミ箱にあるものが対象なので、既定除外のままでは相手を掴めない。
     * 認可と型の解決は `find()` と同じ道を通す — ここだけ緩めない。
     *
     * `type` と `id` の**組**で引くのも同じ（他の型の ID を当てて覗けない）。
     */
    public function findTrashed(string $type, int $id, OperationContext $context): Model|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! $this->resolver->knows($type)) {
            return Result::notFound("コンテンツタイプ [{$type}] は存在しません");
        }

        $class = $this->resolver->modelClass($type);

        $item = $class::query()
            ->withoutGlobalScope(TrashScope::NAME)
            ->whereKey($id)
            ->first();

        if (! $item instanceof Model) {
            return Result::notFound("{$this->resolver->schema($type)->label}（ID: {$id}）は見つかりません");
        }

        return $item;
    }
}

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
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Revisions\RevisionReason;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * コンテンツ更新（Phase 9.1B / STEP 4、Phase 9.3B / STAGE 3B）。
 *
 * Admin はフォーム全体を送るので $partial = false、
 * AI / API は差分だけ送れるよう $partial = true を使える。
 *
 * ## 確定順序（§G.6）
 *
 * 1. OperationPolicy
 * 2. `ContentTypeResolver::knows()`
 * 3. payload 構造の事前検証（DB へ触らない）
 * 4. transaction 開始
 * 5. **Content Entry 本体を `whereKey($id)->lockForUpdate()` で取り直す**
 * 6. lock 後の fresh state だけを使う（transaction 前の stale model へ保存しない）
 * 7. 現在 assignment を読む
 * 8. 関係する term 行を lock して検証する
 * 9. data 変更と assignment 差分を同一 transaction で保存
 * 10. 最終状態を事後検証
 * 11. commit
 * 12. **結末を 1 行だけ** Audit（成功も拒否も競合も。Correction 2-5）
 */
final class UpdateContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly ContentInput $input,
        private readonly ContentValues $values,
        private readonly ContentTaxonomyInput $taxonomyInput,
        private readonly ContentTaxonomyAssignments $assignments,
        private readonly ContentTaxonomyReader $taxonomyReader,
        private readonly GetContent $get,
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly ReferenceLock $referenceLock,
        private readonly RevisionWriter $revisions,
    ) {}

    public function operationId(): string
    {
        return 'content.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * 公開状態を表す field の**いまの値**（持たない型では null）。
     *
     * 型名で分岐しない（SPEC §A.1）。`status` 型の field が在るかだけを見る。
     */
    private static function publishState(ContentSchema $schema, Model $model): ?string
    {
        foreach ($schema->fields as $field) {
            if ($field->type === 'status') {
                $value = $model->getAttribute($field->key);

                return is_scalar($value) ? (string) $value : null;
            }
        }

        return null;
    }

    /**
     * その版が生まれた理由。
     *
     * 公開状態が動いたなら、それを履歴に残す。「更新した」だけでは
     * 「いつ公開したのか」が読めない。
     */
    private static function reasonFor(ContentSchema $schema, ?string $before, Model $model): RevisionReason
    {
        $after = self::publishState($schema, $model);

        if ($before === $after) {
            return RevisionReason::Update;
        }

        return $after === 'published' ? RevisionReason::Publish : RevisionReason::Unpublish;
    }

    /** @param  array<string, mixed>  $attributes */
    public function execute(
        string $type,
        int $id,
        array $attributes,
        OperationContext $context,
        bool $partial = false,
        ?TaxonomyPayload $taxonomies = null,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        $lock = LockVersionInput::normalize($lockVersion);

        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        // ★ CT-8: taxonomy assignment 入力を伴うときは、**存在確認より先に**
        //   type を検証する（`GetContent::find()` が notFound を返してしまうため）。
        //   OperationPolicy を最初に通す順序は維持する
        if ($taxonomies !== null && ! $this->resolver->knows($type)) {
            return $this->outcome->record($this, $context, ContentTypeRejection::of($type, $taxonomies));
        }

        $found = $this->get->find($type, $id, $context);

        if ($found instanceof Result) {
            return $this->outcome->record($this, $context, $found, resourceType: $type, resourceId: (string) $id);
        }

        /*
         * 無効にした投稿タイプへは書かせない（Phase 9.7 / STAGE 20C・SPEC §J.4）。
         *
         * **見つからないことを先に答える。** 存在しない型へ「無効です」と返すと、
         * どの型が在るかを外から探れてしまう（CT-8 の順序と同じ理由）。
         *
         * **判定は `ContentTypeLifecyclePolicy` だけが持つ。** ここで `status` を
         * 読みに行くと、Admin / API / MCP のどれか 1 つで書き忘れが起きる。
         */
        if (! $this->lifecycle->canWrite($type)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('この投稿タイプは無効になっているため、保存できません'),
                resourceType: $type,
                resourceId: (string) $id,
            );
        }

        $schema = $this->resolver->schema($type);

        if ($partial) {
            $known = array_map(static fn ($field) => $field->key, $schema->fields);
            $unknown = array_diff(array_keys($attributes), $known);

            if ($unknown !== []) {
                return $this->outcome->record($this, $context, Result::validationError(
                    array_fill_keys(array_values($unknown), ["{$schema->label}にこのフィールドはありません"]),
                ), resourceType: $type, resourceId: (string) $id);
            }
        }

        /*
         * 版の形が壊れているなら、**DB へ触る前に**止める。
         * 「送っていない」に丸めると、送ったつもりの相手が素通りで上書きする。
         */
        if ($lock->rejected) {
            return $this->outcome->record($this, $context, Result::validationError(
                $lock->errors(), "{$schema->label}の入力内容を確認してください",
            ), resourceType: $type, resourceId: (string) $id);
        }

        // taxonomies の構造は transaction を開く前に確定させる（DB へ触らない検証）
        $parsed = $this->taxonomyInput->parse($schema, $taxonomies);

        if ($parsed['map'] === null) {
            return $this->outcome->record($this, $context, Result::validationError($parsed['errors'], "{$schema->label}の分類を確認してください"), resourceType: $type, resourceId: (string) $id);
        }

        try {
            /** @var array{model: Model, item: array<string, mixed>, outcome: AssignmentOutcome, fields: list<string>, lockVersion: int} $written */
            $written = $this->referenceLock->attempt(function () use ($type, $id, $schema, $attributes, $partial, $parsed, $lock, $context): array {
                // ★ transaction 前に取得した stale model へは保存しない。lock 後の行だけを使う
                $class = $this->resolver->modelClass($type);
                $model = $class::query()->whereKey($id)->lockForUpdate()->first();

                if (! $model instanceof Model) {
                    throw ContentWriteAbort::with(
                        Result::notFound("{$schema->label}（ID: {$id}）は見つかりません"),
                    );
                }

                /*
                 * 版が食い違っていたら、**何も書かずに**止める（OG11）。
                 * ロックした行の値と比べるので、ここを通れば書き込みまで割り込まれない。
                 */
                if (! EntryLockVersion::matches($model, $lock->version)) {
                    throw ContentWriteAbort::with(Result::conflict(EntryLockVersion::CONFLICT_MESSAGE));
                }

                $prepared = $this->input->prepare(
                    $schema,
                    $model->getTable(),
                    $attributes,
                    ignoreId: (int) $model->getKey(),
                    partial: $partial,
                );

                if ($prepared['data'] === null) {
                    throw ContentWriteAbort::with(Result::validationError(
                        $prepared['errors'], "{$schema->label}の入力内容を確認してください",
                    ));
                }

                /*
                 * 参照先を transaction の中で押さえ直す（SPEC §G.5）。
                 * transaction の外の検証だけを信用しない — 開くまでのあいだに消える。
                 */
                $missing = $this->referenceLock->lock(
                    $schema,
                    $prepared['data'],
                    self: ['type' => $type, 'id' => $id],
                );

                if ($missing !== []) {
                    throw ContentWriteAbort::with(Result::validationError(
                        $missing, "{$schema->label}の入力内容を確認してください",
                    ));
                }

                // **書き換える前の**公開状態。あとで「公開した / 下書きへ戻した」を言うために要る
                $statusBefore = self::publishState($schema, $model);

                $existing = is_array($model->getAttribute('data')) ? $model->getAttribute('data') : [];
                $split = $this->values->split($schema, $prepared['data'], $existing);
                $model->fill($split['columns']);

                if ($split['data'] !== null) {
                    $model->setAttribute('data', $split['data']);
                }

                $nextVersion = EntryLockVersion::advance($model);
                $model->save();

                $outcome = $this->assignments->apply(
                    $schema, $type, (int) $model->getKey(), $parsed['map'], isCreate: false,
                );

                /*
                 * **中身が変わったときだけ版を作る**（Phase 9.8 / OG7 = (c)）。
                 *
                 * 「保存を押しただけで同じ内容」の版を残さない。判定は writer が
                 * 直前の版と snapshot を比べて行う。公開状態が変わったなら
                 * その理由（publish / unpublish）を残す — `status` は field なので
                 * 内容の変化であり、履歴に出るべきものである。
                 */
                $this->revisions->write(
                    $schema,
                    $type,
                    $model->refresh(),
                    self::reasonFor($schema, $statusBefore, $model),
                    $context,
                );

                $item = GetContent::present($model);
                $item[ContentTaxonomyInput::RESPONSE_KEY] = $this->taxonomyReader
                    ->forEntry($schema, $type, (int) $model->getKey());

                return [
                    'model' => $model,
                    'item' => $item,
                    'outcome' => $outcome,
                    'fields' => array_keys($prepared['data']),
                    'lockVersion' => $nextVersion,
                ];
            });
        } catch (ContentWriteAbort $abort) {
            return $this->outcome->record($this, $context, $abort->result, resourceType: $type, resourceId: (string) $id);
        } catch (QueryException $e) {
            // SQL・接続情報を利用者へ出さない（ログにだけ残す）
            report($e);

            return $this->outcome->record($this, $context, Result::conflict('ほかの操作と重なったため保存できませんでした。もう一度お試しください。'), resourceType: $type, resourceId: (string) $id);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['item' => $written['item'], 'lock_version' => $written['lockVersion']],
                ContentAudit::meta($type, $written['outcome']),
            ),
            resourceType: $type,
            resourceId: (string) $written['model']->getKey(),
            metadata: array_merge(
                ContentAudit::metadata(
                    $type, (int) $written['model']->getKey(), $written['fields'], $written['outcome'],
                ),
                EntryLockVersion::auditMetadata($lock->version, $written['lockVersion']),
            ),
        );
    }
}

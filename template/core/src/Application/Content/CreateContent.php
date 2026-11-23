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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * コンテンツ作成（Phase 9.1B / STEP 4、Phase 9.3B / STAGE 3B）。
 *
 * 本体の作成と taxonomy assignment は **必ず同一 transaction**（§G.6）。
 * assignment が失敗したら、作成した Content Entry も巻き戻す。
 */
final class CreateContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly ContentInput $input,
        private readonly ContentValues $values,
        private readonly ContentTaxonomyInput $taxonomyInput,
        private readonly ContentTaxonomyAssignments $assignments,
        private readonly ContentTaxonomyReader $taxonomyReader,
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly ReferenceLock $referenceLock,
        private readonly RevisionWriter $revisions,
    ) {}

    public function operationId(): string
    {
        return 'content.create';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $attributes */
    public function execute(
        string $type,
        array $attributes,
        OperationContext $context,
        ?TaxonomyPayload $taxonomies = null,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        // content_type の authoritative 判定は既存 Resolver だけ（CT-1〜CT-9）
        if (! $this->resolver->knows($type)) {
            // 存在しない型への試行も記録する（Correction 2-5）
            return $this->outcome->record($this, $context, ContentTypeRejection::of($type, $taxonomies));
        }

        /*
         * 無効にした投稿タイプへは書かせない（Phase 9.7 / STAGE 20C・SPEC §J.4）。
         *
         * **判定は `ContentTypeLifecyclePolicy` だけが持つ。** ここで `status` を
         * 読みに行くと、Admin / API / MCP のどれか 1 つで書き忘れが起きる。
         * この 1 か所で止めれば、3 つの面すべてに同じ規則が効く。
         */
        if (! $this->lifecycle->canCreate($type)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('この投稿タイプは無効になっているため、新しい投稿を作成できません'),
                resourceType: $type,
            );
        }

        $schema = $this->resolver->schema($type);
        $model = $this->resolver->newModel($type);

        // taxonomies の構造は transaction を開く前に確定させる（DB へ触らない検証）
        $parsed = $this->taxonomyInput->parse($schema, $taxonomies);

        if ($parsed['map'] === null) {
            return $this->outcome->record($this, $context, Result::validationError($parsed['errors'], "{$schema->label}の分類を確認してください"));
        }

        $prepared = $this->input->prepare($schema, $model->getTable(), $attributes);

        if ($prepared['data'] === null) {
            return $this->outcome->record($this, $context, Result::validationError($prepared['errors'], "{$schema->label}の入力内容を確認してください"));
        }

        $split = $this->values->split($schema, $prepared['data']);
        $model->fill($split['columns']);

        if ($split['data'] !== null) {
            $model->setAttribute('data', $split['data']);
        }

        try {
            /** @var array{model: Model, item: array<string, mixed>, outcome: AssignmentOutcome} $written */
            $written = $this->referenceLock->attempt(function () use ($type, $schema, $model, $parsed, $prepared, $context): array {
                /*
                 * 参照先を transaction の中で押さえ直す（SPEC §G.5）。
                 * **保存より先**に押さえる — 自分の行はまだ無いので、並びは参照先だけになる。
                 */
                $missing = $this->referenceLock->lock($schema, $prepared['data']);

                if ($missing !== []) {
                    throw ContentWriteAbort::with(Result::validationError(
                        $missing, "{$schema->label}の入力内容を確認してください",
                    ));
                }

                $model->save();

                // 作成直後の行を **lock 対象として取り直す**（§G.6 の主 mutex）
                $class = $this->resolver->modelClass($type);
                $locked = $class::query()->whereKey($model->getKey())->lockForUpdate()->first();

                if (! $locked instanceof Model) {
                    throw ContentWriteAbort::with(Result::conflict('作成したコンテンツを確認できませんでした'));
                }

                $outcome = $this->assignments->apply(
                    $schema, $type, (int) $locked->getKey(), $parsed['map'], isCreate: true,
                );

                /*
                 * **版を 1 件作る**（Phase 9.8 / STAGE 21D）。
                 *
                 * entry と分類を書き終えたあとに呼ぶ。先に呼ぶと
                 * 「入っていない値の版」ができる。同じ transaction の中なので、
                 * 版が書けなければ作成ごと巻き戻る。
                 */
                $this->revisions->write(
                    $schema, $type, $locked->refresh(), RevisionReason::Create, $context, force: true,
                );

                $item = GetContent::present($locked);
                $item[ContentTaxonomyInput::RESPONSE_KEY] = $this->taxonomyReader
                    ->forEntry($schema, $type, (int) $locked->getKey());

                return ['model' => $locked, 'item' => $item, 'outcome' => $outcome];
            });
        } catch (ContentWriteAbort $abort) {
            // 中断も結末のひとつ。記録せずに返さない（Correction 2-5）
            return $this->outcome->record($this, $context, $abort->result, resourceType: $type);
        } catch (QueryException $e) {
            // SQL・接続情報を利用者へ出さない（ログにだけ残す）
            report($e);

            return $this->outcome->record($this, $context, Result::conflict('ほかの操作と重なったため保存できませんでした。もう一度お試しください。'));
        }

        // 記録は **返す直前の 1 か所**（要約のみ。入力全体や値は記録しない）
        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['item' => $written['item']],
                ContentAudit::meta($type, $written['outcome']),
            ),
            resourceType: $type,
            resourceId: (string) $written['model']->getKey(),
            metadata: ContentAudit::metadata(
                $type, (int) $written['model']->getKey(), array_keys($prepared['data']), $written['outcome'],
            ),
        );
    }
}

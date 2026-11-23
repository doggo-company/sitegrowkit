<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Revisions\RevisionReason;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashReadinessResolver;
use AgentCms\Core\Trash\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ゴミ箱への出し入れ（Phase 9.8 / STAGE 21C・SPEC §E.5・OG5・OG6・OG11）。
 *
 * `content.trash` と、互換 wrapper の `content.delete` が**同じここを通る**。
 * 状態を変える手順を 2 か所に書くと、片方だけ版を進め忘れる、といったずれが出る。
 * **operation ID の違い（権限・確認・監査・応答）は呼び出し側が持つ。**
 *
 * ## 何をしないか
 *
 * - 物理削除しない。列を書き換えるだけである
 * - 分類の割り当てを消さない（ゴミ箱はデータを失わない）
 * - slug も unique 値も動かさない（OG5 = (a)。復元の衝突を原理的に起こさない）
 * - 版（revision）を作らない。それは STAGE 21D
 */
final class ContentTrashState
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly TrashReadinessResolver $readiness,
        private readonly PublicSurface $publicSurface,
        private readonly RevisionWriter $revisions,
    ) {}

    /**
     * 書き込んでよいかを、状態を変える前に確かめる。
     *
     * **順序が意味を持つ。** 「無効な型です」を存在確認より先に返すと、
     * どの型が在るかを外から探れてしまう（既存の CT-8 と同じ理由）。
     */
    public function guard(string $type): ?Result
    {
        if (! $this->lifecycle->canWrite($type)) {
            return Result::conflict('この投稿タイプは無効になっているため、操作できません');
        }

        // 列があるか分からないまま状態を書き換えない（SPEC §C.4.1）
        if (! $this->readiness->supports($type)) {
            return Result::conflict(
                (string) $this->readiness->readiness($type)->message(),
            );
        }

        return null;
    }

    /**
     * ゴミ箱へ入れる。
     *
     * @return array{lockVersion: int}
     *
     * @throws ContentWriteAbort
     * @throws QueryException
     */
    public function trash(string $type, int $id, ?int $expectedVersion, ?int $actorId, ContentSchema $schema): array
    {
        return DB::transaction(function () use ($type, $id, $expectedVersion, $actorId, $schema): array {
            $model = $this->lockRow($type, $id, $schema);

            if ($model->getAttribute(TrashColumns::DELETED_AT) !== null) {
                throw ContentWriteAbort::with(Result::conflict('この投稿は既にゴミ箱にあります'));
            }

            $this->assertVersion($model, $expectedVersion);

            $model->setAttribute(TrashColumns::DELETED_AT, now());
            $model->setAttribute(TrashColumns::TRASHED_BY, $actorId);
            $next = EntryLockVersion::advance($model);

            $model->save();

            return ['lockVersion' => $next];
        });
    }

    /**
     * ゴミ箱から戻す。
     *
     * 公開状態は **型名で分岐せず** schema と 9.7 の lifecycle policy から導く
     * （SPEC §A.1.1・§E.5・OG6）。
     *
     * @return array{lockVersion: int, status: string|null}
     *
     * @throws ContentWriteAbort
     * @throws QueryException
     */
    public function restore(
        string $type,
        int $id,
        ?int $expectedVersion,
        ContentSchema $schema,
        ?OperationContext $context = null,
    ): array {
        $publishState = $this->publishStateField($schema);

        /*
         * 公開状態を持たない型が、戻すだけで公開面へ再び現れるのを止める
         * （SPEC §A.1.1）。**止めたうえで理由を出す** — 黙って戻すと、
         * 「復元しただけで意図せず公開された」が起きる。
         */
        if ($publishState === null && $this->publicSurface->exists($type)) {
            throw ContentWriteAbort::with(Result::conflict(
                'この型は公開状態を持たないため、復元すると即座に公開されます',
            ));
        }

        return DB::transaction(function () use ($type, $id, $expectedVersion, $schema, $publishState, $context): array {
            $model = $this->lockRow($type, $id, $schema);

            if ($model->getAttribute(TrashColumns::DELETED_AT) === null) {
                throw ContentWriteAbort::with(Result::conflict('この投稿はゴミ箱にありません'));
            }

            $this->assertVersion($model, $expectedVersion);

            $model->setAttribute(TrashColumns::DELETED_AT, null);
            $model->setAttribute(TrashColumns::TRASHED_BY, null);

            $status = null;
            $statusBefore = $publishState === null ? null : $model->getAttribute($publishState);

            if ($publishState !== null) {
                // 復元しただけで公開されない（OG6 = (a)。`RestorePage` と同じ作法）
                $status = 'draft';
                $model->setAttribute($publishState, $status);
            }

            $next = EntryLockVersion::advance($model);
            $model->save();

            /*
             * **内容が変わったときだけ版を作る**（Phase 9.8 / SPEC §F.3.1）。
             *
             * ゴミ箱から戻すこと自体は保管状態の話なので版にしない。だが
             * 公開状態を持つ型を `draft` へ戻すのは **`status` field の変化**であり、
             * それは内容の変化である。既に `draft` だったなら何も変わらないので作らない。
             * writer が snapshot を比べるので、ここで場合分けを書かない。
             */
            if ($context !== null) {
                $this->revisions->write(
                    $schema,
                    $type,
                    $model->refresh(),
                    $statusBefore === 'published' ? RevisionReason::Unpublish : RevisionReason::Update,
                    $context,
                );
            }

            return ['lockVersion' => $next, 'status' => $status];
        });
    }

    /**
     * 公開状態を表す field のキー。持たない型では null。
     *
     * **型名で判定しない**（SPEC §A.1）。`status` 型の field が在るかどうかだけを見る。
     */
    public function publishStateField(ContentSchema $schema): ?string
    {
        foreach ($schema->fields as $field) {
            if ($field->type === 'status') {
                return $field->key;
            }
        }

        return null;
    }

    private function lockRow(string $type, int $id, ContentSchema $schema): Model
    {
        $class = $this->resolver->modelClass($type);

        /*
         * ゴミ箱の中身も掴めるようにする。既定除外のままだと、
         * ゴミ箱にある投稿を「見つかりません」と言って戻せなくなる。
         */
        $model = $class::query()
            ->withoutGlobalScope(TrashScope::NAME)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $model instanceof Model) {
            throw ContentWriteAbort::with(Result::notFound("{$schema->label}（ID: {$id}）は見つかりません"));
        }

        return $model;
    }

    private function assertVersion(Model $model, ?int $expected): void
    {
        if (! EntryLockVersion::matches($model, $expected)) {
            // 誰が更新したかは返さない（OG11）
            throw ContentWriteAbort::with(Result::conflict(EntryLockVersion::CONFLICT_MESSAGE));
        }
    }
}

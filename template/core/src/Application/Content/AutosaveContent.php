<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Revisions\RevisionKind;
use AgentCms\Core\Revisions\RevisionReason;
use AgentCms\Core\Revisions\RevisionWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 編集中の内容を、失わないように履歴へ積む（Phase 9.15 / STAGE 28B・SPEC §C）。
 *
 * ## 記事そのものを書き換えない
 *
 * ページは draft と published が別の版なので本体を書いてよい。
 * **記事は本体が 1 行しかない。** 書き換えれば、その瞬間に
 * **公開中の内容が編集途中の状態で外に出る**。だから履歴に積むだけにする。
 *
 * ここが「ページの自動保存とは別の仕組み」であることの核心である。
 *
 * ## 検証しない
 *
 * 自動保存が要るのは**まさに書きかけのとき**である。必須が空・数値が半端・
 * slug が重複 — 編集途中ならどれも普通に起きる。ここで検証して弾くと
 * **書きかけほど守られない**ことになり、この機能の目的と逆になる。
 *
 * 代わりに 3 つで安全を保つ:
 *
 * 1. `ContentValues::split()` が **richtext を Purifier に通す**
 * 2. 版は**公開面に一切出ない**（不正な値がそのまま外へ出る経路が無い）
 * 3. 戻すときは既存の `content.revision-restore` を通るので、**そこで検証される**
 *
 * ## 監査に残さない
 *
 * 監査は「**人が何をしたか**」を追うためのもので、自動保存は人の操作ではない。
 * 30 秒ごとに 1 行積めば、**監査が自動保存で埋まって人の操作が読めなくなる**。
 * 追跡が消えるわけではない — **誰の編集かは版の `created_by` に残る**し、
 * 復元（`content.revision-restore`）は今までどおり監査に残る。
 *
 * **ただし拒否だけは残す。** 「誰が入れなかったか」は人の操作の記録である。
 */
final class AutosaveContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly GetContent $get,
        private readonly OperationPolicy $policy,
        private readonly ContentValues $values,
        private readonly RevisionWriter $revisions,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'content.autosave';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $attributes  編集画面の**そのままの値**（検証していない）
     */
    public function execute(string $type, int $id, array $attributes, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            // **拒否だけは残す。**「誰が入れなかったか」は人の操作の記録である
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ), resourceType: $type, resourceId: (string) $id);
        }

        $model = $this->get->find($type, $id, $context);

        if ($model instanceof Result) {
            return $model;
        }

        $schema = $this->resolver->schema($type);

        /*
         * **版の採番は entry を掴んでから**行う（`RevisionWriter::latest()` の前提）。
         * 掴まずに採ると、2 つの transaction が同じ番号を採る。
         */
        $revision = DB::transaction(function () use ($schema, $type, $id, $attributes, $context): ?int {
            $class = $this->resolver->modelClass($type);
            $held = $class::query()->whereKey($id)->lockForUpdate()->first();

            if (! $held instanceof Model) {
                return null;
            }

            $existing = is_array($held->getAttribute('data')) ? $held->getAttribute('data') : [];
            $split = $this->values->split($schema, $attributes, $existing);

            /*
             * **`fill()` するが `save()` しない。**
             *
             * 版を作るために値を載せた model が要るだけで、記事本体へ書く意図は無い。
             * ここに `save()` を足すと、**編集途中の内容が公開面へ出る**。
             */
            $held->fill($split['columns']);

            if ($split['data'] !== null) {
                $held->setAttribute('data', $split['data']);
            }

            return $this->revisions->write(
                $schema,
                $type,
                $held,
                // **`reason` に autosave を足さない。** `kind` が「どういう保存か」を持つ。
                // 1 つの enum に直交する 2 つの軸を入れない
                RevisionReason::Update,
                $context,
                kind: RevisionKind::Autosave,
            );
        });

        if ($revision === null) {
            // 内容が前の版と同じ = 積む理由が無い。**失敗ではない**
            return Result::success(['saved' => false]);
        }

        return Result::success(['saved' => true, 'revision' => $revision]);
    }
}

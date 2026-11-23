<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content\Revisions;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\ContentWriteAbort;
use AgentCms\Core\Application\Content\EntryLockVersion;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Revisions\ManifestIntegrityError;
use AgentCms\Core\Revisions\RevisionReason;
use AgentCms\Core\Revisions\RevisionRestorePlan;
use AgentCms\Core\Revisions\RevisionRestorePlanner;
use AgentCms\Core\Revisions\RevisionSnapshot;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 過去の版へ戻す（Phase 9.8 / STAGE 21D・OG3 = (a)・SPEC §D.2.2・§F.4）。
 *
 * ## 「ゴミ箱から戻す」とは別の操作である
 *
 * あちらが変えるのは**置き場所**で、こちらが変えるのは**中身**である。
 * 同じ `restore` という語を 2 つの意味で使わない。
 *
 * ## AI へ公開しない
 *
 * `OperationCatalog` にも MCP にも載せない。内容を丸ごと置き換える操作なので、
 * まずは人の操作に留める。後から出すのは容易い。
 *
 * ## 過去の版は書き換えない
 *
 * 「版 N へ戻す」は、版 N の内容を現在へ書き戻し、**新しい版を 1 件作る**。
 * 過去の行には触らない。履歴が書き換わると、何が起きたのか誰にも分からなくなる。
 * 戻す前の状態は既に最新版として保存されているので、事前に版を作る必要も無い。
 *
 * ## 迷ったら止める
 *
 * 定義が見つからない、壊れている、いまの内容が最新版と食い違う、参照先が消えている。
 * どれも「戻した結果が正しいと言えない」状態である。**黙って部分的に戻さない。**
 */
final class RestoreContentRevision implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly GetContent $get,
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly RevisionWriter $revisions,
        private readonly SchemaManifestRepository $manifests,
        private readonly RevisionRestorePlanner $planner,
    ) {}

    public function operationId(): string
    {
        return 'content.revision.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(
        string $type,
        int $id,
        int $revision,
        OperationContext $context,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        $lock = LockVersionInput::normalize($lockVersion);

        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $model = $this->get->find($type, $id, $context);

        if ($model instanceof Result) {
            return $this->fail($context, $model, $type, $id);
        }

        $schema = $this->resolver->schema($type);

        if ($lock->rejected) {
            return $this->fail($context, Result::validationError(
                $lock->errors(), "{$schema->label}の入力内容を確認してください",
            ), $type, $id);
        }

        /*
         * **版はいまの版と必ず突き合わせる**（OG11）。省略は許さない —
         * 内容を丸ごと置き換える操作で「読んだときと同じか」を確かめないのは危うい。
         */
        if ($lock->version === null) {
            return $this->fail($context, Result::validationError(
                ['lock_version' => ['復元には版の指定が必要です']],
                "{$schema->label}の入力内容を確認してください",
            ), $type, $id);
        }

        if (($guard = $this->guard($type, $schema, $model, $lock->version)) !== null) {
            return $this->fail($context, $guard, $type, $id);
        }

        $target = $this->revisions->find($type, $id, $revision);

        if ($target === null || $target['snapshot'] === []) {
            return $this->fail($context, Result::notFound(
                "指定された版（{$revision}）が見つかりません",
            ), $type, $id);
        }

        $plan = $this->planFor($type, $schema, $target);

        if ($plan instanceof Result) {
            return $this->fail($context, $plan, $type, $id);
        }

        if ($plan->blocked()) {
            // 参照先が消えている版は戻せない（§F.5）。**確認へ進めない**
            return $this->fail($context, Result::conflict(
                '復元できません。参照先が見つかりません（'.implode('、', $plan->missing).'）',
                ['missing' => $plan->missing],
            ), $type, $id);
        }

        $title = GetContent::titleOf($schema, $model->toArray());
        $hadToken = $context->confirmationToken !== null;

        /*
         * **分類は `Write` のまま、確認だけ必ず取る**（SPEC §D.2.2）。
         *
         * 復元は取り消せる（また戻せる）ので `Destructive` ではない。だが
         * いまの内容を丸ごと置き換えるので、何が失われるかを先に見せずに
         * 実行してよい操作でもない。分類を偽らずに確認だけを求める。
         *
         * 指紋には **計画そのもの**を混ぜる。押すまでのあいだに参照先が消えたり
         * 分類が消えたり schema が変わったりすれば、計画が変わって同意は通らない。
         */
        $confirmation = $this->confirmations->requireAlways(
            $this->operationId(),
            $context,
            [
                'type' => $type,
                'id' => $id,
                'revision' => $revision,
                'lock_version' => $lock->version,
                'plan' => $plan->fingerprint(),
            ],
            summary: "{$schema->label}「{$title}」を版 {$revision} の内容へ戻します",
            impact: $plan->impact($revision),
        );

        if ($confirmation !== null) {
            /*
             * **同意を持って来たのに通らなかった**なら、それは押したときと状況が
             * 変わったということである。新しい確認を返して「押し直せば通る」ように
             * 見せない — 変わったこと自体を伝える。
             */
            if ($hadToken) {
                return $this->fail($context, Result::conflict(
                    '確認したあとに状態が変わったため実行できません。もう一度確認してください',
                ), $type, $id);
            }

            // **この時点では 1 バイトも書いていない**
            return $this->outcome->record(
                $this,
                $context,
                Result::confirmationRequired($confirmation),
                resourceType: $type,
                resourceId: (string) $id,
            );
        }

        try {
            $written = $this->apply($type, $id, $schema, $target, $plan, $lock->version, $context);
        } catch (ContentWriteAbort $abort) {
            return $this->fail($context, $abort->result, $type, $id);
        } catch (ManifestIntegrityError $e) {
            report($e);

            return $this->fail($context, Result::conflict($e->safeMessage()), $type, $id);
        } catch (QueryException $e) {
            report($e);

            return $this->fail($context, Result::conflict(
                'ほかの操作と重なったため復元できませんでした。もう一度お試しください。',
            ), $type, $id);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success([
                'id' => $id,
                'restored_from' => $revision,
                'revision' => $written['revision'],
                'lock_version' => $written['lockVersion'],
                'lost' => $plan->lost,
                'dropped_terms' => $plan->droppedTerms,
            ], ['type' => $type, 'title' => $title]),
            resourceType: $type,
            resourceId: (string) $id,
            metadata: [
                'title' => $title,
                'restored_from' => $revision,
                'revision' => $written['revision'],
                'lock_version' => $written['lockVersion'],
            ],
        );
    }

    /**
     * その版の計画を立てる。定義が読めなければ **fail-closed**。
     *
     * @param  array{revision: int, snapshot: array<string, mixed>, fingerprint: string}  $target
     */
    private function planFor(string $type, ContentSchema $schema, array $target): RevisionRestorePlan|Result
    {
        $manifest = $this->manifests->find($type, $target['fingerprint']);

        if ($manifest === null) {
            /*
             * 無い・壊れている・鍵と中身が合わない。どれも「その版の項目定義が
             * 分からない」であり、差分を出せない以上、確認の取りようもない。
             * 原因の詳細は外へ出さない。
             */
            return Result::conflict('この版の項目定義が見つからないため復元できません');
        }

        return $this->planner->plan($schema, $manifest, $target['snapshot']);
    }

    /**
     * 戻せない状態を先に断つ。
     *
     * 順序に意味がある — 存在しない型へ「無効です」と返すと、どの型が在るかを外から探れる。
     */
    private function guard(string $type, ContentSchema $schema, Model $model, int $expected): ?Result
    {
        if (! $this->lifecycle->canWrite($type)) {
            return Result::conflict('この投稿タイプは無効になっているため、復元できません');
        }

        if ($model->getAttribute(TrashColumns::DELETED_AT) !== null) {
            // ゴミ箱の中身を直接書き換えない。先に戻してもらう
            return Result::conflict('この投稿はゴミ箱にあります。先にゴミ箱から戻してください');
        }

        if (! EntryLockVersion::matches($model, $expected)) {
            return Result::conflict(EntryLockVersion::CONFLICT_MESSAGE);
        }

        /*
         * **比べる相手は「最後の手動保存」**（Phase 9.15 / STAGE 28C 回帰修正）。
         *
         * 自動保存（`kind = autosave`）は **わざと記事本体を書かない**ので、
         * 絶対の最新と比べると、自動保存が 1 件でもある記事は
         * **常に食い違っていることになり、復元が丸ごと使えなくなる**。
         *
         * この守りが止めたいのは「**記録に無い変更**」であり、
         * その「記録」とは手で保存した内容である。
         */
        $latest = $this->revisions->latestManual($type, (int) $model->getKey());

        if ($latest === null) {
            // 版が 1 件も無い（backfill 前）。戻す先が無い
            return Result::conflict('この投稿にはまだ履歴がありません');
        }

        $current = RevisionSnapshot::of($schema, $type, $model);

        if (! RevisionSnapshot::sameContent($latest['snapshot'], $current)) {
            /*
             * **いまの内容と最新の版が食い違っている。**
             *
             * 契約外の書き込み（seeder / 直接 SQL / 移行スクリプト）が入ると起きる。
             * この状態で戻すと、記録に無い変更が黙って消える。止めて診断を出す。
             */
            return Result::conflict(
                '現在の内容が履歴の最新版と一致しないため復元できません',
                ['mismatched' => $this->mismatchedFields($latest['snapshot'], $current)],
            );
        }

        return null;
    }

    /**
     * 版の内容を現在へ書き戻し、**新しい版を 1 件だけ**作る。
     *
     * ## ロックを取ってから、もう一度ぜんぶ確かめる
     *
     * 確認を押すまでのあいだに何が起きたか分からない。だから書く直前に
     * **同じ検査をやり直す** — 版が動いていないか、いまの内容が最新版と一致するか、
     * 定義が読めるか、参照先が生きているか、計画が変わっていないか。
     * どれか 1 つでも崩れていたら、1 バイトも書かずに止める。
     *
     * @param  array{revision: int, snapshot: array<string, mixed>, fingerprint: string}  $target
     * @return array{revision: int, lockVersion: int}
     */
    /**
     * 食い違っている項目名だけ。**値は出さない**（秘密や個人情報を診断へ載せない）。
     *
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function mismatchedFields(array $latest, array $current): array
    {
        $fields = [];

        foreach (['columns', 'data', 'taxonomies'] as $section) {
            $a = is_array($latest[$section] ?? null) ? $latest[$section] : [];
            $b = is_array($current[$section] ?? null) ? $current[$section] : [];

            foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
                if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                    $fields[] = (string) $key;
                }
            }
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    /**
     * 版の内容を現在へ書き戻し、**新しい版を 1 件だけ**作る。
     *
     * ## ロックを取ってから、もう一度ぜんぶ確かめる
     *
     * 確認を押すまでのあいだに何が起きたか分からない。だから書く直前に
     * **同じ検査をやり直す** — 版が動いていないか、いまの内容が最新版と一致するか、
     * 定義が読めるか、参照先が生きているか、計画が変わっていないか。
     * どれか 1 つでも崩れていたら、1 バイトも書かずに止める。
     *
     * @param  array{revision: int, snapshot: array<string, mixed>, fingerprint: string}  $target
     * @return array{revision: int, lockVersion: int}
     */
    private function apply(
        string $type,
        int $id,
        ContentSchema $schema,
        array $target,
        RevisionRestorePlan $plan,
        int $expected,
        OperationContext $context,
    ): array {
        return DB::transaction(function () use ($type, $id, $schema, $target, $plan, $expected, $context): array {
            $class = $this->resolver->modelClass($type);
            $model = $class::query()
                ->withoutGlobalScope(TrashScope::NAME)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $model instanceof Model) {
                throw ContentWriteAbort::with(Result::notFound("{$schema->label}（ID: {$id}）は見つかりません"));
            }

            // ① 版が動いていないか
            if (! EntryLockVersion::matches($model, $expected)) {
                throw ContentWriteAbort::with(Result::conflict(EntryLockVersion::CONFLICT_MESSAGE));
            }

            // ② いまの内容が**最後の手動保存**と一致しているか
            //    （契約外の書き込みが割り込んでいないか）。
            //    自動保存を基準にしない理由は前段（`latestManual()`）と同じ
            $latest = $this->revisions->latestManual($type, $id);

            if ($latest === null || ! RevisionSnapshot::sameContent(
                $latest['snapshot'],
                RevisionSnapshot::of($schema, $type, $model),
            )) {
                throw ContentWriteAbort::with(Result::conflict(
                    '現在の内容が履歴の最新版と一致しないため復元できません',
                ));
            }

            // ③ 定義・参照先・分類をもう一度見て、④ 計画が変わっていないことを確かめる
            $fresh = $this->planFor($type, $schema, $target);

            if ($fresh instanceof Result) {
                throw ContentWriteAbort::with($fresh);
            }

            if ($fresh->blocked() || $fresh->fingerprint() !== $plan->fingerprint()) {
                throw ContentWriteAbort::with(Result::conflict(
                    '確認したあとに状態が変わったため実行できません。もう一度確認してください',
                ));
            }

            $restorable = array_flip($fresh->restorable);
            $columns = is_array($target['snapshot']['columns'] ?? null) ? $target['snapshot']['columns'] : [];
            $data = is_array($target['snapshot']['data'] ?? null) ? $target['snapshot']['data'] : null;

            foreach ($columns as $key => $value) {
                // 戻せると判定した項目だけ書く。**黙って値を捨てない / 黙って戻さない**
                if (isset($restorable[$key])) {
                    $model->setAttribute((string) $key, $value);
                }
            }

            if ($data !== null) {
                $merged = is_array($model->getAttribute('data')) ? $model->getAttribute('data') : [];

                foreach ($data as $key => $value) {
                    if (isset($restorable[$key])) {
                        $merged[$key] = $value;
                    }
                }

                $model->setAttribute('data', $merged);
            }

            /*
             * **entry の時刻は戻さない**（SPEC §F.2）。復元は内容を戻す操作であって、
             * 更新日時を過去へ巻き戻す操作ではない。戻すと一覧の並びや
             * sitemap の `lastmod` が壊れる。
             */
            $next = EntryLockVersion::advance($model);
            $model->save();

            $this->restoreTaxonomies($type, $id, $target['snapshot'], $fresh);

            // 復元後の姿を**1 件だけ**記録する。過去の版には触れない
            $revision = $this->revisions->write(
                $schema,
                $type,
                $model->refresh(),
                RevisionReason::RevisionRestore,
                $context,
                force: true,
            );

            return ['revision' => (int) $revision, 'lockVersion' => $next];
        });
    }

    /**
     * 分類の割り当てを戻す。
     *
     * **空は「触らない」ではなく「1 件も無い」である。** 版の時点で分類が付いて
     * いなかったなら、戻したあとも付いていないのが正しい。早期に抜けて現在の
     * 割り当てを残すと、戻したのに分類だけ古いままになり、
     * 「現在の内容 = 最新版」が崩れる。
     *
     * 消えている項目は落とすが、**それは計画で先に見せたものだけ**である。
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function restoreTaxonomies(string $type, int $id, array $snapshot, RevisionRestorePlan $plan): void
    {
        $taxonomies = is_array($snapshot['taxonomies'] ?? null) ? $snapshot['taxonomies'] : [];

        /*
         * term の ID は **ULID（文字列）** である。数値へ寄せない —
         * 寄せた瞬間に別の term を指す値になる。
         */
        $wanted = [];

        foreach ($taxonomies as $terms) {
            foreach (is_array($terms) ? $terms : [] as $term) {
                if (is_scalar($term)) {
                    $wanted[] = (string) $term;
                }
            }
        }

        $wanted = array_values(array_unique($wanted));
        $dropped = array_column($plan->droppedTerms, 'term');

        // いま在るものを、**この transaction の中で**確かめる
        $alive = $wanted === []
            ? []
            : array_map(strval(...), DB::table('taxonomy_terms')->whereIn('id', $wanted)->pluck('id')->all());

        // **primary key で消す**（隙間ロックを作らないため）。
        // `where(content_type, content_id)` で消すと `idx_assignment_entry` の
        // 範囲走査になり、**X,GAP が無関係な content_id へ届く**（実測 1205）。
        // **依存**: id を集めてから消すまでの間に行が足されると消し漏らす。
        // ここは直前で **Content Entry 行を `lockForUpdate()` 済み**の同一
        // transaction なので、その窓で誰も足せない。**この lock を外すと静かに壊れる。**
        $stale = DB::table('taxonomy_term_assignments')
            ->where('content_type', $type)
            ->where('content_id', $id)
            ->pluck('id')
            ->all();

        if ($stale !== []) {
            DB::table('taxonomy_term_assignments')->whereIn('id', $stale)->delete();
        }

        $order = 0;
        $rows = [];

        foreach ($wanted as $termId) {
            if (! in_array($termId, $alive, true) || in_array($termId, $dropped, true)) {
                continue;
            }

            $rows[] = [
                'term_id' => $termId,
                'content_type' => $type,
                'content_id' => $id,
                'sort_order' => $order++,
                'created_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('taxonomy_term_assignments')->insert($rows);
        }
    }

    private function fail(OperationContext $context, Result $result, string $type, int $id): Result
    {
        return $this->outcome->record($this, $context, $result, resourceType: $type, resourceId: (string) $id);
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Confirmation;
use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Revisions\RevisionKind;
use AgentCms\Core\Revisions\RevisionReason;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 本文の一括置換（P1 ⑧）。**CLI と管理画面の唯一の置換エンジン。**
 *
 * ## この操作は間引きより重い
 *
 * `agentcms:revision-prune` が消すのは自動保存の版で、**本体は残る**。
 * こちらは**本文そのものを書き換える**。置換前後はリビジョンにも残す。
 * だから前提を 3 つ置く:
 *
 * 1. **backup が在ること**（戻る先が無い状態で始めない）
 * 2. **既定では 1 文字も変えないこと**（予告を出すだけ）
 * 3. **版を残すこと**（backup を丸ごと戻す以外の道を用意する）
 *
 * ## 予告を経ないと置けない
 *
 * `preview()` が出した token を `execute()` で返して初めて置き換わる。token は
 * **type / field / from / to / 触る行の id / 元の値と版の指紋 / 絞る前の件数 / actor**
 * に縛られているので、予告と実行の間に**対象が増えても・入れ替わっても・本文が
 * 変わっても**合わなくなって止まる。実行直前には row lock を取ってもう一度照合する。
 *
 * ## 画面と CLI で 2 つのエンジンを作らない
 *
 * 2026-09-10 に CLI で 3 つの欠陥（lock_version 更新漏れ・値が変わっても旧 token が通る・
 * richtext sanitize 迂回）を直した。**その守りは全部ここに在る。** CLI と controller は
 * 入出力の adapter であり、判断を持たない。
 *
 * ## 全件を 1 つの取引にまとめる
 *
 * `a → aa` のような置換は再実行すると増え続けるので、途中で止まったものを
 * 「続きから」と扱えない。途中の 1 件が項目の条件に反したら、**先行の更新・版・
 * lock_version をすべて戻す**。
 *
 * ## 画面にも AI にも出さない
 *
 * AI へは渡さない（`OperationCatalog` に載せない）。画面は Owner だけで、
 * 認可は入口の表示ではなく **ここ（`OperationPolicy`）** で決まる。
 *
 * ## 当てるのは LIKE、決めるのも置くのも PHP
 *
 * SQL の `REPLACE()` は driver ごとに挙動が違い、LIKE は照合順序で大小文字・アクセント・
 * 全半角を同一視する。**候補を LIKE で絞り、文字どおりの一致は `str_contains` で決め、
 * 置換そのものは PHP でやる**（`scan()`）。SQL 文字列へ値を連結しない（LIKE の値は bind される）。
 */
final class ReplaceContent implements Operation
{
    /** 監査に残す操作の名前。**AI へは出さない**（`OperationCatalog` に足さない） */
    public const OPERATION = 'content.replace';

    /** backup がこれより古ければ、CLI で明示しない限り止まる */
    public const STALE_HOURS = 24;

    /** 予告で見せる before / after の最大件数 */
    public const SAMPLE_LIMIT = 10;

    /** 予告の抜粋で、当たった語の前後に残す文字数 */
    private const EXCERPT_MARGIN = 60;

    /** 候補を読む 1 回分の行数（全部を memory に載せない） */
    private const CHUNK = 200;

    /** 置換できる項目の型。**ID・slug・状態・日付・参照は含めない** */
    public const TEXT_TYPES = ['text', 'textarea', 'richtext', 'url', 'email'];

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ContentTypeResolver $resolver,
        private readonly BackupCatalog $backups,
        private readonly BackupRestorer $restorer,
        private readonly ConfirmationGate $gate,
        private readonly RevisionWriter $revisions,
        private readonly OperationAudit $audit,
        private readonly ContentInput $input,
        private readonly ContentValues $values,
    ) {}

    public function operationId(): string
    {
        return self::OPERATION;
    }

    /** 複数の本文をまとめて変更するため `Destructive` */
    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    /**
     * 置換の対象になり得る投稿タイプと項目（画面の選択肢）。
     *
     * **ここが唯一の判定である。** 受信箱・設定・メディアは `EditorialCollections` で外れ、
     * 状態・ID・slug・日付・参照は型で外れる。画面側に別の表を持たない。
     *
     * @return list<array{id: string, label: string, fields: list<array{key: string, label: string, type: string}>}>
     */
    public function targets(): array
    {
        $targets = [];

        foreach ($this->resolver->availableTypes() as $type) {
            $schema = $this->resolver->schema($type);

            if (! EditorialCollections::isEditorial($schema)) {
                continue;
            }

            $fields = [];

            foreach ($schema->fields as $field) {
                if ($field->isColumnBacked() && in_array($field->type, self::TEXT_TYPES, true)) {
                    $fields[] = ['key' => $field->key, 'label' => $field->label, 'type' => $field->type];
                }
            }

            if ($fields !== []) {
                $targets[] = ['id' => $schema->id, 'label' => $schema->label, 'fields' => $fields];
            }
        }

        return $targets;
    }

    /**
     * 戻る先が在るか。**作らない、確かめるだけ。**
     *
     * ここで backup を自動生成すると、**遅い・失敗する・容量を食う**が置換に混ざる。
     * 見るのは `BackupCatalog`（= 目録が読める zip）で、`verifyContents` なら
     * さらに `BackupRestorer::verify` で **中身の checksum と DB の形**まで確かめる。
     * 戻せない zip は戻る先ではない。
     *
     * @return array{ok: bool, reason: 'missing'|'stale'|'unreadable'|'broken'|null, backup: string|null, problems: list<string>}
     */
    public function backupStatus(bool $verifyContents, bool $allowStale = false): array
    {
        $all = $this->backups->all();

        if ($all === []) {
            return ['ok' => false, 'reason' => 'missing', 'backup' => null, 'problems' => []];
        }

        $candidates = $all;

        if (! $allowStale) {
            $threshold = now()->subHours(self::STALE_HOURS);
            $readable = false;
            $candidates = [];

            foreach ($all as $manifest) {
                $time = self::parseTime($manifest->createdAt);

                if ($time === null) {
                    continue;
                }

                $readable = true;

                if ($time->greaterThan($threshold)) {
                    $candidates[] = $manifest;
                }
            }

            if (! $readable) {
                // 取得時刻が読めない backup しかない。**古いかどうかを言えない**ので通さない
                return ['ok' => false, 'reason' => 'unreadable', 'backup' => null, 'problems' => []];
            }

            if ($candidates === []) {
                return ['ok' => false, 'reason' => 'stale', 'backup' => null, 'problems' => []];
            }
        }

        if (! $verifyContents) {
            return ['ok' => true, 'reason' => null, 'backup' => $candidates[0]->id, 'problems' => []];
        }

        $problems = [];

        foreach ($candidates as $manifest) {
            $check = $this->restorer->verify($manifest->id);

            if ($check['ok']) {
                return ['ok' => true, 'reason' => null, 'backup' => $manifest->id, 'problems' => []];
            }

            $problems = $check['problems'];
        }

        return ['ok' => false, 'reason' => 'broken', 'backup' => null, 'problems' => $problems];
    }

    /**
     * 予告。**ここでは 1 文字も変えない。**
     *
     * 成功なら `matched` / `total` / `samples`（最大 10 件の before / after）と、
     * 実行に使う `confirmation`（0 件なら null）を返す。
     * 本文が含まれるので、返した先で **Owner 以外に見せない・HTML は必ずエスケープ**すること。
     */
    public function preview(ReplaceContentInput $input, OperationContext $context): Result
    {
        $plan = $this->plan($input, $context);

        if ($plan instanceof Result) {
            return $plan;
        }

        $confirmation = null;

        if ($plan['ids'] !== []) {
            /*
             * 予告では token を**消費しない**（token が付いた context で来ても外す）。
             * 予告の側で消費すると「予告し直したら token が死んでいた」が起きる。
             */
            $confirmation = $this->gate->requireAlways(
                self::OPERATION,
                $context->withConfirmationToken(null),
                $plan['arguments'],
                summary: $this->summary($plan['ids'], $input),
                impact: $this->impact(),
            );
        }

        return Result::success($this->previewData($plan, $input, $confirmation));
    }

    /**
     * 実行。**予告が出した token が無ければ、あるいは合わなければ、置き換えない。**
     *
     * 合わないときは**いまの対象で取り直した予告**を `ConfirmationRequired` として返す
     * （件数が変わったのかもしれない、と言う）。
     */
    public function execute(ReplaceContentInput $input, OperationContext $context): Result
    {
        $result = $this->apply($input, $context);

        // 成功・拒否・対象0件を同じ出口で1回だけ記録し、本文や置換文字列は渡さない。
        return $this->audit->record(
            $this,
            $context,
            $result,
            resourceType: $input->type,
            metadata: [
                'field' => $input->field,
                'reason' => $result->meta['reason'] ?? null,
                'matched' => $result->data['matched'] ?? null,
                'changed' => $result->data['changed'] ?? null,
                'from_length' => mb_strlen($input->from),
                'to_length' => mb_strlen($input->to),
            ],
        );
    }

    /** 実行の判定と更新。監査は execute() の出口でまとめる。 */
    private function apply(ReplaceContentInput $input, OperationContext $context): Result
    {
        $plan = $this->plan($input, $context);

        if ($plan instanceof Result) {
            return $plan;
        }

        if ($plan['ids'] === []) {
            return Result::success(['changed' => 0, 'matched' => 0, 'total' => $plan['total']]);
        }

        $confirmation = $this->gate->requireAlways(
            self::OPERATION,
            $context,
            $plan['arguments'],
            summary: $this->summary($plan['ids'], $input),
            impact: $this->impact(),
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation, $this->previewData($plan, $input, $confirmation));
        }

        $class = $plan['class'];
        $ids = $plan['ids'];
        $snapshot = $plan['snapshot'];
        $schema = $plan['schema'];

        try {
            $changed = DB::transaction(function () use ($class, $ids, $input, $schema, $context, $snapshot): int {
                // 確認の検査後に変わった値も、行ロックを取ってから検出する。
                if ($this->snapshot($class, $ids, $input->field) !== $snapshot) {
                    throw new \RuntimeException('対象が更新されています');
                }

                return $this->replace($class, $ids, $input, $schema, $context);
            });
        } catch (\Throwable $e) {
            /*
             * **例外の中身は残さない。** DB 例外のメッセージには SQL と bind された本文が入り、
             * 検証例外にも置換後の値が混ざり得る。既存の機密方針（本文・置換文字列を保存しない）
             * に合わせ、診断は**何の種類の例外で止まったか**までにする。
             */
            Log::warning('content.replace.aborted', [
                'exception' => $e::class,
                'type' => $input->type,
                'field' => $input->field,
                'matched' => count($ids),
            ]);

            return $this->refuse(
                'replacement_refused',
                '対象が更新されたか、置換後の値が項目の条件を満たさないため、変更をすべて取り消しました。予告からやり直してください。',
            );
        }

        return Result::success(['changed' => $changed, 'matched' => count($ids), 'total' => $plan['total']]);
    }

    /**
     * 予告と実行が**同じ式**で対象を決める。
     *
     * 認可 → 入力 → 型と項目 → backup → 対象行と指紋。どこかで断れば `Result`。
     *
     * @return Result|array{class: class-string<Model>, schema: ContentSchema, ids: list<int>, total: int, snapshot: array<int, array{value: mixed, version: mixed}>, titles: array<int, string|null>, arguments: array<string, mixed>}
     */
    private function plan(ReplaceContentInput $input, OperationContext $context): Result|array
    {
        if (! $this->policy->allows(self::OPERATION, $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason(self::OPERATION, $context));
        }

        if ($input->type === '' || $input->field === '' || $input->from === '') {
            return $this->refuse(
                'missing_arguments',
                '投稿タイプ・項目・置き換える前の文字列はすべて必要です（置き換える前の文字列は空にできません）。',
                errors: array_filter([
                    'type' => $input->type === '' ? ['投稿タイプを選んでください。'] : null,
                    'field' => $input->field === '' ? ['項目を選んでください。'] : null,
                    'from' => $input->from === '' ? ['置き換える前の文字列を入力してください。'] : null,
                ]),
            );
        }

        if (! $this->resolver->knows($input->type)) {
            return $this->refuse('unknown_type', "投稿タイプ「{$input->type}」がありません。", errors: ['type' => ['この投稿タイプはありません。']]);
        }

        $schema = $this->resolver->schema($input->type);

        // 本文の置換から、受信箱・設定・状態列などの別業務を書き換えさせない。
        if (! EditorialCollections::isEditorial($schema)) {
            return $this->refuse('not_editorial', '一括置換は編集用の投稿タイプだけを対象にできます。', ['type' => ['この投稿タイプは対象にできません。']]);
        }

        if (! $schema->hasField($input->field)) {
            return $this->refuse('unknown_field', "「{$input->type}」に「{$input->field}」という項目がありません。", ['field' => ['この項目はありません。']]);
        }

        $field = $schema->field($input->field);

        if (! $field->isColumnBacked()) {
            /*
             * **列に載っていない項目は、まだ扱わない。**
             *
             * JSON へ入っている項目は「その項目だけ当てる」書き方が driver ごとに違う。
             * 半端に当てて**別の項目まで書き換える**より、扱えないと言うほうが良い。
             */
            return $this->refuse('field_not_column_backed', "「{$input->field}」は列に載っていない項目です。この操作はまだ列の項目だけを扱います。", ['field' => ['この項目は対象にできません。']]);
        }

        if (! in_array($field->type, self::TEXT_TYPES, true)) {
            return $this->refuse('not_text_field', '一括置換は文章・URL・メールアドレスの項目だけを対象にできます。', ['field' => ['文章・URL・メールアドレスの項目だけを対象にできます。']]);
        }

        $backup = $this->backupStatus($input->verifyBackupContents, $input->allowStaleBackup);

        if (! $backup['ok']) {
            return $this->refuse(
                'backup_missing',
                $this->backupMessage($backup),
                data: ['backup' => $backup['reason'], 'problems' => $backup['problems']],
            );
        }

        /** @var class-string<Model> $class */
        $class = $this->resolver->modelClass($input->type);
        $limit = $input->limit !== null && $input->limit > 0 ? $input->limit : null;

        $scan = $this->scan($class, $input, $limit, $schema->titleKey());

        /*
         * **黙って先頭だけを実行しない。** 上限を超えたら 1 件も触らず、条件を狭めてもらう。
         * `limit` は明示された絞り込みなので、その場合だけ上限を見ない。
         * 走査は上限 + 1 件目で止めているので、`total` は「少なくとも」の数である。
         */
        if ($scan['exceeded']) {
            return $this->refuse(
                'too_many_rows',
                "対象が {$input->maxRows} 件を超えています。1 回に置き換えられるのは {$input->maxRows} 件までです。置き換える前の文字列を長くするなど、条件を狭めてください。",
                data: ['total' => $scan['total'], 'max' => $input->maxRows],
            );
        }

        /*
         * **どの行を触るかまで指紋に入れる**（F-36 / F-37）。
         *
         * - `ids`    — 触る行そのものの digest。**入れ替えを止める**
         * - `total`  — 絞る前の件数。**「全体で何件だったか」が変われば止まる**
         * - `values` — 元の値と lock_version。**件数と行が同じでも本文が変わっていれば止まる**
         */
        $ids = $scan['ids'];
        $total = $scan['total'];

        return [
            'class' => $class,
            'schema' => $schema,
            'ids' => $ids,
            'total' => $total,
            'snapshot' => $scan['values'],
            'titles' => $scan['titles'],
            'arguments' => [
                'type' => $input->type,
                'field' => $input->field,
                'from' => $input->from,
                'to' => $input->to,
                'limit' => $limit,
                'ids' => hash('sha256', implode(',', $ids)),
                'total' => $total,
                'values' => hash('sha256', json_encode($scan['values'], JSON_THROW_ON_ERROR)),
            ],
        ];
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     */
    private function replace(string $class, array $ids, ReplaceContentInput $input, ContentSchema $schema, OperationContext $context): int
    {
        $changed = 0;

        foreach ($ids as $id) {
            $model = $class::query()->whereKey($id)->lockForUpdate()->first();

            if (! $model instanceof Model) {
                continue;
            }

            $before = (string) $model->getAttribute($input->field);
            $after = str_replace($input->from, $input->to, $before);

            if ($after === $before) {
                continue;
            }

            /*
             * **通常の保存と同じ検査を通す。** 必須・文字数・形式・unique は schema の規則、
             * richtext の安全化は `ContentValues`（Purifier）。置換だけの抜け道を作らない。
             */
            $prepared = $this->input->prepare($schema, $model->getTable(), [$input->field => $after], (int) $model->getKey(), partial: true);

            if ($prepared['data'] === null) {
                throw new \RuntimeException('置換後の値が入力条件を満たしません');
            }

            $values = $this->values->split($schema, $prepared['data']);

            /*
             * **置き換える前の内容を、先に版へ残す**（F-35）。
             *
             * WXR で取り込んだ記事や seeder が入れた記事は **版が 1 件も無い**。
             * 「置換後の版」だけを足すと、旧文字列はどこにも残らない。
             * `force` は付けない — 既に同じ内容の版が在るなら、それが戻る先である。
             */
            $this->revisions->write($schema, $input->type, $model, RevisionReason::Update, $context, kind: RevisionKind::Manual);

            $model->forceFill($values['columns']);

            // 古い編集画面が、置換後の本文を知らずに上書きできないようにする
            if (array_key_exists('lock_version', $model->getAttributes())) {
                $model->setAttribute('lock_version', (int) $model->getAttribute('lock_version') + 1);
            }

            $model->save();

            // **置換後も残す。** manual は間引きの対象外である
            $this->revisions->write($schema, $input->type, $model->refresh(), RevisionReason::Update, $context, kind: RevisionKind::Manual);

            $changed++;
        }

        return $changed;
    }

    /**
     * 対象行を決める。**予告も実行もこの 1 つの式**から取る。
     *
     * ## 当てるのは LIKE、決めるのは PHP
     *
     * DB の LIKE は**候補を絞るためだけ**に使う。MySQL の一般的な照合順序（`*_ci`）は
     * 大小文字・アクセント・全半角を同一視し、SQLite も ASCII の大小文字を同一視する。
     * つまり LIKE の当たりは**文字どおりの一致の上位集合**であって、一致そのものではない。
     * 候補は**必ず** `str_contains` で確かめる — 予告の件数と実際に変わる件数がずれない。
     *
     * ## LIKE の記号は「広げる」方向にだけ倒す
     *
     * `%` / `_` は LIKE の記号、`\` は MySQL の既定の escape 文字である。`\` をそのまま渡すと
     * MySQL では次の文字を食って **`C:\path` のような正当な語が当たらない**（対象を漏らす）。
     * `ESCAPE` 句で escape する手も在るが、識別子を含む raw SQL を組むことになり、
     * MySQL と SQLite で escape 文字の文字列リテラルの扱いも違う。
     *
     * だから pattern は **`\` を `%` に置き換える**だけにし、`%` / `_` はそのまま残す。
     * どれも「文字どおりの一致より広く当たる」方向にしか働かないので、候補は
     * **必ず上位集合**になり、漏れない。余分に当たった行は `str_contains` が落とす。
     * 列名は builder が wrap（schema で確定した値）、値は bind — raw SQL は組まない。
     *
     * ## 全部を memory に載せない
     *
     * 候補は主キー順に chunk で読む（`lazyById`）。読む列は主キー・対象項目・見出し・
     * `lock_version` だけ。画面の上限（`maxRows`）を超えた瞬間に止める — 上限は
     * 「触らない」ためだけでなく、**読み込む量を抑える**ためにも在る。
     *
     * @param  class-string<Model>  $class
     * @return array{ids: list<int>, total: int, exceeded: bool, values: array<int, array{value: mixed, version: mixed}>, titles: array<int, string|null>}
     */
    private function scan(string $class, ReplaceContentInput $input, ?int $limit, ?string $titleKey): array
    {
        $model = new $class;
        $key = $model->getKeyName();
        $hasLockVersion = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), 'lock_version');

        /** @var list<string> $columns */
        $columns = array_values(array_unique(array_filter([
            $key,
            $input->field,
            $titleKey !== null && $titleKey !== $input->field ? $titleKey : null,
            $hasLockVersion ? 'lock_version' : null,
        ])));

        // `\` は wildcard へ（上位集合）。`%` / `_` はそのまま wildcard として広く当てる
        $query = $class::query()
            ->where($input->field, 'like', '%'.str_replace('\\', '%', $input->from).'%')
            ->select($columns);

        $ids = [];
        $values = [];
        $titles = [];
        $total = 0;
        $exceeded = false;
        $capped = $input->maxRows !== null && $limit === null;

        foreach ($query->lazyById(self::CHUNK, $key) as $candidate) {
            $value = $candidate->getAttribute($input->field);

            // **文字どおりに含む行だけが対象。** LIKE の当たりは候補であって決定ではない
            if (! is_scalar($value) || ! str_contains((string) $value, $input->from)) {
                continue;
            }

            $total++;

            if ($capped && $total > $input->maxRows) {
                $exceeded = true;

                break;
            }

            // `--limit` は触る件数だけを絞る。`total` は絞る前の数として最後まで数える（F-37）
            if ($limit !== null && count($ids) >= $limit) {
                continue;
            }

            $id = (int) $candidate->getKey();
            $ids[] = $id;
            $values[$id] = ['value' => $value, 'version' => $hasLockVersion ? $candidate->getAttribute('lock_version') : null];
            $title = $titleKey !== null && $titleKey !== $input->field ? $candidate->getAttribute($titleKey) : null;
            $titles[$id] = is_scalar($title) ? (string) $title : null;
        }

        return ['ids' => $ids, 'total' => $total, 'exceeded' => $exceeded, 'values' => $values, 'titles' => $titles];
    }

    /**
     * 実行直前の照合用。**行ロックを取ってから**元の値と版を読む。
     *
     * 形は `scan()` の `values` と同じにする（そのまま `!==` で比べる）。
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     * @return array<int, array{value: mixed, version: mixed}>
     */
    private function snapshot(string $class, array $ids, string $field): array
    {
        if ($ids === []) {
            return [];
        }

        $model = new $class;
        $hasLockVersion = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), 'lock_version');
        $values = [];

        foreach ($class::query()->whereKey($ids)->orderBy($model->getKeyName())->lockForUpdate()->get() as $row) {
            $values[(int) $row->getKey()] = [
                'value' => $row->getAttribute($field),
                'version' => $hasLockVersion ? $row->getAttribute('lock_version') : null,
            ];
        }

        return $values;
    }

    /**
     * 予告の内訳。**本文の抜粋が入る** — 受け取った側は Owner にだけ、エスケープして見せる。
     *
     * @param  array{ids: list<int>, total: int, snapshot: array<int, array{value: mixed, version: mixed}>, titles: array<int, string|null>}  $plan
     * @return array<string, mixed>
     */
    private function previewData(array $plan, ReplaceContentInput $input, ?Confirmation $confirmation): array
    {
        $samples = [];

        foreach (array_slice($plan['ids'], 0, self::SAMPLE_LIMIT) as $id) {
            $before = (string) ($plan['snapshot'][$id]['value'] ?? '');
            $after = str_replace($input->from, $input->to, $before);
            [$start, $length] = self::window($before, $input->from);

            $samples[] = [
                'id' => $id,
                'title' => $plan['titles'][$id] ?? null,
                'before' => self::excerpt($before, $start, $length),
                'after' => self::excerpt($after, $start, $length + mb_strlen($input->to) - mb_strlen($input->from)),
                'occurrences' => mb_substr_count($before, $input->from),
            ];
        }

        return [
            'matched' => count($plan['ids']),
            'total' => $plan['total'],
            'samples' => $samples,
            'confirmation' => $confirmation?->toArray(),
        ];
    }

    /**
     * 抜粋の窓（開始位置と長さ）。最初に当たった語の前後 `EXCERPT_MARGIN` 文字。
     *
     * @return array{int, int}
     */
    private static function window(string $value, string $needle): array
    {
        $position = mb_strpos($value, $needle);

        if ($position === false) {
            return [0, self::EXCERPT_MARGIN * 2];
        }

        $start = max(0, $position - self::EXCERPT_MARGIN);

        return [$start, ($position - $start) + mb_strlen($needle) + self::EXCERPT_MARGIN];
    }

    private static function excerpt(string $value, int $start, int $length): string
    {
        $length = max($length, 0);
        $total = mb_strlen($value);

        return ($start > 0 ? '…' : '')
            .mb_substr($value, $start, $length)
            .($start + $length < $total ? '…' : '');
    }

    /** @param  list<int>  $ids */
    private function summary(array $ids, ReplaceContentInput $input): string
    {
        return count($ids).' 件の「'.$input->type.'.'.$input->field.'」を置き換えます';
    }

    /** @return list<string> */
    private function impact(): array
    {
        return [
            '元の文字列は残りません（版と backup からのみ戻せます）',
            '置き換えた記事は版が 2 つ増え、開いたままの編集画面は保存時に競合になります',
        ];
    }

    /** @param  array{reason: string|null, problems: list<string>}  $backup */
    private function backupMessage(array $backup): string
    {
        return match ($backup['reason']) {
            'missing' => 'backup がありません。先に backup を取ってください。',
            'stale' => 'いちばん新しい backup が '.self::STALE_HOURS.' 時間より前です。いまの状態から取り直してください。',
            'unreadable' => 'backup の取得時刻が読めません。取り直してください。',
            default => '復元できる backup がありません: '.implode(' / ', $backup['problems']),
        };
    }

    private static function parseTime(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 拒否理由を固定コードで返す。実行時の監査は execute() が一度だけ記録する。
     *
     * 入力の誤りは項目ごとの `validationError`、それ以外は `conflict`。理由は固定コードで、
     * 本文や置換文字列は入れない。
     *
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $data
     */
    private function refuse(
        string $reason,
        string $message,
        array $errors = [],
        array $data = [],
    ): Result {
        return ($errors !== [] ? Result::validationError($errors, $message) : Result::conflict($message, $data))
            ->withMeta(['reason' => $reason]);
    }
}

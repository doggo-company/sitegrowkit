<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Console;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\ConsumerRegistry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySyncEntry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionException;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * 分類定義の read-only 検査（Phase 9.3B / STAGE 1・SY-18）。
 *
 * `agentcms verify` から呼ばれる。**データベースを 1 行も書き換えない。**
 * migration・taxonomy-sync・taxonomy / term / assignment の作成を一切行わない。
 *
 * 判定は次の 5 段階に**明確に分離**する。
 *
 *   1. 定義 JSON の検証（DB 不要）
 *   2. DB への実接続確認
 *   3. taxonomy 必須テーブルの存在確認（read-only）
 *   4. Synchronizer による read-only preflight
 *   5. 終了コードの決定
 *
 * **段階 2 の「接続そのもの」が失敗したときだけ**「DB へ接続できない」分岐に入る。
 * 接続が一度成立したあとの例外（テーブル不足 / QueryException / 権限不足 /
 * schema 不整合 / preflight 失敗 / 予期しない例外）は **すべて FAILURE** とし、
 * 接続不可として握りつぶさない。
 *
 * 分類は「例外が発生した段階」という構造で行う。
 * **例外メッセージの文字列比較で接続エラーを判定しない。**
 */
final class TaxonomyCheckCommand extends Command
{
    /**
     * taxonomy 機能が動作するために存在していなければならないテーブルと列。
     *
     * 列まで見るのは、**SQLite が二重引用符付きの未知識別子を文字列リテラルとして
     * 解釈してしまう**ため、列不足が QueryException にならず
     * 「未同期」と誤判定され得るからである（schema 不整合の取りこぼし防止）。
     *
     * STAGE 2A で **domain policy / Reference Inspector が実際に読む列**まで広げた。
     *
     * **`pages` / `page_versions` は意図的に含めない。**
     * PageDocument 参照は `PageDocumentReferenceProvider` が調べるが、
     * これらのテーブルが無い場合は QueryException → **undetermined**（削除不可）へ
     * 安全に落ちる（fail closed）ため、taxonomy-check の責務を
     * ページ機能全体へ広げる必要がない。`ReferenceInspector` 側で
     * 「参照ゼロ」と誤判定されないことをテストで固定してある。
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_SCHEMA = [
        'taxonomies' => [
            'id', 'key', 'label', 'hierarchical', 'managed_by',
            'created_at', 'updated_at',
        ],
        'taxonomy_terms' => [
            'id', 'taxonomy_id', 'parent_id', 'name', 'slug',
            'sort_order', 'archived_at', 'created_at', 'updated_at',
        ],
        'taxonomy_term_assignments' => [
            'id', 'term_id', 'content_type', 'content_id',
            'sort_order', 'created_at',
        ],
    ];

    protected $signature = 'agentcms:taxonomy-check';

    protected $description = '分類定義とデータベースの同期状態を検査する（読み取りのみ・変更しない）';

    public function handle(
        TaxonomyDefinitionRegistry $registry,
        TaxonomySynchronizer $synchronizer,
        DatabaseManager $db,
        SchemaRegistry $schemas,
        ConsumerRegistry $consumers,
    ): int {
        // ── 1. 定義ファイルの検証（DB 不要）──────────────────────────
        try {
            $definitions = $registry->all();
        } catch (TaxonomyDefinitionException $e) {
            $this->components->error('分類の定義ファイルに問題があります。');

            foreach ($e->problems as $problem) {
                $this->line('  • '.$problem);
            }

            return self::FAILURE;
        }

        // ── 1b. ContentSchema の taxonomies[] 検証（DB 不要・STAGE 3A）──
        // A: 宣言自体が不正 / B: 参照 key の定義が無い
        // **DB へ接続する前に確定できる問題はここで FAIL する**
        try {
            $references = $this->schemaReferences($schemas);
        } catch (Throwable $e) {
            report($e);

            $this->components->error('コンテンツタイプの定義（content/schemas/）に問題があります。');
            $this->line('  • '.$this->safeMessage($e));
            $this->line('');
            $this->line('  taxonomies の宣言は key / multiple / required の 3 項目です。');

            return self::FAILURE;
        }

        $missingDefinitions = [];

        foreach ($references as $key => $schemaIds) {
            if (! $registry->has($key)) {
                $missingDefinitions[$key] = $schemaIds;
            }
        }

        if ($missingDefinitions !== []) {
            $this->components->error(
                'コンテンツタイプが参照している分類の定義がありません（content/taxonomies/）。'
            );

            foreach ($missingDefinitions as $key => $schemaIds) {
                $this->line("  • [{$key}] … 参照元: ".implode(' / ', $schemaIds));
            }

            $this->line('');
            $this->line('  content/taxonomies/<key>.json を作成してください。');

            return self::FAILURE;
        }

        // ── 1c. AI 向け索引の consumer 整合（DB 不要・STAGE 3C CORRECTION）──
        // **正は ContentSchema の taxonomies[]**。索引が古いまま残るのを防ぐ。
        //
        // **条件付きで省略しない**（STAGE 3C CORRECTION #2 / F-3C-3）。
        // 定義も参照も 0 件のときに飛ばしてしまうと、
        // 「taxonomy を全部やめたのに索引へ古い項目が残っている」
        // 「索引の JSON が壊れている」「索引が消えている」を verify が見逃す。
        $drift = $consumers->problems(array_keys($definitions), $references);

        if ($drift !== []) {
            $this->components->error(
                'コンテンツタイプの宣言と、AI 向けの索引（'.ConsumerRegistry::LABEL.'）が一致しません。'
            );

            foreach ($drift as $problem) {
                $this->line('  • '.$problem);
            }

            $this->line('');
            $this->line('  修正対象: '.ConsumerRegistry::LABEL.'（consumers を宣言に合わせてください）');
            $this->line('  （この検査コマンドは何も変更していません）');

            return self::FAILURE;
        }

        // **schema 参照がある限り、定義 0 件でも success で早期 return しない。**
        // ここへ来た時点で索引も検証済みなので、定義 0 件 = 索引も 0 件が保証されている
        // （索引に項目が残っていれば直前の drift 検査で FAIL している）
        if ($definitions === [] && $references === []) {
            $this->components->info('分類の定義はありません（content/taxonomies/ が空です）。');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '分類の定義 %d 件 / コンテンツタイプからの参照 %d 件を確認しました。',
            count($definitions),
            count($references),
        ));

        // ── 2. DB への実接続確認 ────────────────────────────────────
        // **ここでの失敗だけ**が「接続できない」である。
        // getPdo() が実際の接続を発生させる（遅延接続を確定させる）
        try {
            $connection = $db->connection();
            $connection->getPdo();
        } catch (Throwable $e) {
            // 例外本文には DSN・認証情報が含まれ得るため画面へ出さない
            report($e);

            $this->components->warn(
                'データベースへ接続できないため、同期状態は検査していません（定義ファイル自体は正常です）。'
            );
            $this->line('  `agentcms dev` でデータベースを起動してから verify すると、同期状態も検査します。');

            return self::SUCCESS;
        }

        // ── 3. 必須テーブル / 列の存在確認（read-only）───────────────
        // 接続は成立している。ここから先の失敗を接続不可として扱わない
        try {
            $schema = $connection->getSchemaBuilder();
            $missingTables = [];
            $missingColumns = [];

            foreach (self::REQUIRED_SCHEMA as $table => $columns) {
                if (! $schema->hasTable($table)) {
                    $missingTables[] = $table;

                    continue;
                }

                $existing = $schema->getColumnListing($table);

                foreach ($columns as $column) {
                    if (! in_array($column, $existing, true)) {
                        $missingColumns[] = "{$table}.{$column}";
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);

            $this->components->error(
                'データベースには接続できましたが、テーブルの構成を確認できませんでした。'
            );
            $this->line('  データベース利用者に参照権限があるかを確認してください。');
            $this->line('  詳しい原因は storage/logs のログに記録しました。');

            return self::FAILURE;
        }

        if ($missingTables !== []) {
            $this->components->error(
                'データベースには接続できましたが、分類用のテーブルがありません（migration が未実行の可能性があります）。'
            );

            foreach ($missingTables as $table) {
                $this->line("  • 見つからないテーブル: {$table}");
            }

            $this->line('');
            $this->line('  `agentcms dev` を実行するか、`php artisan migrate` で migration を適用してください。');
            $this->line('  （この検査コマンドは migration も同期も実行しません）');

            return self::FAILURE;
        }

        if ($missingColumns !== []) {
            $this->components->error(
                'データベースには接続できましたが、分類用テーブルの構成が想定と異なります（schema 不整合）。'
            );

            foreach ($missingColumns as $column) {
                $this->line("  • 見つからない列: {$column}");
            }

            $this->line('');
            $this->line('  migration が最後まで適用されているかを確認してください（`php artisan migrate`）。');
            $this->line('  （この検査コマンドは migration も同期も実行しません）');

            return self::FAILURE;
        }

        // ── 4. read-only preflight ──────────────────────────────────
        try {
            $entries = $synchronizer->plan($definitions);
        } catch (Throwable $e) {
            report($e);

            $this->components->error(
                'データベースには接続できましたが、分類の同期状態を読み取れませんでした。'
            );
            $this->line('  テーブル定義が想定と異なるか、参照権限が不足している可能性があります。');
            $this->line('  詳しい原因は storage/logs のログに記録しました。');
            $this->line('  （この検査コマンドは何も変更していません）');

            return self::FAILURE;
        }

        // ── 5. 終了コードの決定 ─────────────────────────────────────
        $problems = 0;

        foreach ($entries as $entry) {
            if ($entry->action === TaxonomySyncEntry::CONFLICT) {
                $problems++;
                $this->components->error("[{$entry->key()}] {$entry->reason}");

                continue;
            }

            if ($entry->action === TaxonomySyncEntry::CREATE) {
                $problems++;
                $this->components->error(
                    "[{$entry->key()}] 定義はありますが、データベースへ未同期です。"
                );

                continue;
            }

            if ($entry->action === TaxonomySyncEntry::UPDATE) {
                $problems++;
                $changed = implode(' / ', array_keys($entry->changes));
                $this->components->error(
                    "[{$entry->key()}] 定義の変更（{$changed}）がデータベースへ未反映です。"
                );
            }
        }

        if ($problems > 0) {
            $this->line('');
            $this->line('  `php artisan agentcms:taxonomy-sync` を実行してください。');
            $this->line('  （この検査コマンドは何も変更していません）');

            return self::FAILURE;
        }

        $this->components->info('分類の定義とデータベースは一致しています。');

        if ($references !== []) {
            $this->line('  コンテンツタイプからの参照もすべて解決できました。');
        }

        return self::SUCCESS;
    }

    /**
     * ContentSchema の `taxonomies[]` から taxonomy key → 参照元 schema の一覧を作る。
     *
     * **read-only**。SchemaRegistry を読むだけで DB へ触れない。
     * 宣言の型・未知キー・boolean 検証は `TaxonomyDeclaration` が行い、
     * 不正なら例外になる（= A の検出）。
     *
     * `declared()`（ファイル宣言だけ）を読む — Phase 9.5-A / ADR-0015。
     * Field Group overlay が足せるのは `fields` だけで `taxonomies` は増えないので、
     * この関数の答えは effective schema と同じであり、
     * **DB へ触れないという契約を保てる**。
     *
     * @return array<string, list<string>>
     */
    private function schemaReferences(SchemaRegistry $schemas): array
    {
        $references = [];

        foreach ($schemas->declared() as $schema) {
            foreach ($schema->taxonomyKeys() as $key) {
                $references[$key][] = $schema->id;
            }
        }

        ksort($references);

        return $references;
    }

    /**
     * 利用者へ見せてよい範囲のメッセージ。
     * SQL・DSN・認証情報・環境変数・stack trace を含めない。
     */
    private function safeMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        // 想定外の内容が混ざる可能性があるものは出さない
        foreach (['SQLSTATE', 'PDO', 'password', 'mysql:', 'DB_'] as $unsafe) {
            if (stripos($message, $unsafe) !== false) {
                return '定義の読み込みに失敗しました。詳しい原因は storage/logs のログに記録しました。';
            }
        }

        return $message;
    }
}

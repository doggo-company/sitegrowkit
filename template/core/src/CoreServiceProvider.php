<?php

declare(strict_types=1);

namespace AgentCms\Core;

use AgentCms\Core\Application\AuditRecorder;
use AgentCms\Core\Application\DatabaseAuditRecorder;
use AgentCms\Core\Application\DefaultOperationPolicy;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\RequestCorrelation;
use AgentCms\Core\Audit\PruneAuditLogsCommand;
use AgentCms\Core\Backups\BackupLock;
use AgentCms\Core\Backups\BackupRunLauncher;
use AgentCms\Core\Backups\BackupSchedule;
use AgentCms\Core\Backups\Console\AutoBackupCommand;
use AgentCms\Core\Backups\Console\CreateBackupCommand;
use AgentCms\Core\Backups\Console\OffsiteBackupCommand;
use AgentCms\Core\Backups\Console\RecoverRestoreCommand;
use AgentCms\Core\Backups\Console\RestoreBackupCommand;
use AgentCms\Core\Backups\Console\RunBackupCommand;
use AgentCms\Core\Backups\NullRestoreProbe;
use AgentCms\Core\Backups\ProcessBackupRunLauncher;
use AgentCms\Core\Backups\RestoreProbe;
use AgentCms\Core\Comments\PruneCommentsCommand;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Content\Console\ReplaceContentCommand;
use AgentCms\Core\ContentTypes\ContentTypePaths;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\PublicSlugRegistry;
use AgentCms\Core\FieldGroups\DatabaseFieldGroupOverlay;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Forms\PruneFormSubmissionsCommand;
use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Html\RestrictedHtml;
use AgentCms\Core\Menus\MenuTree;
use AgentCms\Core\Pages\Console\PrunePageVersionsCommand;
use AgentCms\Core\Redirects\PruneNotFoundLogsCommand;
use AgentCms\Core\Revisions\Console\ContentBaselineCommand;
use AgentCms\Core\Revisions\Console\ManifestPruneCommand;
use AgentCms\Core\Revisions\Console\PruneContentRevisionsCommand;
use AgentCms\Core\Schema\SchemaFieldOverlay;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Schema\SchemaTaxonomyOverlay;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Taxonomy\Console\TaxonomyCheckCommand;
use AgentCms\Core\Taxonomy\Console\TaxonomySyncCommand;
use AgentCms\Core\Taxonomy\ConsumerRegistry;
use AgentCms\Core\Taxonomy\DatabaseTaxonomyOverlay;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Theme\ThemeTokens;
use AgentCms\Core\Trash\TrashReadinessResolver;
use AgentCms\Core\Turnstile\SiteverifyClient;
use AgentCms\Core\Turnstile\TurnstileVerifier;
use AgentCms\Core\Uploads\UploadValidator;
use AgentCms\Core\Wxr\Console\ApplyWxrCommand;
use AgentCms\Core\Wxr\Console\InspectWxrCommand;
use AgentCms\Core\Wxr\Console\PlanWxrCommand;
use AgentCms\Core\Wxr\Console\RollbackWxrCommand;
use AgentCms\Core\Wxr\Media\HostResolver;
use AgentCms\Core\Wxr\Media\SystemHostResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agentcms.php', 'agentcms');

        /*
         * Field Group（Phase 9.5-A / ADR-0015）。**読み取り専用**。
         *
         * 書き込み経路はこの Stage に存在しない。overlay は
         * 「published かつ定義が読める Field Group の項目を、ファイル宣言の
         * 後ろへ足す」だけで、削除も型変更も Content Type の追加もできない。
         */
        /*
         * **scoped であって singleton ではない**（Phase 9.5-A CORRECTION）。
         *
         * この 3 つは runtime に DB を読む。singleton にすると Octane や
         * 長寿命 worker で process に残り、**次のリクエストでも古い定義**を返す
         * （PHP-FPM でしか「container は 1 リクエスト 1 つ」が成り立たない）。
         *
         * scoped なら 1 リクエスト内では singleton と同じ（DB は 1 度だけ）で、
         * リクエスト / job の境界で捨てられる。
         *
         * ファイルだけを読む registry（`ComponentRegistry` 等）は singleton のままでよい。
         */
        $this->app->scoped(FieldGroupRepository::class, fn ($app) => new FieldGroupRepository(
            $app->make('db')->connection(),
        ));
        $this->app->scoped(SchemaFieldOverlay::class, DatabaseFieldGroupOverlay::class);
        /*
         * サイトの導線（Phase 9.9 / P0-4）。**scoped**。
         *
         * 1 リクエスト内では覚える（ヘッダーとフッターで同じ本を 2 度引かない — OG6）が、
         * リクエストをまたいで持ち越すと **slug を変えても古い URL を配り続ける**。
         * singleton にしてはいけないのはそのためである。
         */
        $this->app->scoped(MenuTree::class);

        /*
         * 管理画面で付けた分類の関連付け（Phase 9.7 / STAGE 20D）。
         * field overlay と同じ場所へ、もう 1 段だけ足す。**scoped**（runtime に DB を読む）。
         */
        $this->app->scoped(SchemaTaxonomyOverlay::class, fn ($app) => new DatabaseTaxonomyOverlay(
            $app->make('db')->connection(),
        ));

        /*
         * 書き込みは **読み取りと別の instance**（Phase 9.5-B）。
         * 読み取り側はリクエスト内でメモ化しているので、同じ instance に
         * 書かせると「保存したのに古い値を返す」が起きる。
         */
        $this->app->bind(FieldGroupWriter::class, fn ($app) => new FieldGroupWriter(
            $app->make('db')->connection(),
        ));

        $this->app->scoped(SchemaRegistry::class, fn ($app) => new SchemaRegistry(
            (string) config('agentcms.schema_path'),
            $app->make(SchemaFieldOverlay::class),
            $app->make(SchemaTaxonomyOverlay::class),
        ));

        /*
         * Content Type Builder（Phase 9.4 / STAGE #17A）。
         *
         * 書き込み先は **config だけ**から解決する。呼び出し側が任意パスを
         * 渡せる口を作らない（`ContentTypePaths` 参照）。
         */
        $this->app->singleton(ContentTypePaths::class, fn () => new ContentTypePaths(
            (string) config('agentcms.schema_path'),
            (string) config('agentcms.modules_path'),
        ));

        /*
         * 投稿タイプの登録と設定（Phase 9.7 / STAGE 20B・ADR-0019）。
         *
         * **scoped**。runtime に DB を読むので、Field Group と同じ境界で捨てる
         * （singleton にすると Octane や長寿命 worker で古い設定が残る）。
         *
         * **boot では触らない。** route 登録とモデル登録は
         * `SchemaRegistry::declared()`（ファイルだけ）で完結している。
         */
        $this->app->scoped(ContentTypeSettingsResolver::class, fn ($app) => new ContentTypeSettingsResolver(
            $app->make('db')->connection(),
        ));
        /*
         * 書き込みは **読み取りと別の instance**（Phase 9.7 / STAGE 20C）。
         * 読み側はリクエスト内でメモ化しているので、同じ instance に書かせると
         * 「保存したのに古い値を返す」が起きる（Field Group と同じ理由）。
         */
        $this->app->bind(ContentTypeSettingsWriter::class, fn ($app) => new ContentTypeSettingsWriter(
            $app->make('db')->connection(),
        ));

        /*
         * 公開スラッグの空き検査（Phase 9.7 / STAGE 20C）。
         * route collection を読むので **request 時にだけ**使う。
         */
        $this->app->scoped(PublicSlugRegistry::class, fn ($app) => new PublicSlugRegistry(
            $app->make(ContentTypeSettingsResolver::class),
            $app->make('router'),
        ));

        $this->app->scoped(ContentTypeLifecyclePolicy::class, fn ($app) => new ContentTypeLifecyclePolicy(
            $app->make(ContentTypeSettingsResolver::class),
            $app->make(SchemaRegistry::class),
        ));

        /*
         * ゴミ箱の対応状況（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2）。
         *
         * **scoped**。列の有無は migration で変わるので、要求をまたいで覚えない。
         * 要求の中では型ごとに 1 回だけ確かめる（一覧・詳細・公開・API・MCP が
         * 同じ答えを何度も要るため）。**boot では呼ばない。**
         */
        $this->app->scoped(TrashReadinessResolver::class, fn ($app) => new TrashReadinessResolver(
            $app->make(SchemaRegistry::class),
            $app->make('db')->connection()->getSchemaBuilder(),
        ));

        // PageDocument 基盤（Phase 9.1D）。registry は allowlist を兼ねる
        /*
         * WXR の `--media` が使う名前解決（§C.6）。**差し替えられる形で bind する。**
         *
         * SSRF の要は「検査した IP へ接続する」ことで、それが働いているかは
         * **名前解決が検査の後で別の答えを返す**状況（DNS rebinding）でしか確かめられない。
         * 本物の DNS ではその状況を作れないので、ここを差し替え点にしてある。
         */
        $this->app->bind(HostResolver::class, SystemHostResolver::class);

        /*
         * 画面から始めた backup 取得の別プロセス起動（2026-09-09・OG3 改訂）。
         * テストは Fake を bind して差し替える（本物は `php artisan` を実際に起動する）。
         */
        $this->app->bind(BackupRunLauncher::class, ProcessBackupRunLauncher::class);

        /*
         * 取得と復元のプロセス横断の排他（release R09）。**singleton でなければならない** —
         * 復元は復元前 backup を取るために同じ lock へ再入する（深さを数えるのは 1 つの instance）。
         * 観測点は本番では何もしない。試験だけが差し替える。
         */
        $this->app->singleton(BackupLock::class);
        $this->app->bind(RestoreProbe::class, NullRestoreProbe::class);

        $this->app->singleton(ComponentRegistry::class, fn () => new ComponentRegistry(
            (string) config('agentcms.component_path'),
        ));
        $this->app->singleton(ThemeTokens::class, fn () => new ThemeTokens(
            (string) config('agentcms.theme_path'),
        ));

        // Taxonomy 定義（Phase 9.3B）。**file registry は read-only で DB を触らない**。
        // 読み取り先は config で固定し、呼び出し側から任意パスを渡せないようにする
        $this->app->singleton(TaxonomyDefinitionRegistry::class, fn () => new TaxonomyDefinitionRegistry(
            (string) config('agentcms.taxonomy_path'),
        ));

        // AI 向け索引の consumer 整合（Phase 9.3B / STAGE 3C CORRECTION）。
        // **read-only**。ファイルも DB も書き換えない
        $this->app->singleton(ConsumerRegistry::class, fn () => new ConsumerRegistry(
            (string) config('agentcms.taxonomy_registry_path'),
        ));

        // 参照調査（Phase 9.3B）。**削除可否の唯一の判定者**（§I.3）。
        // provider 構成は `defaultProviders()` が単一情報源。ここで別のリストを書かない
        // （DI 経由と直接 new で構成がずれるのを防ぐ）。**二重登録もしない**
        //
        // **scoped**（Phase 9.5-A CORRECTION）。provider の中へ effective な
        // `SchemaRegistry` を捕まえるので、singleton だと registry だけ新しくなっても
        // ここが古いままになる。捨てる境界を registry と揃える
        $this->app->scoped(ReferenceInspector::class, fn ($app) => new ReferenceInspector(
            ReferenceInspector::defaultProviders(
                $app->make(SchemaRegistry::class),
                (int) config('agentcms.taxonomy_reference_budget_ms'),
            ),
        ));

        $this->app->singleton(TurnstileVerifier::class, SiteverifyClient::class);
        $this->app->singleton(Purifier::class);
        $this->app->singleton(RestrictedHtml::class);
        $this->app->singleton(SettingsStore::class);
        $this->app->singleton(UploadValidator::class);

        // Application Service 層の共通部品（Phase 9.1B）。
        // Admin / API / MCP はすべてこの認可と監査を通る
        $this->app->singleton(OperationPolicy::class, DefaultOperationPolicy::class);
        $this->app->singleton(AuditRecorder::class, DatabaseAuditRecorder::class);

        /*
         * 監査の相関 ID（STAGE #18A / Correction 2-3）。
         *
         * singleton にしておき、HTTP は `AssignRequestId` が
         * リクエストの先頭で必ず振り直す（同じ container を使い回す
         * Octane / テストでも 1 リクエスト 1 ID になる）。
         */
        $this->app->singleton(RequestCorrelation::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agentcms.php' => config_path('agentcms.php'),
        ], 'agentcms-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        /*
         * 毎日の自動 backup（時刻を設定したときだけ載る — `BackupSchedule`）。
         * core に置くのは、core を更新しただけのサイトでも env の 1 行で入るようにするため。
         */
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            BackupSchedule::register($schedule);
        });

        // taxonomy コマンドは **CLI でのみ登録**する。
        // 登録は class の宣言だけで、boot 中に DB へ触れない（PV-6 / SY-3）
        if ($this->app->runningInConsole()) {
            $this->commands([
                ContentBaselineCommand::class,
                ManifestPruneCommand::class,
                PruneContentRevisionsCommand::class,
                ReplaceContentCommand::class,
                TaxonomySyncCommand::class,
                TaxonomyCheckCommand::class,
                PruneAuditLogsCommand::class,
                PrunePageVersionsCommand::class,
                PruneNotFoundLogsCommand::class,
                CreateBackupCommand::class,
                RunBackupCommand::class,
                RecoverRestoreCommand::class,
                AutoBackupCommand::class,
                OffsiteBackupCommand::class,
                RestoreBackupCommand::class,
                PruneFormSubmissionsCommand::class,
                PruneCommentsCommand::class,
                ApplyWxrCommand::class,
                InspectWxrCommand::class,
                PlanWxrCommand::class,
                RollbackWxrCommand::class,
            ]);
        }
    }
}

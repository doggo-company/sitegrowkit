<?php

declare(strict_types=1);

namespace App\Providers;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\ContentTypes\CmsManagedContentTypes;
use AgentCms\Core\Contracts\MediaIntake;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Contact\Console\ContactMigrateCommand;
use Modules\Media\Application\UploadMediaIntake;
use Modules\Media\Console\MediaPruneCommand;
use Modules\Media\Console\MediaVariantsCommand;

/**
 * modules/ 配下（Project 層）の migration / views を読み込む。
 * モジュール規約は .agentcms/rules/boundaries.md 参照。
 */
final class ModulesServiceProvider extends ServiceProvider
{
    /**
     * **コードで管理する** collection 型の Content Type。
     * schema id => モデル。**core はこの対応を知らない**（project → core の一方向依存）。
     *
     * 管理画面の Content Type Builder（Phase 9.4）が作った型は
     * ここに現れない。`managedBy: "cms"` の schema から実行時に発見する
     * （`CmsManagedContentTypes`）。**同じ情報を 2 か所に持たない**。
     *
     * 対応表そのものは **配らない別ファイル**に置く（Phase 9.7 / STAGE 20F）。
     * `news` / `works` / `product` は kit の例・検証資産であり、新しく生成する
     * サイトへは配らない（SPEC §C / Owner Decision OG-A）。ここに書いてしまうと、
     * その module が無い生成サイトでは**静的解析が「そんなクラスは無い」と言って落ちる**
     * — 実行時は下の `class_exists` が守っているのに、である。
     * 配らないものへの参照は、配るファイルに置かない。
     *
     * @return array<string, class-string>
     */
    private function codeManagedContentTypes(): array
    {
        if (! file_exists(__DIR__.'/example-content-types.php')) {
            return [];
        }

        /** @var array<string, class-string> $types */
        $types = require __DIR__.'/example-content-types.php';

        return $types;
    }

    /**
     * **schema は在るのにモデルを読み込めなかった型**（診断用）。
     *
     * 「module ごと配っていない」（正常）と「配ったのに壊れている」（異常）は違う。
     * 前者は静かに飛ばしてよいが、後者は**黙って消さない** — 一覧に型が出ないだけで
     * 原因が追えなくなる。ここに残して読めるようにする。
     *
     * @var array<string, class-string>
     */
    private static array $unavailableContentTypes = [];

    /**
     * 診断のための読み出し口（`ListContentTypeDefinitions` は
     * schema 側から同じ状態を `unavailable` として出している）。
     *
     * @return array<string, class-string>
     */
    public static function unavailableContentTypes(): array
    {
        return self::$unavailableContentTypes;
    }

    public function register(): void
    {
        /*
         * **Core がモジュールの口を使うための binding**（ADR-0020）。
         *
         * `core/` から `modules/` へは依存できない（`boundaries.md` の MUST）ので、
         * Core は `MediaIntake` という interface だけを見る。**その向きを繋ぐのはここ**である。
         *
         * この module を配らないサイトでは binding が無い。そのとき WXR の `--media` は
         * **黙って飛ばさずに止まる**（`MediaImporter`）— 画像が 1 枚も無い移行が
         * 成功として残る方が悪いからである。
         */
        $this->app->singleton(MediaIntake::class, UploadMediaIntake::class);

        $this->app->singleton(ContentTypeRegistry::class, function (): ContentTypeRegistry {
            $registry = new ContentTypeRegistry;
            $schemas = $this->app->make(SchemaRegistry::class);
            // ファイルだけを読む（boot 中に DB へ触れない）
            $declared = $schemas->declared();

            self::$unavailableContentTypes = [];

            foreach ($this->codeManagedContentTypes() as $schemaId => $model) {
                if (class_exists($model)) {
                    $registry->register($schemaId, $model);

                    continue;
                }

                // schema も無いなら、その module は**配っていない**だけである（正常）
                if (array_key_exists($schemaId, $declared)) {
                    self::$unavailableContentTypes[$schemaId] = $model;
                }
            }

            // CMS が作った型（Phase 9.4）。schema が単一情報源で、
            // モデル FQCN は ID から決定的に導出される
            foreach (CmsManagedContentTypes::discover($schemas) as $schemaId => $model) {
                $registry->register($schemaId, $model);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        $modules = (string) config('agentcms.modules_path');

        /*
         * メディアの保守コマンド（Phase 9.5-D）。
         *
         * core は modules を知らない（project → core の一方向依存）ので、
         * `Media` モデルを扱うコマンドは project 側で登録する。
         * **CLI のときだけ**登録する — class の宣言だけで boot 中に DB へ触れない。
         */
        if ($this->app->runningInConsole()) {
            $this->commands([
                MediaVariantsCommand::class,
                MediaPruneCommand::class,
                // 旧お問い合わせ → フォームの受信（R-12 Stage 2 / B）。
                // 既定は数えるだけで、原本は消さない
                ContactMigrateCommand::class,
            ]);
        }

        foreach (glob($modules.'/*/database/migrations') ?: [] as $dir) {
            $this->loadMigrationsFrom($dir);
        }

        // modules/News/views → view('news::...')
        foreach (glob($modules.'/*/views') ?: [] as $dir) {
            $this->loadViewsFrom($dir, strtolower(basename(dirname($dir))));
        }

        /*
         * 公開サイトの全ビューへサイト設定を共有（`$site`）。
         *
         * ▷ **`forms.*` を含める**（R-12 / 受付の一本化）。layout だけに配ると
         *   **`@extends(...)` の引数では読めない** — あれは子 view の scope で評価されるので、
         *   layout へ配った `$site` はまだ束縛されていない。`contact::*` が入っているのと同じ理由で、
         *   `/contact` を描く view はここに要る（入れ忘れると `Undefined variable $site` で 500）。
         */
        View::composer(
            ['layouts.public', 'layouts.imported', 'public.*', 'forms.*', 'news::*', 'works::*', 'contact::*'],
            function (\Illuminate\View\View $view): void {
                $view->with('site', array_merge(
                    ['site_name' => config('app.name'), 'tagline' => '', 'seo_description' => ''],
                    app(SettingsStore::class)->all(),
                ));
            },
        );
    }
}

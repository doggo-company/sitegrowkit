<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * 「この環境で Content Type を作ってよいか」の事前検査（Phase 9.4 / STAGE #17A）。
 *
 * SPEC §1C: Production で書き込み先や migration 適用条件を満たさない場合は
 * **変更前に安全に停止**し、管理画面へ具体的な理由を出す。
 *
 * ここで見るのは「始めてよいか」だけである。始めた後の失敗は
 * `ContentTypeInstaller` の rollback が受け持つ。
 */
final class ContentTypeEnvironment
{
    public function __construct(
        private readonly Application $app,
        private readonly ContentTypePaths $paths,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * 適用を止める理由。**空なら適用してよい**。
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $blockers = [];

        if (! is_dir($this->paths->schemaDir()) || ! is_writable($this->paths->schemaDir())) {
            $blockers[] = 'Content Type 定義の保存先に書き込めません（'
                .'デプロイ済みの読み取り専用リリースでは作成できません）';
        }

        if (! $this->modulesDirIsWritable()) {
            $blockers[] = 'モジュールの保存先に書き込めません（'
                .'デプロイ済みの読み取り専用リリースでは作成できません）';
        }

        if ($this->classMapIsAuthoritative()) {
            $blockers[] = 'Composer のオートローダーが classmap-authoritative で構築されているため、'
                .'新しいモデルクラスを読み込めません（composer dump-autoload が必要です）';
        }

        if ($this->app->routesAreCached() && ! $this->bootstrapCacheIsWritable()) {
            $blockers[] = 'ルートキャッシュが有効ですが bootstrap/cache に書き込めないため、'
                .'作成した Content Type の管理画面 URL を有効にできません';
        }

        if (! $this->databaseIsReachable()) {
            $blockers[] = 'データベースへ接続できないため、テーブルを作成できません';
        }

        return $blockers;
    }

    /** 適用後にルートキャッシュを作り直す必要があるか */
    public function needsRouteCacheRefresh(): bool
    {
        return $this->app->routesAreCached();
    }

    private function modulesDirIsWritable(): bool
    {
        $dir = $this->paths->modulesDir();

        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent = dirname($dir);

        return is_dir($parent) && is_writable($parent);
    }

    private function bootstrapCacheIsWritable(): bool
    {
        $dir = $this->app->bootstrapPath('cache');

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * classmap-authoritative なオートローダーは PSR-4 の探索を行わない。
     * 生成した `Modules\<Name>\Models\<Name>` が **絶対に読み込めない**ので、
     * 書き込む前に止める。
     */
    private function classMapIsAuthoritative(): bool
    {
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                return $autoloader[0]->isClassMapAuthoritative();
            }
        }

        return false;
    }

    private function databaseIsReachable(): bool
    {
        try {
            $this->db->connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

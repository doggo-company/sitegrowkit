<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Throwable;

/**
 * 計画の適用（Phase 9.4 / STAGE #17A）。
 *
 * ## 半端な状態を作らない
 *
 * 「schema だけ」「テーブルだけ」「registry だけ」が残ることを禁じる（SPEC §1C）。
 * そのために
 *
 * 1. **完成させてから公開する**（`publish()`）。同じディレクトリの一時ファイルへ
 *    書き切って `fsync` し、`link()` で原子的に公開する。既にあれば公開は失敗する
 * 2. 作ったものを**すべて記録**しながら進める
 * 3. どこで失敗しても `rollback()` が**逆順に**、かつ
 *    **自分が作ったものだけ**を取り消す
 * 4. 取り消しきれなかったものは例外へ添えて**画面に出す**（黙って残さない）。
 *    パスは base_path からの相対にして、サーバーの絶対パスを露出させない
 *
 * 再実行可能かつ冪等である。既に存在する Content Type は
 * `ContentTypePlanner` が計画段階で弾くため、ここへは来ない。
 */
final class ContentTypeInstaller
{
    public function __construct(
        private readonly Application $app,
        private readonly ContentTypePaths $paths,
        private readonly ContentTypeGenerator $generator,
        private readonly DatabaseManager $db,
        private readonly ConsoleKernel $console,
    ) {}

    /**
     * @return array<string, mixed> 適用結果の要約（監査 / 画面用）
     *
     * @throws ContentTypeInstallFailed
     */
    public function install(ContentTypePlan $plan): array
    {
        $id = $plan->draft->identifier;

        /** @var list<string> $files */
        $files = [];
        /** @var list<string> $dirs 逆順に消すため作った順に積む */
        $dirs = [];
        // 「適用した」ではなく「**適用を試みた**」を記録する。migrate が
        // テーブルを作った後に落ちた場合も取り消せるようにするため
        $migrationAttempted = false;

        try {
            // 直前にもう一度確かめる（計画から適用までの間に他の経路が作った可能性）
            $this->assertStillAbsent($plan);

            $migrationPath = $this->paths->migrationFile($id, $plan->migrationTimestamp);

            $dirs = $this->ensureDirectories([
                $this->paths->schemaDir(),
                $this->paths->moduleDir($id),
                dirname($this->paths->modelFile($id)),
                $this->paths->migrationDir($id),
            ]);

            /*
             * **schema は最後に公開する。**
             *
             * schema ファイルの存在が「その Content Type がある」という意味を持つ
             * （`CmsManagedContentTypes` も route 登録もそこを見る）。先に置くと、
             * 並行して走っている別リクエストが **テーブルもモデルも無い型**を
             * 一瞬だけ観測してしまう。モデル → migration → テーブル → schema の順なら、
             * 読み手から見て型は「無い」か「完全にある」かのどちらかしかない。
             */
            $modelFile = $this->publish($this->paths->modelFile($id), $this->generator->modelSource($plan->draft));
            $files[] = $modelFile;

            /*
             * **書いたばかりのモデルは、この場で読み込む。**
             *
             * 同じ要求の中で `ContentTypePlanner` が「その型はまだ無い」を確かめるために
             * `class_exists()` を呼んでいる。composer の ClassLoader は**見つからなかった
             * クラスを覚える**（`missingClasses`）ので、そのあとでファイルを置いても、
             * 同じプロセスからは二度と見つからない。放っておくと下の確認で必ず落ち、
             * せっかく作った型が毎回巻き戻る。
             *
             * 自分が今書いたファイルなのだから、探させずに直接読めばよい。
             * 次の要求からは composer の PSR-4 が普通に見つける。
             */
            require_once $modelFile;

            $files[] = $this->publish($migrationPath, $this->generator->migrationSource($plan->draft));

            $migrationAttempted = true;
            $this->runMigration($migrationPath);

            if (! SchemaFacade::hasTable($plan->table())) {
                throw new \RuntimeException("テーブル [{$plan->table()}] を作成できませんでした");
            }

            $files[] = $this->publish($this->paths->schemaFile($id), $this->generator->schemaJson($plan->draft));

            // 実行中のプロセスへ即座に反映させる（再起動を要求しない — SPEC §1D）
            $this->refreshRuntimeRegistries();

            $schema = $this->app->make(SchemaRegistry::class)->get($id->value);

            if ($schema->managedBy !== 'cms') {
                throw new \RuntimeException('生成した schema を CMS 管理として読み込めませんでした');
            }

            if (! class_exists($id->modelClass(), autoload: true)) {
                throw new \RuntimeException(
                    "生成したモデルクラス {$id->modelClass()} を読み込めませんでした",
                );
            }

            $this->refreshRouteCache();
        } catch (Throwable $e) {
            $notes = $this->rollback($plan, $files, $dirs, $migrationAttempted);

            throw new ContentTypeInstallFailed(
                $e instanceof \RuntimeException ? $e->getMessage() : 'Content Type を作成できませんでした',
                $notes,
                $e,
            );
        }

        return [
            'id' => $id->value,
            'table' => $plan->table(),
            'adminUrl' => $plan->adminUrl,
            'files' => [$plan->schemaFile, $plan->modelFile, $plan->migrationFile],
            'fieldCount' => count($plan->draft->fields),
        ];
    }

    /**
     * 適用直前の再確認。計画時点の検査を**信用しない**。
     *
     * @throws \RuntimeException
     */
    private function assertStillAbsent(ContentTypePlan $plan): void
    {
        $id = $plan->draft->identifier;

        if (is_file($this->paths->schemaFile($id)) || is_dir($this->paths->moduleDir($id))) {
            throw new \RuntimeException("Content Type [{$id->value}] はすでに作成されています");
        }

        if (SchemaFacade::hasTable($plan->table())) {
            throw new \RuntimeException("テーブル [{$plan->table()}] はすでに存在します");
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> このメソッドが**新しく作った**ディレクトリだけ
     */
    private function ensureDirectories(array $paths): array
    {
        $created = [];

        foreach ($paths as $path) {
            foreach ($this->missingAncestors($path) as $directory) {
                // **中間ディレクトリも 1 つずつ記録する。** `mkdir(recursive: true)` に
                // 任せると `modules/Faq/database` のような途中の階層が記録から漏れ、
                // rollback で親を消せずに空のモジュールが残る
                if (! mkdir($directory, 0o755) && ! is_dir($directory)) {
                    // **絶対パスをメッセージへ入れない**（画面に出るため — `relative()` を通す）
                    throw new \RuntimeException('ディレクトリを作成できませんでした（'.$this->relative($directory).'）');
                }

                $created[] = $directory;
            }
        }

        return $created;
    }

    /**
     * まだ存在しない階層を **浅い方から** 並べる。
     *
     * @return list<string>
     */
    private function missingAncestors(string $path): array
    {
        $missing = [];

        for ($current = $path; ! is_dir($current); $current = dirname($current)) {
            array_unshift($missing, $current);

            if (dirname($current) === $current) {
                break;
            }
        }

        return $missing;
    }

    /**
     * ファイルを **完成させてから、一度で見えるようにする**。
     *
     * 目的は 2 つあり、どちらも「並行して動いている別リクエスト」対策である。
     *
     * | 守ること | 手段 |
     * | --- | --- |
     * | 途中まで書けた内容を読ませない | 同じディレクトリの一時ファイルへ全部書き、`fsync` してから公開する |
     * | 同じ ID を二重に生成しない | `link()` は **対象が既にあれば失敗する**。上書きが起きない |
     *
     * 一時ファイル名は `.<name>.tmp-<random>` にする。先頭のドットと
     * 拡張子の違いにより、`glob('*.json')`（SchemaRegistry）にも
     * migration の `*.php` 走査にも **引っかからない**。
     *
     * `rename()` を使わないのは、既存ファイルを黙って上書きしてしまうため。
     */
    private function publish(string $path, string $contents): string
    {
        $temporary = dirname($path).'/.'.basename($path).'.tmp-'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new \RuntimeException('一時ファイルを作成できませんでした');
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new \RuntimeException('ファイルを最後まで書き込めませんでした');
            }

            // 公開前に確実にディスクへ落とす。**戻り値を無視しない** —
            // 落ちきっていないものを公開すると、内容の欠けたファイルが見える
            if (! fflush($handle) || ! fsync($handle)) {
                throw new \RuntimeException('ファイルをディスクへ書き出せませんでした');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($temporary);

            throw $e;
        }

        fclose($handle);

        // link() は EEXIST で失敗する = 上書きしない原子的な公開
        if (! @link($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(
                is_file($path)
                    ? '生成先のファイルがすでに存在します'
                    : 'ファイルを保存できませんでした（'.$this->relative($path).'）',
            );
        }

        @unlink($temporary);

        return $path;
    }

    /**
     * 生成した migration **だけ**を適用する。
     *
     * `--path` で対象を 1 ファイルへ限定し、`--realpath` で
     * 呼び出し側の任意パス解決を挟まない。任意 migration の実行口ではない。
     */
    private function runMigration(string $migrationPath): void
    {
        $status = $this->console->call('migrate', [
            '--path' => $migrationPath,
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($status !== 0) {
            throw new \RuntimeException('テーブルを作成できませんでした（migration が失敗しました）');
        }
    }

    /**
     * 失敗時の取り消し。**作った順の逆**で戻す。
     *
     * **自分が作ったものだけ**を消す。`hasTable()` が真だからという理由で
     * drop すると、同時実行のもう一方が作ったばかりのテーブルを
     * 巻き添えで消しかねない（呼び出し側は同一 ID の lock を
     * ここまで保持する契約 — `CreateContentType` 参照）。
     *
     * @param  list<string>  $files
     * @param  list<string>  $dirs
     * @return list<string> 取り消せなかったもの（画面に出して人が拾えるようにする）
     */
    private function rollback(ContentTypePlan $plan, array $files, array $dirs, bool $migrationAttempted): array
    {
        $notes = [];

        if ($migrationAttempted) {
            try {
                SchemaFacade::dropIfExists($plan->table());
            } catch (Throwable) {
                $notes[] = "テーブル [{$plan->table()}] を削除できませんでした";
            }

            // migrations テーブルの記録も消す（残すと次回の migrate が空振りする）
            try {
                $this->db->connection()->table('migrations')
                    ->where('migration', basename($plan->migrationFile, '.php'))
                    ->delete();
            } catch (Throwable) {
                $notes[] = 'migration の適用記録を取り消せませんでした';
            }
        }

        foreach (array_reverse($files) as $file) {
            if (is_file($file) && ! @unlink($file)) {
                $notes[] = '生成ファイルが残っています。手動で削除してください：'.$this->relative($file);
            }
        }

        foreach (array_reverse($dirs) as $dir) {
            if (is_dir($dir) && ! @rmdir($dir)) {
                $notes[] = '生成ディレクトリが残っています。中身を確認して削除してください：'.$this->relative($dir);
            }
        }

        $this->refreshRuntimeRegistries();

        return $notes;
    }

    /** 画面へ出すのは base_path からの相対パスだけ（サーバーの絶対パスを露出させない） */
    private function relative(string $absolute): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : basename($absolute);
    }

    /**
     * schema / Content Type の登録簿を作り直す。
     *
     * どちらも singleton で読み込み結果を抱えているため、
     * forget しないと **同じリクエストの中では新しい type が見えない**。
     */
    private function refreshRuntimeRegistries(): void
    {
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(ContentTypeRegistry::class);
    }

    /**
     * ルートキャッシュを作り直す。
     *
     * キャッシュされていない環境（ローカル / 既定）では**何もしない**。
     *
     * 書き込み可否は `ContentTypeEnvironment` が適用前に確かめているが、
     * **事前検査は生成時の失敗を覆えない**ので終了コードを必ず見る。
     * ここで失敗したら「作成できたが管理画面へ出ない」状態になるため、
     * 成功扱いにせず rollback させる。
     */
    private function refreshRouteCache(): void
    {
        if (! $this->app->routesAreCached()) {
            return;
        }

        if ($this->console->call('route:cache') !== 0) {
            throw new \RuntimeException(
                'ルートキャッシュを更新できなかったため、作成した Content Type の'
                .'管理画面 URL を有効にできませんでした',
            );
        }
    }
}

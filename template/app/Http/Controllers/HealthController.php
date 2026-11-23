<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * デプロイ後の health endpoint（agentcms verify staging / deploy の health check が参照）。
 * 秘密情報・環境詳細（DB名・パス・バージョン）は返さない。
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /*
         * **繋がるだけでは足りない。**
         *
         * `select 1` は migration が 1 つも当たっていない DB でも通る。
         * この endpoint は deploy の**自動切り戻しの引き金**なので、
         * 表が無い状態で `ok` を返すと、**壊れたサイトが公開されたまま残る**。
         *
         * 見るのは「**この release が動く形になっているか**」だけ。
         * 版番号や表の名前は**返さない** — ここは無認証なので、
         * 出す情報を増やすと**外から DB の中身が読める**ようになる。
         */
        $db = $this->check(
            fn (): bool => DB::select('select 1') !== []
                && Schema::hasTable('migrations')
                && $this->everythingApplied(),
        );
        $storage = $this->check(function (): bool {
            $file = 'health-check.tmp';
            Storage::disk('local')->put($file, (string) time());
            $ok = Storage::disk('local')->exists($file);
            Storage::disk('local')->delete($file);

            return $ok;
        });

        $ok = $db && $storage;

        return response()->json(
            ['ok' => $ok, 'db' => $db, 'storage' => $storage],
            $ok ? 200 : 503,
        )->header('Cache-Control', 'no-store');
    }

    /**
     * **当てるべき migration が 1 つも残っていないか。**
     *
     * 「1 行でも在れば通す」では足りない。現実に起きるのは
     * 「表が 1 つも無い」ではなく、**前の release の表は在るが、
     * 今回の migration が失敗した / 走っていない**である。
     *
     * **数えるのは中だけ。** 何件残っているか・どの file かは**返さない** —
     * ここは無認証なので、出す情報を増やすと**外から中身が読める**。
     */
    private function everythingApplied(): bool
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        // `pendingMigrations()` は protected なので、**差を自分で取る**。
        // 呼べないものを呼ぶと例外になり、`check()` が飲んで **503 に化ける**
        $ran = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')]),
        );

        return array_diff(array_keys($files), $ran) === [];
    }

    /** @param  callable(): bool  $probe */
    private function check(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }
}

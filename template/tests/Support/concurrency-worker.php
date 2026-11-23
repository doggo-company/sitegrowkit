<?php

declare(strict_types=1);

/**
 * `LastOwnerConcurrencyTest` の子プロセス（STAGE #18A / Correction 2-1）。
 *
 * ## なぜ別プロセスなのか
 *
 * 行ロックの競合は **待たされる側がブロックする**。PHP は 1 プロセス 1 スレッドなので、
 * 同じプロセスで 2 本の接続を持っても、片方が待ちに入った時点で
 * もう片方を進められない（テストが固まるだけで競合を再現できない）。
 * 実際に 2 プロセスを同時に走らせるしかない。
 *
 * ## 使い方
 *
 * ```
 * php tests/Support/concurrency-worker.php <operation> <targetId> <actorId> <token> <startAtMicros> [payloadJson]
 * ```
 *
 * `payloadJson` は content 系の operation だけが使う（type / lock_version / data）。
 *
 * `startAtMicros` まで待ってから実行するので、2 つの worker が
 * **ほぼ同時に**同じ mutex へ入る。結果は 1 行の JSON で stdout へ返す。
 * 例外は握りつぶさず、種別ごと JSON にして返す
 * （生の `QueryException`（deadlock）が漏れていないことを親が判定するため）。
 */

use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use App\Application\Users\ChangeUserRole;
use App\Application\Users\DeactivateUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$operation, $targetId, $actorId, $token, $startAt] = [
    (string) ($argv[1] ?? ''),
    (int) ($argv[2] ?? 0),
    (int) ($argv[3] ?? 0),
    (string) ($argv[4] ?? ''),
    (float) ($argv[5] ?? 0),
];

/** @var array<string, mixed> $payload */
$payload = json_decode((string) ($argv[6] ?? '{}'), true) ?: [];

// 合図の時刻まで待つ（2 プロセスを同じ瞬間へそろえる）
$wait = $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$context = OperationContext::admin($actorId, 'worker')->withConfirmationToken($token);

try {
    $result = match ($operation) {
        'deactivate' => $app->make(DeactivateUser::class)->execute($targetId, $context),
        // ゴミ箱への移動（Phase 9.8 / STAGE 21C）
        'content.trash' => $app->make(TrashContent::class)->execute(
            (string) ($payload['type'] ?? ''),
            $targetId,
            $context,
            isset($payload['lock_version']) ? (int) $payload['lock_version'] : null,
        ),
        /*
         * 参照を書き換える更新。**2 つの worker が逆の順序で参照する**ことで、
         * ロックの並びが決まっていなければ deadlock になる状況を作る。
         */
        'content.update' => $app->make(UpdateContent::class)->execute(
            (string) ($payload['type'] ?? ''),
            $targetId,
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            $context,
            partial: true,
            lockVersion: isset($payload['lock_version']) ? (int) $payload['lock_version'] : null,
        ),
        default => $app->make(ChangeUserRole::class)->execute($targetId, 'viewer', $context),
    };

    echo json_encode([
        'ok' => true,
        'status' => $result->status->value,
        'message' => $result->message,
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    // **握りつぶさない。** 生の例外が出たこと自体が不合格の証拠になる
    echo json_encode([
        'ok' => false,
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
}

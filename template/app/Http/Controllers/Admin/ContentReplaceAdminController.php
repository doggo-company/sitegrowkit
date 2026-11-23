<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Content\ReplaceContent;
use AgentCms\Core\Application\Content\ReplaceContentInput;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use App\Http\Controllers\Api\V1\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 本文の一括置換の管理画面（P1 ⑧ / GUI 化・2026-09-10）。
 *
 * ## ここは adapter である
 *
 * 予告・token・backup・版・取引・監査は `ReplaceContent`（core）に在り、CLI と同じ 1 つを通る。
 * ここが決めるのは **HTTP の形**だけ: 入力を `ReplaceContentInput` へ写し、`Result` を
 * `ApiResponse` で JSON にする（面ごとに別の解釈を作らない）。
 *
 * ## 画面は Owner だけ。入口を隠すだけではない
 *
 * 一覧（`index`）・予告（`preview`）・実行（`apply`）のすべてで `OperationPolicy` を通す。
 * `content.replace` は `Capability::ContentReplace`（Owner だけ）に対応し、MCP / `OperationCatalog` には出さない。
 *
 * ## 画面が立てられないもの
 *
 * `allowStaleBackup` と `limit` は CLI の明示フラグで、ここでは受け取らない（request に何を
 * 付けても無視される）。代わりに画面は **上限 `MAX_ROWS`** と **中身まで確かめた backup** を課す。
 */
final class ContentReplaceAdminController
{
    /**
     * 1 回に置き換えられる件数。超えたら **1 件も触らずに**条件を狭めてもらう。
     * 黙って先頭だけを実行しない。
     */
    public const MAX_ROWS = 200;

    /** backup が無い・古い・壊れているときに案内する画面 */
    private const BACKUPS_URL = '/admin/backups';

    public function index(Request $request, ReplaceContent $replace, OperationPolicy $policy): Response
    {
        $context = $this->context($request);

        abort_unless($policy->allows($replace->operationId(), $replace->classification(), $context), 403);

        $backup = $replace->backupStatus(verifyContents: true);

        return Inertia::render('admin/content-replace/Index', [
            'targets' => $replace->targets(),
            'backup' => [
                'ok' => $backup['ok'],
                'reason' => $backup['reason'],
                'problems' => $backup['problems'],
                'url' => self::BACKUPS_URL,
            ],
            'maxRows' => self::MAX_ROWS,
            'sampleLimit' => ReplaceContent::SAMPLE_LIMIT,
        ]);
    }

    /** 予告。**1 文字も変えない。** 本文の抜粋が返るので、応答は private cache にする */
    public function preview(Request $request, ReplaceContent $replace): JsonResponse
    {
        $result = $replace->preview($this->input($request), $this->context($request));

        return ApiResponse::from($result, $replace->operationId())
            ->header('Cache-Control', 'private, no-store');
    }

    /** 実行。token は `X-Confirmation` で受ける（ほかの破壊的操作と同じ経路） */
    public function apply(Request $request, ReplaceContent $replace): JsonResponse
    {
        $result = $replace->execute($this->input($request), $this->context($request));

        return ApiResponse::from($result, $replace->operationId())
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * 画面からの入力を service の形へ写す。
     *
     * `limit` / `allowStaleBackup` は**受け取らない**。上限と backup の内容検証は固定する。
     */
    private function input(Request $request): ReplaceContentInput
    {
        return new ReplaceContentInput(
            type: $this->string($request, 'type'),
            field: $this->string($request, 'field'),
            from: $this->string($request, 'from'),
            to: $this->string($request, 'to'),
            maxRows: self::MAX_ROWS,
            verifyBackupContents: true,
        );
    }

    private function string(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->header('X-Confirmation');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }
}

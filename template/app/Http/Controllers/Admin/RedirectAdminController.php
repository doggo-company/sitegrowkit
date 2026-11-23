<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Redirects\DeleteRedirect;
use AgentCms\Core\Application\Redirects\ListNotFound;
use AgentCms\Core\Application\Redirects\ListRedirects;
use AgentCms\Core\Application\Redirects\ResolveNotFound;
use AgentCms\Core\Application\Redirects\SaveRedirect;
use AgentCms\Core\Application\Redirects\SimulateRedirect;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectStatus;
use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 転送ルールと 404 の記録の管理画面（Phase 9.10 / STAGE 23D・SPEC §G）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 正規化・輪の検査・確認・監査は Application Service 側にある。
 *
 * ## シミュレータを必ず置く
 *
 * 301 はブラウザが強く記憶する。**試しに踏んで確かめると、
 * ルールを直したあとも自分のブラウザだけ古い転送先へ飛び続ける。**
 * だから「踏まずに分かる」口をここに置く（`simulate`）。
 */
final class RedirectAdminController
{
    public function index(Request $request, ListRedirects $list): Response
    {
        $result = $list->execute(
            $this->context($request),
            (string) $request->query('q', ''),
            $request->has('enabled') ? $request->boolean('enabled') : null,
            (int) $request->query('page', 1),
        );

        $this->abortUnlessSuccess($result);

        return Inertia::render('admin/redirects/Index', [
            'rules' => $result->data['rules'],
            'meta' => $result->meta,
            'statuses' => array_map(
                static fn (RedirectStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    // 301 / 308 はブラウザが覚える。画面で注意を出す
                    'sticky' => $status->isSticky(),
                ],
                RedirectStatus::cases(),
            ),
            'canWrite' => $this->canWrite($request),
        ]);
    }

    public function notFound(Request $request, ListNotFound $list): Response
    {
        $result = $list->execute(
            $this->context($request),
            (string) $request->query('q', ''),
            $request->boolean('includeResolved'),
            (int) $request->query('page', 1),
        );

        $this->abortUnlessSuccess($result);

        return Inertia::render('admin/redirects/NotFound', [
            'logs' => $result->data['logs'],
            'meta' => $result->meta,
            'canWrite' => $this->canWrite($request),
        ]);
    }

    public function store(Request $request, SaveRedirect $save): RedirectResponse
    {
        return $this->persist($save, $request, null);
    }

    public function update(Request $request, SaveRedirect $save, string $id): RedirectResponse
    {
        return $this->persist($save, $request, (int) $id);
    }

    public function destroy(Request $request, DeleteRedirect $delete, string $id): RedirectResponse
    {
        $result = $delete->execute((int) $id, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '転送ルールを削除しました');
    }

    /** 404 の記録から 1 操作でルールを作る（SPEC §H.2 の本命の導線） */
    public function resolve(Request $request, ResolveNotFound $resolve, string $id): RedirectResponse
    {
        $result = $resolve->execute(
            (int) $id,
            (string) $request->input('target_url', ''),
            $this->context($request),
            (int) $request->input('status_code', 301),
        );

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '転送ルールを作りました');
    }

    /** **何も変えない。** 踏まずに結果だけ返す */
    public function simulate(Request $request, SimulateRedirect $simulate): JsonResponse
    {
        $result = $simulate->execute((string) $request->query('url', ''), $this->context($request));

        return response()->json(
            $result->isSuccess() ? $result->data : ['message' => $result->message],
            $result->isSuccess() ? 200 : 422,
        );
    }

    private function persist(SaveRedirect $save, Request $request, ?int $id): RedirectResponse
    {
        $result = $save->execute(
            [
                'match_type' => $request->input('match_type', 'exact'),
                'source_path' => $request->input('source_path'),
                'target_url' => $request->input('target_url'),
                'status_code' => $request->input('status_code', 301),
                'is_enabled' => $request->boolean('is_enabled', true),
                'keep_query' => $request->boolean('keep_query', true),
                'note' => $request->input('note'),
            ],
            $this->context($request),
            $id,
            $id === null ? LockVersionInput::of(0) : LockVersionInput::fromRequest($request->all()),
        );

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '転送ルールを保存しました');
    }

    /**
     * 保存できる利用者か。
     *
     * **画面の導線を出すかどうかだけ**を決める。実際の拒否は operation 側が行う。
     */
    private function canWrite(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return in_array(
            Capability::SettingsManage->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($user),
            true,
        );
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    private function abortUnlessSuccess(Result $result): void
    {
        if ($result->isSuccess()) {
            return;
        }

        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, (string) $result->message);
    }
}

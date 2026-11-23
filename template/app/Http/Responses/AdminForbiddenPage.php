<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\Capability;
use App\Support\OperationCapabilities;
use App\Support\Role;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 権限の無い管理画面を開いたときの 403 を、管理画面の枠の中で見せる（UX 一周 23 周目 #6）。
 *
 * 以前は白地に「403 この操作には「利用者の管理」の権限が必要です（user.list）」だけで、
 * メニューも戻る道も無かった。ここで扱うのは「ログイン済みの人が、権限の無い管理画面を GET で開いた」
 * ときだけ。API・JSON・無効化されたアカウント（別の文言）は今までどおり。
 */
final class AdminForbiddenPage
{
    /** RoleOperationPolicy::denialReason() の権限不足の文（この形のときだけ扱う） */
    private const DENIAL = '/^この操作には「(?<label>[^」]+)」の権限が必要です（(?<operation>[a-z0-9_.]+)）$/u';

    public function render(HttpExceptionInterface $e, Request $request): ?Response
    {
        if ($e->getStatusCode() !== 403
            || ! $request->isMethod('GET')
            || $request->expectsJson() && ! $request->header('X-Inertia')
            || $request->user() === null
            || ! $request->is('admin', 'admin/*')
            || ! $request->user()->is_active) {
            return null;
        }

        preg_match(self::DENIAL, $e->getMessage(), $m);
        $capability = isset($m['operation']) ? OperationCapabilities::for($m['operation']) : ($e->getMessage() === 'この画面はサイトを作る人（制作者）だけが開けます。' ? Capability::SiteBuild : null);
        $roles = $capability === null ? [] : array_values(array_map(
            static fn (Role $role): string => $role->label(),
            array_filter(Role::cases(), static fn (Role $role): bool => $role->can($capability)),
        ));

        return Inertia::render('admin/Forbidden', [
            'adminScreen' => array_replace(Inertia::getShared('adminScreen', []), [
                'readOnly' => false,
                'readOnlyMessage' => null,
                'editing' => false,
            ]),
            // 誰なら見られるかを言う（「管理者だけ」と決め打ちしない。役割の名前は Role の表から）
            'message' => $roles === []
                ? 'この画面を見る権限がありません。'
                : 'この画面は'.implode('・', $roles).'だけが見られます。',
            'needed' => $m['label'] ?? $capability?->label() ?? 'この画面の管理',
            'backUrl' => route('dashboard'),
        ])->toResponse($request)->setStatusCode(403);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * サイトの作りの画面は、制作者だけが開ける（UX 一周 2026-09-28・メンテナの方針）。
 *
 * 投稿タイプ・カスタムフィールド・再利用コンポーネント・リダイレクト・見つからない URL・
 * 一括置換。店主（オーナー）のメニューから外すだけでなく、URL を直接開いても入れないようにする。
 * 店主が日常で使う口（記事を書けるようにする 1 クリック・フォームの入力項目の保存）は閉じない。
 */
final class RequireSiteBuild
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array(
                Capability::SiteBuild->value,
                app(RoleOperationPolicy::class)->capabilitiesFor($user),
                true,
            ),
            403,
            'この画面はサイトを作る人（制作者）だけが開けます。',
        );

        return $next($request);
    }
}

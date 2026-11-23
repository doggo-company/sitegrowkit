<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 本人の設定画面（プロフィール）。
 *
 * ## 自分でアカウントを消す口は持たない（STAGE #18A 追加要件 B）
 *
 * 利用者の停止は **オーナーが `/admin/users` から無効化**する、で統一する。
 * 本人が自分を消せると
 *
 * - 監査ログの actor が失われ、過去に誰が何をしたか追えなくなる
 * - 最後のオーナーが自分を消して、誰も管理できない CMS ができる
 *
 * という取り返しのつかない状態を作れてしまう。
 * 無効化なら行は残るので、履歴も権限の最低 1 人も守られる。
 */
class ProfileController extends Controller
{
    /** プロフィール設定画面を表示する。 */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /** プロフィール情報を更新する。 */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'プロフィールを更新しました']);

        return to_route('profile.edit');
    }
}

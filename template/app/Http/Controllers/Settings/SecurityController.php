<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /** セキュリティ設定画面を表示する。 */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            /* @chisel-2fa */
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            /* @end-chisel-2fa */
            /* @chisel-passkeys */
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            /* @end-chisel-passkeys */
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        /* @chisel-2fa */
        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
        /* @end-chisel-2fa */

        return Inertia::render('settings/Security', $props);
    }

    /** パスワードを変更する。 */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            'password' => $request->password,
        ]);

        /*
         * パスワード変更を **行動履歴へ残す**（STAGE #18A / Correction 2-6）。
         *
         * 記録そのものは `RecordAuthenticationActivity` が 1 か所で行う。
         * ここは「起きた」ことを知らせるだけで、Controller から
         * 監査へ直接書かない（記録口が増えると二重記録が生まれる）。
         *
         * この 1 行が無い間、listener は登録されているのに
         * **本番の経路からは一度も呼ばれていなかった**（Fortify の
         * 既定 Controller を使わず自前で更新しているため）。
         */
        PasswordUpdatedViaController::dispatch($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'パスワードを変更しました']);

        return back();
    }
}

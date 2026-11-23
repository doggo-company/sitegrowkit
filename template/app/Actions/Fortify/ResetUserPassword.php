<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $firstTime = $user->isPendingInvitation();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        // この要求で実際に設定できたときだけ、成功の文を切り替える。
        request()->attributes->set('first_password_setup', $firstTime);
    }
}

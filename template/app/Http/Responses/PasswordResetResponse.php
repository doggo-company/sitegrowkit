<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\PasswordResetResponse as FortifyPasswordResetResponse;

final class PasswordResetResponse extends FortifyPasswordResetResponse
{
    public function toResponse($request)
    {
        if ($request->attributes->get('first_password_setup') === true) {
            $this->status = 'パスワードを設定しました。';
        }

        return parent::toResponse($request);
    }
}

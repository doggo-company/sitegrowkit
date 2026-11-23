<?php

declare(strict_types=1);

return [
    // ログイン失敗とレート制限の文言（Fortify は trans('auth.failed') / auth.throttle を返す）。
    // どのメールアドレスが存在するかを推測させないため、原文と同じく理由をぼかす。
    'failed' => 'メールアドレスまたはパスワードが正しくありません。',
    'password' => 'パスワードが正しくありません。',
    'throttle' => 'ログインの試行回数が上限に達しました。:seconds 秒後にもう一度お試しください。',
];

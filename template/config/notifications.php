<?php

// Web Push (VAPID)。キーは `php artisan notifications:vapid-keys` で生成し .env へ置く。
// 秘密鍵をコード・Git に含めることは禁止（.agentcms/rules/security.md）。
return [
    'vapid' => [
        // Push Service への連絡先（mailto: または https: URL）
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.test'),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],
];

<?php

declare(strict_types=1);

return [
    // 設定画面で使用する検証ルールを日本語化する。未定義ルールは fallback_locale を使う。
    'confirmed' => ':attributeと確認用の入力が一致しません。',
    'current_password' => 'パスワードが正しくありません。',
    'email' => ':attributeには有効なメールアドレスを入力してください。',
    'max' => [
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'min' => [
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'password' => [
        'letters' => ':attributeには文字を1文字以上含めてください。',
        'mixed' => ':attributeには大文字と小文字を1文字以上ずつ含めてください。',
        'numbers' => ':attributeには数字を1文字以上含めてください。',
        'symbols' => ':attributeには記号を1文字以上含めてください。',
        'uncompromised' => 'この:attributeはデータ漏えいで確認されています。別の:attributeを使用してください。',
    ],
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeは必須です。',
    'string' => ':attributeは文字列で入力してください。',
    'unique' => 'この:attributeはすでに使用されています。',

    'custom' => [],

    'attributes' => [
        'code' => '確認コード',
        'current_password' => '現在のパスワード',
        'email' => 'メールアドレス',
        'name' => '名前',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード（確認）',
        'recovery_code' => 'リカバリーコード',
        // 画面のラベルに揃える。内部の語（slug）を利用者に見せない（WP 一周 2026-09-03）
        'slug' => 'URL',
        'title' => 'タイトル',
    ],
];

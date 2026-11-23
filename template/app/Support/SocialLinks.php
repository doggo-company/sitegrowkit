<?php

declare(strict_types=1);

namespace App\Support;

/**
 * サイト設定の SNS の欄から、公開面に出すリンクを作る（UX 一周 2026-09-28）。
 *
 * X / Instagram の URL の欄は、入れても公開面のどこにも出ていなかった。
 * 店の人は URL ではなく「@komorebi_cafe」やアカウント名だけを書くことが多いので、
 * **アカウント名だけでも**その SNS の URL にする。どちらでもないものは出さない
 * （壊れたリンクを配らない）。
 */
final class SocialLinks
{
    private const SERVICES = [
        'x_url' => ['label' => 'X', 'base' => 'https://x.com/'],
        'instagram_url' => ['label' => 'Instagram', 'base' => 'https://www.instagram.com/'],
    ];

    /**
     * @param  array<string, mixed>  $site
     * @return list<array{label: string, href: string}>
     */
    public static function from(array $site): array
    {
        $links = [];

        foreach (self::SERVICES as $key => $service) {
            $href = self::href(trim((string) ($site[$key] ?? '')), $service['base']);

            if ($href !== null) {
                $links[] = ['label' => $service['label'], 'href' => $href];
            }
        }

        return $links;
    }

    private static function href(string $value, string $base): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
        }

        $handle = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) === 1 ? $base.$handle : null;
    }
}

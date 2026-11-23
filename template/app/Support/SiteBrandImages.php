<?php

declare(strict_types=1);

namespace App\Support;

use Modules\Media\Models\Media;

/** ロゴとアイコンは公開画像だけをまとめて解決する。非公開ファイルのURLは出さない。 */
final class SiteBrandImages
{
    /**
     * @param  array<string, mixed>  $site
     * @return array{logo: ?Media, icon: ?Media}
     */
    public function resolve(array $site): array
    {
        $ids = [];
        foreach (['logo' => 'site_logo_id', 'icon' => 'site_icon_id'] as $name => $key) {
            $id = $site[$key] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[$name] = (int) $id;
            }
        }

        $media = $ids === [] ? collect() : Media::query()->whereKey(array_values($ids))
            ->where('kind', 'image')->where('visibility', 'public')->get()->keyBy('id');

        return [
            'logo' => $media->get($ids['logo'] ?? 0),
            'icon' => $media->get($ids['icon'] ?? 0),
        ];
    }
}

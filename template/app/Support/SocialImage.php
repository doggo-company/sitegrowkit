<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Media\Models\Media;

/** SNS 向け画像は公開画像だけ。添付ファイルや管理者専用 URL をメタ情報へ出さない。 */
final class SocialImage
{
    /** @param array<string, mixed> $site */
    public function resolve(array $site, ?Model $entry = null): ?Media
    {
        foreach ([$entry?->getAttribute('main_image_id'), $site['social_image_id'] ?? null] as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            $media = Media::query()->whereKey($id)->where('kind', 'image')->where('visibility', 'public')->first();
            if ($media !== null) {
                return $media;
            }
        }

        return null;
    }
}

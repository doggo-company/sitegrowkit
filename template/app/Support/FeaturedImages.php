<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Media\Models\Media;

/** 一覧の画像と派生物をまとめて読み、記事の数だけ問い合わせが増えるのを防ぐ。 */
final class FeaturedImages
{
    /**
     * @param  iterable<Model>  $entries
     * @return array<int, Media>
     */
    public function forEntries(iterable $entries): array
    {
        $references = [];
        foreach ($entries as $entry) {
            $id = $entry->getAttribute('main_image_id');
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $references[(int) $entry->getKey()] = (int) $id;
            }
        }

        if ($references === []) {
            return [];
        }

        // 添付ファイルや非公開画像は、公開用テンプレートから参照しない。
        $media = Media::query()->with('variants')->whereKey(array_values($references))
            ->where('kind', 'image')->where('visibility', 'public')->get()->keyBy('id');
        $images = [];
        foreach ($references as $entryId => $mediaId) {
            if (isset($media[$mediaId])) {
                $images[$entryId] = $media[$mediaId];
            }
        }

        return $images;
    }
}

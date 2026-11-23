<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaVisibility;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\Media\Models\Media;

/** 公開記事の実データだけからJSON-LDを組み立てる。著者や評価は推測しない。 */
final class ArticleStructuredData
{
    /** @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    public function forEntry(Model $entry, array $site, ?Media $image, string $canonical): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => strip_tags((string) $entry->getAttribute('title')),
            'mainEntityOfPage' => $canonical,
            'url' => $canonical,
            'inLanguage' => 'ja',
        ];
        foreach (['datePublished' => 'published_at', 'dateModified' => 'updated_at'] as $key => $attribute) {
            $date = $entry->getAttribute($attribute);
            if ($date instanceof DateTimeInterface) {
                $data[$key] = $date->format(DATE_ATOM);
            }
        }
        $description = $entry->getAttribute('excerpt');
        if (is_string($description) && trim($description) !== '') {
            $data['description'] = mb_substr(trim(strip_tags($description)), 0, 200);
        }
        $name = $site['site_name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            $data['publisher'] = ['@type' => 'Organization', 'name' => strip_tags($name)];
        }
        // 呼び出し元でも公開画像に限定する。単独利用時にも非公開画像は出さない。
        if ($image !== null && $image->kind === MediaKind::Image && $image->visibility === MediaVisibility::Public) {
            $data['image'] = url($image->url());
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * CMS の richtext body を公開側で表示するための唯一のサニタイズ経路。
 * Blade で {!! !!} を使ってよいのはこの出力だけ（.agentcms/rules/coding.md）。
 */
final class Purifier
{
    /**
     * 公開側で残す tag と属性（**ここが唯一の定義**）。
     *
     * 編集画面の書式ボタン（Phase 9.15 / STAGE 28D）は、**ここに在るものしか出さない**。
     * 出せば消えるものをボタンにすると、**編集中に見えたものが公開で消える**。
     * ずれていないことは `RichtextToolbarTest` が検査する。
     */
    public const ALLOWED = 'p,br,strong,em,u,s,blockquote,ul,ol,li,h2,h3,h4,a[href|rel],img[src|alt|width|height]';

    private ?HTMLPurifier $purifier = null;

    /**
     * 残る tag の名前だけ（属性を落とした形）。
     *
     * @return list<string>
     */
    public static function allowedTags(): array
    {
        // `explode()` は既に list を返すので、`array_values()` を重ねない
        return array_map(
            static fn (string $part): string => (string) preg_replace('/\[.*$/', '', $part),
            explode(',', self::ALLOWED),
        );
    }

    public function clean(string $html): string
    {
        return $this->instance()->purify($html);
    }

    private function instance(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('HTML.Nofollow', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', []);
        $config->set('AutoFormat.RemoveEmpty', true);

        $cacheDir = storage_path('app/purifier');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        return $this->purifier = new HTMLPurifier($config);
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HTML Component の制限付き HTML（Phase 9.1D・owner 決定 3）。
 *
 * richtext（`Purifier`）よりは広いが、**コードの逃げ道にはしない**。
 * 禁止: script / on* / javascript: / 危険な data: / 任意 iframe / style 注入。
 *
 * JavaScript が必要な場合は Custom Registered Component（project code + deploy）を使う。
 * ここに escape hatch を作らない。
 */
final class RestrictedHtml
{
    /**
     * 表や注釈など「文章の中身」を組める程度に許す。
     *
     * ページの骨組み（section / header / footer）は **HTML ではなく component** で作る。
     * ここに構造用の要素を足すと、Visual Editor から編集できない領域が増えるため。
     * `style` 属性・`class` 属性も許可しない（見た目は token と styleSchema で決める）。
     */
    private const ALLOWED =
        'div,h2[id],h3[id],h4[id],h5,h6,p,span,br,hr,'.
        'strong,em,b,i,u,s,small,sub,sup,code,pre,blockquote,cite,'.
        'ul,ol,li,dl,dt,dd,'.
        'table,thead,tbody,tfoot,tr,th[scope|colspan|rowspan],td[colspan|rowspan],caption,'.
        'a[href|rel|title],img[src|alt|width|height],abbr[title]';

    private ?HTMLPurifier $purifier = null;

    public function clean(string $html): string
    {
        return $this->instance()->purify($html);
    }

    /**
     * sanitize で内容が落ちたか（保存前の警告に使う）。
     *
     * @return array{clean: string, removed: bool}
     */
    public function inspect(string $html): array
    {
        $clean = $this->clean($html);

        return [
            'clean' => $clean,
            'removed' => $this->normalize($clean) !== $this->normalize($html),
        ];
    }

    private function normalize(string $html): string
    {
        return preg_replace('/\s+/', ' ', trim($html)) ?? $html;
    }

    private function instance(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('HTML.Nofollow', true);
        // javascript: / data:text/html / vbscript: などはここで落ちる
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('Attr.AllowedFrameTargets', []);
        $config->set('CSS.AllowedProperties', []);
        $config->set('AutoFormat.RemoveEmpty', false);

        $cacheDir = storage_path('app/purifier');

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $config->set('Cache.SerializerPath', $cacheDir);

        return $this->purifier = new HTMLPurifier($config);
    }
}

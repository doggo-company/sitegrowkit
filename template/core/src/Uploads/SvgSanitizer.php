<?php

declare(strict_types=1);

namespace AgentCms\Core\Uploads;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

/**
 * SVG のサニタイズ（Phase 8.5-F / F-UX5 — SECURITY BLOCKER 相当の扱い）。
 *
 * SVG は **能動的コンテンツを含み得る**（script / event handler / javascript: URL /
 * foreignObject に埋め込んだ HTML / 外部参照 / XML entity 展開）。
 * 拡張子や申告 MIME だけで許可すると保存済み XSS になるため、
 * **保存するのは常にこのクラスが再構築した安全な文字列だけ**にする。
 *
 * 方針:
 *  - allowlist（要素・属性）。知らないものは落とす
 *  - DTD / ENTITY を含むものは**サニタイズせず拒否**（billion laughs・外部実体の回避）
 *  - 外部ネットワーク参照は一切解決しない（LIBXML_NONET）
 *  - `href` は同一文書内の fragment（`#id`）と、安全な `data:image/*` のみ許可
 *  - 出力に `<script>` や `on*` が残っていないことを最後にもう一度検査する
 */
final class SvgSanitizer
{
    /** 許可する要素（描画に必要な最小限） */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern',
        'clipPath', 'mask', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
        'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
        'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
        'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset',
        'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence',
    ];

    /**
     * 許可する属性。`on*`（event handler）は**この一覧に無いので必ず落ちる**。
     * `style` は url() や expression を持ち込めるため許可しない。
     */
    private const ALLOWED_ATTRIBUTES = [
        'id', 'class', 'xmlns', 'xmlns:xlink', 'version', 'viewBox', 'preserveAspectRatio',
        'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'gradientTransform', 'gradientUnits', 'patternUnits',
        'clipPathUnits', 'maskUnits', 'markerWidth', 'markerHeight', 'refX', 'refY', 'orient',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-miterlimit', 'opacity', 'color', 'stop-color', 'stop-opacity', 'offset',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'letter-spacing', 'word-spacing', 'writing-mode',
        'clip-path', 'clip-rule', 'mask', 'marker-start', 'marker-mid', 'marker-end',
        'filter', 'in', 'in2', 'result', 'stdDeviation', 'dx', 'dy', 'values', 'type',
        'mode', 'operator', 'k1', 'k2', 'k3', 'k4', 'scale', 'xChannelSelector',
        'yChannelSelector', 'baseFrequency', 'numOctaves', 'seed', 'flood-color',
        'flood-opacity', 'lighting-color', 'surfaceScale', 'specularConstant',
        'specularExponent', 'diffuseConstant', 'azimuth', 'elevation', 'pointsAtX',
        'pointsAtY', 'pointsAtZ', 'limitingConeAngle', 'radius', 'tableValues',
        'slope', 'intercept', 'amplitude', 'exponent', 'href', 'xlink:href',
        'aria-hidden', 'aria-label', 'aria-labelledby', 'role', 'focusable',
    ];

    /** 属性値に現れたら危険とみなす URL scheme（大小・空白・実体参照を潰してから判定する） */
    private const DANGEROUS_SCHEMES = ['javascript:', 'vbscript:', 'data:text/html', 'data:image/svg'];

    /**
     * @return array{content: string, width: int|null, height: int|null}
     *
     * @throws ValidationException サニタイズしても安全にできない場合
     */
    public function sanitize(string $svg, string $fieldKey = 'file'): array
    {
        $svg = trim($svg);

        if ($svg === '') {
            $this->reject($fieldKey, 'SVG ファイルが空です');
        }

        // DTD / ENTITY は展開攻撃の入口。書き換えではなく拒否する
        if (preg_match('/<!DOCTYPE|<!ENTITY|<!\[CDATA\[\s*<script/i', $svg) === 1) {
            $this->reject($fieldKey, 'この SVG は安全に読み込めない定義（DOCTYPE / ENTITY）を含んでいます');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: 外部参照を一切取りに行かない
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
            $this->reject($fieldKey, 'SVG として読み込めないファイルです');
        }

        $root = $document->documentElement;

        if (strtolower($root->localName) !== 'svg') {
            $this->reject($fieldKey, 'ルート要素が svg ではありません');
        }

        $this->clean($root);

        $output = $document->saveXML($root);

        if (! is_string($output) || $output === '') {
            $this->reject($fieldKey, 'SVG のサニタイズに失敗しました');
        }

        // 念のための最終検査（allowlist の取りこぼしを出荷しない）
        if (preg_match('/<\s*script|\son[a-z]+\s*=|javascript:/i', $output) === 1) {
            $this->reject($fieldKey, 'この SVG には安全に取り除けないスクリプトが含まれています');
        }

        return [
            'content' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".$output."\n",
            'width' => $this->dimension($root, 'width'),
            'height' => $this->dimension($root, 'height'),
        ];
    }

    /** 許可されていない要素・属性を再帰的に取り除く */
    private function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! $this->isAllowedElement($child)) {
                    $element->removeChild($child);

                    continue;
                }

                $this->clean($child);

                continue;
            }

            // コメント・処理命令は落とす（コメント内に仕込む手口を避ける）
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $element->removeChild($child);
            }
        }

        $this->cleanAttributes($element);
    }

    private function isAllowedElement(DOMElement $element): bool
    {
        $name = $element->localName;

        foreach (self::ALLOWED_ELEMENTS as $allowed) {
            if (strcasecmp($name, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }

    private function cleanAttributes(DOMElement $element): void
    {
        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes ?? []);

        foreach ($attributes as $attribute) {
            $name = $attribute->nodeName;

            if (! $this->isAllowedAttribute($name)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (! $this->isSafeValue($name, (string) $attribute->nodeValue)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private function isAllowedAttribute(string $name): bool
    {
        // event handler は問答無用で落とす
        if (stripos($name, 'on') === 0) {
            return false;
        }

        foreach (self::ALLOWED_ATTRIBUTES as $allowed) {
            if (strcasecmp($name, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }

    /** URL を持つ属性は fragment（#id）と安全な data:image のみ許可する */
    private function isSafeValue(string $name, string $value): bool
    {
        // 実体参照・制御文字・空白で難読化された scheme を潰してから判定する
        $normalized = strtolower(preg_replace('/[\s\x00-\x1f]|&#x?[0-9a-f]+;?/i', '', $value) ?? '');

        foreach (self::DANGEROUS_SCHEMES as $scheme) {
            if (str_contains($normalized, $scheme)) {
                return false;
            }
        }

        $isReference = in_array(strtolower($name), ['href', 'xlink:href'], true);

        if ($isReference) {
            // 外部参照（http / file / 相対パス）は許可しない
            return str_starts_with($normalized, '#')
                || str_starts_with($normalized, 'data:image/png')
                || str_starts_with($normalized, 'data:image/jpeg')
                || str_starts_with($normalized, 'data:image/gif')
                || str_starts_with($normalized, 'data:image/webp');
        }

        // url(...) で外部を参照させない（filter / clip-path 等）
        if (str_contains($normalized, 'url(') && ! str_contains($normalized, 'url(#')) {
            return false;
        }

        return true;
    }

    /** width / height 属性、無ければ viewBox から寸法を取る */
    private function dimension(DOMElement $root, string $axis): ?int
    {
        $raw = $root->getAttribute($axis);

        if ($raw !== '' && preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $raw, $m) === 1) {
            $value = (int) round((float) $m[1]);

            return $value > 0 ? $value : null;
        }

        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));

        if (is_array($viewBox) && count($viewBox) === 4) {
            $value = (int) round((float) $viewBox[$axis === 'width' ? 2 : 3]);

            return $value > 0 ? $value : null;
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $fieldKey, string $message): never
    {
        throw ValidationException::withMessages([$fieldKey => $message]);
    }

    public static function describe(DOMNode $node): string
    {
        return $node->nodeName;
    }
}

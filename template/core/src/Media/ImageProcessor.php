<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

use AgentCms\Core\Uploads\SvgSanitizer;
use Illuminate\Validation\ValidationException;

/**
 * 画像の実体検査と WebP 生成（GD のみ・追加依存なし — ADR-0006）。
 * 共用レンタルサーバーでも動くよう Capability Detection を行い、
 * WebP を生成できない環境では静かにフォールバックする。
 */
final class ImageProcessor
{
    /**
     * 画像として実際に解釈できるか検査し、寸法を返す（不正・破損画像は拒否）。
     *
     * @return array{width: int, height: int, mime: string}
     *
     * @throws ValidationException
     */
    public function inspect(string $absolutePath, string $fieldKey = 'file'): array
    {
        // SVG は raster ではないので getimagesize では測れない。
        // ここで sanitizer を通すことで **保存前に危険な SVG を弾く**（F-UX5）
        if ($this->isSvgFile($absolutePath)) {
            $raw = @file_get_contents($absolutePath);

            if ($raw === false) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルを読み込めませんでした']);
            }

            $safe = (new SvgSanitizer)->sanitize($raw, $fieldKey);
            $max = (int) config('agentcms.uploads.max_dimension');
            $width = $safe['width'] ?? 0;
            $height = $safe['height'] ?? 0;

            if ($width > $max || $height > $max) {
                throw ValidationException::withMessages([
                    $fieldKey => "画像が大きすぎます（最大 {$max}px、この画像は {$width}×{$height}px）",
                ]);
            }

            return ['width' => $width, 'height' => $height, 'mime' => 'image/svg+xml'];
        }

        $info = @getimagesize($absolutePath);

        if ($info === false) {
            throw ValidationException::withMessages([
                $fieldKey => '画像として読み込めないファイルです（破損している可能性があります）',
            ]);
        }

        [$width, $height] = $info;
        $max = (int) config('agentcms.uploads.max_dimension');

        if ($width < 1 || $height < 1) {
            throw ValidationException::withMessages([$fieldKey => '画像の寸法を取得できませんでした']);
        }

        if ($width > $max || $height > $max) {
            throw ValidationException::withMessages([
                $fieldKey => "画像が大きすぎます（最大 {$max}px、この画像は {$width}×{$height}px）",
            ]);
        }

        return ['width' => $width, 'height' => $height, 'mime' => (string) $info['mime']];
    }

    /**
     * 同じディレクトリに .webp 変換を生成する。生成できた場合 true。
     * 元画像が jpeg / png 以外、または環境に WebP 能力が無い場合は false（フォールバック）。
     */
    public function makeWebp(string $absolutePath): bool
    {
        // SVG はベクタなので WebP 変換しない
        if ($this->isSvgFile($absolutePath)) {
            return false;
        }

        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromjpeg')) {
            return false;
        }

        $mime = @mime_content_type($absolutePath);

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            default => false, // webp/avif は変換不要（そのまま配信）
        };

        if ($image === false) {
            return false;
        }

        if ($mime === 'image/png') {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $ok = imagewebp($image, self::webpPath($absolutePath), 80);
        imagedestroy($image);

        return $ok;
    }

    /** 元パスに対応する WebP パス（拡張子置換） */
    public static function webpPath(string $path): string
    {
        return preg_replace('/\.[a-zA-Z0-9]+$/', '.webp', $path) ?? $path.'.webp';
    }

    /** 拡張子ではなく内容で SVG かを判定する */
    public function isSvgFile(string $absolutePath): bool
    {
        $mime = @mime_content_type($absolutePath);

        if ($mime === 'image/svg+xml' || $mime === 'image/svg') {
            return true;
        }

        $head = (string) @file_get_contents($absolutePath, false, null, 0, 512);

        return stripos($head, '<svg') !== false;
    }
}

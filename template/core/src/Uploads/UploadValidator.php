<?php

declare(strict_types=1);

namespace AgentCms\Core\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 画像アップロードの検証と保存。
 * - 拡張子・MIME（内容ベース）両方の許可リスト検査
 * - SVG は **SvgSanitizer を通した安全な内容だけ**を保存する（拡張子では許可しない）
 * - ファイル名は必ず再生成し、実行可能領域外（storage/app/public）へ保存
 */
final class UploadValidator
{
    public function __construct(private readonly SvgSanitizer $svg = new SvgSanitizer) {}

    /** 拡張子と内容 MIME の両方が SVG を示すか */
    public function isSvg(string $extension, string $mime): bool
    {
        return $extension === 'svg' || $mime === 'image/svg+xml';
    }

    /**
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $fieldKey = 'file', ?string $dir = null): string
    {
        $allowedExtensions = (array) config('agentcms.uploads.allowed_extensions');
        $allowedMimes = (array) config('agentcms.uploads.allowed_mimes');
        $maxKilobytes = (int) config('agentcms.uploads.max_kilobytes');

        $extension = strtolower($file->getClientOriginalExtension());
        // MIME はクライアント申告ではなく内容（finfo）から判定する
        $mime = (string) $file->getMimeType();

        if (! in_array($extension, $allowedExtensions, true) || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                $fieldKey => '許可されていないファイル形式です（許可: '.implode(', ', $allowedExtensions).'）',
            ]);
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            $maxMegabytes = $maxKilobytes / 1024;
            throw ValidationException::withMessages([
                $fieldKey => "画像は{$maxMegabytes}MBまでアップロードできます。",
            ]);
        }

        $name = Str::uuid()->toString().'.'.$extension;
        $directory = $dir ?? (string) config('agentcms.uploads.dir');
        $disk = (string) config('agentcms.uploads.disk');

        // SVG は受け取った bytes をそのまま置かない。
        // sanitizer が再構築した内容だけを保存する（ここが唯一の入口）
        if ($this->isSvg($extension, $mime)) {
            $raw = @file_get_contents($file->getRealPath());

            if ($raw === false) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルを読み込めませんでした']);
            }

            $safe = $this->svg->sanitize($raw, $fieldKey);
            $path = $directory.'/'.$name;

            if (! Storage::disk($disk)->put($path, $safe['content'])) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの保存に失敗しました']);
            }

            return $path;
        }

        $path = $file->storeAs($directory, $name, $disk);

        if ($path === false) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの保存に失敗しました']);
        }

        return $path;
    }
}

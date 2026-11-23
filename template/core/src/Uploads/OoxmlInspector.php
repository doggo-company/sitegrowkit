<?php

declare(strict_types=1);

namespace AgentCms\Core\Uploads;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * OOXML（docx / xlsx / pptx）を **展開せずに** 確かめる（Phase 9.5-D / SPEC §3.8）。
 *
 * ## なぜ展開しないのか
 *
 * 展開してから調べると、その時点で **zip bomb を踏む**。
 * ZIP の中央ディレクトリには entry 名・圧縮後サイズ・展開後サイズが入っているので、
 * **読むだけで**上限判定ができる。
 *
 * ## 何を確かめるか
 *
 * | 検査 | 目的 |
 * | --- | --- |
 * | entry 数 / 総展開量 / 圧縮率 | zip bomb を拒否する |
 * | 暗号化 entry | 中身を確認できないものを通さない |
 * | path traversal | `..` や絶対パスを含む entry を拒否する |
 * | `[Content_Types].xml` | ただの ZIP を OOXML と偽れないようにする |
 * | 形式固有 entry | docx を xlsx と偽れないようにする |
 * | マクロ | `vbaProject.bin` を含むものを拒否する |
 */
final class OoxmlInspector
{
    /** 形式ごとに必ず在るべき entry */
    private const REQUIRED_ENTRY = [
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
        'pptx' => 'ppt/presentation.xml',
    ];

    private const CONTENT_TYPES = '[Content_Types].xml';

    private const MACRO_ENTRY = 'vbaProject.bin';

    /**
     * @throws ValidationException
     */
    public function assert(string $path, string $extension, string $fieldKey = 'file'): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }

        try {
            $this->assertBounded($zip, $fieldKey);
            $this->assertShape($zip, $extension, $fieldKey);
        } finally {
            $zip->close();
        }
    }

    /** zip bomb と traversal を、**中央ディレクトリを読むだけ**で拒否する */
    private function assertBounded(ZipArchive $zip, string $fieldKey): void
    {
        $maxEntries = (int) config('agentcms.files.ooxml.max_entries', 1000);
        $maxBytes = (int) config('agentcms.files.ooxml.max_uncompressed_bytes', 200 * 1024 * 1024);
        $maxRatio = (int) config('agentcms.files.ooxml.max_compression_ratio', 100);

        if ($zip->numFiles > $maxEntries) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
            }

            $name = (string) $stat['name'];

            // 絶対パス・親への遡上・ドライブレター・バックスラッシュを拒否
            if (
                str_starts_with($name, '/')
                || str_contains($name, '..')
                || str_contains($name, '\\')
                || preg_match('/^[A-Za-z]:/', $name) === 1
            ) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
            }

            // 暗号化されていると中身を確かめられない
            if ((int) $stat['encryption_method'] !== 0) {
                throw ValidationException::withMessages([
                    $fieldKey => 'パスワード付きのファイルは登録できません',
                ]);
            }

            $size = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];
            $total += $size;

            if ($total > $maxBytes) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
            }

            if ($compressed > 0 && $size / $compressed > $maxRatio) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
            }
        }
    }

    /** ただの ZIP・別形式・マクロ付きを拒否する */
    private function assertShape(ZipArchive $zip, string $extension, string $fieldKey): void
    {
        if ($zip->locateName(self::CONTENT_TYPES) === false) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }

        $required = self::REQUIRED_ENTRY[$extension] ?? null;

        if ($required === null || $zip->locateName($required) === false) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_ends_with($name, self::MACRO_ENTRY)) {
                throw ValidationException::withMessages([
                    $fieldKey => 'マクロを含むファイルは登録できません',
                ]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * 一般ファイルの実体検査（Phase 9.5-D / ADR-0018・SPEC §3.8）。
 *
 * **画像とは別の経路**である。`ImageProcessor` は「画像として読めるか」を見るが、
 * ここは「宣言された拡張子のとおりの中身か」を見る。
 *
 * ## 拡張子だけを信用しない
 *
 * 拡張子と **内容 MIME の両方**が許可表に一致することを要求する。
 * そのうえで、形式ごとに中身の特徴を確かめる。
 *
 * | 形式 | 確かめること |
 * | --- | --- |
 * | PDF | 先頭 `%PDF-` と末尾 1KB 以内の `%%EOF` |
 * | TXT / CSV | signature が無いので「**何でないか**」で守る（§`assertText`） |
 * | OOXML | ZIP の中央ディレクトリだけを読み、**展開せずに** bounded で判定 |
 *
 * PDF の中身（オブジェクト構造や JavaScript の有無）は解析しない。
 * 配信が常に `attachment` + `sandbox` で、**ブラウザに解釈させない**からである。
 */
final class FileInspector
{
    /** テキスト判定で読む先頭バイト数 */
    private const TEXT_SAMPLE_BYTES = 8192;

    /**
     * テキストとして受け付けない先頭バイト列（実行形式・別フォーマットの偽装）。
     *
     * @var list<string>
     */
    private const BINARY_MAGIC = ['MZ', "\x7fELF", '#!', "PK\x03\x04", '%PDF', "\xca\xfe\xba\xbe"];

    /**
     * テキストに含まれていてはいけない断片（HTML / PHP として解釈され得る）。
     *
     * @var list<string>
     */
    private const ACTIVE_MARKERS = ['<?php', '<?=', '<script', '<html', '<!doctype html'];

    /** UTF-16 / UTF-32 の BOM。表計算やブラウザで別の解釈をされ得るので受けない */
    private const REJECTED_BOMS = ["\xff\xfe", "\xfe\xff", "\x00\x00\xfe\xff", "\xff\xfe\x00\x00"];

    public function __construct(private readonly OoxmlInspector $ooxml = new OoxmlInspector) {}

    /**
     * 受け入れてよいか。
     *
     * @return array{extension: string, mime: string}
     *
     * @throws ValidationException
     */
    public function inspect(UploadedFile $file, string $fieldKey = 'file'): array
    {
        // **実体を読めないなら、何ひとつ確かめられない。** 先に止める。
        // ここを抜けると TXT / CSV の判定が「空の内容」を見て通してしまう
        $path = $file->getRealPath();

        if ($path === false || $path === '' || ! is_readable($path)) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルを読み込めませんでした']);
        }

        /** @var array<string, list<string>> $allowed */
        $allowed = (array) config('agentcms.files.allowed');

        $extension = strtolower($file->getClientOriginalExtension());
        // MIME はクライアント申告ではなく内容（finfo）から判定する
        $mime = (string) $file->getMimeType();

        if (! isset($allowed[$extension])) {
            throw ValidationException::withMessages([
                $fieldKey => 'この形式のファイルは登録できません（登録できるのは '
                    .$this->allowedLabel($allowed).' です）',
            ]);
        }

        if (! in_array($mime, $allowed[$extension], true)) {
            throw ValidationException::withMessages([
                $fieldKey => 'ファイルの中身が拡張子と一致しません',
            ]);
        }

        $this->assertSize($file, $fieldKey);

        match ($extension) {
            'pdf' => $this->assertPdf($path, $fieldKey),
            'txt', 'csv' => $this->assertText($path, $fieldKey),
            'docx', 'xlsx', 'pptx' => $this->ooxml->assert($path, $extension, $fieldKey),
            default => null,
        };

        return ['extension' => $extension, 'mime' => $mime];
    }

    /** @param array<string, list<string>> $allowed */
    private function allowedLabel(array $allowed): string
    {
        $labels = [
            'pdf' => 'PDF', 'txt' => 'テキスト', 'csv' => 'CSV',
            'docx' => 'Word', 'xlsx' => 'Excel', 'pptx' => 'PowerPoint',
        ];

        return implode('・', array_map(
            static fn (string $ext): string => $labels[$ext] ?? strtoupper($ext),
            array_keys($allowed),
        ));
    }

    private function assertSize(UploadedFile $file, string $fieldKey): void
    {
        $max = (int) config('agentcms.files.max_kilobytes', 20480);

        if ($file->getSize() > $max * 1024) {
            $mb = (int) round($max / 1024);

            throw ValidationException::withMessages([
                $fieldKey => "ファイルサイズが上限（{$mb}MB）を超えています",
            ]);
        }
    }

    /**
     * PDF は先頭と末尾だけを見る。
     *
     * `%%EOF` が末尾ちょうどとは限らない（線形化や追記で後ろに余白が付く）ので、
     * 末尾 1KB の範囲で探す。
     */
    private function assertPdf(string $path, string $fieldKey): void
    {
        $head = (string) @file_get_contents($path, length: 5);

        if ($head !== '%PDF-') {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }

        $size = (int) @filesize($path);
        $offset = max(0, $size - 1024);
        $tail = (string) @file_get_contents($path, offset: $offset);

        if (! str_contains($tail, '%%EOF')) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }
    }

    /**
     * TXT / CSV には signature が無いので「何でないか」で守る。
     *
     * **文字コードは変換しない。** 判定に通ったバイト列をそのまま保存する
     * （変換すると原本と食い違い、利用者が元へ戻せない）。
     * CP932 を許すのは、Excel が書き出す CSV が CP932 のままであることが普通だからである。
     */
    private function assertText(string $path, string $fieldKey): void
    {
        $sample = (string) @file_get_contents($path, length: self::TEXT_SAMPLE_BYTES);

        foreach (self::REJECTED_BOMS as $bom) {
            if (str_starts_with($sample, $bom)) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
            }
        }

        if (str_contains($sample, "\0")) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
        }

        foreach (self::BINARY_MAGIC as $magic) {
            if (str_starts_with($sample, $magic)) {
                throw ValidationException::withMessages([$fieldKey => 'ファイルの中身が拡張子と一致しません']);
            }
        }

        $lower = strtolower($sample);

        foreach (self::ACTIVE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                throw ValidationException::withMessages([
                    $fieldKey => 'ファイルの中身が拡張子と一致しません',
                ]);
            }
        }

        // UTF-8（BOM 付きを含む）→ CP932 の順で試す。どちらでもなければ受けない
        if (! mb_check_encoding($sample, 'UTF-8') && ! mb_check_encoding($sample, 'CP932')) {
            throw ValidationException::withMessages([
                $fieldKey => '文字コードを判別できません（UTF-8 または Shift_JIS で保存してください）',
            ]);
        }
    }
}

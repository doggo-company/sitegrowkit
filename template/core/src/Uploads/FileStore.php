<?php

declare(strict_types=1);

namespace AgentCms\Core\Uploads;

use AgentCms\Core\Media\MediaKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 検査を通った一般ファイルを private disk へ置く（Phase 9.5-D / SPEC §3.1）。
 *
 * `UploadValidator` は画像専用（SVG の sanitize と画像 allowlist を持つ）なので、
 * 一般ファイルは別経路にする。**allowlist も保存先も配信も共有しない。**
 *
 * ## public disk へ置かない
 *
 * 一般ファイルを public disk へ置くと、直リンクで開ける経路ができる。
 * 直リンク = ブラウザが中身を解釈する経路である。配信は必ず controller を通す。
 *
 * ## 名前は再生成する
 *
 * 保存名は UUID。利用者がアップロードした名前は `media.original_name` に持ち、
 * **配信のときだけ**使う（パスの一部にしない）。
 */
final class FileStore
{
    /** `media.original_name` に入る長さ（列は 180） */
    private const MAX_ORIGINAL_NAME = 180;

    public function disk(): string
    {
        return (string) config('agentcms.files.disk', 'local');
    }

    /**
     * @return string 保存したパス
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $extension, string $fieldKey = 'file'): string
    {
        $directory = MediaKind::File->directory();
        $name = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $name, $this->disk());

        if ($path === false) {
            throw ValidationException::withMessages([$fieldKey => 'ファイルの保存に失敗しました']);
        }

        return $path;
    }

    /**
     * 表示・ダウンロード名の素。
     *
     * 制御文字とパス区切りは**ここで**落とす。日本語はそのまま残す
     * （配信時に RFC 5987 で符号化するので、ここで ASCII へ潰さない）。
     */
    public function originalName(UploadedFile $file): string
    {
        $raw = $file->getClientOriginalName();
        $safe = (string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '', $raw);
        $safe = trim($safe);

        return $safe === ''
            ? 'file'
            : mb_substr($safe, 0, self::MAX_ORIGINAL_NAME);
    }
}

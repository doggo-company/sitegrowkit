<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Media;

/**
 * 取ってきた 1 件（Phase 9.14 / STAGE 27E-3・SPEC §C.6）。
 *
 * ## 名前は運ぶが、信じない
 *
 * `filename` は取得先の URL から取った文字列で、**他人のサイトが決めた名前**である。
 * 種類の判定はこれではなく実体（`finfo`）が決める — 既存の upload 経路
 * （`UploadValidator` / `FileInspector`）が拡張子と中身の食い違いを弾く。
 * 名前を運ぶのは、人が管理画面で見たときに元のファイル名が読めるようにするためだけ。
 */
final class FetchedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
    ) {}

    /** 一時ファイルを片付ける。取り込めても取り込めなくても、最後に呼ぶ */
    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}

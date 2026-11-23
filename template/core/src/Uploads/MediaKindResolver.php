<?php

declare(strict_types=1);

namespace AgentCms\Core\Uploads;

use AgentCms\Core\Media\MediaKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * 受け取ったものが画像か一般ファイルかを決める（Phase 9.5-D / SPEC §8.1）。
 *
 * **利用者に「これは画像ですか」と聞かない。** アップロード欄は 1 つで、
 * サーバーが判定する。
 *
 * ## 拡張子で振り分け、中身で確かめる
 *
 * ここは「どちらの検査へ回すか」を決めるだけである。中身が拡張子のとおりかは、
 * 振り分けた先（`ImageProcessor` / `FileInspector`）が確かめる。
 * `.png` と名乗る PDF は画像側へ回り、そこで拒否される。
 */
final class MediaKindResolver
{
    /**
     * @throws ValidationException 画像でも一般ファイルでもない拡張子
     */
    public function resolve(UploadedFile $file, string $fieldKey = 'file'): MediaKind
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, $this->imageExtensions(), true)) {
            return MediaKind::Image;
        }

        if (array_key_exists($extension, $this->fileExtensions())) {
            return MediaKind::File;
        }

        throw ValidationException::withMessages([$fieldKey => $this->rejection()]);
    }

    /** @return list<string> */
    private function imageExtensions(): array
    {
        return array_values(array_map(
            static fn (mixed $extension): string => strtolower((string) $extension),
            (array) config('agentcms.uploads.allowed_extensions'),
        ));
    }

    /** @return array<string, mixed> */
    private function fileExtensions(): array
    {
        return (array) config('agentcms.files.allowed');
    }

    /**
     * 何なら登録できるのかを、拡張子ではなく **利用者の言葉**で伝える。
     *
     * 「jpg, jpeg, png, ...」と並べても、利用者は自分のファイルが該当するか
     * 判断できないことがある。
     */
    private function rejection(): string
    {
        $labels = [
            'pdf' => 'PDF', 'txt' => 'テキスト', 'csv' => 'CSV',
            'docx' => 'Word', 'xlsx' => 'Excel', 'pptx' => 'PowerPoint',
        ];

        $files = implode('・', array_map(
            static fn (string $extension): string => $labels[$extension] ?? strtoupper($extension),
            array_keys($this->fileExtensions()),
        ));

        return "この形式のファイルは登録できません（登録できるのは 画像と {$files} です）";
    }
}

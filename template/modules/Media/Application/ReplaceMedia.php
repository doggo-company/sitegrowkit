<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\VariantGenerator;
use AgentCms\Core\Uploads\FileInspector;
use AgentCms\Core\Uploads\FileStore;
use AgentCms\Core\Uploads\MediaKindResolver;
use AgentCms\Core\Uploads\UploadValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use Throwable;

/**
 * メディアの差し替え（F-UX6 / Phase 9.1B、Phase 9.5-D で kind 対応）。
 *
 * **id は維持する** — 参照しているコンテンツを壊さないため。
 *
 * ## 種別は変えられない（SPEC §3.7）
 *
 * `media` / `images` field は「kind=image の ID」を、`file` field は
 * 「kind=file の ID」を指す契約になっている。差し替えで kind が変わると
 * **既に保存されている参照の契約が後から破られる**。参照側は ID しか持たないので、
 * 気づく手段が無い。画面で隠すだけにせず、ここで拒否する。
 *
 * ## 旧側に触れるのは commit のあとだけ（SPEC §5）
 *
 * ① 種別の一致を確認 → ② 新実体を検査 → ③ **新しいパスへ**保存 →
 * ④ 新しい派生物を作る → ⑤ DB を更新 → ⑥ **成功後に**旧原本と旧派生物を消す。
 *
 * ⑤ より前で落ちたら旧側はそのまま残る。新側だけを片付ける。
 */
final class ReplaceMedia implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetMedia $get,
        private readonly UploadValidator $uploads,
        private readonly ImageProcessor $processor,
        private readonly OperationAudit $outcome,
        private readonly MediaKindResolver $kinds,
        private readonly FileInspector $files,
        private readonly FileStore $store,
        private readonly VariantGenerator $variants,
    ) {}

    public function operationId(): string
    {
        return 'media.replace';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $id, UploadedFile $file, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $media = $this->get->find($id, $context);

        if ($media instanceof Result) {
            return $this->outcome->record($this, $context, $media, resourceType: 'media', resourceId: (string) $id);
        }

        // 1) 種別が一致しないなら、実体に触れる前に止める
        $kind = $this->kinds->resolve($file);

        if ($kind !== $media->kind) {
            return $this->outcome->record($this, $context, Result::validationError(
                ['file' => [$this->mismatch($media->kind, $kind)]],
            ), resourceType: 'media', resourceId: (string) $id);
        }

        // 2) 旧側の姿を**新しい実体に触れる前に**控える。
        //    ここを保存のあとに置くと、控えるだけの失敗で新しい実体が孤児になる
        $old = [
            'disk' => $media->diskName(),
            'path' => (string) $media->path,
            // 旧実装が作った `.webp`（拡張子置換）も片付ける対象である
            'legacy_webp' => (bool) $media->has_webp,
            'variants' => array_values($media->variants()->get(['id', 'media_id', 'disk', 'path'])->all()),
        ];

        [$attributes, $newVariants] = $kind === MediaKind::Image
            ? $this->prepareImage($file)
            : $this->prepareFile($file);

        $newDisk = (string) $attributes['disk'];
        $newPath = (string) $attributes['path'];

        try {
            // 原本の差し替えと**旧派生物の行の削除**だけを 1 つにする。
            // 新しい派生物の記録はここに入れない — 記録できなかっただけで
            // 差し替えごと巻き戻るのは、派生物の位置づけに合わない（SPEC §5 / D7）
            DB::transaction(function () use ($media, $attributes, $old): void {
                $media->update($attributes);

                // 旧派生物の行は、新しい実体に対応しないので必ず落とす
                foreach ($old['variants'] as $variant) {
                    $variant->delete();
                }
            });
        } catch (Throwable $e) {
            // 5) より前で落ちた。**旧側には一切触れない**
            Storage::disk($newDisk)->delete($newPath);
            $this->variants->discard($newVariants);

            throw $e;
        }

        // 6) ここまで成功して初めて旧側を片付ける
        $this->forget($old);

        // 7) 新しい派生物の記録は best-effort。落ちても差し替えは成立している
        $this->attachVariants($media, $newVariants);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['media' => $media->fresh()?->toPickerArray()]),
            resourceType: 'media',
            resourceId: (string) $media->getKey(),
            metadata: ['kind' => $kind->value, 'mime' => $attributes['mime'], 'size' => $attributes['size']],
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array{format: string, disk: string, path: string, mime: string, size: int, width: int, height: int}>}
     */
    private function prepareImage(UploadedFile $file): array
    {
        $info = $this->processor->inspect($file->getRealPath());
        $path = $this->uploads->store($file, 'file', MediaKind::Image->directory());
        $disk = (string) config('agentcms.uploads.disk');

        return [
            [
                'disk' => $disk,
                'original_name' => $this->store->originalName($file),
                'path' => $path,
                'mime' => $info['mime'],
                'size' => (int) $file->getSize(),
                'width' => $info['width'],
                'height' => $info['height'],
                // 新しい派生物は media_variants にだけ書く。旧列は触らない（SPEC §7.1）
                'has_webp' => false,
            ],
            $this->variants->generate($disk, $path, $info['mime'], $info['width'], $info['height']),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array{format: string, disk: string, path: string, mime: string, size: int, width: int, height: int}>}
     */
    private function prepareFile(UploadedFile $file): array
    {
        $inspected = $this->files->inspect($file);
        $disk = $this->store->disk();

        return [
            [
                'disk' => $disk,
                'original_name' => $this->store->originalName($file),
                'path' => $this->store->store($file, $inspected['extension']),
                'mime' => $inspected['mime'],
                'size' => (int) $file->getSize(),
                'width' => null,
                'height' => null,
            ],
            [],
        ];
    }

    /**
     * 新しい派生物を記録する。**差し替えは既に成立している。**
     *
     * 記録できなかったときは実体も残さない（DB が知らないファイルを増やさない）。
     * 原本は差し替わっているので、派生物が無くても配信は成立する。
     *
     * @param  list<array{format: string, disk: string, path: string, mime: string, size: int, width: int, height: int}>  $descriptors
     */
    private function attachVariants(Media $media, array $descriptors): void
    {
        if ($descriptors === []) {
            return;
        }

        try {
            DB::transaction(function () use ($descriptors, $media): void {
                foreach ($descriptors as $descriptor) {
                    MediaVariant::query()->create([...$descriptor, 'media_id' => $media->getKey()]);
                }
            });
        } catch (Throwable) {
            $this->variants->discard($descriptors);
        }
    }

    /** @param array{disk: string, path: string, legacy_webp: bool, variants: list<MediaVariant>} $old */
    private function forget(array $old): void
    {
        $disk = Storage::disk($old['disk']);
        $disk->delete($old['path']);

        if ($old['legacy_webp']) {
            $disk->delete(ImageProcessor::webpPath($old['path']));
        }

        foreach ($old['variants'] as $variant) {
            Storage::disk($variant->disk)->delete($variant->path);
        }
    }

    private function mismatch(MediaKind $current, MediaKind $incoming): string
    {
        return "このメディアは{$current->label()}です。{$incoming->label()}に差し替えることはできません"
            .'（種別が変わると、このメディアを使っている場所が壊れます）';
    }
}

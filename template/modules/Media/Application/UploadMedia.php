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
use AgentCms\Core\Media\MediaVisibility;
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
 * メディアのアップロード（Phase 9.1B / STEP 9、Phase 9.5-D で kind 対応）。
 *
 * ## 受け取ってから保存するまで
 *
 * ① 種別を決める → ② **実体を検査する** → ③ 保存する → ④ DB 行を作る →
 * ⑤ 派生物を best-effort で作る。
 *
 * 検査は必ず保存より前である。危険な SVG も、拡張子を偽装したファイルも、
 * disk に触れる前に落ちる。
 *
 * ## 失敗しても孤児を残さない（SPEC §5）
 *
 * DB 行の作成に失敗したら、**保存したばかりの実体を消す**。
 * 逆に派生物の生成は落ちても upload は成功のままにする — 原本は残っており、
 * 派生物が無くても配信は成立するからである。
 */
final class UploadMedia implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
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
        return 'media.upload';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(UploadedFile $file, OperationContext $context, ?string $name = null, ?string $alt = null): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        // アップロード欄は 1 つ。受け取ったものの種別はサーバーが決める
        $kind = $this->kinds->resolve($file);

        $media = $kind === MediaKind::Image
            ? $this->storeImage($file, $name, $alt)
            : $this->storeFile($file, $name);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['media' => $media->toPickerArray()]),
            resourceType: 'media',
            resourceId: (string) $media->getKey(),
            metadata: ['kind' => $kind->value, 'mime' => $media->mime, 'size' => $media->size],
        );
    }

    private function storeImage(UploadedFile $file, ?string $name, ?string $alt): Media
    {
        // 実体検査（破損・過大画像の拒否）は保存前に行う
        $info = $this->processor->inspect($file->getRealPath());

        // 拡張子 + 内容 MIME 許可リスト・UUID ファイル名再生成・非実行領域保存
        $path = $this->uploads->store($file, 'file', MediaKind::Image->directory());
        $disk = (string) config('agentcms.uploads.disk');

        $media = $this->persist(
            attributes: [
                'kind' => MediaKind::Image,
                'visibility' => MediaVisibility::Public,
                'disk' => $disk,
                'name' => $this->title($name, $file),
                'alt' => $alt,
                'original_name' => $this->store->originalName($file),
                'path' => $path,
                // MIME は保存前の実体検査が返した値だけを使う（SPEC §3.5）
                'mime' => $info['mime'],
                'size' => (int) $file->getSize(),
                'width' => $info['width'],
                'height' => $info['height'],
            ],
            disk: $disk,
            path: $path,
        );

        // 派生物は **原本の登録が済んだあと**に、落ちてもよいものとして作る
        $this->attachVariants($media, $disk, $path, $info['mime'], $info['width'], $info['height']);

        return $media;
    }

    private function storeFile(UploadedFile $file, ?string $name): Media
    {
        $inspected = $this->files->inspect($file);

        $disk = $this->store->disk();
        $path = $this->store->store($file, $inspected['extension']);

        return $this->persist(
            attributes: [
                'kind' => MediaKind::File,
                'visibility' => MediaVisibility::Public,
                'disk' => $disk,
                'name' => $this->title($name, $file),
                // 代替テキストは画像の概念なので、一般ファイルには持たせない
                'alt' => null,
                'original_name' => $this->store->originalName($file),
                'path' => $path,
                'mime' => $inspected['mime'],
                'size' => (int) $file->getSize(),
                // 一般ファイルに寸法は無い
                'width' => null,
                'height' => null,
            ],
            disk: $disk,
            path: $path,
        );
    }

    /**
     * 原本の行を作る。失敗したら**保存したばかりの実体を消す**。
     *
     * 「DB に無いファイル」は害が小さく、「ファイルの無い DB 行」は画面が壊れる。
     * ここは前者すら残さない場面である（まだ誰もこの実体を参照していない）。
     *
     * **派生物はこの transaction に入れない。** 入れると、派生物を記録できなかっただけで
     * 原本ごと巻き戻り、upload が失敗する。派生物は「あれば速い」ものであって、
     * それが理由で登録が失敗してよいものではない（SPEC §5 / D7）。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(array $attributes, string $disk, string $path): Media
    {
        try {
            return DB::transaction(fn (): Media => Media::query()->create($attributes));
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * 派生物を best-effort で作り、記録する。**upload は絶対に失敗させない。**
     *
     * 作れなくても、記録できなくても、原本は残っているので配信は成立する。
     * 記録できなかったときは実体も残さない（DB が知らないファイルを増やさない）。
     */
    private function attachVariants(Media $media, string $disk, string $path, string $mime, int $width, int $height): void
    {
        $descriptors = $this->variants->generate($disk, $path, $mime, $width, $height);

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

    /**
     * name は任意。未指定ならアップロード元のファイル名から補う。
     *
     * 列は 120 文字なので、補った名前が長すぎて保存に失敗しないよう切り詰める
     * （利用者が入力した name は controller が既に検証している）。
     */
    private function title(?string $name, UploadedFile $file): string
    {
        $title = ($name ?: null)
            ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return mb_substr($title, 0, 120);
    }
}

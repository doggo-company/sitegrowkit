<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Media\MediaUsageInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;

/**
 * メディア削除（Phase 9.1B / STEP 9）。
 *
 * **使用中は削除しない**（DELETE SAFETY / ADR-0006）。使用箇所を Conflict で返す。
 * 未使用でも破壊的操作なので確認トークンを要求する。
 */
final class DeleteMedia implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetMedia $get,
        private readonly MediaUsageInspector $inspector,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'media.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $media = $this->get->find($id, $context);

        if ($media instanceof Result) {
            return $this->outcome->record($this, $context, $media, resourceType: 'media', resourceId: (string) $id);
        }

        // 使用中は削除拒否 + 使用箇所を返す（DB の FK restrict は最終防衛線）
        $usages = $this->inspector->usages($media->id);

        if ($usages !== []) {
            $places = collect($usages)
                ->map(fn (array $u): string => $u['label'].': '.collect($u['items'])->pluck('title')->implode('、'))
                ->implode(' / ');

            return $this->outcome->record($this, $context, Result::conflict(
                "この{$media->kind->label()}は使用中のため削除できません（使用箇所 — {$places}）",
                ['usages' => $usages],
            ), resourceType: 'media', resourceId: (string) $id);
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            summary: "{$media->kind->label()}「{$media->name}」を削除します",
            impact: [
                $media->isImage()
                    ? '画像ファイルと、そこから作った軽量版がサーバーから消えます'
                    : 'ファイルがサーバーから消え、配布済みの URL も開けなくなります',
                '削除したメディアは元に戻せません',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation), resourceType: 'media', resourceId: (string) $id);
        }

        $name = (string) $media->name;
        $kind = $media->kind;
        // 消す順序: ③ variants 行 → ④ media 行 → ⑤ ファイル（SPEC §5）。
        // ③④ が落ちたらファイルを消さない。⑤ が部分的に落ちても DB は整合したまま
        $files = $this->filesOf($media);

        DB::transaction(function () use ($media): void {
            $media->variants()->delete();
            $media->delete();
        });

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        return $this->outcome->record(
            $this,
            $context,
            // 呼び出し側が「何を消したのか」を言えるように種別を返す
            Result::success(['id' => $id, 'kind' => $kind->value]),
            resourceType: 'media',
            resourceId: (string) $id,
            metadata: ['name' => $name, 'kind' => $kind->value],
        );
    }

    /**
     * 消すべき実体を、**DB 行を消す前に**数え上げておく。
     *
     * 行を消してからでは、どのファイルが属していたか分からなくなる。
     *
     * @return list<array{0: string, 1: string}> [disk, path]
     */
    private function filesOf(Media $media): array
    {
        $disk = $media->diskName();
        $files = [[$disk, (string) $media->path]];

        if ($media->has_webp) {
            // 旧実装が作った `.webp`（拡張子置換）。写し替えが済むまで残っている
            $files[] = [$disk, ImageProcessor::webpPath((string) $media->path)];
        }

        foreach ($media->variants as $variant) {
            $files[] = [(string) $variant->disk, (string) $variant->path];
        }

        return $files;
    }
}

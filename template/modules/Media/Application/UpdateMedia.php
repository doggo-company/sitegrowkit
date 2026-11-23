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
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaVisibility;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;

/**
 * メディアのメタデータ更新（Phase 9.1B / STEP 9、Phase 9.5-D で公開範囲）。
 *
 * ## 公開範囲は確認を挟む
 *
 * `visibility` を変えられるのは **一般ファイルだけ**である（画像は常に public）。
 * どちらの向きも**外向きの変化**なので、確認なしには起こさない。
 *
 * - public → private: 既に配った URL が開けなくなる
 * - private → public: URL を知っていれば誰でも開けるようになる
 */
final class UpdateMedia implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetMedia $get,
        private readonly ValidationFactory $validator,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $confirmations,
    ) {}

    public function operationId(): string
    {
        return 'media.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $attributes */
    public function execute(int $id, array $attributes, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $media = $this->get->find($id, $context);

        if ($media instanceof Result) {
            return $this->outcome->record($this, $context, $media, resourceType: 'media', resourceId: (string) $id);
        }

        $validator = $this->validator->make($attributes, [
            'name' => ['required', 'string', 'max:120'],
            'alt' => ['nullable', 'string', 'max:200'],
            'visibility' => ['sometimes', Rule::enum(MediaVisibility::class)],
        ]);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $this->outcome->record($this, $context, Result::validationError($errors, 'メディア情報の入力内容を確認してください'), resourceType: 'media', resourceId: (string) $id);
        }

        /** @var array{name: string, alt?: string|null, visibility?: string} $data */
        $data = $validator->validated();

        // 代替テキストは画像の概念。画面で出していないものが、送られてきたからといって
        // 保存されてはいけない（name の保存や公開範囲の切り替えで null が '' に変わる）
        if ($media->kind === MediaKind::File) {
            unset($data['alt']);
        }

        $visibility = isset($data['visibility'])
            ? MediaVisibility::from($data['visibility'])
            : null;

        // 画像に公開範囲は無い。画面で出していないものを、経路を変えれば通せる状態にしない
        if ($visibility !== null && $media->kind !== MediaKind::File) {
            unset($data['visibility']);

            if ($visibility !== $media->visibility) {
                return $this->outcome->record($this, $context, Result::validationError(
                    ['visibility' => ['画像の公開範囲は変更できません']],
                ), resourceType: 'media', resourceId: (string) $id);
            }
        }

        if ($visibility !== null && $media->kind === MediaKind::File && $visibility !== $media->visibility) {
            $confirmation = $this->confirmations->require(
                $this->operationId(),
                // 外向きの変化なので、確認なしに起こさない（Write の既定では止まらない）
                OperationClassification::ExternalEffect,
                $context,
                ['id' => $id, 'visibility' => $visibility->value],
                summary: "ファイル「{$media->name}」の公開範囲を「{$visibility->label()}」に変更します",
                impact: [
                    $visibility->description(),
                    $visibility === MediaVisibility::Private
                        ? '既に配布した URL からは開けなくなります'
                        : 'URL を知っている人は誰でもダウンロードできるようになります',
                ],
            );

            if ($confirmation !== null) {
                return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation), resourceType: 'media', resourceId: (string) $id);
            }
        }

        $media->update($data);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['media' => $media->toPickerArray()]),
            resourceType: 'media',
            resourceId: (string) $media->getKey(),
            metadata: ['fields' => array_keys($data)],
        );
    }
}

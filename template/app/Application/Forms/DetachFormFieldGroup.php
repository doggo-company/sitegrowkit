<?php

declare(strict_types=1);

namespace App\Application\Forms;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Forms\GetForm;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Forms\Form;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 共有している入力項目を、このフォームだけのものにする（B-31）。
 *
 * ## なぜ要るか
 *
 * 新しく作ったフォームは、作った時点で専用の束が付く（R-8A ①）ので画面の中で項目を
 * 直せる。**既に在るフォームだけが取り残されていた** — その束が他のフォームや投稿タイプ
 * とも共有されていると、画面は読み取りで見せるだけになる（共有の束をここで書き換えると
 * 利用者が見ていない画面が壊れるからである）。
 *
 * ## オーナー判断（2026-08-24）— 2 つとも「利用者が見ていないものを勝手に変えない」
 *
 * 1. **黙って複製しない。利用者に聞く。**
 *    複製そのものは何も壊さないので分類は `Write` のままにし、**確認だけを必ず取る**
 *    （`ConfirmationGate::requireAlways()`。分類を `Destructive` へ偽らない）。
 * 2. **複製した後、元の束を指していた他のフォームは元の束のまま据え置く。**
 *    付け替えると、そのフォームを開いていない人の画面が黙って変わる。
 *
 * ## 何を写して、何を写さないか
 *
 * | | 写すか | 理由 |
 * | --- | --- | --- |
 * | 項目の `key` | **写す** | 受信済みの入力値は key で引く。変えると過去の受信が読めなくなる |
 * | 項目の `id`（ULID） | **写さない**（採番し直す） | 同じ ID が 2 つの束に居ると、片方の編集がもう片方の binding を巻き添えにする |
 * | 割当先 | **写さない**（このフォーム 1 つだけにする） | それが「専用にする」ということそのもの |
 * | `storage` | **写さない** | DB の宣言に書かせない値（`FieldShape::DB_FORBIDDEN_KEYS`）。読み込み時に付く |
 *
 * ## 権限
 *
 * `form.save` と同じ `SettingsManage`。フォームを作ると専用の束が 1 つ付いてくるのと
 * 同じことをしているので、そこだけ別の権限を要求しても運用の意味が無い。
 */
final class DetachFormFieldGroup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $gate,
        private readonly OperationAudit $outcome,
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'form.field_group.detach';
    }

    /**
     * **複製は何も失わない。** 元の束も他のフォームもそのまま残るので `Write` である。
     * 確認は分類ではなく `requireAlways()` で取る（分類は正直なままにする）。
     */
    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(string $formKey, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $form = GetForm::find($formKey);

        if ($form === null) {
            return $this->outcome->record($this, $context, Result::notFound("フォーム [{$formKey}] は存在しません"));
        }

        if (! $this->repository->isInstalled()) {
            return $this->outcome->record($this, $context, Result::conflict(
                'カスタムフィールドの保存先がまだ準備できていません',
            ), resourceType: 'form', resourceId: $formKey);
        }

        $record = $this->repository->find((string) $form->field_group_id);

        if ($record === null) {
            return $this->outcome->record($this, $context, Result::conflict(
                'このフォームにはまだ入力項目がありません。先に入力項目を選んでください。',
            ), resourceType: 'form', resourceId: $formKey);
        }

        if (! $record->isValid()) {
            // 読めない定義を写すと、壊れた束が 2 つになるだけである
            return $this->outcome->record($this, $context, Result::conflict(
                'この入力項目の定義が読めないため複製できません。定義エラーを解消してください。',
            ), resourceType: 'form', resourceId: $formKey);
        }

        if (FormFieldGroup::belongsOnlyTo($record, $formKey)) {
            return $this->outcome->record($this, $context, Result::conflict(
                'この入力項目は、すでにこのフォームだけのものです。そのまま編集できます。',
            ), resourceType: 'form', resourceId: $formKey);
        }

        $confirmation = $this->gate->requireAlways(
            $this->operationId(),
            $context,
            ['form' => $formKey, 'group' => $record->id],
            "「{$form->name}」の入力項目を複製して、このフォームだけのものにします",
            $this->impact($record, $formKey),
        );

        if ($confirmation !== null) {
            return $this->outcome->record(
                $this,
                $context,
                Result::confirmationRequired($confirmation),
                resourceType: 'form',
                resourceId: $formKey,
            );
        }

        try {
            $copyId = DB::transaction(function () use ($form, $record, $formKey, $context): string {
                $id = $this->writer->create(
                    $this->uniqueGroupKey($formKey),
                    $record->label,
                    $this->copyDeclarations($record->declarations),
                    [['kind' => FieldGroupLocationKind::Form->value, 'value' => $formKey]],
                    $context,
                );

                /*
                 * **`lock_version` を進める。** 別の人がこのフォームの画面を開いたままだと、
                 * その画面は古い `field_group_id` を持っている。進めておかないと、
                 * その人が「フォームの設定を保存」を押した瞬間に**切り離しが黙って巻き戻る**。
                 */
                Form::query()
                    ->whereKey($form->getKey())
                    ->update([
                        'field_group_id' => $id,
                        'lock_version' => (int) $form->lock_version + 1,
                        'updated_at' => now(),
                    ]);

                return $id;
            });
        } catch (QueryException $e) {
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                '同時に別の変更があったため複製できませんでした。もう一度お試しください。',
            ), resourceType: 'form', resourceId: $formKey);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['form' => ['key' => $formKey, 'field_group_id' => $copyId]]),
            resourceType: 'form',
            resourceId: $formKey,
            // **何を写したかの要約だけ。** 項目の表示名も選択肢も監査には載せない
            metadata: [
                'from' => $record->id,
                'to' => $copyId,
                'fieldCount' => count($record->declarations),
            ],
        );
    }

    /**
     * 確認の文。**「何が変わらないか」を先に言う**（オーナー判断 2 をそのまま画面へ出す）。
     *
     * @return list<string>
     */
    private function impact(FieldGroupRecord $record, string $formKey): array
    {
        $others = [];

        foreach ($record->locations as $location) {
            if ($location->kind === FieldGroupLocationKind::Form && $location->value === $formKey) {
                continue;
            }

            // 種類の呼び名は enum が持つ（画面と同じ言葉を写さない）
            $kind = $location->kind?->label() ?? $location->rawKind;

            $others[] = "{$kind}「{$location->value}」";
        }

        $impact = ['いまの入力項目をそのまま写すので、公開中のフォームの見た目は変わりません。'];

        if ($others !== []) {
            $impact[] = 'ほかで使われている '.implode(' / ', $others).' は、いままでどおりのままです。';
        }

        $impact[] = '受信済みの内容は消えません。';

        return $impact;
    }

    /**
     * 写す宣言。**`id` は採番し直し、`storage` は落とす**（入れ子も同じ規則で辿る）。
     *
     * @param  list<array<string, mixed>>  $declarations
     * @return list<array<string, mixed>>
     */
    private function copyDeclarations(array $declarations): array
    {
        $copied = [];

        foreach ($declarations as $declaration) {
            $declaration['id'] = (string) Str::ulid();
            unset($declaration['storage']);

            if (is_array($declaration['fields'] ?? null)) {
                /** @var list<array<string, mixed>> $children */
                $children = $declaration['fields'];
                $declaration['fields'] = $this->copyDeclarations($children);
            }

            $copied[] = $declaration;
        }

        return $copied;
    }

    /**
     * 束の key。**`SaveForm` が自動生成に使うのと同じ形**（`form_<フォームの key>`）。
     *
     * 束の key はハイフンを許さない（`FieldGroupDraft::MACHINE_NAME`）ので `_` へ寄せ、
     * 空いている番号まで進める。既に在るものを奪わない。
     */
    private function uniqueGroupKey(string $formKey): string
    {
        $base = mb_substr('form_'.str_replace('-', '_', $formKey), 0, 60);

        $taken = [];

        foreach ($this->repository->all() as $record) {
            $taken[$record->key] = true;
        }

        if (! isset($taken[$base])) {
            return $base;
        }

        for ($n = 2; $n < 1000; $n++) {
            if (! isset($taken[$base.'_'.$n])) {
                return $base.'_'.$n;
            }
        }

        return $base.'_'.substr((string) now()->getTimestampMs(), -6);
    }
}

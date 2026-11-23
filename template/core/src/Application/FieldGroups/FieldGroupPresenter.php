<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\FieldGroups\FieldGroupLimits;
use AgentCms\Core\FieldGroups\FieldGroupLocation;
use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * Field Group を画面へ渡す形にする（Phase 9.5-B）。
 *
 * **一覧と編集で同じ形を返す。** 画面ごとに別の形を作ると、
 * どちらかを直したときにもう片方がずれる。
 *
 * 導出できる値（割当先の表示名・項目数）は**保存していない**。ここで解決する。
 */
final class FieldGroupPresenter
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * 項目エディタが要る選択肢と上限（R-8A ②）。
     *
     * **カスタムフィールドの画面とフォームの画面が、同じ 1 つを見る。**
     * 写すと、型を足したときに片方だけ置いていかれる（F-18 と同じ形）。
     *
     * @return array<string, mixed>
     */
    public function editorOptions(): array
    {
        return [
            'contentTypes' => $this->contentTypeOptions(),
            'fieldTypes' => FieldGroupDraft::ALLOWED_TYPES,
            'formForbiddenTypes' => FieldGroupDraft::formForbiddenTypes(),
            // Phase 9.5-C。画面は「押せるもの」をサーバーの契約どおりに出す
            'nestedTypes' => FieldGroupDraft::NESTED_TYPES,
            'referenceTypes' => FieldGroupDraft::REFERENCE_TYPES,
            'topLevelOnlyTypes' => FieldGroupDraft::TOP_LEVEL_ONLY_TYPES,
            'referenceTargets' => $this->contentTypeOptions(),
            'limits' => [
                'depth' => FieldGroupLimits::MAX_DEPTH,
                'fields' => FieldGroupLimits::MAX_FIELDS,
                'repeaterDefaultMax' => FieldGroupLimits::REPEATER_DEFAULT_MAX,
                'repeaterMax' => FieldGroupLimits::REPEATER_MAX,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(FieldGroupRecord $group): array
    {
        return [
            'id' => $group->id,
            'key' => $group->key,
            'label' => $group->label,
            'status' => $group->status->value ?? $group->rawStatus,
            'fieldCount' => count($group->fields),
            'locations' => array_map($this->location(...), $group->locations),
            'valid' => $group->isValid(),
            'invalidReason' => $group->invalidReason,
            'active' => $group->contributesFields(),
        ];
    }

    /**
     * 編集画面用。**項目の中身まで**返す。
     *
     * @return array<string, mixed>
     */
    public function detail(FieldGroupRecord $group): array
    {
        return [
            ...$this->summary($group),
            'fields' => array_map($this->field(...), $group->fields),
        ];
    }

    /** @return array<string, mixed> */
    private function field(Field $field): array
    {
        return [
            // ID は rename しても変わらない。画面はこれをそのまま返してくる
            'id' => $field->id,
            'key' => $field->key,
            'type' => $field->type,
            'label' => $field->label,
            'required' => $field->required,
            'help' => $field->help,
            'choices' => $field->choices,
            'max' => $field->max,
            'target' => $field->target,
            // 入れ子は同じ形で返す（画面が再帰的に描ける）
            'fields' => array_map($this->field(...), $field->fields),
        ];
    }

    /** @return array<string, mixed> */
    private function location(FieldGroupLocation $location): array
    {
        return [
            'kind' => $location->kind->value ?? $location->rawKind,
            'kindLabel' => $location->kind?->label(),
            'value' => $location->value,
            'label' => $this->targetLabel($location),
        ];
    }

    /**
     * 割当先を、利用者の言葉で呼ぶ。
     *
     * 🔴 **ここは以前、投稿タイプ以外を必ず `null` で返していた**
     *（WP 一周 6 周目 2026-09-03）。画面は「`label` が無い ＝ 未解決」と描くので、
     * フォームに割り当てた束は**構造上いつでも「（未解決）」**だった。
     * 入れたての生成 project で唯一ある束が同梱のお問い合わせなので、
     * **初見で必ずそれを見る**ことになる。壊れていないのに壊れて見えていた。
     *
     * 🔴 **無いものを在るように見せない。** 指した先が消えていれば `null` のままにする
     *（嘘の名前を作ると、消えたことに気づけなくなる）。
     */
    private function targetLabel(FieldGroupLocation $location): ?string
    {
        if ($location->kind === FieldGroupLocationKind::ContentType) {
            // **ファイル宣言だけ**を見る（effective を読むと overlay が自分を読み直す）
            $schema = $this->schemas->declared()[$location->value] ?? null;

            return $schema instanceof ContentSchema ? $schema->label : null;
        }

        if ($location->kind === FieldGroupLocationKind::Form) {
            $name = Form::query()->where('key', $location->value)->value('name');

            return is_string($name) && $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * 割当先に選べる投稿タイプ（画面の選択肢）。
     *
     * @return list<array{value: string, label: string}>
     */
    public function contentTypeOptions(): array
    {
        $options = [];

        foreach ($this->schemas->declared() as $id => $schema) {
            if ($schema->kind !== 'collection') {
                continue;
            }

            $options[] = ['value' => $id, 'label' => $schema->label];
        }

        usort($options, static fn (array $a, array $b): int => strcmp($a['value'], $b['value']));

        return $options;
    }
}

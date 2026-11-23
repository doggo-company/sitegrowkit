<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\Schema\Field;

/**
 * そのフォームの入力項目（Phase 9.12 / STAGE 25B・SPEC §B.3・OG1）。
 *
 * ## 項目は Field Group が持つ
 *
 * 9.5-A が `FieldGroupLocationKind::Form` を用意し、
 * 「content と form は Field 定義だけを共用する（G9）」と決めている。
 * **項目を編集する画面を 2 つにしない**ためである。ここはその定義を読むだけで、
 * 型の一覧も必須の規則も持たない（`Field::fromArray()` の検証をそのまま使う）。
 *
 * ## 公開していない束の項目は出さない
 *
 * `contributesFields()` が false（draft / archived / 定義エラー）なら **1 つも返さない**。
 * ADR-0015 の fail closed をそのまま守る。中途半端に半分だけ出すと、
 * 「必須項目が消えたフォーム」を外へ晒すことになる。
 */
final class FormFields
{
    /**
     * 公開フォームに出せる項目の種類（Phase 9.12 / STAGE 25C）。
     *
     * **未認証の相手に出せるものだけ**を並べる。`media` / `file` / `image` /
     * `images` は Media Entity の id を受ける形で、外から任意の id を送れる口に
     * なるうえ、そもそも訪問者は upload できない（添付は V1 の非対象 — §A.3）。
     * `relation` / `relations` は公開していない投稿を指せてしまう。
     * `status` は管理側の語彙で、`group` / `repeater` は入れ子の描画と確認画面の
     * 持ち回りが別の題材になる。`richtext` は Field Group 側で既に禁止（G9）。
     *
     * ここに無い種類を含む束は**公開できない**（`problemFor()`）。
     * 黙って項目を落とすと、「必須のはずの欄が無いフォーム」を外へ出すことになる。
     *
     * @var list<string>
     */
    public const PUBLIC_TYPES = [
        'text', 'textarea', 'email', 'url', 'tel', 'number',
        'select', 'radio', 'checkbox', 'date', 'datetime', 'boolean',
    ];

    public function __construct(private readonly FieldGroupRepository $groups) {}

    /**
     * そのフォームの項目。
     *
     * @return list<Field>
     */
    public function for(Form $form): array
    {
        $record = $this->record($form);

        return $record === null ? [] : $record->fields;
    }

    /**
     * 保存に使う生の宣言（`SchemaRules` へ渡す形）。
     *
     * @return list<array<string, mixed>>
     */
    public function declarationsFor(Form $form): array
    {
        $record = $this->record($form);

        return $record === null ? [] : $record->declarations;
    }

    /**
     * いま画面に出ている表示名（受信と一緒に保存する — OG4）。
     *
     * @return array<string, string>
     */
    public function labelsFor(Form $form): array
    {
        $labels = [];

        foreach ($this->for($form) as $field) {
            $labels[$field->key] = $field->label;
        }

        return $labels;
    }

    /**
     * その束をフォームに使えるか。
     *
     * 使えない理由を**そのまま返す**（画面で「なぜ項目が出ないのか」を言えるようにする）。
     */
    public function problemFor(?string $fieldGroupId): ?string
    {
        if ($fieldGroupId === null || $fieldGroupId === '') {
            return '入力項目がまだ決まっていません';
        }

        if (! $this->groups->isInstalled()) {
            return 'カスタムフィールドの表がありません';
        }

        $record = $this->groups->find($fieldGroupId);

        if ($record === null) {
            return 'その入力項目の束はありません';
        }

        if (! $record->isValid()) {
            return '入力項目の定義を読めません: '.(string) $record->invalidReason;
        }

        if (! $this->assignedToForm($record)) {
            return 'その束はフォームに割り当てられていません';
        }

        if (! $record->contributesFields()) {
            return '入力項目の束が公開されていません（下書きのままです）';
        }

        if ($record->fields === []) {
            return '入力項目が 1 つもありません';
        }

        foreach ($record->fields as $field) {
            if (! in_array($field->type, self::PUBLIC_TYPES, true)) {
                return "「{$field->label}」は公開フォームでは使えない種類です（{$field->type}）";
            }
        }

        return null;
    }

    private function record(Form $form): ?FieldGroupRecord
    {
        if ($form->field_group_id === null || ! $this->groups->isInstalled()) {
            return null;
        }

        $record = $this->groups->find($form->field_group_id);

        if ($record === null || ! $record->contributesFields() || ! $this->assignedToForm($record)) {
            return null;
        }

        return $record;
    }

    /**
     * その束が「フォーム」に割り当てられているか。
     *
     * **投稿タイプ用の束をフォームへ流用させない。** 割当先が違うということは、
     * 使ってよい項目の種類（`richtext` は不可）も違うということである。
     */
    private function assignedToForm(FieldGroupRecord $record): bool
    {
        foreach ($record->locations as $location) {
            if ($location->kind === FieldGroupLocationKind::Form) {
                return true;
            }
        }

        return false;
    }
}

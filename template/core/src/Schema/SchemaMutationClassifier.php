<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

/**
 * 「この schema 変更はどれくらい危険か」を判定する（Phase 9.2B）。
 *
 * 判定は **field の immutable ID** を基準にする（owner 決定 G6）。
 * ID が同じなら、key が変わっても label や型が変わっても **同じフィールド**として追跡できる。
 */
final class SchemaMutationClassifier
{
    /**
     * 2 つの schema を比較し、最も危険な分類と個別の変更内訳を返す。
     *
     * @return array{level: SchemaMutation, changes: list<array{field: string, change: string, level: string, reason: string}>}
     */
    public function compare(ContentSchema $before, ContentSchema $after): array
    {
        $changes = [];

        $beforeById = $this->byIdentity($before->fields);
        $afterById = $this->byIdentity($after->fields);

        foreach ($beforeById as $identity => $field) {
            $updated = $afterById[$identity] ?? null;

            if ($updated === null) {
                $changes[] = $this->change($field->key, 'field.delete', SchemaMutation::Destructive,
                    'この項目は画面に出なくなります。入力済みの値は消えません');

                continue;
            }

            $changes = [...$changes, ...$this->compareField($field, $updated)];
        }

        foreach ($afterById as $identity => $field) {
            if (! isset($beforeById[$identity])) {
                $changes[] = $field->required
                    ? $this->change($field->key, 'field.add.required', SchemaMutation::MigrationRequired,
                        '必須フィールドの追加は、既存データの埋め戻しが必要です')
                    : $this->change($field->key, 'field.add', SchemaMutation::Safe,
                        '任意フィールドの追加は既存データに影響しません');
            }
        }

        return ['level' => $this->highest($changes), 'changes' => $changes];
    }

    /** @return list<array{field: string, change: string, level: string, reason: string}> */
    private function compareField(Field $before, Field $after): array
    {
        $changes = [];

        if ($before->key !== $after->key) {
            $changes[] = $this->change($after->key, 'field.rename', SchemaMutation::MigrationRequired,
                "カラム名 / JSON キー / API の項目名が変わります（{$before->key} → {$after->key}）");
        }

        if ($before->type !== $after->type) {
            $changes[] = $this->change($after->key, 'field.type', SchemaMutation::MigrationRequired,
                "型の変更は値の変換が必要です（{$before->type} → {$after->type}）");
        }

        if ($before->storage() !== $after->storage()) {
            $changes[] = $this->change($after->key, 'field.storage', SchemaMutation::MigrationRequired,
                '保存場所が変わるため、既存の値を移す必要があります');
        }

        if (! $before->required && $after->required) {
            $changes[] = $this->change($after->key, 'field.required', SchemaMutation::MigrationRequired,
                '必須化は、空のままの既存データを不正にします');
        }

        $removedChoices = array_diff($before->choices, $after->choices);

        if ($removedChoices !== []) {
            $changes[] = $this->change($after->key, 'field.choices.remove', SchemaMutation::Destructive,
                // 「不正になります」は、直後の「入力済みの値が消えることはありません」と食い違って読めた（UX 一周 2026-09-28）
                '外した選択肢（'.implode(', ', $removedChoices).'）が入っている既存のデータは、値がそのまま残ります。'
                    .'編集するときは選び直しが必要です');
        }

        $addedChoices = array_diff($after->choices, $before->choices);

        if ($addedChoices !== []) {
            $changes[] = $this->change($after->key, 'field.choices.add', SchemaMutation::Safe,
                '選択肢の追加は既存データに影響しません');
        }

        if ($before->label !== $after->label) {
            $changes[] = $this->change($after->key, 'field.label', SchemaMutation::Safe, '表示名の変更です');
        }

        if ($before->help !== $after->help) {
            $changes[] = $this->change($after->key, 'field.help', SchemaMutation::Safe, '説明文の変更です');
        }

        return $changes;
    }

    /**
     * identity => field。**入れ子の子まで辿る**（Phase 9.5-C）。
     *
     * トップレベルだけ比べると、`group` / `repeater` の中の改名・削除・型変更が
     * 「変更なし」と判定され、確認ゲートを通らずに適用されてしまう。
     * 追跡は immutable ID（`Field::identity()`）なので、親をまたいでも同じ項目だと分かる。
     *
     * @param  list<Field>  $fields
     * @return array<string, Field>
     */
    private function byIdentity(array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $out[$field->identity()] = $field;

            foreach ($this->byIdentity($field->fields) as $identity => $child) {
                $out[$identity] = $child;
            }
        }

        return $out;
    }

    /** @return array{field: string, change: string, level: string, reason: string} */
    private function change(string $field, string $change, SchemaMutation $level, string $reason): array
    {
        return ['field' => $field, 'change' => $change, 'level' => $level->value, 'reason' => $reason];
    }

    /** @param list<array{level: string}> $changes */
    private function highest(array $changes): SchemaMutation
    {
        $levels = array_column($changes, 'level');

        if (in_array(SchemaMutation::Destructive->value, $levels, true)) {
            return SchemaMutation::Destructive;
        }

        if (in_array(SchemaMutation::MigrationRequired->value, $levels, true)) {
            return SchemaMutation::MigrationRequired;
        }

        return SchemaMutation::Safe;
    }
}

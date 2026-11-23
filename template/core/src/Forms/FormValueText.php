<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

use AgentCms\Core\Schema\Field;

/**
 * 入力値を人が読む形にする（Phase 9.12 / STAGE 25C）。
 *
 * 確認画面・管理者通知メール・自動返信・受信詳細・CSV が**同じ 1 箇所**を使う。
 * 別々に整形すると、「確認画面では『はい』だったのにメールでは『1』」になる。
 *
 * 項目の定義（`Field`）が既に消えていても読めるようにするため、
 * **`$field` は null を受ける**（過去の受信を開いたときに起きる）。
 */
final class FormValueText
{
    public static function of(?Field $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'はい' : 'いいえ';
        }

        if (is_array($value)) {
            return implode('、', array_map(
                static fn (mixed $one): string => self::of(null, $one),
                array_values($value),
            ));
        }

        // boolean 型の項目は "1" / "0" で届くことがある（checkbox の value）
        if ($field?->type === 'boolean') {
            return in_array($value, ['1', 1, 'true', 'on'], true) ? 'はい' : 'いいえ';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

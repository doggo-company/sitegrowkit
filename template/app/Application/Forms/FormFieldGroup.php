<?php

declare(strict_types=1);

namespace App\Application\Forms;

use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRecord;

/**
 * 「その項目の束は、このフォームだけのものか」（R-8A ② / B-31）。
 *
 * ## なぜ 1 か所に置くか
 *
 * この判定は **2 か所で同じでなければならない**。
 *
 * - 画面（`FormAdminController::fieldEditor()`）— 編集させるか、読み取りで見せるか
 * - 切り離し（`DetachFormFieldGroup`）— 複製してよいか、断るか
 *
 * 食い違うと、**画面が「共有です」と言いながら切り離しは「もう専用です」と断る**
 * （あるいはその逆で、専用の束をもう 1 つ複製して増やし続ける）。
 * 同じことを 2 か所で覚えさせない（OG1）。
 *
 * ## 数だけ見ない
 *
 * 割当先が 1 つでも、それが**投稿タイプ**だったり**別のフォーム**だったりする。
 * 数で判定すると、そういう束を専用だと誤認して**利用者が見ていない画面を壊す**。
 */
final class FormFieldGroup
{
    /** この束の割当先が「このフォーム 1 つだけ」か */
    public static function belongsOnlyTo(FieldGroupRecord $record, string $formKey): bool
    {
        return count($record->locations) === 1
            && $record->locations[0]->kind === FieldGroupLocationKind::Form
            && $record->locations[0]->value === $formKey;
    }
}

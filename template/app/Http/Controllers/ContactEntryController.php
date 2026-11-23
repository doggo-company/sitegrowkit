<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Forms\FormFields;
use App\Support\ContactEntry;
use Illuminate\Contracts\View\View;
use Modules\Contact\Http\ContactController;

/**
 * `/contact` の入口（R-12 Stage 1）。
 *
 * **route 名 `contact.show` は残す**（director 裁定 (2)）。同梱テーマの 5 か所と sitemap、
 * 既定の導線、送信後の戻り先が**この名前を呼んでいる**ので、名前を変えると
 * それだけで壊れる箇所が 8 つある。変えるのは**中身だけ**。
 *
 * どちらを出すかは `ContactEntry` が決める。ここは**振り分けだけ**を持つ —
 * 条件をここに書くと、設定画面が出す説明（`reason()`）と判定が 2 か所になる。
 */
final class ContactEntryController extends Controller
{
    public function __invoke(
        ContactEntry $entry,
        ContactController $legacy,
        PublicFormController $forms,
        FormFields $fields,
    ): View {
        // 窓口のフォームを下書きにしたら、見本の窓口に戻さずに閉じる（UX 一周 6 周目 R6-1）
        abort_if($entry->closed(), 404);

        if (! $entry->usesForm()) {
            return $legacy->show();
        }

        return $forms->show(ContactEntry::RESERVED_KEY, $fields);
    }
}

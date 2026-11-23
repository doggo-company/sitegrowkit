<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ImportedPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 取り込んだページの元の URL（`routes/imported.php` が生成する route の受け口）。
 *
 * route の `defaults`:
 * - `view`   … 静的な Blade の名前（apply が書き出したもの）。**必ず在る**
 * - `layout` … その URL のページの枠（`layouts.preserved.about`）。無い route（URL から slug を
 *              決められなかった）は静的な Blade だけを出す
 *
 * 答えは `ImportedPages::respond()` に従う: 所有者が無ければ静的な Blade、公開中ならページ、
 * slug が変わっていれば 301、未公開 / 予約 / ゴミ箱 / 版欠落なら 404（公開を取り下げた本文を
 * 静的な Blade で見せない）。以前の `Route::view` だと、管理画面で直しても元の URL に出なかった。
 */
final class ImportedPageController
{
    public function __invoke(Request $request, ImportedPages $imported): View|RedirectResponse
    {
        $view = $request->route('view');
        $layout = $request->route('layout');

        abort_unless(is_string($view) && $view !== '', 404);

        if (is_string($layout) && $layout !== '') {
            $answer = $imported->respond($layout);

            if ($answer !== null) {
                return $answer;
            }
        }

        abort_unless(view()->exists($view), 404);

        return view($view);
    }
}

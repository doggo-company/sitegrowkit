<?php

declare(strict_types=1);

namespace App\Http\Responses;

use AgentCms\Core\Settings\SettingsStore;
use App\Support\DocumentPageView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class NotFoundPage
{
    /** レイアウトや DB が壊れていても、元の 404 を 500 に変えず、詳細を外に出さない。 */
    public function render(Request $request): Response
    {
        // ファイル取得の拒否は、存在・非公開・未認可を同じ応答にする契約がある。
        // 公開側も管理側も DB を読まず、同じ日本語の画面へ返す（MediaDownloadTest）。
        if ($request->is('media/file/*', 'admin/media/file/*')) {
            return response()->view('errors.media-404', [], 404);
        }

        try {
            if ($request->is('admin', 'admin/*')) {
                return response()->view('errors.admin-404', [], 404);
            }
            // 通常の固定ページと同じサイトの枠を選ぶ。独自ページに結び付いた原本の枠は流用しない。
            $layout = app(DocumentPageView::class)->siteLayout();
            $title = 'ページが見つかりません';
            if ($layout !== 'layouts.public') {
                $title .= ' | '.(string) app(SettingsStore::class)->get('site_name', config('app.name'));
            }

            return response()->view('errors.404', ['layout' => $layout, 'title' => $title], 404);
        } catch (Throwable) {
            return response()->view('errors.admin-404', ['publicFallback' => ! $request->is('admin', 'admin/*')], 404);
        }
    }
}

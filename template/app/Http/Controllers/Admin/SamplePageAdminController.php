<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use App\Support\SamplePages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 同梱の見本ページ（`/about` `/service`）を非公開にする／戻す。`/contact` は見本の意匠を
 * やめて標準の表示にする／戻す（UX 一周 2026-09-27）。
 *
 * 公開前チェックが「見本のまま公開されています。消してください」と言うのに、
 * 消す手段がファイルの削除（ターミナル）しか無かった。ここはその**画面の側の口**である。
 *
 * - **ファイルは消さない。**出さないという状態を持つだけなので、いつでも戻せる
 * - サイト全体の見え方を変えるので、**サイト設定を触れる人だけ**（公開前チェックと同じ線）
 * - ページの route（`admin.pages.*`）には足さない。あちらは本数を固定している
 */
final class SamplePageAdminController
{
    public function hide(Request $request, SamplePages $pages, string $route): RedirectResponse
    {
        return $this->set($request, $pages, $route, true);
    }

    public function show(Request $request, SamplePages $pages, string $route): RedirectResponse
    {
        return $this->set($request, $pages, $route, false);
    }

    /**
     * 作ったページをトップ（`/`）にする（UX 一周 7 周目 R7-4）。
     *
     * 「トップ用のページを作る」から作って公開しても、トップは見本のままだった。
     * サイト設定の「トップページ」で選ぶ一手が、公開した画面のどこにも出ていなかった。
     * ページの画面から 1 回で選べるようにする（サイト設定と同じ設定を書く）。
     */
    public function front(Request $request, int $id): RedirectResponse
    {
        $this->authorize($request);

        $page = Page::query()->whereKey($id)->where('managed_by', Page::MANAGED_BY_DOCUMENT)->first();
        abort_unless($page !== null && $page->isPublicallyVisible(), 404);

        $result = app(UpdateSettings::class)->execute(
            [FrontPage::SETTING_KEY => $page->getKey()],
            OperationContext::admin($request->user()?->getKey(), (string) $request->user()?->name),
            partial: true,
        );

        if ($result->status === OperationStatus::ValidationError) {
            return back()->withErrors($result->errors);
        }

        abort_unless($result->isSuccess(), $result->status === OperationStatus::Forbidden ? 403 : 500, $result->message ?? '');

        Inertia::flash('toast', ['type' => 'success', 'message' => '「'.$page->title.'」をトップページ（/）にしました']);

        return redirect()->back();
    }

    private function authorize(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array(
                Capability::SettingsManage->value,
                app(RoleOperationPolicy::class)->capabilitiesFor($user),
                true,
            ),
            403,
        );
    }

    private function set(Request $request, SamplePages $pages, string $route, bool $hidden): RedirectResponse
    {
        $this->authorize($request);

        // 見本以外（案件のページ・存在しない名前・トップ）は受け付けない
        abort_unless($pages->setHidden($route, $hidden), 404);

        $message = $route === 'contact'
            ? ($hidden ? '/contact を標準の表示に切り替えました' : '/contact を見本のデザインに戻しました')
            : ($hidden
                ? "/{$route} の見本を非公開にしました（メニューとサイトマップからも外れます）"
                : "/{$route} の見本を公開に戻しました");

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->to('/admin/pages#sample-'.$route);
    }
}

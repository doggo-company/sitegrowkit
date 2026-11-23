<?php

declare(strict_types=1);

namespace Modules\Site\Http;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Settings\GetSettings;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Menus\MenuTree;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\ContactEntry;
use App\Support\DefaultNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site settings（kind: settings）— schema 駆動の単一フォーム。
 *
 * Phase 9.1B: **業務ロジックは Application Service へ移した**。
 * この Controller の責務は「リクエストの取り出し → service 呼び出し → 画面描画」だけ。
 * 同じ操作を Artisan / MCP から呼んでも同じ検証・認可・監査を通る。
 */
final class AdminSiteController
{
    public function edit(Request $request, GetSettings $getSettings, FrontPage $frontPage, MenuTree $menus, ContactEntry $contact): Response
    {
        $result = $getSettings->execute($this->context($request));

        /** @var array{schema: array<string, mixed>, values: array<string, mixed>, frontPageChoices?: list<array{id: int, label: string, slug: string}>} $data */
        $data = $result->data ?? ['schema' => [], 'values' => []];

        return Inertia::render('admin/site/Edit', [
            'schema' => $data['schema'],
            'values' => $data['values'],
            'frontPageChoices' => $data['frontPageChoices'] ?? [],
            /*
             * 指定したページが出せなくなっているときの理由（R-5）。
             *
             * 公開面は黙って既定のトップへ戻る。それだけだと編集者からは
             * **「設定が効いていない」としか見えない** — 何が起きたかはここで言う。
             */
            'frontPageNotice' => $frontPage->reason(),
            /*
             * ヘッダーのメニューが空のとき、公開側は**既定の導線へ落ちている**（B-28）。
             *
             * 訪問者にナビが無い状態は公開品質として通らないので落とさない。
             * ただし**黙って代替を出さない** — 運用者から見ると
             * 「メニューを空にしたのに何か出ている」だけになる。
             *
             * 判定は component と**同じ呼び出し**（`forKey`）である。2 つ目を作らない。
             */
            'navigationNotice' => $this->navigationNotice($menus),
            /*
             * `/contact` が従来のお問い合わせのままのとき、その理由（R-12 Stage 1）。
             *
             * **黙って従来のままにしない。** 何も出さないと、運用者は
             * 「フォームへ一本化されたつもり」で窓口が 2 つ在るまま納品する。
             */
            'contactNotice' => $contact->reason(),
            /*
             * 管理メニューから外している機能（B-39）。
             *
             * **「隠した」とは言わない** — route は閉じないと決めたので、
             * URL では開ける。**言い切ると嘘になる。**
             */
            'menuNotice' => $this->menuNotice(),
        ]);
    }

    /** 管理メニューから外している機能の説明。外していなければ null */
    private function menuNotice(): ?string
    {
        if (app(SettingsStore::class)->get('show_comments_menu', true) !== false) {
            return null;
        }

        return 'コメントは管理メニューに出していません。'
            .'機能そのものは止まっていないので、/admin/comments を直接開けば管理できます。';
    }

    /** 既定の導線が出ているか。出ていなければ null（言うことが無い） */
    private function navigationNotice(MenuTree $menus): ?string
    {
        if ($menus->forKey('header', request()->path()) !== []) {
            return null;
        }

        return DefaultNavigation::links() === []
            ? 'ヘッダーのメニューが空で、代わりに出せる導線もありません。公開ページにナビゲーションが出ていません。'
            : 'ヘッダーのメニューが空なので、公開ページには既定の導線を出しています。メニューを作ると、そちらが使われます。';
    }

    public function update(Request $request, UpdateSettings $updateSettings): RedirectResponse
    {
        $result = $updateSettings->execute($request->all(), $this->context($request));

        if ($result->status->value === 'validation_error') {
            return back()->withErrors($result->errors)->withInput();
        }

        if (! $result->isSuccess()) {
            return back()->withErrors(['site' => $result->message ?? '保存できませんでした']);
        }

        return back()->with('success', 'サイト設定を保存しました');
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::admin($user?->getAuthIdentifier(), (string) ($user->email ?? ''));
    }
}

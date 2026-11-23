<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Components\ListReusableComponents;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Components\Reference;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Components\ReusableReferences;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Render\DocumentCss;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 再利用 component の画面（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 専用の画面を持つ
 *
 * 実体は複数のページから参照されるので、**どのページから開いても同じものを直す**。
 * それはページの編集とは別の作業なので、画面も別にする。
 *
 * ## 部品は流用し、書き込みだけ別にする
 *
 * Canvas / Layers / Inspector はそのまま使う。**書き込みは
 * `/api/v1/components/reusable/...` へ送る** — `usePageDocument` を流用しないのは、
 * あれが `pageId` から URL を組み立てており、「送信先は全部 `/api/v1/pages/`」を
 * `PageAdminTest` が固定しているためである（流用するとその守りを緩める）。
 *
 * ## 読む口だけ置く
 *
 * 作る・直す・公開する・しまうは api/v1 を呼ぶ。ここは画面を返すだけである。
 */
final class ReusableComponentAdminController
{
    public function index(Request $request, ListReusableComponents $list): Response
    {
        $result = $list->execute($this->context($request), $request->boolean('archived'));

        return Inertia::render('admin/reusable-components/Index', [
            'components' => $result->data['components'] ?? [],
            'includeArchived' => $request->boolean('archived'),
            'editUrlBase' => route('admin.reusable-components.index', absolute: false),
        ]);
    }

    /**
     * 1 件の編集画面。
     *
     * **影響の数を最初から渡す。** 公開の確認はサーバーが出すが、
     * 直している人は「いくつのページに関わっているか」を**押す前に**知りたい。
     */
    public function editor(
        Request $request,
        string $id,
        ReusableReferences $references,
        ComponentRegistry $components,
        ThemeTokens $theme,
    ): Response {
        $component = ReusableComponent::query()->find($id);

        abort_if($component === null, 404, 'この再利用ブロックはありません');

        $found = $references->pagesUsing($component->id);
        $public = count(array_filter($found, static fn (Reference $r): bool => $r->affectsPublic()));

        return Inertia::render('admin/reusable-components/Editor', [
            'component' => [
                'id' => $component->id,
                'key' => $component->key,
                'label' => $component->label,
                'document' => $component->draft_document,
                'published' => $component->isPublished(),
                'archived' => ! $component->isActive(),
            ],
            'impact' => ['publicPages' => $public, 'draftPages' => count($found) - $public],
            // Inspector が入力欄を組み立てるための宣言（ページの編集画面と同じもの）
            'specs' => $components->specs(),
            'styles' => StyleProperties::specs($theme),
            'backUrl' => route('admin.reusable-components.index', absolute: false),
        ]);
    }

    /**
     * 中身を描いて見せる（Canvas が枠に入れて読む）。
     *
     * **`draft_document` を描く。** 編集画面が見たいのは編集中のもので、
     * preview は常に draft を描くという既存の作法に揃える（`PageAdminController::preview()`）。
     *
     * 実体は `page` の起点を持たないので、**その場で包んで**渡す。
     * ページの preview と同じ blade を使う — 描画を 2 つ持つと、
     * 「Canvas で見えたものと公開が違う」の原因になる。
     */
    public function preview(
        Request $request,
        string $id,
        DocumentRenderer $renderer,
        DocumentCss $documentCss,
        SettingsStore $settings,
    ): HttpResponse {
        $component = ReusableComponent::query()->find($id);

        abort_if($component === null, 404, 'この再利用ブロックはありません');

        /*
         * 描画に要るのは page の title / slug だけ（binding が読む）。
         * **保存しない** — 実体はページに属さないので、器として渡すだけである。
         */
        $page = new Page([
            'slug' => 'reusable-'.$component->key,
            'title' => $component->label,
            /*
             * 共有の blade は「現在の状態」を出す。**実体の公開状態をそのまま写す** —
             * ページの状態ではないが、利用者に伝わる意味は同じ（公開に出ているか）。
             * ここを空にすると案内の描画で落ちる。
             */
            'status' => $component->isPublished() ? PageStatus::Published : PageStatus::Draft,
        ]);

        $document = [
            'schemaVersion' => DocumentTree::SCHEMA_VERSION,
            'root' => [
                'id' => NodeId::generate(),
                'type' => 'page',
                'props' => [],
                'children' => [$component->draft_document],
            ],
        ];

        return response()
            ->view('admin.pages.preview', [
                'title' => $component->label,
                'page' => $page,
                'document' => $renderer->render(
                    $document,
                    new RenderContext($page, $settings->all(), preview: true),
                ),
                'themeCss' => $documentCss->build(),
                'backUrl' => route('admin.reusable-components.index', absolute: false),
                'canvas' => $request->boolean('canvas'),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name);
    }
}

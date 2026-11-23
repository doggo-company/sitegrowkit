<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Menus\DeleteMenu;
use AgentCms\Core\Application\Menus\GetMenu;
use AgentCms\Core\Application\Menus\ListMenus;
use AgentCms\Core\Application\Menus\SaveMenu;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuTree;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\Capability;
use App\Support\Industry\IndustrySetup;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * メニューの管理画面（Phase 9.9 / STAGE 22D・SPEC §F）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・認可・確認・監査・transaction・木の規則はすべて Application Service 側にある。
 *
 * ## API を保存経路にしない
 *
 * 管理画面の保存は `api/v1` を呼ばず、Admin Controller から同じ Application Service を
 * `OperationContext::admin()` で呼ぶ（既存の分類・投稿タイプと同じ方針）。
 *
 * ## 選べる先を渡す
 *
 * 「どのページを指せるか」を画面が自分で調べると、公開条件が 2 か所に分かれる。
 * 候補はここで作って渡す。**ゴミ箱にあるものは候補に出さない** —
 * 出すと「選べたのに公開面に出ない」項目を作らせることになる。
 */
final class MenuAdminController
{
    public function index(Request $request, ListMenus $list): Response
    {
        $result = $list->execute($this->context($request));

        $this->abortUnlessSuccess($result);

        return Inertia::render('admin/menus/Index', [
            'menus' => $result->data['menus'],
            'canWrite' => $this->canWrite($request),
        ]);
    }

    public function edit(Request $request, GetMenu $get, MenuTree $tree, string $key): Response
    {
        $result = $get->execute($key, $this->context($request));

        $this->abortUnlessSuccess($result);

        return Inertia::render('admin/menus/Edit', [
            'headerItems' => $key !== 'header' && Menu::query()->where('key', 'header')->exists()
                ? $get->execute('header', $this->context($request))->data['items'] : null,
            'copyHeader' => $key !== 'header' && $request->query('copy') === 'header' && $this->canWrite($request),
            'hasFooter' => Menu::query()->where('key', 'footer')->exists(),
            'menu' => $result->data['menu'],
            'items' => $result->data['items'],
            // 出せない項目には画面で印を付ける（消すかどうかは編集者が決める）
            'unavailable' => (object) $tree->unavailable($key),
            'choices' => $this->choices(),
            'canWrite' => $this->canWrite($request),
        ]);
    }

    public function update(Request $request, SaveMenu $save, string $key): RedirectResponse
    {
        $result = $save->execute(
            $key,
            [
                'name' => $request->input('name'),
                'description' => $request->input('description'),
            ],
            // 送られてきた木。連想配列で来ても list として扱う（添字は使わない）
            array_values(is_array($request->input('items')) ? $request->input('items') : []),
            $this->context($request),
            LockVersionInput::fromRequest($request->all()),
        );

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        // 重なったときは画面で言う（UX 一周 6 周目 R6-3: 開発者向けの英語のエラー画面が出ていた）。
        // 画面は最初の誤りを「読み直してください」の帯に出す（menus/Edit.vue の onError）
        if ($result->status === OperationStatus::Conflict) {
            throw ValidationException::withMessages([
                'lock_version' => (string) ($result->message ?: 'ほかの操作と重なったため保存できませんでした。画面を読み込み直してください。'),
            ]);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', 'メニューを保存しました');
    }

    public function destroy(Request $request, DeleteMenu $delete, string $key): RedirectResponse
    {
        $result = $delete->execute($key, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        $this->abortUnlessSuccess($result);

        return redirect()->route('admin.menus.index')->with('success', 'メニューを削除しました');
    }

    /**
     * 指せる先の候補。
     *
     * **公開できるものだけ**を出す。ゴミ箱にあるものや下書きを候補に出すと、
     * 選べたのに公開面へ出ない項目を作らせてしまう。
     *
     * @return array<string, mixed>
     */
    private function choices(): array
    {
        $front = app(FrontPage::class)->resolve()?->getKey();

        $pages = Page::query()
            ->whereNull('trashed_at')
            ->orderBy('slug')
            ->get()
            ->filter(static fn (Page $page): bool => $page->isPublicallyVisible())
            ->map(static fn (Page $page): array => [
                'id' => (int) $page->getKey(),
                'label' => $page->label(),
                'slug' => (string) $page->slug,
                // トップに選んだページ（= ホーム）。足す項目の初期値には使わない（UX 一周 2026-09-28）
                'isFront' => $page->getKey() === $front,
            ])
            ->values()
            ->all();

        $types = [];

        foreach (app(SchemaRegistry::class)->all() as $id => $schema) {
            if (! app(ContentTypeLifecyclePolicy::class)->isPublic($id)) {
                continue;
            }

            // 店が付けた呼び名で出す（工務店なら「施工事例」。UX 一周 第10周: 「投稿」しか無く、探せなかった）
            $types[] = ['id' => $id, 'label' => app(ContentTypeSettingsResolver::class)->for($id)->labelOr($schema->label)];
        }

        return [
            'pages' => $pages,
            'contentTypes' => $types,
            // 組み込みページ。**在るものだけ**（module が無い生成物では消える）
            'routes' => $this->routes(),
            // 分類の項目（WP 一周 7 周目 2026-09-03）
            'terms' => $this->terms(),
        ];
    }

    /**
     * 分類の一覧を指せる項目（WP 一周 7 周目 2026-09-03 / 効くもの ③）。
     *
     * 分類ごとの一覧ページは作ったのに、メニューからは選べず
     * `URL を直接指定` で手打ちするしかなかった。それだと
     * **項目の綴りを変えた瞬間に、メニューが黙って 404 になる**。
     *
     * 出すのは**公開面に出るものだけ** — 無効にした分類とアーカイブ済みの項目は
     * 選べても描くときに消えるので、最初から出さない
     *（`TermLink` / `AssignedTerms` / `TermArchive` と同じ条件）。
     *
     * @return list<array{id: string, label: string, taxonomy: string}>
     */
    private function terms(): array
    {
        $rows = DB::table('taxonomy_terms as t')
            ->join('taxonomies as x', 'x.id', '=', 't.taxonomy_id')
            ->where('x.status', 'active')
            ->whereNull('t.archived_at')
            ->orderBy('x.key')
            ->orderBy('t.sort_order')
            ->orderBy('t.id')
            ->get(['t.id', 't.name', 'x.label as taxonomy_label', 'x.key as taxonomy_key']);

        $terms = [];

        foreach ($rows as $row) {
            $taxonomy = ((string) $row->taxonomy_label) !== ''
                ? (string) $row->taxonomy_label
                : (string) $row->taxonomy_key;

            $terms[] = [
                'id' => (string) $row->id,
                'label' => (string) $row->name,
                'taxonomy' => $taxonomy,
            ];
        }

        return $terms;
    }

    /** @return list<array{name: string, label: string}> */
    private function routes(): array
    {
        $known = [
            'home' => 'ホーム',
            'about' => '私たちについて',
            // 店の呼び名で出す（カフェなら「メニュー」）。ナビに出る名前と同じ言葉にする
            'service' => app(IndustrySetup::class)->serviceNoun(),
            'contact.show' => 'お問い合わせ',
        ];

        $routes = [];

        foreach ($known as $name => $label) {
            if (RouteFacade::has($name)) {
                $routes[] = ['name' => $name, 'label' => $label];
            }
        }

        return $routes;
    }

    /** 投稿の候補は型ごとに引く（一覧を全部積まない） */
    public function entries(Request $request, string $type): JsonResponse
    {
        $schemas = app(SchemaRegistry::class);

        abort_unless($schemas->has($type), 404);

        $table = $schemas->get($type)->table;
        $search = trim((string) $request->query('q', ''));

        $rows = DB::table($table)
            ->when($search !== '', static fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->when(
                Schema::hasColumn($table, 'deleted_at'),
                static fn ($q) => $q->whereNull('deleted_at'),
            )
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'title', 'slug']);

        return response()->json([
            'entries' => $rows->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'label' => (string) $row->title,
            ])->all(),
        ]);
    }

    /**
     * 保存できる利用者か。
     *
     * **画面の導線を出すかどうかだけ**を決める。実際の拒否は operation 側の
     * `OperationPolicy` が行う（画面で隠しても、経路は塞がない）。
     */
    private function canWrite(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return in_array(
            Capability::SettingsManage->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($user),
            true,
        );
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    private function abortUnlessSuccess(Result $result): void
    {
        if ($result->isSuccess()) {
            return;
        }

        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, (string) $result->message);
    }
}

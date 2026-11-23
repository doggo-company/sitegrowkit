<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Components\ListReusableComponents;
use AgentCms\Core\Application\Content\ListContentTypes;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\DuplicatePage;
use AgentCms\Core\Application\Pages\GetPage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\ListPages;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Pages\RestorePage;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Application\Pages\UnpublishPage;
use AgentCms\Core\Application\Pages\UpdatePageMetadata;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Document\BindingTypes;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Render\DocumentCss;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;
use App\Models\User;
use App\Support\Capability;
use App\Support\DocumentPageView;
use App\Support\LinkTargets;
use App\Support\PageEditorSummary;
use App\Support\RoleOperationPolicy;
use App\Support\SamplePages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ページの管理画面（Phase 9.3B / #19・SPEC §V.1〜§V.2 / §W / §Y / §Z）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・認可・確認・監査・publish 検証はすべて Application Service 側にある。
 *
 * ## API を保存経路にしない（§V.2-(2)）
 *
 * mutation は `api/v1` を呼ばず、**Admin Controller から同じ Application Service を
 * `OperationContext::admin()` で**呼ぶ。API は programmatic surface として別に維持する。
 *
 * ## 公開範囲
 *
 * route は **10 本だけ**（§V.2-(1)、Phase 9.6 / P0-C2 で Visual Page Editor の画面を 1 本追加）。
 * purge / duplicate / document mutation / 共有 preview token は置かない。
 * 本文ブロックの編集は Visual Page Editor（`docs/PHASE9_6_SPEC.md`）の範囲で、
 * **その変更経路は `page.document.patch` だけ**。ここには置かない。
 */
final class PageAdminController
{
    public function index(Request $request, ListPages $list, SamplePages $samples): Response
    {
        // ゴミ箱は「別の一覧」として明示的に切り替える（混ぜて見せない）
        $trashed = $request->boolean('trashed');
        $result = $list->execute($this->context($request), $trashed ? 'trashed' : '', $trashed);

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/pages/Index', [
            'items' => $result->data['items'] ?? [],
            'trashed' => $trashed,
            /*
             * 同梱の見本ページ（UX 一周 2026-09-27）。公開前チェックが「消してください」と
             * 言う `/` `/about` `/service` `/contact` はページではないので、
             * **一覧に出ず、直す場所が分からなかった。**直し方ごと並べる。
             */
            'samples' => $trashed ? [] : $samples->forAdmin(),
            'canManageSamples' => $this->canManageSite($request),
        ]);
    }

    /**
     * 新規作成。**見本ページの一覧から来たときは、タイトルと URL を埋めておく**
     * （`/about` を作り直す人に、英字の URL を考えさせない — UX 一周 2026-09-27）。
     * 入れておくだけで、確定は本人が「作成する」を押したとき。検証は store 側が持つ。
     */
    public function create(Request $request): Response
    {
        $title = $request->query('title');
        $slug = $request->query('slug');

        return Inertia::render('admin/pages/Create', [
            // 見本と同じ名前を打ったら、見本と入れ替わると言う（R4-5）
            'samples' => collect(app(SamplePages::class)->replaceable())
                ->map(fn (string $title, string $route): array => ['title' => $title, 'path' => '/'.$route])
                ->values()->all(),
            'initial' => [
                'title' => is_string($title) ? mb_substr($title, 0, 200) : '',
                'slug' => is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) === 1 ? $slug : '',
            ],
        ]);
    }

    /**
     * 公開中のページで、まだトップに選んでいないなら true。
     *
     * @param  array<string, mixed>  $page
     */
    private function frontPageOffer(Request $request, array $page): bool
    {
        $id = (int) ($page['id'] ?? 0);

        return $this->canManageSite($request)
            && $this->publicUrl($page) !== null
            && app(FrontPage::class)->selectedId() !== $id;
    }

    private function canManageSite(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && in_array(
            Capability::SettingsManage->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($user),
            true,
        );
    }

    /** 作りの画面（`site.build`）を開ける人か。制作者だけ */
    private function canBuildSite(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && in_array(
            Capability::SiteBuild->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($user),
            true,
        );
    }

    /**
     * 入力は **transport 専用キーだけを外して**そのまま Application Service へ渡す。
     *
     * `only(['title','slug'])` で絞ると、明示的に送られた `managedBy` や未知キーを
     * **controller が黙って捨てて**しまい、Phase 9.3B / #17 の MB-3（明示送信は拒否）と
     * MB-5（経路によらず同じ挙動）が Admin だけ破れる。
     * 受理キーの判定は `CreatePage` が一元的に行う契約なので、ここでは判定しない。
     * UI が送るのは `title` / `slug` だけ。
     */
    public function store(Request $request, CreatePage $create, SamplePages $samples): RedirectResponse
    {
        $payload = $this->payload($request);
        // 画面に出していた URL の候補（作った URL と違えば、そう言う。UX 一周 19 周目 U3）
        $shown = $payload['slug_preview'] ?? null;
        unset($payload['slug_preview']);
        // 見本と同じ名前なら、見本の URL で作る（公開すると見本の代わりに出る。UX 一周 4 周目 R4-5）
        $replacing = trim((string) ($payload['slug'] ?? '')) === '' && is_string($payload['title'] ?? null)
            ? $samples->replaceableRouteFor($payload['title'])
            : null;

        if ($replacing !== null) {
            $payload['slug'] = $replacing;
        }

        $result = $create->execute($payload, $this->context($request));

        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        // URL を空のまま作った人には、付いた URL を言う（どの URL になったかを知らない）
        $slug = (string) ($result->data['page']['slug'] ?? '');
        $drafted = trim((string) $request->input('slug', '')) === '' && $slug !== '';

        if ($replacing !== null) {
            return $this->toEdit((int) $result->data['page']['id'], "ページを作成しました（URL: /{$slug}）。公開すると、同じ名前の見本の代わりにこのページが出ます");
        }

        if ($drafted && is_string($shown) && $shown !== '' && $shown !== $slug) {
            return $this->toEdit((int) $result->data['page']['id'], "ページを作成しました。URL は /{$slug} です（画面に出ていた /{$shown} は、ほかのページが使っていました）");
        }

        return $this->toEdit((int) $result->data['page']['id'], $drafted ? "ページを作成しました（URL: /{$slug}）" : 'ページを作成しました');
    }

    /**
     * 作成の画面の URL の候補（UX 一周 19 周目）。読みはサーバーの形態素解析（IPADIC）。
     * **使われている URL は、出す前に -2 を付けて見せる**（作るときと同じ決め方）。
     */
    public function slugPreview(Request $request, CreatePage $create): JsonResponse
    {
        $title = mb_substr(trim((string) $request->query('title', '')), 0, 200);

        return response()->json(['data' => ['slug' => $title === '' ? '' : $create->previewSlug($title)]]);
    }

    /** 保存済み本文を別の下書きへ複製し、その設定画面を開く。 */
    public function duplicate(Request $request, DuplicatePage $duplicate, int $id): RedirectResponse
    {
        $result = $duplicate->execute($id, $this->context($request));
        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        return $this->toEdit((int) $result->data['page']['id'], '下書きとして複製しました');
    }

    public function edit(Request $request, GetPage $get, PageEditorSummary $summary, int $id): Response
    {
        $result = $get->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        /** @var array<string, mixed> $page */
        $page = $result->data['page'];

        return Inertia::render('admin/pages/Edit', [
            'page' => $page,
            'previewUrl' => route('admin.pages.preview', ['id' => $id], absolute: false),
            // 公開ページを開けるのは **公開中の document ページ**だけ（無効なリンクを出さない）
            'publicUrl' => $this->publicUrl($page),
            // 構成サマリ・公開可否・依頼文（read-only。document 全文は渡さない — §X）
            'summary' => $summary->forPage($page),
            // トップ用に作ったページなら、トップにする一手をここに出す（UX 一周 7 周目 R7-4）
            'frontPageOffer' => $this->frontPageOffer($request, $page),
            'frontPageSelected' => app(FrontPage::class)->resolve()?->getKey() === $id,
        ]);
    }

    /**
     * Visual Page Editor（Phase 9.6 / P0-C2・`PHASE9_6_SPEC.md` §4.1 / §4.2）。
     *
     * **この route は画面を配るだけで、document を変更しない。**
     * Canvas / Layers からの変更は `page.document.patch` 1 本だけを通る
     * （同一オリジンの操作 API 側。同 §5.1 / Owner Gate V2）。
     * 管理画面側に 2 本目の変更経路を作らないため、ここに mutation は置かない。
     *
     * パレットに出す component は **サーバーが決める**。`html` は sanitize 済みでも
     * 「任意の HTML を貼れる面」なので初期版では渡さない（同 §3.1 / Owner Gate V1）。
     */
    public function editor(
        Request $request,
        GetPage $get,
        GetPageDocument $document,
        ComponentRegistry $components,
        ListContentTypes $contentTypes,
        ListReusableComponents $reusables,
        ThemeTokens $theme,
        int $id,
    ): Response {
        $result = $get->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        /** @var array<string, mixed> $page */
        $page = $result->data['page'];

        // コードで管理されたページは document を持たない。編集する対象が無い
        abort_if(($page['managedBy'] ?? null) !== Page::MANAGED_BY_DOCUMENT, 404, 'このページは document で管理されていません');

        $documentResult = $document->execute($id, $this->context($request));

        if (! $documentResult->isSuccess()) {
            $this->abortFor($documentResult);
        }

        return Inertia::render('admin/pages/Editor', [
            'page' => $page,
            'document' => $documentResult->data['document'],
            // 楽観ロックの基準。patch のたびに更新される
            'version' => $documentResult->meta['version'] ?? null,
            // 編集中の気づき。**保存も公開も止めない**（P0-C8 / Owner 決定）
            'accessibility' => $documentResult->meta['accessibility'] ?? [],
            'components' => self::palette(
                $components,
                // 一覧 component は参照先が無いと検証を通らない（参照の作り込みは P0-C5）
                (string) (($contentTypes->execute($this->context($request))->data['types'][0]['id'] ?? '')),
            ),
            // 既にある node を読み書きするための宣言（`html` を含む **登録された全種類**）。
            // パレットに出さないことと、既にあるものを編集できないことは別（Owner Gate V1）
            'specs' => self::editorSpecs($components),
            // binding の接続先。**表は渡さない** — 解いた結果だけ（§4.9 / P0-C5）
            'bindingSources' => self::bindingSources(app(SchemaRegistry::class)),
            /*
             * 使える再利用 component（§4.5 / P0-C7）。**中身は渡さない** —
             * 画面が要るのは「名前」だけで、部分木は描画側が引く。
             * 一覧に中身を載せると、参照の数だけ応答が膨らむ。
             */
            'reusables' => $reusables->execute($this->context($request))->data['components'] ?? [],
            // 再利用ブロックの編集は作りの画面（制作者だけ）。開けない人には入口を渡さない（UX 一周 2026-09-28）
            'reusableEditUrl' => $this->canBuildSite($request)
                ? route('admin.reusable-components.index', absolute: false)
                : null,
            // Inspector が入力欄を組み立てるための宣言。**ここに無いものは出せない**
            'styles' => StyleProperties::specs($theme),
            'tokens' => self::tokenChoices($components, $theme),
            // トークンの表示名（`gapSmall` → 狭い）。知らない名前はそのまま出す
            'tokenLabels' => StyleProperties::valueLabels(),
            // ボタンのリンク先の候補（公開面で開けるページだけ。UX 一周 2026-09-28）
            'linkTargets' => app(LinkTargets::class)->all(),
            // 画面幅は theme.json の宣言が唯一の出どころ。名前も px も画面へ焼き込まない
            'breakpoints' => self::breakpoints($theme),
            // Canvas は公開と同じ描画を読む（Editor 専用の描画を作らない — §4.1）
            'canvasUrl' => route('admin.pages.preview', ['id' => $id], absolute: false).'?canvas=1',
            'backUrl' => route('admin.pages.edit', ['id' => $id], absolute: false),
            'frontPageOffer' => $this->frontPageOffer($request, $page),
            'frontPageSelected' => app(FrontPage::class)->resolve()?->getKey() === $id,
        ]);
    }

    /**
     * Canvas から追加できる component と、**追加した瞬間に検証を通る既定値**。
     *
     * 既定値をここで決めるのは、component の型名を画面へ焼き込ませないため
     * （Registry は実行時に取得する契約 — Phase 9.1A K）。
     * 「押せるのに置けない」ボタンを出さないための責任もサーバー側にある。
     *
     * @return list<array<string, mixed>>
     */
    private static function palette(ComponentRegistry $components, string $contentType): array
    {
        $palette = [];

        foreach (self::editorSpecs($components) as $spec) {
            if (($spec['type'] ?? null) === 'html') {
                continue;
            }

            $spec['defaults'] = self::defaultsFor($spec, $contentType);
            $palette[] = $spec;
        }

        return $palette;
    }

    /** @return list<array<string, mixed>> */
    private static function editorSpecs(ComponentRegistry $components): array
    {
        $specs = $components->specs();
        foreach ($specs as &$spec) {
            // 内部キーの手入力を求めず、公開中のフォームを名前で選べるようにする。
            if (($spec['type'] ?? null) === 'form') {
                $spec['propsSchema']['formKey']['options'] = Form::query()
                    ->whereIn('status', ['published', 'closed'])->orderBy('name')->get()
                    ->filter(fn ($form): bool => app(FormFields::class)->for($form) !== [])
                    ->map(fn ($form): array => ['value' => $form->key, 'label' => $form->name.'（'.$form->status()->label().'）'])
                    ->values()->all();
            }
        }
        unset($spec);

        return $specs;
    }

    /** 画面幅の呼び名（UX 一周 2026-09-28: `mobile` などがそのまま出ていた）。知らない名前はそのまま */
    private const BREAKPOINT_LABELS = [
        'mobile' => 'スマホ',
        'tablet' => 'タブレット',
        'desktop' => 'パソコン',
    ];

    /**
     * 画面幅の宣言（`content/theme.json`）。
     *
     * Canvas の幅は **その画面幅が効き始める境目**を使う。
     * 一番狭い段は下限が 0 なので、代わりに上限（次の段の 1px 手前）を使う。
     * どちらも theme.json から導いた値で、画面側には持たせない。
     *
     * @return list<array{name: string, label: string, condition: string, width: int}>
     */
    private static function breakpoints(ThemeTokens $theme): array
    {
        $sizes = $theme->breakpoints();
        asort($sizes);

        $names = array_keys($sizes);
        $out = [];

        foreach (array_values($theme->breakpointRanges()) as $index => $condition) {
            $name = (string) $names[$index];
            $min = (int) $sizes[$name];
            $next = $names[$index + 1] ?? null;

            $out[] = [
                'name' => $name,
                // 画面に出す呼び名（UX 一周 2026-09-28: `mobile` などがそのまま出ていた）
                'label' => self::BREAKPOINT_LABELS[$name] ?? $name,
                'condition' => $condition,
                'width' => $min > 0 ? $min : (int) ($sizes[$next] ?? 0) - 1,
            ];
        }

        return $out;
    }

    /**
     * binding の接続先（Phase 9.6 / P0-C5・SPEC §4.9）。
     *
     * ## 何を渡し、何を渡さないか
     *
     * 型整合の**表は渡さない**。どの Field がどの prop へ流せるかは
     * `specs[].accepts` として**解いた結果**が入っている。画面は
     * 「この prop の `accepts` に、この Field の型が入っているか」を見るだけでよい。
     *
     * `page` の項目は `BindingTypes::PAGE_FIELDS` から出す。画面に `title` / `slug` を
     * 焼き込むと、`BindingResolver` が解決する集合とずれたときに気づけない。
     *
     * **`settings` は入れていない。** schema を持たない source なので型が引けず、
     * UI から新しく作れるようにすると**型検査の効かない binding を画面が量産する**。
     * 既にある settings binding は正当なものとして表示し、外すこともできる
     * （作れないだけである）。settings が schema を持つ日に見直す。
     *
     * @return array<string, mixed>
     */
    private static function bindingSources(SchemaRegistry $schemas): array
    {
        $entry = [];
        $collection = [];

        foreach ($schemas->all() as $schema) {
            $collection[] = ['type' => $schema->id, 'label' => $schema->label];

            $entry[] = [
                'type' => $schema->id,
                'label' => $schema->label,
                'fields' => array_map(
                    static fn (Field $field): array => [
                        'key' => $field->key,
                        'label' => $field->label,
                        'type' => $field->type,
                    ],
                    $schema->fields,
                ),
            ];
        }

        $page = [];

        foreach (BindingTypes::PAGE_FIELDS as $key => $spec) {
            $page[] = ['key' => $key, 'label' => $spec['label'], 'type' => $spec['type']];
        }

        return ['entry' => $entry, 'collection' => $collection, 'page' => $page];
    }

    /**
     * `type: token` の prop が選べるトークン名。
     *
     * 対象のグループは **component の宣言から拾う**（画面にグループ名を焼き込まない）。
     *
     * @return array<string, list<string>>
     */
    private static function tokenChoices(ComponentRegistry $components, ThemeTokens $theme): array
    {
        $groups = [];

        foreach ($components->specs() as $spec) {
            /** @var array<string, array<string, mixed>> $schema */
            $schema = $spec['propsSchema'] ?? [];

            foreach ($schema as $prop) {
                if (($prop['type'] ?? null) === 'token' && is_string($prop['group'] ?? null)) {
                    $groups[$prop['group']] = true;
                }
            }
        }

        $choices = [];

        foreach (array_keys($groups) as $group) {
            $choices[$group] = $theme->tokenNames($group);
        }

        return $choices;
    }

    /**
     * 値を入れずに置く文字の欄（UX 一周 3 周目 N3）。
     *
     * 以前は表示名（「見出し」「リッチテキスト」）を**値として**入れていたので、欄を押して打つと
     * `見出し腰痛・肩こりの施術` のようにくっつき、そのまま保存・公開された。
     * ここに挙げた欄は空のまま置き、何を書く場所かは仮の文字（プレースホルダ）で出す —
     * 右の欄は `placeholder`、Canvas とプレビューは各 view の `doc-placeholder`。公開面には出ない。
     * どれも binding できる欄なので、空でも DocumentValidator を通る。
     */
    private const PLACEHOLDER_ONLY = [
        'heading' => ['text'],
        'text' => ['text'],
        'richtext' => ['html'],
    ];

    /**
     * 追加時の初期値。宣言から導ける範囲だけを埋める。
     *
     * - `default` があればそれ
     * - 見出し・テキスト・リッチテキストの文字は**空のまま**（`PLACEHOLDER_ONLY`）
     * - それ以外の必須の文字列は **component の表示名**を仮の文言として置く（空だと Canvas に何も出ない）
     * - 必須のリンク先はページ内アンカー
     * - 一覧は参照先が無いと検証を通らないので、既定の投稿タイプへ繋ぐ
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function defaultsFor(array $spec, string $contentType): array
    {
        $props = [];

        /** @var array<string, array<string, mixed>> $schema */
        $schema = $spec['propsSchema'] ?? [];

        foreach ($schema as $key => $prop) {
            if (array_key_exists('default', $prop)) {
                $props[$key] = $prop['default'];

                continue;
            }

            if (($prop['required'] ?? false) !== true) {
                continue;
            }

            if (in_array($key, self::PLACEHOLDER_ONLY[(string) ($spec['type'] ?? '')] ?? [], true)) {
                continue;
            }

            $value = match ((string) ($prop['type'] ?? '')) {
                'string' => (string) ($spec['label'] ?? $spec['type']),
                'url' => '#',
                default => null,
            };

            if ($value !== null) {
                $props[$key] = $value;
            }
        }

        $defaults = ['props' => $props];

        // 一覧は binding が無いと描画できない（DocumentValidator と同じ判断）
        if (($spec['type'] ?? null) === 'collection' && $contentType !== '') {
            $defaults['binding'] = ['source' => 'collection', 'type' => $contentType];
        }

        return $defaults;
    }

    /**
     * metadata（`title` / `slug` / `seo`）だけ。本文 document はここでは触らない。
     *
     * **store と扱いが違う理由。**
     * `CreatePage` は受理キー以外を拒否する contract を持つので、store は
     * transport キーだけ外して全部渡し、判定を Application Service に委ねる（MB-3 / MB-5）。
     * 一方 `UpdatePageMetadata` は `sometimes` ルールで **検証済みのキーだけ**を保存する。
     * ここへ余計なキーを渡しても「黙って無視される」だけで、拒否にはならない。
     * それなら controller が **何を更新するのかを明示**した方が境界が読める。
     */
    public function update(Request $request, UpdatePageMetadata $update, int $id): RedirectResponse
    {
        $result = $update->execute(
            $id,
            $request->only(['title', 'slug', 'seo']),
            $this->context($request),
        );

        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        return $this->toEdit($id, 'ページ設定を保存しました');
    }

    /**
     * 下書きのプレビュー（§Y）。
     *
     * - 認証・verified・`OperationPolicy` を必ず通る（共有 token は作らない）
     * - **draft version を読むだけ**。GET で PageVersion を作らない
     * - 検索エンジンにもキャッシュにも残さない（noindex / no-store）
     * - 公開 URL は published version を描画する。ここは常に draft
     */
    public function preview(
        Request $request,
        GetPage $get,
        DocumentRenderer $renderer,
        SettingsStore $settings,
        DocumentCss $documentCss,
        DocumentPageView $documentPage,
        int $id,
    ): HttpResponse {
        $result = $get->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        $page = Page::query()->findOrFail($id);

        // Blade 管理のページは document を持たない。プレビューする対象が無い
        abort_if(! $page->isDocumentManaged(), 404, 'このページは document で管理されていません');

        $version = PageVersion::query()->find($page->draft_version_id);

        abort_if($version === null, 404, 'このページには下書きがありません');

        // preview であることを描画側へ明示する。将来 binding / renderer が
        // preview を区別したときに、ここだけ false のままだと契約違反になる
        $context = new RenderContext($page, $settings->all(), preview: true);

        // トップに選ばれているページは、Canvas でもトップ（ヒーロー）として描く
        $isFront = app(FrontPage::class)->selectedId() === $page->getKey();

        $response = response()->view('admin.pages.preview', [
            // 公開時と同じ枠・CSSを使い、取り込みページの見た目を編集画面でも確認できる。
            'layout' => $documentPage->layoutFor($page),
            // 公開ページと同じ帯（UX 一周 第 16 周: Canvas に帯が無く、公開して初めて 2 重の見出しに気づいた）
            'band' => $documentPage->bandFor($page, $version->document, $isFront),
            'siteFrame' => $documentPage->layoutFor($page) === DocumentPageView::SITE_LAYOUT,
            'title' => $page->title,
            'page' => $page,
            'document' => $renderer->render($version->document, $context),
            'themeCss' => $documentCss->build(),
            'backUrl' => route('admin.pages.edit', ['id' => $id], absolute: false),
            // Visual Page Editor の Canvas から読まれているか（案内の出し分けだけに使う）
            'canvas' => $request->boolean('canvas'),
        ]);

        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store, max-age=0')
            // Visual Page Editor の Canvas が **同じオリジンから**枠に入れて読む（Phase 9.6 / P0-C2）。
            // 他オリジンからは入れられない。加えて auth + verified + OperationPolicy を通った人しか
            // 開けず、共有 token も無いので、枠に入れられる相手は結局この管理画面だけになる
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'");
    }

    /**
     * 公開（§V.2-(3) / F-P2 の是正確認）。
     *
     * Admin の「公開する」操作自体が人間の確認なので、**1 回の POST で完了**する。
     * 空 document は Phase 9.3B / #18 の publish 専用検証で validation error になる。
     */
    public function publish(Request $request, PublishPage $publish, int $id): RedirectResponse
    {
        // 公開日時は **潰さずに渡す**（空欄は「いま」。読めるかは Application 層が決める）
        $result = $publish->execute($id, $this->context($request), $request->input(PublishPage::INPUT_KEY));

        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        /** @var array<string, mixed> $page */
        $page = $result->data['page'];

        $message = ($result->meta['scheduled'] ?? false) === true
            ? '公開を予約しました（'.$page['publishedAtLocal'].' '.$page['timezone'].'）'
            : 'ページを公開しました';

        if ($this->frontPageOffer($request, $page) && app(FrontPage::class)->selectedId() === null) {
            $message .= '。このページをトップページにするには、下のボタンを押してください';
        }

        // ブロック編集の画面から押したときは、その画面に留まる（触り心地 2026-11-05 / docs/feel-bar.md）
        if ($request->input('from') === 'editor') {
            $this->toast($message);

            return redirect()->route('admin.pages.editor', ['id' => $id]);
        }

        return $this->toEdit($id, $message);
    }

    /** 本文と履歴を残して、公開・予約を取り消す。 */
    public function unpublish(Request $request, UnpublishPage $unpublish, int $id): RedirectResponse
    {
        $result = $unpublish->execute($id, $this->context($request));
        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        return $this->toEdit($id, '公開・予約を取り消し、下書きへ戻しました');
    }

    /** ゴミ箱へ移す。破壊的操作なので既存 ConfirmationGate の 2 段階を通る */
    public function trash(Request $request, TrashPage $trash, int $id): RedirectResponse
    {
        $result = $trash->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        $this->toast('ページをゴミ箱へ移しました');

        return redirect()->route('admin.pages.index');
    }

    /** 復元は **下書きへ戻す**。勝手に再公開しない（§V.2-(9)） */
    public function restore(Request $request, RestorePage $restore, int $id): RedirectResponse
    {
        $result = $restore->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            return $this->failureFor($result);
        }

        return $this->toEdit(
            $id,
            '下書きに戻りました。公開するには「公開する」を押してください',
        );
    }

    /**
     * 失敗した `Result` を管理画面の表現へ写す（**1 箇所に集約**）。
     *
     * | status | 画面の挙動 |
     * | --- | --- |
     * | ValidationError | Inertia の form errors |
     * | ConfirmationRequired | 確認内容を flash して元の画面へ（既存 ConfirmationDialog が拾う） |
     * | Conflict | 理由つきの form error（何が競合したか分かる文言をそのまま出す） |
     * | NotFound / Forbidden | 404 / 403 |
     */
    private function failureFor(Result $result): RedirectResponse
    {
        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            throw ValidationException::withMessages([
                'conflict' => [$result->message ?? '同時に別の変更があったため実行できませんでした'],
            ]);
        }

        $this->abortFor($result);
    }

    /** 成功は既存の toast（`Inertia::flash('toast')`）で伝える。ボタン名と文言を揃える */
    private function toEdit(int $id, string $message): RedirectResponse
    {
        $this->toast($message);

        return redirect()->route('admin.pages.edit', ['id' => $id]);
    }

    private function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * transport 専用キーだけを外した外部入力。
     *
     * `_token` / `_method` は HTTP の都合であって業務入力ではない。
     * **それ以外は 1 つも落とさず**渡し、受理可否は Application Service に決めさせる。
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        /** @var array<string, mixed> $input */
        $input = $request->except(['_token', '_method', 'confirmation_token']);

        return $input;
    }

    /**
     * 公開ページの URL。開けない状態（下書き / 公開予約中 / ゴミ箱 / blade 管理）では null。
     *
     * @param  array<string, mixed>  $page  GetPage::present() の結果
     */
    private function publicUrl(array $page): ?string
    {
        // `status` ではなく publishState を見る — 予約中は status が published でも開けない
        $isPublished = ($page['publishState'] ?? null) === 'published';
        $isDocument = ($page['managedBy'] ?? null) === Page::MANAGED_BY_DOCUMENT;
        $slug = $page['slug'] ?? null;

        if (! $isPublished || ! $isDocument || ! is_string($slug) || $slug === '') {
            return null;
        }

        return '/'.$slug;
    }

    /** 誰が・どの経路で操作しているか（確認トークンがあれば同梱） */
    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    /** 失敗した Result を HTTP の例外へ写す */
    private function abortFor(Result $result): never
    {
        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, $result->message ?? '');
    }
}

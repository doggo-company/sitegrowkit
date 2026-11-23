<?php

declare(strict_types=1);

namespace AgentCms\Core\Admin;

use AgentCms\Core\Application\Content\AutosaveContent;
use AgentCms\Core\Application\Content\ContentTaxonomyInput;
use AgentCms\Core\Application\Content\ContentTaxonomyReader;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\DuplicateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Content\RestoreContent;
use AgentCms\Core\Application\Content\Revisions\ListContentRevisions;
use AgentCms\Core\Application\Content\Revisions\RestoreContentRevision;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\GetTaxonomy;
use AgentCms\Core\Application\Taxonomy\ListTerms;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Content\SchemaModel;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Ordering\SortOrderReadiness;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Taxonomy\AssignedTerms;
use AgentCms\Core\Uploads\UploadValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Schema 駆動の管理画面 CRUD 基底クラス（Extension Point）。
 * モジュールは schemaId / modelClass を返すだけで一覧・作成・編集・削除を得る。
 * 個別画面の手書き CRUD は禁止（.agentcms/rules/boundaries.md）。
 *
 * Phase 9.1B 以降、**業務ロジックは Application Service 側**にある。
 * ここがやるのは request の受け取り → service 呼び出し → Inertia / redirect だけ。
 */
abstract class SchemaResourceController
{
    /**
     * 「このフォームは分類を管理していた」ことを伝える印（multipart 対策 — `taxonomies()` 参照）。
     * **分類の値そのものではない**ので、通常フィールドとしても Application Service へ渡さない。
     */
    private const TAXONOMIES_MANAGED_KEY = 'taxonomies_managed';

    /** 一覧の状態（URL の `?state=`）。画面は 1 つで、状態が 2 つある */
    public const STATE_ACTIVE = 'active';

    public const STATE_TRASHED = 'trashed';

    abstract protected function schemaId(): string;

    /** @return class-string<Model&SchemaModel> */
    abstract protected function modelClass(): string;

    protected function schema(): ContentSchema
    {
        return $this->modelClass()::schema();
    }

    /**
     * 画面で言う型の名前は**店が付けた呼び名**（工務店なら「施工事例」、カフェなら「お知らせ」）。
     *
     * サイドバーとダッシュボードは呼び名で出ているのに、一覧・新規作成・保存の知らせが
     * 型の名前（投稿）のままだと、同じものに 2 つの名前が付いて見える（UX 一周 第 15 周）。
     */
    protected function displayLabel(): string
    {
        return app(ContentTypeSettingsResolver::class)->for($this->schemaId())->labelOr($this->schema()->label);
    }

    /** @return array<string, mixed> `toAdminSpec()` の `label` を呼び名に差し替えたもの */
    protected function adminSpec(): array
    {
        return array_replace($this->schema()->toAdminSpec(), ['label' => $this->displayLabel()]);
    }

    public function index(Request $request, ListContent $list): Response
    {
        /*
         * 通常の一覧とゴミ箱の一覧は **同じ画面の別の状態**である（Phase 9.8 / STAGE 21E）。
         *
         * 画面を 2 つ作らないのは、列も検索も同じだからである。違うのは
         * 「どちらの状態を見ているか」と、行に出す操作だけ。
         * 検索語とページは状態ごとに URL で持つので、切り替えても混ざらない。
         */
        $trashed = $request->query('state') === self::STATE_TRASHED;
        $search = trim((string) $request->query('q', ''));
        $items = $list->paginate(
            $this->schemaId(), $this->context($request), $search, trashedOnly: $trashed,
        );

        if ($items instanceof Result) {
            $this->abortFor($items);
        }

        /*
         * **ページを送っても、見ている条件を変えない**（Phase 9.8 / STAGE 21E）。
         *
         * paginator が作るリンクは既定では path だけで、`state` も `q` も落ちる。
         * ゴミ箱の 2 ページ目へ進んだ瞬間に通常の一覧へ戻るのは、利用者から見れば
         * 「捨てたものが勝手に戻った」に等しい。
         *
         * `withQueryString()` は使わない。**来た query を全部**貼り直すので、
         * 知らない値までリンクに焼き付いて増えていく。運ぶのは状態と検索語だけで、
         * `page` は paginator 自身が付ける。
         *
         * 直すのは Application 層ではなくここである。`state` / `q` は
         * **この画面の URL の語彙**であって、API / MCP から呼ぶ `content.list` の
         * 契約ではない。あちらのページャへ admin の query 名を混ぜない。
         */
        $items->appends(array_filter([
            'state' => $trashed ? self::STATE_TRASHED : null,
            'q' => $search !== '' ? $search : null,
        ], static fn (?string $value): bool => $value !== null));

        $items->getCollection()->transform(function (Model $item): Model {
            // 一覧の行から編集画面 / 公開ページへ入れるようにする（F-UX2）
            $item->setAttribute('editUrl', $this->editUrl($item));
            $item->setAttribute('publicUrl', $this->publicUrl($item));

            return $item;
        });

        return Inertia::render('admin/resource/Index', [
            'schema' => $this->adminSpec(),
            'items' => $items,
            'q' => $search,
            'routeBase' => $this->routeBase(),
            'state' => $trashed ? self::STATE_TRASHED : self::STATE_ACTIVE,
            // 無効にした投稿タイプでは読むだけにする（SPEC §A.3）
            'canWrite' => $this->lifecycle()->canWrite($this->schemaId()),
            // 一覧でも「公開にしてもサイトに出ない」ことを言う（UX 一周 2026-09-28）
            'publicTypeDisabled' => ! $trashed && EditorialCollections::isEditorial($this->schema()) && ! $this->lifecycle()->isPublic($this->schemaId()),
            /*
             * 掲載順を触れるか（P1 ②）。**宣言ではなく実行時の状態**なので
             * `toAdminSpec()` へ混ぜない（schema は「型がどう在るか」だけを言う）。
             *
             * ゴミ箱では出さない。**捨てたものの掲載順に意味は無い。**
             */
            'canReorder' => ! $trashed
                && $this->lifecycle()->canWrite($this->schemaId())
                && EditorialCollections::isEditorial($this->schema())
                && app(SortOrderReadiness::class)->supports($this->schema()->table),
        ]);
    }

    public function create(Request $request, GetTaxonomy $taxonomies, ListTerms $terms): Response
    {
        return Inertia::render('admin/resource/Form', [
            'schema' => $this->adminSpec(),
            'item' => null,
            'routeBase' => $this->routeBase(),
            'taxonomyOptions' => $this->taxonomyOptions($request, $taxonomies, $terms),
            // 記事の公開状態と、投稿型全体の公開設定を混同させない。
            'publicTypeDisabled' => EditorialCollections::isEditorial($this->schema()) && ! $this->lifecycle()->isPublic($this->schemaId()),
        ]);
    }

    /** 保存済みの記事を複製し、新しく作った下書きの編集画面へ移る。 */
    public function duplicate(Request $request, DuplicateContent $duplicate, int $id): RedirectResponse
    {
        $result = $duplicate->execute($this->schemaId(), $id, $this->context($request));
        if (! $result->isSuccess()) {
            if ($result->status === OperationStatus::ValidationError) {
                throw ValidationException::withMessages($result->errors);
            }
            $this->abortFor($result);
        }

        return redirect()->route("admin.{$this->routeBase()}.edit", ['id' => $result->data['item']['id']])
            ->with('success', '保存済みの内容を新しい下書きに複製しました');
    }

    public function store(Request $request, UploadValidator $uploads, CreateContent $create): RedirectResponse
    {
        $result = $create->execute(
            $this->schemaId(),
            $this->attributes($request, $uploads),
            $this->context($request),
            $this->taxonomies($request),
        );

        return $this->redirectFor($result, "{$this->displayLabel()}を作成しました");
    }

    /**
     * ゴミ箱にある投稿の read-only 画面。
     *
     * 出すのは **状態の説明・復元の導線・履歴の導線**だけ。
     * 編集・公開・ゴミ箱へ移す・完全削除は 1 つも置かない。
     */
    private function trashedEntry(Model $item): Response
    {
        return Inertia::render('admin/resource/Trashed', [
            'schema' => $this->adminSpec(),
            'item' => GetContent::present($item),
            'routeBase' => $this->routeBase(),
            'canWrite' => $this->lifecycle()->canWrite($this->schemaId()),
        ]);
    }

    /** 無効にした投稿タイプでは書かせない（判定は 9.7 の policy が持つ） */
    protected function lifecycle(): ContentTypeLifecyclePolicy
    {
        return app(ContentTypeLifecyclePolicy::class);
    }

    public function edit(
        Request $request,
        GetContent $get,
        GetTaxonomy $taxonomies,
        ListTerms $terms,
        ContentTaxonomyReader $assignments,
        RevisionWriter $revisions,
        int $id,
    ): Response {
        $item = $get->find($this->schemaId(), $id, $this->context($request));

        /*
         * **ゴミ箱にある投稿の編集画面を出さない**（Phase 9.8 / STAGE 21E・SPEC §E.1）。
         *
         * URL を直接叩かれても、入力欄も保存も公開も見せない。見せてしまうと
         * 「編集できたのに保存で弾かれる」ことになり、何が起きているのか分からない。
         * 代わりに、いまどういう状態か・どう戻すか・履歴はどこかを出す。
         */
        if ($item instanceof Result) {
            $trashed = $get->findTrashed($this->schemaId(), $id, $this->context($request));

            if ($trashed instanceof Model) {
                return $this->trashedEntry($trashed);
            }

            $this->abortFor($item);
        }

        // 付与済みの分類を read response と同じ形で渡す（§K.4）。
        // **archived な assignment も落とさない**（AS-11 / AS-12 — フォームが選択済みとして残せる）。
        // 宣言が無い schema では reader が DB へ触らず空を返す
        $item->setAttribute(
            ContentTaxonomyInput::RESPONSE_KEY,
            $assignments->forEntry($this->schema(), $this->schemaId(), (int) $item->getKey()),
        );

        return Inertia::render('admin/resource/Form', [
            'schema' => $this->adminSpec(),
            /*
             * **`GetContent::present()` を通す**（Phase 9.5-C）。
             *
             * 生の Model を渡すと `data` JSON が `item.data` の中に埋まったままになり、
             * フォームは `item.spec_group` を見て空だと判断する。つまり
             * **保存はできるのに開き直すと消えているように見える**。
             * AI / API と同じ表現（複合フィールドをトップレベルへ引き上げた形）で渡す。
             */
            'item' => GetContent::present($item),
            'routeBase' => $this->routeBase(),
            // 編集中のコンテンツから公開ページを開けるようにする（F-UX3）。
            // 公開できない状態（下書き / slug なし / 公開 route なし）は null
            'publicUrl' => $this->publicUrl($item),
            // 保存済みの下書きを確かめる口（Phase 9.15 / STAGE 28A）。
            // **描く相手がいない型では null** — 押しても「ありません」しか出ないボタンを出さない
            'previewUrl' => $this->previewUrl($item),
            // 手で保存したあとに積まれた自動保存（Phase 9.15 / STAGE 28C）。
            // **知らせるだけ。勝手には戻さない**（開いた瞬間に中身が変わるのは事故と区別が付かない）
            'pendingAutosave' => $revisions->pendingAutosave($this->schemaId(), (int) $item->getKey()),
            'taxonomyOptions' => $this->taxonomyOptions($request, $taxonomies, $terms),
            // 記事の公開状態と、投稿型全体の公開設定を混同させない。
            'publicTypeDisabled' => EditorialCollections::isEditorial($this->schema()) && ! $this->lifecycle()->isPublic($this->schemaId()),
        ]);
    }

    /**
     * 保存済みの下書きを、公開と同じ見た目で確かめる（Phase 9.15 / STAGE 28A・SPEC §B）。
     *
     * ## 描くのは「保存済みの内容」だけ
     *
     * ブラウザ内の未送信の内容は **1 バイトも受け取らない**（OG-B1）。
     * 運ぶには送る口が要り、それは「保存されていないのに保存されている場所」を
     * 増やすことになって **自動保存（P0-B2）と二重になる**。
     *
     * ## 越える壁は `published()` の 1 つだけ
     *
     * view も sanitize も**公開側からそのまま借りる**。
     * **preview だけ sanitize を緩めない** — 緩めると
     * 「preview では出たのに公開で消えた」が起き、**preview が嘘をついた**ことになる。
     *
     * ## 公開の口を持たない型がある
     *
     * 管理画面から作った投稿型も汎用 view で描く。システム用の型だけは描く相手がない。
     * ここで 404 を返すと「壊れている」に見えるので、**理由の分かる応答**を返す。
     * 判定に view の存在を使うのは、**それが「描けるか」そのものだから**である。
     */
    public function preview(Request $request, GetContent $get, Purifier $purifier, int $id): HttpResponse
    {
        $item = $get->find($this->schemaId(), $id, $this->context($request));

        if ($item instanceof Result) {
            $this->abortFor($item);
        }

        $view = $this->previewView();

        if ($view === null) {
            $response = response()->view('admin.previews.unavailable', [
                'label' => $this->displayLabel(),
                'backUrl' => $this->editUrl($item),
            ]);
        } else {
            $settings = app(ContentTypeSettingsResolver::class)->for($this->schemaId());
            $response = response()->view($view, [
                'item' => $item,
                'body' => $purifier->clean((string) ($item->body ?? '')),
                'contentType' => $this->schema(),
                'label' => $settings->labelOr($this->schema()->label),
                'slug' => $settings->publicSlug ?? '',
                'hasArchive' => false,
                'preview' => true,
                'taxonomies' => app(AssignedTerms::class)->forEntry($this->schemaId(), (int) $item->getKey()),
            ]);
        }

        return $response
            // 下書きが検索に載る事故を防ぐ。共有 proxy にも残さない
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store, max-age=0')
            // 他所から枠に入れられない（ページ側の preview と同じ守り）
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'");
    }

    /**
     * 編集中の内容を履歴へ積む（Phase 9.15 / STAGE 28B・SPEC §C）。
     *
     * **記事そのものは書き換えない。** 詳しくは `AutosaveContent` の docblock。
     *
     * 応答は JSON である。**画面遷移を起こさない** — 30 秒ごとに redirect が
     * 返ってきたら、編集している人の操作を奪う。
     *
     * **失敗の写像は増やさない。** `abortFor()`（core が持つ唯一の対応表）へ渡す。
     * ここに 2 つ目の対応表を作ると、2 つが食い違ったときに
     * **どちらが正しいのか誰にも分からなくなる**。
     */
    public function autosave(Request $request, AutosaveContent $autosave, int $id): JsonResponse
    {
        $result = $autosave->execute(
            $this->schemaId(),
            $id,
            // **検証しない値をそのまま渡す**（書きかけほど守られない、を避けるため）。
            // schema に無いキーは `ContentValues::split()` が落とす
            $request->except(['_token', '_method']),
            $this->context($request),
        );

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return response()->json(['data' => $result->data, 'meta' => $result->meta]);
    }

    public function update(Request $request, UploadValidator $uploads, UpdateContent $update, int $id): RedirectResponse
    {
        $result = $update->execute(
            $this->schemaId(),
            $id,
            $this->attributes($request, $uploads),
            $this->context($request),
            taxonomies: $this->taxonomies($request),
            lockVersion: $this->lockVersion($request),
        );

        return $this->redirectFor($result, "{$this->displayLabel()}を更新しました");
    }

    /**
     * 既存の削除 route。**互換 wrapper のまま残す**（Phase 9.8 / OG2 = (c)）。
     *
     * 呼び出しの形も画面の導線も変えないが、**行うことはゴミ箱への移動**になった。
     * だから成功の文言も変える — 「削除しました」と出しておいて残っているのは嘘である。
     */
    public function destroy(Request $request, DeleteContent $delete, int $id): RedirectResponse
    {
        $result = $delete->execute(
            $this->schemaId(), $id, $this->context($request), $this->lockVersion($request),
        );

        return $this->confirmable($result, "{$this->displayLabel()}をゴミ箱へ移動しました");
    }

    /** ゴミ箱へ移す正式な route（Phase 9.8 / STAGE 21C） */
    public function trash(Request $request, TrashContent $trash, int $id): RedirectResponse
    {
        $result = $trash->execute(
            $this->schemaId(), $id, $this->context($request), $this->lockVersion($request),
        );

        return $this->confirmable($result, "{$this->displayLabel()}をゴミ箱へ移動しました");
    }

    /**
     * ゴミ箱から戻す（Phase 9.8 / STAGE 21C）。
     *
     * 確認は要らない。公開状態を持つ型は**下書きへ**戻るので、
     * そのことが分かる文言にする — 「戻したのに公開されていない」を驚きにしない。
     */
    public function restore(Request $request, RestoreContent $restore, int $id): RedirectResponse
    {
        $result = $restore->execute(
            $this->schemaId(), $id, $this->context($request), $this->lockVersion($request),
        );

        $message = ($result->data['status'] ?? null) === 'draft'
            ? "{$this->displayLabel()}をゴミ箱から戻しました（下書きに戻っています）"
            : "{$this->displayLabel()}をゴミ箱から戻しました";

        return $this->redirectFor($result, $message);
    }

    /**
     * 履歴の一覧（Phase 9.8 / STAGE 21D）。**画面は STAGE 21E**。
     *
     * ここで返すのは JSON だけで、Vue の履歴画面は作らない。
     */
    public function revisions(Request $request, ListContentRevisions $list, int $id): JsonResponse
    {
        $result = $list->execute($this->schemaId(), $id, $this->context($request));

        return response()->json(
            $result->isSuccess()
                ? ['revisions' => $result->data['items'], 'meta' => $result->meta]
                : ['message' => $result->message],
            $result->isSuccess() ? 200 : ($result->status === OperationStatus::NotFound ? 404 : 409),
        );
    }

    /**
     * 過去の版へ戻す（Phase 9.8 / STAGE 21D）。
     *
     * **preview → 明示確認 → apply。** 1 回目は確認内容を返して止まり、
     * 何が失われるかを列挙する。押されて初めて書き換える。
     */
    public function revisionRestore(
        Request $request,
        RestoreContentRevision $restore,
        int $id,
        int $revision,
    ): RedirectResponse {
        $result = $restore->execute(
            $this->schemaId(), $id, $revision, $this->context($request), $this->lockVersion($request),
        );

        return $this->confirmable($result, "{$this->displayLabel()}を版 {$revision} の内容へ戻しました");
    }

    /**
     * 破壊的操作の共通後処理。
     *
     * token 無しの 1 回目は確認内容を返して止まる（STEP 7）。
     * 管理画面も AI も **同じ ConfirmationGate** を通る。
     */
    private function confirmable(Result $result, string $message): RedirectResponse
    {
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        return $this->redirectFor($result, $message);
    }

    /**
     * 版（楽観ロック）。**黙って捨てない**（OG11）。
     *
     * 画面がまだ送っていない段階では null になり、移行期間中は通る。
     * 送ってきたなら必ず Application 層まで運ぶ。
     */
    private function lockVersion(Request $request): LockVersionInput
    {
        // キーの有無で分ける（壊れた値を「送っていない」へ丸めない）
        return LockVersionInput::fromRequest($request->all());
    }

    protected function routeBase(): string
    {
        return $this->schemaId();
    }

    /** 誰が・どの経路で操作しているか（確認トークンがあれば同梱） */
    protected function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    /**
     * request → Application Service へ渡す属性。
     * 画像は `<key>_file` を検証・保存してパス文字列へ差し替える（HTTP 固有の前処理）。
     *
     * @return array<string, mixed>
     */
    protected function attributes(Request $request, UploadValidator $uploads): array
    {
        $attributes = $request->all();

        // 分類は **field ではない**（§J.1）。ContentInput へ通常フィールドとして渡さない
        unset($attributes[ContentTaxonomyInput::RESPONSE_KEY]);
        // multipart 対策の印も値ではないので、通常フィールドとして渡さない（`taxonomies()` 参照）
        unset($attributes[self::TAXONOMIES_MANAGED_KEY]);

        foreach ($this->schema()->fields as $field) {
            if ($field->type === 'image' && $request->hasFile("{$field->key}_file")) {
                $attributes[$field->key] = $uploads->store($request->file("{$field->key}_file"), "{$field->key}_file");
            }

            unset($attributes["{$field->key}_file"]);
        }

        return $attributes;
    }

    /**
     * 分類の payload（Phase 9.3B / STAGE 3B・#16）。
     *
     * フォームが `taxonomies` を送ったときだけ VO を作る。**キーの有無だけを見て、
     * 値は検証せずそのまま運ぶ**（§K.2 の 3 状態を adapter で 1 つに潰さないため）。
     * 検証・term 解決・assignment 保存は adapter ではなく Application Service の責務。
     *
     * ## `taxonomies_managed`（multipart の穴を塞ぐ HTTP 固有の前処理）
     *
     * 画像付き送信は `multipart/form-data` になり、**空配列はキーごと消える**。
     * そのままだと「分類をすべて外す」が黙って無視され、AS-4（解除できる）が壊れる。
     * そこでフォームは「このフォームが分類を管理していた」ことだけを scalar で送り、
     * ここで **schema が宣言した key を空 list として補う**。
     *
     * 値が配列でないときは補完せずそのまま渡す（不正入力を握り潰して
     * 「送ったのに黙って何もしなかった」を作らないため）。
     */
    protected function taxonomies(Request $request): ?TaxonomyPayload
    {
        $payload = TaxonomyPayload::fromInput($request->all(), ContentTaxonomyInput::RESPONSE_KEY);

        if (! $request->boolean(self::TAXONOMIES_MANAGED_KEY)) {
            return $payload;
        }

        if ($payload !== null && ! is_array($payload->raw)) {
            return $payload;
        }

        /** @var array<array-key, mixed> $map */
        $map = $payload->raw ?? [];

        foreach ($this->schema()->taxonomies as $declaration) {
            if (! array_key_exists($declaration->key, $map)) {
                $map[$declaration->key] = [];
            }
        }

        return TaxonomyPayload::of($map);
    }

    /**
     * 分類の選択候補（Phase 9.3B / #16・SPEC §R）。
     *
     * **候補は active term のみ**（AR-3 / HR-1）。既に付与されている archived term は
     * `item.taxonomies` 側に残り、フォームが「選択済み + アーカイブ済み」として表示する（AS-12）。
     *
     * schema が taxonomy を宣言していなければ **DB へ 1 度も触らず空を返す**ので、
     * 未宣言 schema のフォーム挙動は変わらない。
     * 未同期・未登録でもフォーム自体は開けるようにし、理由を添えて選べない状態にする。
     *
     * @return list<array<string, mixed>>
     */
    protected function taxonomyOptions(Request $request, GetTaxonomy $taxonomies, ListTerms $terms): array
    {
        $schema = $this->schema();

        if ($schema->taxonomies === []) {
            return [];
        }

        $context = $this->context($request);
        $options = [];

        foreach ($schema->taxonomies as $declaration) {
            $taxonomy = $taxonomies->execute($declaration->key, $context);
            $list = $terms->execute($declaration->key, $context, includeArchived: false);
            $available = $taxonomy->isSuccess() && $list->isSuccess();

            $options[] = [
                'key' => $declaration->key,
                'label' => $taxonomy->isSuccess()
                    ? ($taxonomy->data['taxonomy']['label'] ?? $declaration->key)
                    : $declaration->key,
                'multiple' => $declaration->multiple,
                'required' => $declaration->required,
                'hierarchical' => $taxonomy->isSuccess()
                    ? ($taxonomy->data['taxonomy']['hierarchical'] ?? false)
                    : false,
                'available' => $available,
                'message' => $available ? null : ($taxonomy->message ?? $list->message),
                'terms' => $available ? ($list->data['terms'] ?? []) : [],
            ];
        }

        return $options;
    }

    /** Result → 管理画面のリダイレクト（失敗は Laravel 標準の例外へ戻す） */
    protected function redirectFor(Result $result, string $success): RedirectResponse
    {
        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()
            ->route("admin.{$this->routeBase()}.index")
            ->with('success', $success);
    }

    /** 失敗した Result を HTTP の例外へ写す */
    protected function abortFor(Result $result): never
    {
        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, $result->message ?? '');
    }

    /** 編集画面の URL（一覧のタイトルリンク用） */
    protected function editUrl(Model $item): string
    {
        return route("admin.{$this->routeBase()}.edit", ['id' => $item->getKey()], absolute: false);
    }

    /**
     * 公開ページの URL。**開けない場合は null**（無効なリンクを出さないため）。
     *
     * schema id と同名の公開 route（例 `news.show`）が存在し、slug を持ち、
     * 公開状態であるときだけ URL を返す。News / Works を hardcode しない。
     */
    protected function publicUrl(Model $item): ?string
    {
        /*
         * **形は `ContentPermalink` が決める**（P1 ③ 段 1）。ここが見るのは
         * **その行が公開されているか**だけ — 形と状態を混ぜない。
         */
        if (! $this->isPublicallyVisible($item)) {
            return null;
        }

        return app(ContentPermalink::class)->entry($this->schemaId(), $item, absolute: false);
    }

    /**
     * preview の URL。**描く相手がいなければ null**（`publicUrl` と同じ作法）。
     *
     * 公開状態は見ない。**preview は公開前に見るためのものだから**である。
     */
    protected function previewUrl(Model $item): ?string
    {
        if ($this->previewView() === null) {
            return null;
        }

        return route("admin.{$this->routeBase()}.preview", ['id' => $item->getKey()], absolute: false);
    }

    /** 投稿型には汎用公開テンプレートがある。専用 view が無くても下書きを確認できる。 */
    private function previewView(): ?string
    {
        foreach (["{$this->schemaId()}::show", "public.{$this->schemaId()}.show"] as $view) {
            if (ViewFacade::exists($view)) {
                return $view;
            }
        }

        return EditorialCollections::isEditorial($this->schema())
            && ViewFacade::exists('public.content-type.show') ? 'public.content-type.show' : null;
    }

    /** 公開ページから見える状態か（status / published_at を持つ schema のみ判定） */
    protected function isPublicallyVisible(Model $item): bool
    {
        if (SchemaFacade::hasColumn($item->getTable(), 'status')
            && $item->getAttribute('status') !== 'published') {
            return false;
        }

        if (SchemaFacade::hasColumn($item->getTable(), 'published_at')) {
            $publishedAt = $item->getAttribute('published_at');

            if ($publishedAt === null || $publishedAt->isFuture()) {
                return false;
            }
        }

        return true;
    }
}

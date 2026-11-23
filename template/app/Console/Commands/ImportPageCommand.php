<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\ActorType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Page;
use App\Console\Concerns\SpeaksJson;
use App\Models\ImportedPageLayout;
use App\Support\DocumentPageView;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Template Import が取り込んだ節を、ページとして起こす（R-12 Stage 2 / NEW-2）。
 *
 * ## なぜ要るのか
 *
 * `buildImportedSectionComponent()` が `content/components/imported_*.json` と Blade を
 * 書き出しても、**それを置いたページが無ければ画面には何も出ない**。
 * 原本の並び順どおりに 1 枚のページへ並べる作業を、ここで 1 回だけ行う。
 *
 * ## 既に在るなら触らない
 *
 * 同じ slug のページが在れば **何もしない**（`action: kept`）。
 * `agentcms:import-form` と同じ作法で、**運用者が組み替えたものを import が
 * 上書きしてよい理由が無い**。再 apply しても並びが戻らないのはそのためである。
 *
 * ## トップページには、ここでは繋がない
 *
 * `front_page_id` は **`agentcms:site-setting front_page_id <id>` が既に持っている**。
 * ここへ 2 つ目の口を作ると、schema 検証・監査・「公開されていない page を指したら
 * 既定へ戻す」の判断が二重になる（`PatchPageDocument` の `component.detach` と同じ理由で
 * 口は増やさない）。**このコマンドは page の id を返すので、繋ぐのは呼び出し側**。
 *
 * ## ブロックは 1 つずつ、いつもの口から入れる
 *
 * `page_versions` へ document を直接書かない。`component.insert` を 1 ブロックにつき
 * 1 回呼ぶ。**import だけ検証を迂回する経路は作らない**（`DocumentValidator` の宣言）ので、
 * 置き場所（`allowedParents`）・props の型・木の大きさは自動的に同じ検査を通る。
 *
 * ## 途中で止まったら、何も残さない
 *
 * 作成 → ブロック → 公開を **1 つの取引**で行う。n 個目で弾かれたら**何個目の何が悪かったかを
 * 言って巻き戻す**。以前は下書きを残していたが、残った下書きは次の実行で `kept` になり、
 * 「在るのに公開されないページ」として運用者の下書きと見分けが付かなかった。
 * ゴミ箱に入れても slug は予約されたままなので（`CreatePage`）、「捨てて再実行」は成り立たない。
 * **残さないのが唯一の後始末**である。プロセスが途中で殺されても commit 前の行は現れない。
 *
 * ## AI へは公開しない
 *
 * `agentcms:import-form` と同じく CLI 専用の内部経路で、`OperationCatalog` にも
 * MCP tool にも API にも足さない。
 *
 * ## 入出力
 *
 * 入力は **stdin の JSON** のみ（argv に載せない）。
 *
 * ```json
 * {"page": {"title": "ホーム", "slug": "home", "publish": true,
 *           "layout": "layouts.preserved.home",
 *           "blocks": [{"type": "imported_home", "props": {"text_1": "みどり造園"}}]}}
 * ```
 *
 * 出力は stdout に結果 JSON 1 行（`id` / `slug` / `blocks` / `published`）。
 */
final class ImportPageCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:import-page';

    protected $description = 'Template Import 用: 取り込んだ節をページとして起こす（既に在れば触らない）';

    /**
     * 1 ページに入れられるブロックの上限（暴走した plan を止める）。
     *
     * `DocumentValidator::MAX_NODES`（500）より**手前で**止める。
     * 木の上限に当たってから断ると「499 個までは入った」状態が残る。
     */
    private const MAX_BLOCKS = 100;

    private const ALLOWED_ROOT_KEYS = ['page'];

    private const ALLOWED_PAGE_KEYS = ['title', 'slug', 'publish', 'blocks', 'layout'];

    private const ALLOWED_BLOCK_KEYS = ['type', 'props', 'binding'];

    /**
     * 監査に残る呼び出し元。
     *
     * **外部入力にしない**（`ImportFormCommand` と同じ理由 — 入力で偽装できてしまう）。
     */
    private const ACTOR = 'template-import';

    public function handle(CreatePage $create, PatchPageDocument $patch, PublishPage $publish): int
    {
        $raw = $this->readStdin();
        $input = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($input)) {
            return $this->refuse('標準入力の JSON を読み取れませんでした');
        }

        $unknownRoot = array_diff(array_keys($input), self::ALLOWED_ROOT_KEYS);

        if ($unknownRoot !== []) {
            return $this->refuse('受け付けないキーがあります: '.implode(', ', $unknownRoot));
        }

        $page = $input['page'] ?? null;

        if (! is_array($page)) {
            return $this->refuse('page はオブジェクトで渡してください');
        }

        $unknownPage = array_diff(array_keys($page), self::ALLOWED_PAGE_KEYS);

        if ($unknownPage !== []) {
            return $this->refuse('page に受け付けないキーがあります: '.implode(', ', $unknownPage));
        }

        $title = $page['title'] ?? null;
        $slug = $page['slug'] ?? null;

        if (! is_string($title) || $title === '' || ! is_string($slug) || $slug === '') {
            return $this->refuse('page の title と slug は必須です');
        }

        $blocks = $this->validatedBlocks($page['blocks'] ?? null);

        if (is_string($blocks)) {
            return $this->refuse($blocks);
        }

        /*
         * **原本の枠（layout）は、このページの id に結び付けて残す**（Codex 続き ①）。
         *
         * slug（ファイルの有無）で当てると、取り込みの前から在った同名ページにも枠が当たり
         * （`kept` で「触らない」と言った保証が破れる）、slug を変えた瞬間に枠が外れる。
         * だから **作成と同じ取引**で `imported_page_layouts` に行を入れ、行があるページだけが載る。
         * `kept` のときは書かない — 既に在るページに枠を当てるのは「触る」ことになる。
         */
        $layout = $page['layout'] ?? null;

        if ($layout !== null && ! $this->validLayoutName($layout)) {
            return $this->refuse(
                'page の layout は '.DocumentPageView::PRESERVED_LAYOUT_PREFIX.'<名前> の形で渡してください',
            );
        }

        /*
         * **既に在れば触らない。** ゴミ箱の中も「在る」として数える —
         * `CreatePage` は捨てられた slug も `unique` で弾くので、
         * ここで作りに行っても断られる。断られてから理由を推測するより、
         * 先に見て `kept` と言う方が読む人に分かる。
         */
        $existing = Page::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $this->emit([
                'status' => 'success',
                'action' => 'kept',
                'id' => $existing->id,
                'slug' => $existing->slug,
                'blocks' => 0,
                'published' => $existing->isPublicallyVisible(),
                'message' => "ページ [{$slug}] は既にあるので触っていません",
            ], failure: false);
        }

        $wantPublish = ($page['publish'] ?? false) === true;

        /*
         * **作成 → ブロック → 公開は 1 つの取引**（Codex 指摘 ④）。
         *
         * 途中で断られたら例外で巻き戻し、**ページを 1 行も残さない**。
         * 以前は「下書きのまま残す」だったが、残った下書きは次の実行で `kept` になり、
         * **在るのに公開されないページ**として見分けが付かなくなる。
         * 中で使う `PageRepository::createVersion()` は自分でも取引を張るが、
         * 入れ子の取引は外側に吸収される（savepoint）ので、外側が巻き戻せば全部戻る。
         *
         * プロセスが途中で殺されても同じ — commit していない行は DB に現れない。
         */
        try {
            /** @var array{id: int, published: bool} $done */
            $done = DB::transaction(function () use ($create, $patch, $publish, $title, $slug, $blocks, $wantPublish, $layout): array {
                $context = OperationContext::cli(self::ACTOR);
                $created = $create->execute(['title' => $title, 'slug' => $slug], $context);

                if ($created->status !== OperationStatus::Success) {
                    throw new ImportPageRefused("ページ [{$slug}] を作成できませんでした", $created);
                }

                /** @var array<string, mixed> $presented */
                $presented = $created->data['page'] ?? [];
                $pageId = (int) ($presented['id'] ?? 0);
                $model = Page::query()->findOrFail($pageId);
                $rootId = $this->rootId($model);

                if ($rootId === null) {
                    throw new ImportPageRefused("ページ [{$slug}] の編集中の版を読み取れませんでした");
                }

                if (is_string($layout)) {
                    // 元の URL の所有者はこの 1 枚（複製は owned_view を持たない）
                    ImportedPageLayout::query()->create([
                        'page_id' => $pageId,
                        'view' => $layout,
                        'owned_view' => $layout,
                        'source' => null,
                    ]);
                }

                foreach ($blocks as $index => $block) {
                    $component = ['type' => $block['type'], 'props' => $block['props']];

                    /*
                     * **一覧は binding が無いと中身が出ない。**
                     *
                     * 付けずに入れると、検証が「一覧に表示するコンテンツが指定されていません」で
                     * 断る。**通ってしまうより断られる方がよい** — 通ると空の一覧が公開面に出て、
                     * 「まだ登録がありません」と見分けが付かない。
                     */
                    if ($block['binding'] !== null) {
                        $component['binding'] = $block['binding'];
                    }

                    $result = $patch->execute($pageId, [
                        'operation' => 'component.insert',
                        'parent' => $rootId,
                        'component' => $component,
                    ], OperationContext::cli(self::ACTOR));

                    if ($result->status !== OperationStatus::Success) {
                        $nth = $index + 1;

                        throw new ImportPageRefused(
                            "{$nth} 個目のブロック [{$block['type']}] を置けませんでした"
                                ."（ページ [{$slug}] は作成を取り消しました）",
                            $result,
                        );
                    }
                }

                $published = false;

                if ($wantPublish) {
                    $outcome = $this->publish($publish, $pageId);

                    if ($outcome->status !== OperationStatus::Success) {
                        throw new ImportPageRefused(
                            "ページ [{$slug}] を公開できませんでした（作成を取り消しました）",
                            $outcome,
                        );
                    }

                    $published = true;
                }

                return ['id' => $pageId, 'published' => $published];
            });
        } catch (ImportPageRefused $refused) {
            return $refused->result === null
                ? $this->refuse($refused->reason)
                : $this->refuseResult($refused->result, $refused->reason);
        }

        return $this->emit([
            'status' => 'success',
            'action' => 'created',
            'id' => $done['id'],
            'slug' => $slug,
            'blocks' => count($blocks),
            'published' => $done['published'],
            'layout' => is_string($layout) ? $layout : null,
        ], failure: false);
    }

    /**
     * 枠の名前は `layouts.preserved.<名前>` だけ（`DocumentPageView` と同じ規則）。
     * 任意の view 名を受けると、取り込みが**別の layout を横取り**できてしまう。
     */
    private function validLayoutName(mixed $layout): bool
    {
        return is_string($layout)
            && str_starts_with($layout, DocumentPageView::PRESERVED_LAYOUT_PREFIX)
            && preg_match('/^[a-z0-9][a-z0-9-]*$/', substr($layout, strlen(DocumentPageView::PRESERVED_LAYOUT_PREFIX))) === 1;
    }

    /**
     * 公開する。
     *
     * 承認は plan の段で済んでいる（`publish: true` と書いたのが承認）。
     * 発行された token をその場で提示する — 2 度目の context は**呼び出し元が同じ**で
     * なければならない（確認の指紋が `actorKey()` を含む）ので、`cli()` と同じ
     * actorType / interface / actorLabel を組んで token だけを足す。
     */
    private function publish(PublishPage $publish, int $pageId): Result
    {
        $result = $publish->execute($pageId, OperationContext::cli(self::ACTOR));

        if ($result->status !== OperationStatus::ConfirmationRequired) {
            return $result;
        }

        return $publish->execute($pageId, new OperationContext(
            actorType: ActorType::System,
            interface: OperationInterface::Cli,
            actorLabel: self::ACTOR,
            confirmationToken: $result->confirmation?->token,
        ));
    }

    /** 編集中の版の root ノードの id。読めなければ null */
    private function rootId(Page $page): ?string
    {
        $draft = $page->draftVersion;
        $root = is_array($draft?->document) ? ($draft->document['root'] ?? null) : null;
        $id = is_array($root) ? ($root['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * ブロックの並びを検査する。**駄目なら理由の文字列を返す**。
     *
     * `type` が実在するかはここで見ない — `component.insert` が
     * `ComponentRegistry` で見る。**同じ判断を 2 箇所に置かない**
     * （置くと、片方だけ直した日に食い違う）。
     *
     * @return list<array{type: string, props: array<string, mixed>, binding: array<string, mixed>|null}>|string
     */
    private function validatedBlocks(mixed $blocks): array|string
    {
        if (! is_array($blocks) || $blocks === []) {
            return 'page の blocks には 1 つ以上のブロックが要ります';
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            return 'ブロックが多すぎます（上限 '.self::MAX_BLOCKS.' 個）';
        }

        $validated = [];

        foreach (array_values($blocks) as $index => $block) {
            $nth = $index + 1;

            if (! is_array($block)) {
                return "{$nth} 個目のブロックはオブジェクトで渡してください";
            }

            $unknown = array_diff(array_keys($block), self::ALLOWED_BLOCK_KEYS);

            if ($unknown !== []) {
                return "{$nth} 個目のブロックに受け付けないキーがあります: ".implode(', ', $unknown);
            }

            $type = $block['type'] ?? null;

            if (! is_string($type) || $type === '') {
                return "{$nth} 個目のブロックに type がありません";
            }

            $props = $block['props'] ?? [];

            if (! is_array($props)) {
                return "{$nth} 個目のブロック [{$type}] の props はオブジェクトで渡してください";
            }

            $binding = $block['binding'] ?? null;

            if ($binding !== null && ! is_array($binding)) {
                return "{$nth} 個目のブロック [{$type}] の binding はオブジェクトで渡してください";
            }

            $validated[] = ['type' => $type, 'props' => $props, 'binding' => $binding];
        }

        return $validated;
    }

    /**
     * Operation が返した断りを、そのまま人に見せる形で出す。
     *
     * **直し方の分からない断りにしない**（`CreateContentTypeCommand` と同じ）。
     * `Result::validationError` の message は「入力内容を確認してください」しか
     * 持たないので、`errors` を落とすと利用者は原因を知る手段が無くなる。
     */
    private function refuseResult(Result $result, string $message): int
    {
        return $this->emit([
            'status' => $result->status->value,
            'action' => 'none',
            'message' => $message,
            'reason' => $result->message,
            'errors' => $result->errors,
        ], failure: true);
    }
}

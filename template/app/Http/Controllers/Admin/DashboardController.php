<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\ContentTypes\Registration\PublishingQuickStart;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Forms\SubmissionStatus;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\AdminScreenAccess;
use App\Support\Capability;
use App\Support\ContactEntry;
use App\Support\FirstSteps;
use App\Support\Industry\IndustryProfiles;
use App\Support\Industry\IndustrySetup;
use App\Support\ReadinessResult;
use App\Support\RoleOperationPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contact\Models\ContactStatus;
use Modules\Contact\Models\ContactSubmission;
use Modules\Media\Models\Media;

/**
 * 管理画面ダッシュボード（CMS overview）。
 *
 * 表示するのは実データベースの実データのみ（架空の分析・placeholder チャートは置かない）。
 *
 * **投稿タイプを名前で書かない**（Phase 9.7 / STAGE 20F）。
 * `news` / `works` は kit の例であって、新しく生成するサイトには配られない。
 * 配られていない型の名前がここに残っていると、生成物では**静的解析すら通らない**。
 * 一覧も「最近の投稿」も、実行時の登録簿（schema + モデル）から作る。
 */
final class DashboardController
{
    /** 「最近の投稿」に出す件数 */
    private const RECENT_LIMIT = 5;

    public function __invoke(
        SchemaRegistry $schemas,
        ContentTypeResolver $types,
        ContentTypeLifecyclePolicy $lifecycle,
        FirstSteps $firstSteps,
    ): Response {
        $collections = [];

        foreach ($types->availableTypes() as $id) {
            $schema = $schemas->get($id);

            // 投稿タイプだけ（`media` などの system facility は別枠にある）
            if (! EditorialCollections::isEditorial($schema) || ! $lifecycle->showInMenu($id)) {
                continue;
            }

            if (! Route::has("admin.{$id}.index") || ! Route::has("admin.{$id}.create")) {
                continue;
            }

            $collections[] = [
                'id' => $id,
                'label' => $lifecycle->settings($id)->labelOr($schema->label),
                'count' => $types->newModel($id)->newQuery()->count(),
                'indexUrl' => route("admin.{$id}.index", absolute: false),
                'createUrl' => route("admin.{$id}.create", absolute: false),
            ];
        }

        $first = $collections[0] ?? null;

        return Inertia::render('Dashboard', [
            'collections' => $collections,
            // 書ける入れ物が 1 つも無いときだけ、1 クリックの出口を渡す（WP 一周 2026-09-03）
            'quickStart' => $this->quickStart($schemas, $types, $lifecycle),
            'media' => [
                'count' => Media::query()->count(),
                'indexUrl' => route('admin.media.index', absolute: false),
            ],
            'contact' => $this->contactCard(),
            'recent' => [
                'label' => $first['label'] ?? null,
                'items' => $first === null ? [] : $this->recentEntries($types, (string) $first['id']),
            ],
            'siteSettingsUrl' => route('admin.site.edit', absolute: false),
            // 最初に業種を聞く入口（UX 一周 2026-09-28）。未設定なら開いて出す
            'industry' => $this->canManageSettings() ? [
                'current' => app(IndustrySetup::class)->current(),
                // 「その他」で打ったお仕事の名前（UX 一周 9 周目 M2）
                'name' => app(IndustrySetup::class)->name(),
                'options' => IndustryProfiles::options(),
            ] : null,
            /*
             * 「最初の 6 歩」（オーナー 2026-09-03 13:06「一番困るのははじめ
             * インストールした後に何をすればいいかわからないこと」）。
             *
             * **済んだ印はどこにも保存しない。** いまのサイトの状態から導くので、
             * 印と現実がずれない（`FirstSteps` の docblock）。
             */
            'firstSteps' => [
                'steps' => array_map(function (array $step): array {
                    if (! AdminScreenAccess::canChangePath(auth()->user()->role(), $step['href'])) {
                        $step['href'] = '';
                        $step['description'] = 'この設定を変更する権限がありません。オーナーに頼んでください。';
                    }

                    return $step;
                }, $firstSteps->all()),
                'allDone' => $firstSteps->allDone(),
                'readiness' => app(ReadinessResult::class)->last(),
                'doneMessage' => app(ReadinessResult::class)->doneMessage(),
            ],
        ]);
    }

    /**
     * 「記事を書けるようにする」1 クリックの行き先（WP 一周 2026-09-03 / 効くもの ①）。
     *
     * **既定は変えていない。** 空の投稿タイプを並べない B-39（2026-08-24）はそのままで、
     * ここは「読んだ人がどこへ行けば書けるようになるか」を**行き先ではなくボタン**にする。
     * 5 か所を自分で組み立てる代わりに 1 回押せば済む。
     *
     * 出すのは **隠れている投稿タイプが在るときだけ**。1 つも無ければ null を返し、
     * 画面は今までどおり投稿タイプ一覧への link を出す（押せない導線を作らない）。
     *
     * @return array{typeId: string, label: string, url: string}|null
     */
    private function quickStart(
        SchemaRegistry $schemas,
        ContentTypeResolver $types,
        ContentTypeLifecyclePolicy $lifecycle,
    ): ?array {
        $id = PublishingQuickStart::candidateFor($schemas, $types, $lifecycle);

        if ($id === null) {
            return null;
        }

        return [
            'typeId' => $id,
            'label' => $lifecycle->settings($id)->labelOr($schemas->get($id)->label),
            'url' => route('admin.content-types.quick-start', ['type' => $id], absolute: false),
        ];
    }

    /**
     * 「お問い合わせ」の枠（R-12 / 受付の一本化）。
     *
     * 🔴 **数える先と、開く先を、窓口と揃える。**
     * 受付はフォームに一本化したので、届いた問い合わせは `form_submissions` に入る。
     * ここが旧 `contact_submissions` を数え続けると、**画面には 0 件と出たまま
     * 問い合わせだけが溜まる** — 一本化する前と同じ読み違いを、ダッシュボードで作り直すことになる。
     *
     * 予約識別子のフォームが無い案件（利用者が消した等）だけ、これまでどおり旧側を指す。
     * **窓口が在るほうを指す**のであって、どちらが新しいかで選ばない。
     *
     * ▷ 移していない旧受信は**ここでは数えない**。数えても開いた先に出ないからである。
     *   その件数はフォーム一覧が出す（`FormAdminController::legacyPending()`）。
     *
     * @return array{total: int, received: int, indexUrl: string}
     */
    private function contactCard(): array
    {
        $form = app(ContactEntry::class)->publishedForm();
        $all = FormSubmission::query()->count();

        /*
         * フォームが 2 つ以上あるときは、全部のフォームの受信を数える（UX 一周 6 周目 R6-4）。
         * 体験レッスン用に作ったフォームに申し込みが届いても、ここは「お問い合わせ」フォームだけを数えて
         * 0 件と出ていた。開く先は、受信のあるフォームが 1 つならそこ、2 つ以上ならフォームの一覧。
         */
        $withSubmissions = FormSubmission::query()->distinct()->pluck('form_id');

        if ($all > 0 && ($form === null || $withSubmissions->contains(fn ($id): bool => (int) $id !== (int) $form->getKey()))) {
            $only = $withSubmissions->count() === 1
                ? Form::query()->whereKey($withSubmissions->first())->first()
                : null;

            return [
                'total' => $all,
                'received' => FormSubmission::query()->where('status', SubmissionStatus::Received->value)->count(),
                'indexUrl' => $only !== null
                    ? route('admin.forms.submissions', ['key' => $only->key], absolute: false)
                    : route('admin.forms.index', absolute: false),
            ];
        }

        if ($form === null) {
            return [
                'total' => ContactSubmission::query()->count(),
                'received' => ContactSubmission::query()
                    ->where('status', ContactStatus::Received)
                    ->count(),
                'indexUrl' => route('admin.contact.index', absolute: false),
            ];
        }

        return [
            'total' => FormSubmission::query()->where('form_id', $form->getKey())->count(),
            'received' => FormSubmission::query()
                ->where('form_id', $form->getKey())
                ->where('status', SubmissionStatus::Received->value)
                ->count(),
            'indexUrl' => route('admin.forms.submissions', ['key' => $form->key], absolute: false),
        ];
    }

    /**
     * 直近の投稿。**schema が持っている項目だけ**を読む。
     *
     * 「投稿」型には `title` / `status` / `published_at` があるが、
     * 利用者が作った型には無いこともある。無いものは空か null にして画面へ渡す。
     *
     * @return list<array<string, mixed>>
     */
    private function recentEntries(ContentTypeResolver $types, string $id): array
    {
        $query = $types->newModel($id)->newQuery();
        ContentOrder::apply($query, $query->getModel()->getTable());
        if (app(SchemaRegistry::class)->get($id)->hasField('published_at')) {
            $query->orderByDesc('published_at');
        }

        /** @var list<array<string, mixed>> $items */
        $items = $query
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(function (Model $entry) use ($id): array {
                $publishedAt = $entry->getAttribute('published_at');
                /*
                 * 日時は Carbon とは限らない（CarbonImmutable で来る型・文字のままの型がある）。
                 * `instanceof Carbon` だけを見ていたので、ダッシュボードには公開日時が一度も渡らず、
                 * 予約した記事が「公開」と出ていた（UX 一周 8 周目 R7-3 の残り）
                 */
                if ($publishedAt instanceof \DateTimeInterface) {
                    $publishedAt = Carbon::instance($publishedAt);
                } elseif (is_string($publishedAt) && trim($publishedAt) !== '') {
                    $publishedAt = Carbon::parse($publishedAt);
                }

                return [
                    'id' => (int) $entry->getKey(),
                    'title' => (string) ($entry->getAttribute('title') ?? ''),
                    'status' => (string) ($entry->getAttribute('status') ?? ''),
                    'published_at' => $publishedAt instanceof Carbon
                        ? $publishedAt->format('Y-m-d H:i')
                        : null,
                    // 「予約」の判定用（時間帯つき）。表示用の文字では時間帯が分からず、
                    // 予約した記事が「公開」と出ていた（UX 一周 8 周目 R7-3 の残り）
                    'published_at_iso' => $publishedAt instanceof Carbon ? $publishedAt->toIso8601String() : null,
                    'editUrl' => Route::has("admin.{$id}.edit")
                        ? route("admin.{$id}.edit", ['id' => $entry->getKey()], absolute: false)
                        : null,
                ];
            })
            ->values()
            ->all();

        return $items;
    }

    /** 業種の入口は、サイト設定を触れる人にだけ出す（押しても断られるボタンを出さない） */
    private function canManageSettings(): bool
    {
        $user = auth()->user();

        return $user instanceof User && in_array(
            Capability::SettingsManage->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($user),
            true,
        );
    }
}

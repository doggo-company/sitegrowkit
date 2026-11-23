<?php

declare(strict_types=1);

namespace App\Support\Industry;

use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\PublicSlugRegistry;
use AgentCms\Core\ContentTypes\Registration\PublishingQuickStart;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuLinkType;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\PublicationReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * 業種を選んだときに、サイトをその業種の形にする（UX 一周 2026-09-28）。
 *
 * 変えるのは 3 つ — 業種の記録、お問い合わせフォームの項目と説明、「投稿」の呼び名。
 * 見本ページの文章は、記録した業種を見本の view が読んで出す（ここでは書き込まない）。
 *
 * 🔴 **店の人が手を入れたものは上書きしない。** フォームは、同梱の見本のままか、
 * ここで入れた業種の形のままのときだけ差し替える。呼び名も、自分で付けていれば変えない。
 * 業種を選び直したときも同じ規則で、選び直しただけで書いた内容が消えることはない。
 */
final class IndustrySetup
{
    /** 選び直す前の「その他」の名前（`untouched()` が前の名前で入れた形を見分けるため） */
    private string $previousName = '';

    /** 未保存メニューに kit が足した行を、初期設定の歩でも見分ける。 */
    public const AUTO_MENU_ITEM_KEY = 'industry_auto_post_menu_item';

    private const AUTO_LIST_SLUG_KEY = 'industry_auto_post_list_slug';

    /** 同梱の見本フォームの項目（ContactFormSeeder と同じ並び） */
    private const SEEDED_KEYS = ['name', 'email', 'company', 'subject', 'message'];

    private const SEEDED_CHOICES = ['お見積もり', 'ご相談', 'その他'];

    /** 会社名の欄の既定の名前（業種が `companyLabel` を持たないとき） */
    public const COMPANY_LABEL = '会社名・屋号';

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly ContentTypeSettingsResolver $types,
        private readonly ContentTypeSettingsWriter $writer,
    ) {}

    public function current(): ?string
    {
        $key = $this->settings->get(IndustryProfiles::SETTING_KEY);

        return is_string($key) && IndustryProfiles::get($key) !== null ? $key : null;
    }

    /** 「その他」で打ったお仕事の名前（UX 一周 9 周目 M2）。無ければ空 */
    public function name(): string
    {
        $name = $this->settings->get(IndustryProfiles::NAME_KEY);

        return is_string($name) ? $name : '';
    }

    /**
     * いまの業種の形（名前を置き換え済み）。見本の view と画面はこれを読む。業種を選んでいなければ null
     *
     * @return array<string, mixed>|null
     */
    public function profile(): ?array
    {
        $profile = IndustryProfiles::get($this->current());

        return $profile === null ? null : IndustryProfiles::resolve($profile, $this->name());
    }

    /** サービスのページ（/service）の、いまの呼び名（業種を選んでいなければ「サービス」） */
    public function serviceNoun(): string
    {
        return IndustryProfiles::serviceNoun($this->current());
    }

    /**
     * `$context` を渡したときだけ、記事の一覧の URL も業種に合わせる（操作として監査に残し、
     * 公開中なら旧 URL からの転送を作るため。`UpdateContentType` を通す）。
     *
     * @return array{form: bool, postLabel: bool, listSlug?: bool, public?: bool}|null 何を変えたか。知らない業種なら null
     */
    public function apply(string $key, ?OperationContext $context = null, ?string $name = null): ?array
    {
        $profile = IndustryProfiles::get($key);

        if ($profile === null) {
            return null;
        }

        $firstApplication = $this->current() === null;

        // 前の名前で入れた形も「手を入れていない」とみなすため、替える前に覚えておく
        $this->previousName = $this->name();
        $name = $key === 'other' ? mb_substr(trim((string) $name), 0, IndustryProfiles::NAME_MAX) : '';
        $this->settings->put([IndustryProfiles::SETTING_KEY => $key, IndustryProfiles::NAME_KEY => $name]);
        $profile = IndustryProfiles::resolve($profile, $name);

        $changed = [
            'form' => $this->applyForm($profile),
            'postLabel' => $this->applyPostLabel($profile),
            'serviceTitle' => $this->applyServiceTitle($profile),
        ];

        if ($context !== null) {
            $changed['public'] = $firstApplication && $this->applyPublishingDefaults($profile, $context);
            $changed['listSlug'] = $this->applyListSlug($profile, $context);
            $this->applyPostNavigation();
        }

        return $changed;
    }

    /** 利用者が保存するまで、公開した記事への入口と業種の呼び名を揃える。 */
    private function applyPostNavigation(): void
    {
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        app()->forgetInstance(ContentTypeLifecyclePolicy::class);
        if (! app(ContentTypeLifecyclePolicy::class)->isPublic('post')) {
            return;
        }
        $settings = app(ContentTypeSettingsResolver::class)->for('post');
        if (! $settings->flag('has_archive')) {
            return;
        }

        DB::transaction(function () use ($settings): void {
            $menu = Menu::query()->where('key', 'header')->lockForUpdate()->first();
            if ($menu === null || $menu->lock_version !== 0) {
                return;
            }

            $items = $menu->items()->orderBy('sort_order')->get();
            $existing = $items->first(fn ($item): bool => $item->link_type === MenuLinkType::ContentIndex->value && $item->content_type === 'post');
            $title = $settings->labelOr('投稿');
            if ($existing !== null) {
                if ($existing->id === $this->settings->get(self::AUTO_MENU_ITEM_KEY)) {
                    $existing->update(['title' => $title]);
                }

                return;
            }

            $roots = $items->whereNull('parent_id')->values();
            $position = $roots->search(fn ($item): bool => $item->link_type === MenuLinkType::Route->value && $item->link_route === 'contact.show');
            $position = $position === false ? $roots->count() : $position;
            // 既存の並びを保ち、問い合わせの直前に 1 行分の場所を空ける。
            foreach ($roots as $index => $item) {
                $item->update(['sort_order' => $index >= $position ? $index + 1 : $index]);
            }
            $item = $menu->items()->create([
                'title' => $title,
                'link_type' => MenuLinkType::ContentIndex->value,
                'content_type' => 'post',
                'sort_order' => $position,
            ]);
            $this->settings->put([self::AUTO_MENU_ITEM_KEY => $item->id]);
        });
    }

    /**
     * 初めての業種選択では、その記事を公開サイトへ出せる形で始める。
     * 保存済みの false は利用者の選択なので、初回であっても上書きしない。
     *
     * @param  array<string, mixed>  $profile
     */
    private function applyPublishingDefaults(array $profile, OperationContext $context): bool
    {
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        $current = app(ContentTypeSettingsResolver::class)->for('post');

        if (array_key_exists('public', $current->registration)) {
            return false;
        }

        $payload = PublishingQuickStart::payloadFor($current);
        $slug = $current->publicSlug;

        if ($slug === null) {
            $slug = $this->availableListSlug((string) $profile['listSlug']);
            if ($slug === null) {
                return false;
            }
        }

        $payload['public_slug'] = $slug;

        $success = app(UpdateContentType::class)->execute($payload, $context)->isSuccess();
        if ($success && $current->publicSlug === null) {
            $this->settings->put([self::AUTO_LIST_SLUG_KEY => $slug]);
        }

        return $success;
    }

    /**
     * 記事の一覧の URL を業種の呼び名に合わせる（UX 一周 2026-09-28: 工務店の施工事例が /blog だった）。
     *
     * - 変えるのは、URL が未設定・既定の /blog・どれかの業種で入れた URL のときだけ。自分で付けた URL は変えない
     * - 公開中なら `UpdateContentType` が旧 URL からの転送（301）を作る
     * - ほかの画面と重なる URL は採番する。空きが無ければ今の URL を残す
     *
     * @param  array<string, mixed>  $profile
     */
    private function applyListSlug(array $profile, OperationContext $context): bool
    {
        // 呼び名を保存した直後なので、読み直す（1 リクエストの中で覚えている版は古い）
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        $current = app(ContentTypeSettingsResolver::class)->for('post');
        $slug = $current->publicSlug;
        $ours = [PublishingQuickStart::DEFAULT_SLUG, ...array_column(IndustryProfiles::all(), 'listSlug'), $this->settings->get(self::AUTO_LIST_SLUG_KEY)];

        if (is_string($slug) && $slug !== '' && ! in_array($slug, $ours, true)) {
            return false;
        }

        $nextSlug = $this->availableListSlug((string) $profile['listSlug']);

        if ($nextSlug === null || $slug === $nextSlug) {
            return false;
        }

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => $current->lockVersion,
            'display_overrides' => $current->displayOverrides,
            'registration' => $current->registration,
            'public_slug' => $nextSlug,
        ], $context);

        if ($result->isSuccess()) {
            $this->settings->put([self::AUTO_LIST_SLUG_KEY => $nextSlug]);
        }

        return $result->isSuccess();
    }

    /** 初回と選び直しで同じ採番を使い、既存のページや機能の URL を守る。 */
    private function availableListSlug(string $base): ?string
    {
        $registry = app(PublicSlugRegistry::class);
        for ($suffix = 1; $suffix <= 100; $suffix++) {
            $slug = $suffix === 1 ? $base : $base.'-'.$suffix;
            if ($registry->conflictFor($slug, 'post') === null) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * その業種のお問い合わせの項目。
     *
     * @param  array<string, mixed>  $profile
     * @return list<array<string, mixed>>
     */
    public static function contactFields(array $profile): array
    {
        $fields = [
            ['id' => 'name', 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true, 'max' => 100],
            ['id' => 'email', 'key' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'required' => true, 'max' => 255],
        ];

        if ($profile['keepCompany']) {
            $label = is_string($profile['companyLabel'] ?? null) ? $profile['companyLabel'] : self::COMPANY_LABEL;
            $fields[] = ['id' => 'company', 'key' => 'company', 'type' => 'text', 'label' => $label, 'max' => 120];
        }

        foreach ($profile['extraFields'] as $extra) {
            $fields[] = ['id' => $extra['key'], ...$extra];
        }

        $fields[] = ['id' => 'subject', 'key' => 'subject', 'type' => 'select', 'label' => $profile['subjectLabel'], 'choices' => $profile['subjectChoices'], 'required' => true];
        $fields[] = ['id' => 'message', 'key' => 'message', 'type' => 'textarea', 'label' => 'お問い合わせ内容', 'required' => true, 'max' => 5000];

        return $fields;
    }

    /** @param  array<string, mixed>  $profile */
    private function applyForm(array $profile): bool
    {
        $form = Form::query()->where('key', 'contact')->first();

        if ($form === null || $form->field_group_id === null) {
            return false;
        }

        $group = DB::table('field_groups')->where('id', $form->field_group_id)->first();

        if ($group === null || ! $this->untouched($form, (string) $group->fields)) {
            return false;
        }

        DB::transaction(function () use ($form, $group, $profile): void {
            DB::table('field_groups')->where('id', $group->id)->update([
                'fields' => json_encode(self::contactFields($profile), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            $form->forceFill(['description' => $profile['contactDescription']])->save();
        });

        return true;
    }

    /** 見本のままか、どれかの業種の形のままか（店の人が手を入れていないか） */
    private function untouched(Form $form, string $fieldsJson): bool
    {
        $fields = json_decode($fieldsJson, true);

        if (! is_array($fields)) {
            return false;
        }

        $shape = array_map(static fn (array $f): array => [$f['key'] ?? null, $f['label'] ?? null, $f['choices'] ?? []], $fields);
        $description = trim((string) $form->description);

        $seeded = array_column($fields, 'key') === self::SEEDED_KEYS
            && (collect($fields)->firstWhere('key', 'subject')['choices'] ?? null) === self::SEEDED_CHOICES
            && ($description === '' || PublicationReadiness::sampleLeadsIn($description) !== []);

        if ($seeded) {
            return true;
        }

        $shapes = [];

        foreach (IndustryProfiles::all() as $profile) {
            // 「その他」は名前で文が変わる。前の名前・名前なしで入れた形も、業種の形として扱う
            foreach (array_unique([$this->previousName, $this->name(), '']) as $name) {
                $shapes[] = IndustryProfiles::resolve($profile, $name);
            }
        }

        // 前の版の「その他」の形（会社名の欄があった）も、手を入れていない形として扱う
        foreach (IndustryProfiles::legacyOtherShapes() as $legacy) {
            $shapes[] = [...IndustryProfiles::get('other'), ...$legacy];
        }

        foreach ($shapes as $profile) {
            // 前の版で入れた形（会社名の欄が「会社名・屋号」だった「その他」）も、業種の形として扱う。
            // 名前を替えただけで「店の人が手を入れた」とみなすと、選び直しても項目が替わらなくなる
            foreach ([$profile, [...$profile, 'companyLabel' => self::COMPANY_LABEL]] as $shapeOf) {
                $expected = array_map(
                    static fn (array $f): array => [$f['key'], $f['label'], $f['choices'] ?? []],
                    self::contactFields($shapeOf),
                );

                if ($shape === $expected && ($description === '' || $description === $profile['contactDescription'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * メニューに並ぶ /service の表示名を店の呼び名にする（UX 一周 第 15 周: カフェなのにナビが「サービス」）。
     *
     * 替えるのは、表示名が「サービス」かどれかの業種の呼び名のまま（= 店の人が付けていない）の行だけ。
     * 自分で付けた名前は残す。ナビ・ページの一覧・メニューの画面の候補は `serviceNoun()` を読むので、
     * DB に残るのはこのメニューの行だけである。
     *
     * @param  array<string, mixed>  $profile
     */
    private function applyServiceTitle(array $profile): bool
    {
        if (! Route::has('service')) {
            return false;
        }

        $noun = (string) ($profile['serviceNoun'] ?? IndustryProfiles::DEFAULT_SERVICE_NOUN);

        $changed = DB::table('menu_items')
            ->where('link_type', 'route')
            ->where('link_route', 'service')
            ->whereIn('title', IndustryProfiles::serviceNouns())
            ->where('title', '!=', $noun)
            ->update(['title' => $noun, 'updated_at' => now()]);

        return $changed > 0;
    }

    /** @param  array<string, mixed>  $profile */
    private function applyPostLabel(array $profile): bool
    {
        $current = $this->types->for('post');
        $label = (string) ($current->displayOverrides['label'] ?? '');
        $ours = array_column(IndustryProfiles::all(), 'postLabel');

        // 自分で付けた呼び名は変えない（業種で入れた呼び名なら、選び直しに合わせて替える）
        if ($label !== '' && ! in_array($label, $ours, true)) {
            return false;
        }

        return $this->writer->save('post', [
            'display_overrides' => json_encode(
                [...$current->displayOverrides, 'label' => $profile['postLabel'], 'plural_label' => $profile['postLabel']],
                JSON_UNESCAPED_UNICODE,
            ),
        ], $current->lockVersion);
    }
}

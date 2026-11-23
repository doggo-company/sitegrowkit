<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\SlugFromTitle;
use AgentCms\Core\Wxr\Plan\PageSlugReservation;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ページ作成（Phase 9.1D / F-P1 是正は Phase 9.3B #17）。作成直後は下書きで、document は空。
 *
 * ## 外部入力 contract は `title` / `slug` の 2 つだけ（MB-1〜MB-7）
 *
 * | # | 規則 |
 * | --- | --- |
 * | MB-1 | 外部入力面では **常に document 管理の Page** を作る |
 * | MB-2 | `managedBy` は contract から**除去**した（validation rule にも無い） |
 * | MB-3 | 明示的に送られたら **validation error**。黙って無視しない |
 * | MB-4 | 未指定時は必ず document |
 * | MB-5 | Admin / API / MCP / CLI で**同じ挙動**。経路による差を作らない |
 * | MB-6 | `interface` による分岐を内部に作らない（`Cli` でも blade を許さない） |
 * | MB-7 | blade 管理 Page は seeder / internal factory 等の**別の内部コード経路**で作る |
 *
 * `managedBy: "document"` も拒否する。値が安全かどうかではなく、
 * **contract に存在しないキーだから**拒否する（「隠す」でも「経路で分ける」でもない）。
 */
final class CreatePage implements Operation
{
    /** 外部入力で受理するキー。**ここに無いものは値によらず拒否する** */
    private const ALLOWED_KEYS = ['title', 'slug'];

    /**
     * 返す言葉を画面のラベルに揃える。
     *
     * 画面は「URL」と書いてあるのに、返るエラーだけ `slug` だった。
     * **利用者に内部の語を見せない**（locale に依らず同じ言葉になるよう、ここで渡す）。
     *
     * @var array<string, string>
     */
    private const ATTRIBUTE_NAMES = ['title' => 'タイトル', 'slug' => 'URL'];

    /** 題から URL を作れなかったときの土台。意味は持たないが、あとから直せる */
    private const FALLBACK_SLUG = 'page';

    /** 連番の打ち切り。ここまで埋まっていたら乱数へ倒す */
    private const MAX_SUFFIX = 50;

    /**
     * 管理方式を外から指定しようとする綴り（別名で blade を通させない）。
     * **小文字で持つ**。判定時は入力側だけを小文字化して比べる
     */
    private const MANAGED_BY_KEYS = ['managedby', 'managed_by'];

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly PageRepository $pages,
        private readonly ValidationFactory $validator,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.create';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        // 認可の後・DB へ触れる前に契約違反を弾く。
        // ここで返した場合、pages / page_versions / audit_logs は 1 行も変わらない
        $rejected = $this->rejectedKeys($attributes);

        if ($rejected !== []) {
            return $this->outcome->record($this, $context, Result::validationError($rejected, 'ページの入力内容を確認してください'));
        }

        // URL を先に決める習慣が無い人のために、空なら題から下書きする（WP 一周 2026-09-03）
        $attributes = $this->draftSlug($attributes);

        /*
         * 漢字を含む題で URL が空のまま来た（画面や MCP の読みを通らなかった）ときは、黙って page に
         * 落とさず、URL を求める（UX 一周 18 周目 A1: MCP の page_create で /page-2 になっていた）。
         * ここでは漢字を読めない（読みは画面と MCP の形態素解析が持つ）。
         */
        if (($attributes['slug'] ?? '') === '') {
            return $this->outcome->record($this, $context, Result::validationError(
                ['slug' => [self::KANJI_NEEDS_SLUG]],
                'ページの入力内容を確認してください',
            ));
        }

        $validator = $this->validator->make($attributes, [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')],
        ], [], self::ATTRIBUTE_NAMES);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $this->outcome->record($this, $context, Result::validationError(
                $this->explainTrashedSlug($attributes, $errors),
                'ページの入力内容を確認してください',
            ));
        }

        /** @var array{title: string, slug: string} $data */
        $data = $validator->validated();

        $page = Page::query()->create([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'status' => PageStatus::Draft->value,
            // MB-1 / MB-4 / MB-6: 常に document。context / interface による分岐は作らない
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $this->pages->createVersion(
            $page,
            DocumentTree::empty(),
            $context->interface->value,
            $context->actorId,
        );

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['page' => GetPage::present($page->refresh())]),
            resourceType: 'page',
            resourceId: (string) $page->id,
            metadata: ['slug' => $page->slug],
        );
    }

    /**
     * 受理キー以外を **送られたキー名のまま** validation error にする（MB-3）。
     *
     * 正しい `title` / `slug` と一緒に送られても全体を拒否する。
     * 「一部だけ採用して残りを黙って捨てる」を作らないための境界。
     * エラー文には**入力値を載せない**（値の反射を避ける）。
     *
     * @param  array<array-key, mixed>  $attributes  外部入力（キーが文字列とは限らない）
     * @return array<string, list<string>>
     */
    private function rejectedKeys(array $attributes): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        foreach (array_keys($attributes) as $key) {
            $name = (string) $key;

            if (in_array($name, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $errors[$name] = [$this->rejectionMessage($name)];
        }

        return $errors;
    }

    /**
     * slug がゴミ箱のページと衝突したときは、**その事実を具体的に**伝える。
     *
     * trash 中も slug は予約され続ける（unique 制約は維持する）。
     * 「既に使用されています」だけでは、一覧のどこを探しても見つからず利用者が詰まる。
     * 判定は **Application Service 側**に置き、Admin / API / MCP のどこから来ても同じ理由になる。
     *
     * @param  array<array-key, mixed>  $attributes
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function explainTrashedSlug(array $attributes, array $errors): array
    {
        if (! isset($errors['slug'])) {
            return $errors;
        }

        $slug = $attributes['slug'] ?? null;

        if (! is_string($slug) || $slug === '') {
            return $errors;
        }

        $trashed = Page::query()
            ->where('slug', $slug)
            ->where('status', PageStatus::Trashed->value)
            ->exists();

        if (! $trashed) {
            return $errors;
        }

        $errors['slug'] = ['同じ URL のページがゴミ箱にあります。復元するか、別の URL を指定してください。'];

        return $errors;
    }

    /**
     * 作成の画面に出す URL の候補（UX 一周 19 周目）。作るときと同じ読み・同じ「使われていれば -2」で決める。
     * 何も読めなければ空。
     */
    public function previewSlug(string $title): string
    {
        $drafted = $this->draftSlug(['title' => $title]);

        return (string) ($drafted['slug'] ?? '');
    }

    /** 漢字の題で URL が空のときの言葉（画面の欄の下と、MCP の結果に出る） */
    public const KANJI_NEEDS_SLUG = 'このタイトルからは URL を作れませんでした（読めない字だけでした）。'
        .'URL を英小文字・数字・ハイフンで入れてください（例: company-profile）。';

    /**
     * URL（slug）が空なら、題から下書きする（WP 一周 2026-09-03 / 効くもの ②）。
     *
     * **利用者が入れた URL は絶対に書き換えない。** 触るのは空のときだけである。
     *
     * 日本語だけの題は `Str::slug` が空を返すので、意味を持たない土台へ倒す
     * （`TermSlug::generate` と同じ考え方 — 一意であることを優先し、rename できることで償う）。
     *
     * 重複は**ここで避ける**。validator の unique に任せると、
     * 「URL を書いていないのに URL のエラーが出る」という説明のつかない画面になる。
     * ゴミ箱のページも slug を予約し続けるので、status で絞らずに数える。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function draftSlug(array $attributes): array
    {
        $slug = $attributes['slug'] ?? null;

        if (is_string($slug) && trim($slug) !== '') {
            return $attributes;
        }

        $title = $attributes['title'] ?? null;
        // 日本語の定番のページ名は意味のある URL へ（会社概要 → company）。`SlugFromTitle`
        $base = is_string($title) ? SlugFromTitle::draft($title) : '';

        if ($base === '') {
            // 漢字を含む題は土台に倒さない（execute が URL を求める。18 周目 A1）
            if (is_string($title) && preg_match('/\\p{Han}/u', $title) === 1) {
                $attributes['slug'] = '';

                return $attributes;
            }

            $base = self::FALLBACK_SLUG;
        }

        $attributes['slug'] = $this->firstUnusedSlug(Str::limit($base, 180, ''));

        return $attributes;
    }

    /**
     * 空いている URL を探す。`about-us` → `about-us-2` → `about-us-3` …
     *
     * **ほかの画面が持っている URL も避ける**（UX 一周 2026-09-28）。タイトルから
     * `contact` や `news` を付けられるようになったので、お問い合わせやお知らせの一覧と
     * 同じ URL を黙って取ると、公開しても出ないページができる。
     */
    private function firstUnusedSlug(string $base): string
    {
        $candidate = $base;
        $reservation = app(PageSlugReservation::class);

        for ($n = 2; $this->slugTaken($candidate) || $reservation->reserved($candidate); $n++) {
            if ($n > self::MAX_SUFFIX) {
                return $base.'-'.Str::lower(Str::random(6));
            }

            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    private function slugTaken(string $slug): bool
    {
        return Page::query()->where('slug', $slug)->exists();
    }

    /** 管理方式の指定だけは、なぜ通らないのかが分かる文言にする */
    private function rejectionMessage(string $key): string
    {
        if (in_array(strtolower($key), self::MANAGED_BY_KEYS, true)) {
            return 'ページの管理方式は指定できません（作成されるページは常に document 管理です）';
        }

        return 'このキーは指定できません';
    }
}

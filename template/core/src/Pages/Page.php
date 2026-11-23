<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ページ（Phase 9.1D・owner 決定 G17 / G23 / G25）。
 *
 * 構成（document）は保持せず、**version が持つ**。
 * `managed_by` により既存の Blade 管理ページと共存する。
 *
 * ## 予約公開（release R02）
 *
 * `status = published` のまま `published_at` を **未来**に置くと予約になる。
 * 公開面（route / menu / sitemap / トップ指定）はすべて `isPublicallyVisible()` か
 * `scopePubliclyVisible()` を通るので、**日時が来れば cron 無しで**そのまま出る。
 * `published_at` が **null の公開ページは従来どおり公開**（既存サイトを変えない）。
 * 下書き・ゴミ箱は日時が過ぎても出ない — 状態が先で、日時は公開状態の中の条件。
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property PageStatus $status
 * @property string $managed_by
 * @property int|null $draft_version_id
 * @property int|null $published_version_id
 * @property Carbon|null $published_at
 * @property Carbon|null $trashed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $seo
 */
final class Page extends Model
{
    public const MANAGED_BY_DOCUMENT = 'document';

    public const MANAGED_BY_BLADE = 'blade';

    /** `publishState()` が `status` に足す唯一の値 */
    public const STATE_SCHEDULED = 'scheduled';

    protected $table = 'pages';

    protected $fillable = [
        'slug', 'title', 'status', 'managed_by',
        'draft_version_id', 'published_version_id',
        'published_at', 'trashed_at', 'seo',
    ];

    protected $casts = [
        'status' => PageStatus::class,
        'seo' => 'array',
        'published_at' => 'datetime',
        'trashed_at' => 'datetime',
    ];

    /** @return HasMany<PageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderByDesc('revision');
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'draft_version_id');
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'published_version_id');
    }

    public function isDocumentManaged(): bool
    {
        return $this->managed_by === self::MANAGED_BY_DOCUMENT;
    }

    /** 公開ページとして見えるか（trash は公開しない・予約中は日時まで出さない） */
    public function isPublicallyVisible(): bool
    {
        return $this->status === PageStatus::Published
            && $this->published_version_id !== null
            && ! $this->isScheduled();
    }

    /**
     * 公開予約中か（公開状態で、公開日時がまだ来ていない）。
     *
     * 境界は **秒**で、`published_at` ちょうどの秒から公開になる
     * （`scopePubliclyVisible()` の `<=` と同じ側に倒す）。
     */
    public function isScheduled(): bool
    {
        return $this->status === PageStatus::Published
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }

    /**
     * 画面・API に出す公開状態。`status` に **予約**を足した 4 値。
     *
     * `status` の列は増やさない — 予約は「公開状態のうち日時が未来」であって、
     * 別の状態遷移を持たない（日時が来ても行は変わらない）。
     */
    public function publishState(): string
    {
        return $this->isScheduled() ? self::STATE_SCHEDULED : $this->status->value;
    }

    public function publishStateLabel(): string
    {
        return $this->isScheduled() ? '公開予約' : $this->status->label();
    }

    /**
     * 公開面から見えるページだけに絞る（`isPublicallyVisible()` の query 版）。
     *
     * 2 つの判定は **同じ条件**でなければならない。片方だけ緩むと
     * 「sitemap には載るのに開くと 404」（またはその逆）が起きる。
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', PageStatus::Published->value)
            ->whereNotNull('published_version_id')
            ->whereNull('trashed_at')
            ->where(static function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    /** 一覧・監査で人が識別するための表示名 */
    public function label(): string
    {
        return $this->title !== '' ? $this->title : "/{$this->slug}";
    }
}

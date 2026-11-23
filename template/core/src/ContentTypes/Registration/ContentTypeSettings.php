<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\Permalink\PermalinkStructure;

/**
 * 1 つの投稿タイプの解決済み設定（Phase 9.7 / SPEC §F.5.1 / §J.7）。
 *
 * **行が無い型にも必ず答える。** 既存のファイル型は `content_types` に行を持たないので、
 * 「行が無い = 既定」を 1 か所で決めておかないと、面ごとに違う既定が生まれる。
 */
final readonly class ContentTypeSettings
{
    /**
     * @param  array<string, bool|string>  $registration  allowlist 済み（§D.1 の「登録」列）
     * @param  array<string, bool|string>  $displayOverrides  allowlist 済み（§F.5.1）
     * @param  list<string>  $unrecognized  診断用。読めなかった列 / 落としたキー
     */
    private function __construct(
        public string $contentTypeId,
        public ContentTypeStatus $status,
        public ContentTypeLifecycle $lifecycle,
        public int $menuOrder,
        public bool $commentsEnabled,
        public bool $revisionsEnabled,
        public ?string $publicSlug,
        public array $registration,
        public array $displayOverrides,
        public int $lockVersion,
        public bool $stored,
        public array $unrecognized,
    ) {}

    /**
     * 行が無いときの既定（SPEC §J.7）。
     *
     * **`content_types` に行を作らずに済ませるための既定である。** migration が
     * 既存の型へ一括 insert しないのは、この既定があるからこそ成り立つ。
     */
    public static function defaultsFor(string $contentTypeId): self
    {
        return new self(
            contentTypeId: $contentTypeId,
            status: ContentTypeStatus::Active,
            lifecycle: ContentTypeLifecycle::Ready,
            menuOrder: 0,
            commentsEnabled: false,
            revisionsEnabled: true,
            publicSlug: null,
            registration: [],
            displayOverrides: [],
            lockVersion: 0,
            stored: false,
            unrecognized: [],
        );
    }

    /**
     * DB の 1 行を読む。**未知の値は fail-closed で倒し、倒したことを覚えておく**。
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $id = (string) ($row['content_type_id'] ?? '');
        $rawStatus = (string) ($row['status'] ?? ContentTypeStatus::Active->value);
        $rawLifecycle = (string) ($row['lifecycle'] ?? ContentTypeLifecycle::Ready->value);

        $unrecognized = [];

        if (! ContentTypeStatus::recognizes($rawStatus)) {
            $unrecognized[] = "status={$rawStatus}";
        }

        if (! ContentTypeLifecycle::recognizes($rawLifecycle)) {
            $unrecognized[] = "lifecycle={$rawLifecycle}";
        }

        $droppedRegistration = [];
        $droppedDisplay = [];

        $registration = ContentTypeRegistrationAllowlist::filterRegistration($row['registration'] ?? null, $droppedRegistration);
        $display = ContentTypeRegistrationAllowlist::filterDisplay($row['display_overrides'] ?? null, $droppedDisplay);

        foreach ($droppedRegistration as $key) {
            $unrecognized[] = "registration.{$key}";
        }

        foreach ($droppedDisplay as $key) {
            $unrecognized[] = "display_overrides.{$key}";
        }

        $slug = $row['public_slug'] ?? null;

        if ($slug !== null && ! ContentTypeRegistrationAllowlist::isValidSlug($slug)) {
            $unrecognized[] = 'public_slug';
            $slug = null;
        }

        return new self(
            contentTypeId: $id,
            status: ContentTypeStatus::fromStorage($rawStatus),
            lifecycle: ContentTypeLifecycle::fromStorage($rawLifecycle),
            menuOrder: self::clampMenuOrder($row['menu_order'] ?? 0),
            commentsEnabled: (bool) ($row['comments_enabled'] ?? false),
            revisionsEnabled: (bool) ($row['revisions_enabled'] ?? true),
            publicSlug: $slug === null ? null : (string) $slug,
            registration: $registration,
            displayOverrides: $display,
            lockVersion: max(0, (int) ($row['lock_version'] ?? 0)),
            stored: true,
            unrecognized: $unrecognized,
        );
    }

    /** 表示に使う label（上書きが無ければ呼び出し側の値をそのまま返す） */
    public function labelOr(string $fallback): string
    {
        $label = $this->displayOverrides['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $fallback;
    }

    /** 登録属性の真偽値（未設定は `$default`） */
    /**
     * 公開 URL の構造（P1 ③ 段 2）。
     *
     * **書かれていない / 読めない値なら既定**（`base_slug` = いままでの形）。
     * 読めなかったことは `unrecognized` に残っているので、画面から診断できる。
     */
    public function permalink(): PermalinkStructure
    {
        return PermalinkStructure::tryFromValue($this->registration['permalink'] ?? null)
            ?? PermalinkStructure::DEFAULT;
    }

    public function flag(string $key, bool $default = false): bool
    {
        $value = $this->registration[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'lifecycle' => $this->lifecycle->value,
            'menuOrder' => $this->menuOrder,
            'commentsEnabled' => $this->commentsEnabled,
            'revisionsEnabled' => $this->revisionsEnabled,
            'publicSlug' => $this->publicSlug,
            'registration' => $this->registration,
            'displayOverrides' => $this->displayOverrides,
            'lockVersion' => $this->lockVersion,
            'stored' => $this->stored,
            'unrecognized' => $this->unrecognized,
        ];
    }

    /**
     * 0〜9999 に収める（SPEC §J.2）。
     *
     * CHECK が効かない driver でも、**読み取り側で必ず範囲へ倒す**。
     */
    private static function clampMenuOrder(mixed $raw): int
    {
        $value = is_numeric($raw) ? (int) $raw : 0;

        return max(0, min(9999, $value));
    }
}

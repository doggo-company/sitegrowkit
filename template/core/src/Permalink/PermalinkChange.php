<?php

declare(strict_types=1);

namespace AgentCms\Core\Permalink;

/**
 * URL の入口・構造が動いたこと（P1 ③ 段 3）。
 *
 * ## 1 本で書けるかどうかが、この class の存在理由である
 *
 * 構造を変えると**その型の記事の URL が一斉に変わる**。1000 記事なら 1000 の URL が死ぬ。
 * 転送ルールを記事ごとに作れば数が際限なく増えるので、**接頭辞の付け替え 1 本**で
 * 書けるときだけ自動で作り、書けないときは**変更そのものを断る**。
 *
 * ## 何が 1 本で書けるのか
 *
 * `prefix` ルールは**接頭辞を置き換えて残りを付け直す**（`RedirectPath::buildTarget`）。
 * だから **入口より後ろの形が変わらない**変更だけが 1 本で書ける:
 *
 * | 変更 | 例 | 1 本で書けるか |
 * | --- | --- | --- |
 * | 入口の付け替え | `/blog/x` → `/news/x` | **書ける**（`/blog` → `/news`） |
 * | 入口を落とす | `/blog/x` → `/x` | **書ける**（`/blog` → `/`） |
 * | 入口を足す | `/x` → `/blog/x` | **書けない**（`/` を source にすると**サイト全体**を巻き込む） |
 * | 後ろの形が変わる | `/blog/hello` → `/blog/2026/08/hello` | **書けない**（途中に挿入する形は接頭辞では表せない） |
 */
final readonly class PermalinkChange
{
    public function __construct(
        public PermalinkStructure $fromStructure,
        public ?string $fromBase,
        public PermalinkStructure $toStructure,
        public ?string $toBase,
    ) {}

    /** 何か動いたか（動いていなければ転送は要らない） */
    public function isMove(): bool
    {
        return $this->fromStructure !== $this->toStructure || $this->fromBase !== $this->toBase;
    }

    /**
     * **何が動くのか**（F-41）。
     *
     * 「入口が在るか」と「記事 URL に入口が入るか」は別の問いである。
     * 兼ねていたせいで、`slug` 構造のまま入口の名前だけ変えるとき
     * （**記事 URL は動かず、一覧だけが動く**）に、起きないことを理由に断っていた。
     */
    public function kind(): PermalinkMoveKind
    {
        if (! $this->isMove()) {
            return PermalinkMoveKind::Nothing;
        }

        if (self::tail($this->fromStructure) !== self::tail($this->toStructure)) {
            // 入口より後ろの形が変わる（途中への挿入・削除は接頭辞で表せない）
            return PermalinkMoveKind::Unwritable;
        }

        $fromBase = self::text($this->fromBase);
        $toBase = self::text($this->toBase);

        if ($this->fromStructure->usesBase()) {
            // 記事 URL に入口が入っていた。付け替え / 取り外しは接頭辞 1 本で書ける
            if ($fromBase === null) {
                return PermalinkMoveKind::Nothing;   // 入口が無ければ、記事 URL も作れていない
            }

            return ! $this->toStructure->usesBase() || $toBase !== null
                ? PermalinkMoveKind::Entries
                : PermalinkMoveKind::Nothing;
        }

        if ($this->toStructure->usesBase()) {
            /*
             * **入口を足す向き。** 旧 URL は `/hello` で、これを 1 本で書くには
             * source が `/` になり**サイト全体**を巻き込む。
             */
            return PermalinkMoveKind::Unwritable;
        }

        // 記事 URL は `/hello` のまま動かない。**動くのは一覧だけ**
        return $fromBase !== null && $toBase !== null
            ? PermalinkMoveKind::ArchiveOnly
            : PermalinkMoveKind::Nothing;
    }

    /** 接頭辞の付け替え 1 本で書けるか */
    public function canRewrite(): bool
    {
        return $this->kind()->needsRedirect();
    }

    /** 転送元（`/blog`）。書けないときは null */
    public function source(): ?string
    {
        return $this->canRewrite() ? '/'.$this->fromBase : null;
    }

    /**
     * 転送先。書けないときは null。
     *
     * **記事ごと動くときだけ「入口を落とす」がある**（`/blog/x` → `/x`）。
     * 一覧だけが動くときは、行き先は必ず新しい入口である。
     */
    public function target(): ?string
    {
        return match ($this->kind()) {
            PermalinkMoveKind::Entries => $this->toStructure->usesBase() ? '/'.$this->toBase : '/',
            PermalinkMoveKind::ArchiveOnly => '/'.$this->toBase,
            default => null,
        };
    }

    private static function text(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * 入口より後ろの形。**これが同じなら接頭辞の付け替えで済む。**
     */
    private static function tail(PermalinkStructure $structure): string
    {
        return match ($structure) {
            PermalinkStructure::BaseSlug, PermalinkStructure::Slug => 'slug',
            PermalinkStructure::BaseDateSlug => 'date/slug',
            PermalinkStructure::BaseId => 'id',
        };
    }
}

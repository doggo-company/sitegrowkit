<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Support\Str;

/**
 * Term の slug 規則（Phase 9.3B / STAGE 2・SPEC §F）。
 *
 * slug は **identity ではない**（identity は ULID — §C.1）。rename しても
 * assignment・PageDocument filter は壊れない。
 *
 * **DB collation に依存しない**（F-4）。既定の `utf8mb4_unicode_ci` は
 * 大文字小文字・アクセント非区別のため、unique index だけに頼ると
 * 「衝突したりしなかったり」する。canonical 化と重複検査を Application 側で必ず行う。
 */
final class TermSlug
{
    /** URL 安全な形（§F.1） */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** DB の varchar(120) に合わせる */
    public const MAX_LENGTH = 120;

    /** 自動生成 slug の接頭辞（§F.2 F-1） */
    private const GENERATED_PREFIX = 'term-';

    /**
     * 保存前に 1 回だけ行う正規化（F-5）。
     * 小文字化・前後の空白除去のみで、**語の統合や別名解決はしない**（F-3）。
     */
    public static function canonicalize(string $raw): string
    {
        return Str::lower(trim($raw));
    }

    public static function isValid(string $slug): bool
    {
        return $slug !== ''
            && mb_strlen($slug) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $slug) === 1;
    }

    /**
     * 日本語名から slug を作る。
     *
     * `Str::slug('お知らせ')` は **空文字**を返すため、そのままでは保存できない（F-1）。
     * 空になった場合は **ULID 由来の一意な fallback** を使う。
     * fallback は「一意だが意味を持たない」ので、Admin から rename できることを示す（F-2）。
     *
     * **同義語の自動統合はしない**（F-3）。異なる名前は異なる slug になる。
     */
    public static function generate(string $name, string $termId): string
    {
        $slug = self::canonicalize(Str::slug($name));

        if (self::isValid($slug)) {
            return $slug;
        }

        return self::fallbackFor($termId);
    }

    /**
     * 名前から作れないときの綴りを、**読める通し番号**で作る
     * （WP 一周 6 周目 2026-09-03）。
     *
     * ## なぜ ULID をやめたか
     *
     * `Str::slug('予防接種')` は空を返すので、これまでは `term-a2ywwcsg` のように
     * ULID の下 8 桁へ倒していた。日本語の案件では**ほぼ全部がこれになる**。
     *
     * いまは公開側に出ていないので実害は無いが、
     * **分類ごとの一覧ページを作った瞬間、これがそのまま URL になる**。
     * `/blog/shinryo-kamoku/term-a2ywwcsg` は案件で配れない。
     *
     * 製品は同じ問題を 2 度、読める形で解いている — 投稿の URL は日付から
     * （`2026-09-03-1`）、ページは型名から（`page`）。ここだけ乱数だった。
     *
     * 🔴 **SPEC §F の F-1／F-2 に触れている。** 変えたのは**綴りの形だけ**で、
     * 「一意」「意味を持たない」「あとから変えられる」は変えていない。
     *
     * ## アーカイブ済みも数に入れる
     *
     * archive は綴りを解放しない（`isTaken` と同じ扱い）。解放すると、
     * 戻したときに 2 つが同じ綴りになる。
     */
    public static function numberedFallback(string $taxonomyId): string
    {
        $used = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomyId)
            ->where('slug', 'like', self::GENERATED_PREFIX.'%')
            ->pluck('slug')
            ->all();

        $taken = [];

        foreach ($used as $slug) {
            $tail = substr((string) $slug, strlen(self::GENERATED_PREFIX));

            if (preg_match('/^[0-9]+$/', $tail) === 1) {
                $taken[(int) $tail] = true;
            }
        }

        $next = 1;

        while (isset($taken[$next])) {
            $next++;
        }

        return self::GENERATED_PREFIX.$next;
    }

    /**
     * ULID から意味を持たない一意 slug を作る（F-1）。
     * ULID の下 8 桁は乱数部なので、同一 taxonomy 内で実質衝突しない。
     */
    public static function fallbackFor(string $termId): string
    {
        $suffix = Str::lower(substr($termId, -8));

        // ULID 以外が渡された場合でも形式を壊さない
        if (preg_match('/^[a-z0-9]{1,8}$/', $suffix) !== 1) {
            $suffix = Str::lower(substr(TaxonomyId::generate(), -8));
        }

        return self::GENERATED_PREFIX.$suffix;
    }

    /** 自動生成された slug か（Admin が「後から変更できます」と案内するため） */
    public static function isGenerated(string $slug): bool
    {
        return preg_match('/^'.preg_quote(self::GENERATED_PREFIX, '/').'[a-z0-9]{1,8}$/', $slug) === 1;
    }

    /**
     * 同一 taxonomy 内で slug が既に使われているか（F-4 の重複検査）。
     *
     * **canonical 化した値で比較**する。DB の collation に判定を委ねない。
     * archived term も対象に含める（archive は slug を解放しない）。
     */
    public static function isTaken(
        string $taxonomyId,
        string $slug,
        ?string $exceptTermId = null,
    ): bool {
        $canonical = self::canonicalize($slug);

        $query = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomyId)
            ->where('slug', $canonical);

        if ($exceptTermId !== null) {
            $query->whereKeyNot($exceptTermId);
        }

        return $query->exists();
    }

    /**
     * 入力 slug を検証する。**空 slug は保存しない**（F-1）。
     *
     * @return list<string> 日本語のエラー。空なら妥当
     */
    public static function validate(
        string $slug,
        string $taxonomyId,
        ?string $exceptTermId = null,
    ): array {
        $canonical = self::canonicalize($slug);

        if ($canonical === '') {
            return ['スラッグを入力してください（空のままでは保存できません）'];
        }

        if (mb_strlen($canonical) > self::MAX_LENGTH) {
            return ['スラッグは '.self::MAX_LENGTH.' 文字以内にしてください'];
        }

        if (preg_match(self::PATTERN, $canonical) !== 1) {
            return ['スラッグは半角英小文字・数字・ハイフンだけで入力してください（例: press-release）'];
        }

        if (self::isTaken($taxonomyId, $canonical, $exceptTermId)) {
            return ['このスラッグは同じ分類の中で既に使われています'];
        }

        return [];
    }
}

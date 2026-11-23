<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

/**
 * 項目が何を指しているか（Phase 9.9 / STAGE 22B・SPEC §B.4）。
 *
 * `content` と `content_index` を分けているのが要点である。
 * 「`content_id` が null なら一覧」という設計にすると、読む側が
 * **null の意味を覚えていないと解釈できない**。後から必ず取り違える。
 *
 * `target`（どこで開くか）は別の軸なのでここへ混ぜない。
 */
enum MenuLinkType: string
{
    /** 外部サイト・アンカーなど、CMS の外にある URL */
    case Custom = 'custom';

    /** 組み込みページ（named route）。route が無い生成物では項目ごと出さない */
    case Route = 'route';

    /** ページ 1 枚 */
    case Page = 'page';

    /** 投稿 1 件 */
    case Content = 'content';

    /** 投稿タイプの一覧 */
    case ContentIndex = 'content_index';

    /**
     * 分類の項目ごとの一覧（`/{入口}/{分類のキー}/{項目の綴り}`）。
     *
     * 🔴 **綴りではなく id で持つ**（WP 一周 7 周目 2026-09-03）。
     * 綴りはあとから変えられるので、URL を直接指定で持つと
     * **変えた瞬間にメニューが黙って 404 になる**。
     *
     * 入口（投稿タイプ）は項目だけからは決まらないので、`content_type` と組で持つ。
     */
    case Term = 'term';

    /**
     * その link_type で **値を持ってよい列**。
     *
     * ここに無い列に値が入っていたら、それは形を取り違えている
     * （例: `page` なのに `link_url` がある = URL を二重に持っている）。
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Custom => ['link_url'],
            self::Route => ['link_route'],
            self::Page => ['page_id'],
            self::Content => ['content_type', 'content_id'],
            self::ContentIndex => ['content_type'],
            self::Term => ['content_type', 'taxonomy_term_id'],
        };
    }

    /** 名前を導出できるか。できないなら `title` は必須（SPEC §B.8） */
    public function derivesTitle(): bool
    {
        return match ($this) {
            self::Custom, self::Route => false,
            self::Page, self::Content, self::ContentIndex, self::Term => true,
        };
    }

    /** 参照列すべて（どの link_type でも使い得る列の集合） */
    public const ALL_COLUMNS = [
        'link_url', 'link_route', 'page_id', 'content_type', 'content_id', 'taxonomy_term_id',
    ];
}

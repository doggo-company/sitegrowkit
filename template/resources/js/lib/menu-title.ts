/**
 * メニュー項目のリンク先を替えたときの表示名（UX 一周 3 周目 M7）。
 *
 * 2 周目、2 番目の項目を「組み込みページ／メニュー」から「ページ／パンのご案内」へ替えても、
 * 表示名は `メニュー` のままだった。公開サイトには「メニュー」と出て、押すと「パンのご案内」が開く。
 *
 * ## 決まり
 *
 * - 表示名が**前のリンク先の名前のまま**（= 自分で書き直していない）なら、新しいリンク先の名前にする。
 *   名前を自動で出す種類（ページ等）では、名前を書き込まずに**空にする**（UX 一周 13 周目 R13-4:
 *   書き込むと「自分で書き直した名前」と区別が付かなくなり、次にリンク先を替えても替わらなかった）
 * - **自分で書き直した表示名は残す**（「お店のこと」など、リンク先の名前と違うもの）
 * - 空のままで、リンク先の名前を使う種類（ページ等）なら空のまま（今までどおり名前が自動で出る）
 */
export interface MenuTargetInput {
    link_type: string;
    link_route: string | null;
    page_id: number | null;
    content_type: string | null;
    taxonomy_term_id: string | null;
}

export interface MenuChoices {
    pages: { id: number; label: string }[];
    contentTypes: { id: string; label: string }[];
    routes: { name: string; label: string }[];
    terms: { id: string; label: string }[];
}

/** いまのリンク先の名前。名前の無いもの（URL 直接・記事 1 件）は null */
export function targetLabel(
    item: MenuTargetInput,
    choices: MenuChoices,
): string | null {
    switch (item.link_type) {
        case 'route':
            return (
                choices.routes.find((r) => r.name === item.link_route)?.label ??
                null
            );
        case 'page':
            return (
                choices.pages.find((p) => p.id === item.page_id)?.label ?? null
            );
        case 'content_index':
            return (
                choices.contentTypes.find((t) => t.id === item.content_type)
                    ?.label ?? null
            );
        case 'term':
            return (
                choices.terms.find((t) => t.id === item.taxonomy_term_id)
                    ?.label ?? null
            );
        default:
            return null;
    }
}

export function followTitle(
    title: string | null,
    before: string | null,
    after: string | null,
    derivesTitle: boolean,
): string {
    const current = (title ?? '').trim();

    if (current === '') {
        return derivesTitle ? '' : (after ?? '');
    }

    if (before !== null && current === before.trim()) {
        if (derivesTitle) {
            return '';
        }

        return after ?? current;
    }

    return title ?? '';
}

import { componentType } from "./blade-html.js";
/**
 * 別テンプレートの再取り込みで、**既存のページを壊さない**ための判断（Codex 続き ②）。
 *
 * apply は前回の生成物のうち新しい Plan に無いものを整理（prune）し、Plan に在るものは
 * 書き直す。どちらも、取り込みで作ったページが**使っている**部品・枠に対して行うと
 * 公開面が壊れる — 登録の外れた部品は描かれず、枠は kit 既定へ落ち、書き直せば意匠が別物になる。
 *
 * だから apply は **ファイルを 1 つも書く前に** CMS へ使用状況を聞き、使われているものは
 * - 新しい Plan に無ければ **消さない**（retained）
 * - 新しい Plan に在って中身が変わるなら **書き直さない**（frozen）
 * とする。どちらも記録には前回の hash のまま載せ続ける。確認できなければ何も書かずに止まる。
 */
const COMPONENT_PREFIX = "content/components/imported_";
const COMPONENT_VIEW_PREFIX = "resources/views/components/document/imported-";
const LAYOUT_PREFIX = "resources/views/layouts/preserved/";
/** 前回と今回の生成物から、使用状況を問うべき部品・枠を集める */
export function importedPruneCandidates(previous, currentTargets) {
    const components = new Set();
    const layouts = new Set();
    for (const target of [
        ...(previous?.files.map((f) => f.target) ?? []),
        ...currentTargets,
    ]) {
        const type = componentTypeOf(target);
        if (type !== null)
            components.add(type);
        const view = layoutViewOf(target);
        if (view !== null)
            layouts.add(view);
    }
    return {
        components: [...components].sort(),
        layouts: [...layouts].sort(),
    };
}
/**
 * 使用状況から、守る target を決める。
 *
 * 部品は定義（json）と view の**両方**を守る（片方だけ残ると「一覧には並ぶのに描けない」）。
 */
export function protectedTargets(usage, targets) {
    const protect = new Set();
    const usedBy = new Map();
    for (const target of targets) {
        const type = componentTypeOf(target);
        const view = layoutViewOf(target);
        const slugs = type !== null
            ? (usage.components[type] ?? [])
            : view !== null
                ? (usage.layouts[view] ?? [])
                : [];
        if (slugs.length === 0)
            continue;
        protect.add(target);
        usedBy.set(target, [...slugs].sort());
    }
    return { protect, usedBy };
}
/** `content/components/imported_x.json` / `components/document/imported-x.blade.php` → `imported_x` */
export function componentTypeOf(target) {
    if (target.startsWith(COMPONENT_PREFIX) && target.endsWith(".json")) {
        return componentType(target.slice(COMPONENT_PREFIX.length, -".json".length));
    }
    if (target.startsWith(COMPONENT_VIEW_PREFIX) &&
        target.endsWith(".blade.php")) {
        const stem = target.slice(COMPONENT_VIEW_PREFIX.length, -".blade.php".length);
        // view 名の `-` は type の `_`（`componentViewName()` の逆）
        return componentType(stem);
    }
    return null;
}
/** `resources/views/layouts/preserved/home.blade.php` → `layouts.preserved.home` */
export function layoutViewOf(target) {
    if (!target.startsWith(LAYOUT_PREFIX) || !target.endsWith(".blade.php"))
        return null;
    const slug = target.slice(LAYOUT_PREFIX.length, -".blade.php".length);
    return slug.includes("/") ? null : `layouts.preserved.${slug}`;
}

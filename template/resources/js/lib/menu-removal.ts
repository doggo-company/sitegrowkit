/**
 * メニューの「取り除く」を、**取り消せる**ようにする（WP 一周 6 周目 2026-09-03）。
 *
 * ## なぜ要るか
 *
 * 「取り除く」を押すと行がその場で消える。保存前なので実際にはまだ消えていないが、
 * **画面はそれを言わない**。押した人から見れば「戻せないことをした」ようにしか見えない。
 *
 * さらに親を外すと**子も一緒に、黙って**消える。
 * 入れ子にした項目が視界から丸ごと落ちるので、気づくのは公開したあとになる。
 *
 * ## 確認ダイアログにしない理由
 *
 * ここは**保存するまで何も起きない**場所である。1 行外すたびに確認を挟むのは、
 * 並べ替えのたびに確認するのと同じくらい邪魔になる。
 * 必要なのは「戻せる」ことと、「何が一緒に外れたか」が見えることである。
 */

/** `children` を持つ木の節（メニューの項目・画面の型に依存しない） */
export interface TreeNode {
    children: this[];
}

/** 外したものを、元の場所ごと覚えておく */
export interface RemovedNode<T> {
    item: T;
    /** 上の階層なら null */
    parent: T | null;
    index: number;
    /** 一緒に外れた子の数 */
    childCount: number;
}

/**
 * 木から 1 つ外す。**元の場所を返す**（戻せなければ意味が無い）。
 *
 * 見つからなければ `null`。木に無いものを「外した」ことにしない。
 */
export function removeFromTree<T extends TreeNode>(
    tree: T[],
    item: T,
): RemovedNode<T> | null {
    const top = tree.indexOf(item);

    if (top !== -1) {
        tree.splice(top, 1);

        return {
            item,
            parent: null,
            index: top,
            childCount: item.children.length,
        };
    }

    for (const parent of tree) {
        const at = parent.children.indexOf(item);

        if (at !== -1) {
            parent.children.splice(at, 1);

            return {
                item,
                parent,
                index: at,
                childCount: item.children.length,
            };
        }
    }

    return null;
}

/**
 * 外したものを**同じ場所へ**戻す。
 *
 * 末尾に付け足すのは「戻した」ではない — 並び順もその人が決めたものである。
 */
export function restoreToTree<T extends TreeNode>(
    tree: T[],
    removed: RemovedNode<T>,
): void {
    const list = removed.parent === null ? tree : removed.parent.children;
    const at = Math.min(removed.index, list.length);

    list.splice(at, 0, removed.item);
}

/**
 * 外したことの言い方。
 *
 * 🔴 **子を黙って持っていかない。** 数まで言う。
 */
export function removedSummary(label: string, childCount: number): string {
    const name = label.trim();

    if (name === '') {
        return '項目を外しました';
    }

    return childCount > 0
        ? `「${name}」と、その下の ${childCount} 件を外しました`
        : `「${name}」を外しました`;
}

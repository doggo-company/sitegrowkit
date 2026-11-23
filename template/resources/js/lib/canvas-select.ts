/**
 * クリックされた場所から、選択できる一番近いブロックを探す（R-9 Stage 0 ⑤）。
 *
 * ## なぜ画面から出すか
 *
 * **B-25（変更リクエスト）が、同じ判定を使う。** 顧客がプレビューを指差したときに
 * 「どの node か」を決めるのは、エディタでブロックを選ぶのとまったく同じ問題である。
 *
 * ただし **共有するのは判定だけで、見た目は共有しない** — `CanvasFrame` には
 * 選択枠・階層・操作帯という**制作会社の語彙**が付いてくるので、
 * 顧客が見る面へそのまま出すと B-25 の二面設計に反する（`docs/PHASE9_16_SPEC.md` §3）。
 *
 * ## `instanceof Element` は使えない
 *
 * iframe の中は**別の実行環境**なので、向こうの `Element` はこちらの `Element` とは
 * 別物として扱われ、判定が常に false になる。**形**（`parentElement` を辿れるか）で見る。
 */

/** 祖先を辿るのに必要な最小限。iframe 越しでも成り立つ形だけを要求する */
export interface SelectableNode {
    id?: unknown;
    parentElement?: unknown;
}

export function selectableIdFrom(
    target: unknown,
    selectableIds: readonly string[],
): string | null {
    let node = target as SelectableNode | null;

    while (node !== null && typeof node === 'object' && 'parentElement' in node) {
        const id = node.id;

        if (typeof id === 'string' && id !== '' && selectableIds.includes(id)) {
            return id;
        }

        node = (node.parentElement ?? null) as SelectableNode | null;
    }

    return null;
}

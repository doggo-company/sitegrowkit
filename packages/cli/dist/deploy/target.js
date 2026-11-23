/**
 * **どこへ押すかの承認**（B-41）。
 *
 * `deploy staging` / `deploy rollback` は target が `"staging"` のハードコードで、
 * 本番へ押す手段が無かった。足すときに一緒に守らなければならないのは
 * **「うっかり本番を押す」を作らないこと**である。
 *
 * ## 規則
 *
 * - **既定は staging のまま。** 何も指定しなければ今までと同じ振る舞い
 * - **staging 以外は target 名をもう一度書かせる**（`--confirm-target <name>`）。
 *   `-y` は「訊かれるのを省く」ためのもので、**行き先を選ぶ権限ではない** —
 *   だから `-y` だけで本番へは通さない
 * - 書かれた名前が食い違えば止める（打ち間違い・古いスクリプトをそこで捕まえる）
 *
 * 判断だけを純関数にしてあるので、SSH も対話も無しで試験に固定できる。
 */
export const DEFAULT_TARGET = "staging";
export function approveTarget(target, opts) {
    if (target === DEFAULT_TARGET) {
        return opts.yes === true ? { kind: "approved" } : { kind: "ask" };
    }
    if (opts.confirmTarget === undefined) {
        return {
            kind: "refuse",
            what: `${target} は明示しないと押せません`,
            why: `staging 以外の target には確認が要ります（-y だけでは通しません）`,
            fix: `本当に ${target} へ押すなら --confirm-target ${target} を付けてください`,
        };
    }
    if (opts.confirmTarget !== target) {
        return {
            kind: "refuse",
            what: "--confirm-target が target と一致しません",
            why: `target=${target} / --confirm-target=${opts.confirmTarget}`,
            fix: `押す先を確かめ、一致させてください（--confirm-target ${target}）`,
        };
    }
    return opts.yes === true ? { kind: "approved" } : { kind: "ask" };
}

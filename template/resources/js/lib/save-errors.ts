/**
 * 保存できなかった理由を、**保存ボタンの横で**言うための並べ替え（UX 一周 2026-09-28）。
 *
 * サイト設定でサイト名を空にして保存すると、エラーは**画面の上の入力欄の下**にだけ出ていた。
 * 押した人は画面の下（保存ボタン）を見ているので、何も起きなかったように見える。
 *
 * ここでは「どの項目が・なぜ」を**画面の並び順で**返すだけ。描くのは `SaveErrorSummary.vue`。
 */
export interface SaveErrorItem {
    key: string;
    label: string;
    message: string;
    /** 入力欄の id（`SchemaFieldInput` と同じ作り方: path の `.` を `-` に） */
    inputId: string;
}

export function saveErrorItems(
    errors: Record<string, string | undefined>,
    fields: { key: string; label: string; inputId?: string }[],
): SaveErrorItem[] {
    const order = new Map(fields.map((f, i) => [f.key, i]));
    const labelOf = new Map(fields.map((f) => [f.key, f.label]));
    // 入力欄の id が key と違う画面（`public-slug` など）は、画面が教える
    const idOf = new Map(fields.map((f) => [f.key, f.inputId]));

    return Object.entries(errors)
        .filter(
            (entry): entry is [string, string] =>
                typeof entry[1] === 'string' && entry[1] !== '',
        )
        .map(([key, message]) => {
            const top = key.split('.')[0] ?? key;

            return {
                key,
                // 画面に無い項目（競合など）は名前を付けず、理由だけ出す
                label: labelOf.get(key) ?? labelOf.get(top) ?? '',
                message,
                inputId: idOf.get(key) ?? key.replace(/\./g, '-'),
                rank: order.get(top) ?? Number.MAX_SAFE_INTEGER,
            };
        })
        .sort((a, b) => a.rank - b.rank)
        .map(({ key, label, message, inputId }) => ({
            key,
            label,
            message,
            inputId,
        }));
}

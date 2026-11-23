/**
 * 画像を選んだ・替えたときの代替テキストの扱い（UX 一周 2026-09-28・23 周目 #5）。
 *
 * - 空なら、選んだ画像のメディアに付けた説明を写す
 * - 前の画像の説明を写しただけなら、新しい画像の説明に替える
 * - **画像を替えたら、自分で書いた代替テキストも残さない**（23 周目 #5: 差し替えても前の写真の説明
 *   「晴れ着姿で笑う七五三の女の子」が残り、公開前チェックも拾わなかった）。新しい画像の説明があれば
 *   それに、無ければ空にして、書き直すよう知らせる（空なら公開前チェックが拾う）
 */
export type AltDecision = {
    /** 書き込む代替テキスト（`undefined` なら触らない・`null` なら空にする） */
    alt: string | null | undefined;
    /** 知らせ（`cleared`: 空にした・`replaced`: 新しい画像の説明に替えた） */
    notice: 'cleared' | 'replaced' | null;
};

export function altAfterPick(input: {
    currentAlt: string;
    previousMediaAlt: string | null;
    currentMediaId: number | null;
    pickedMediaId: number | null;
    pickedMediaAlt: string | null;
}): AltDecision {
    if (input.pickedMediaId === null) {
        return { alt: undefined, notice: null };
    }

    const current = input.currentAlt.trim();
    const picked = (input.pickedMediaAlt ?? '').trim();
    const copiedFromPrevious =
        current !== '' && current === (input.previousMediaAlt ?? '').trim();
    const replacing =
        input.currentMediaId !== null &&
        input.currentMediaId !== input.pickedMediaId;

    if (current === '' || copiedFromPrevious) {
        return { alt: picked === '' ? null : picked, notice: null };
    }

    if (!replacing) {
        return { alt: undefined, notice: null };
    }

    return picked === ''
        ? { alt: null, notice: 'cleared' }
        : { alt: picked, notice: 'replaced' };
}

export const ALT_NOTICE: Record<'cleared' | 'replaced', string> = {
    cleared:
        '画像を替えたので、前の写真の代替テキストを消しました。新しい画像の説明を入れてください。',
    replaced:
        '画像を替えたので、代替テキストを新しい画像の説明に替えました。合っているか確かめてください。',
};

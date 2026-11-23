/**
 * 見本の文字（〇〇 の入った書き方の例・ブロックの名前のままの文言）を、押したら選んだ状態にする
 * （UX 一周 4 周目 R4-1・R4-2）。
 *
 * メニュー表・営業時間・ボタンは、書き方の例を**値として**置いている（空だと形が分からない）。
 * そのため、押して打つと `〇〇曜日月曜日` `ボタン予約の問い合わせをする` のようにくっついた。
 * 例の部分（「｜」と改行で区切った 1 マス）を選んだ状態にすれば、打った文字で置き換わる。
 * 店が書いた文字（〇 を含まない・ブロック名と違う）は選ばない。
 */

/** マスの区切り。1 行 1 品・品名と値段のあいだは全角／半角の「｜」 */
const DELIMITER = /[\n｜|]/;

export type Range = [number, number];

/** 位置 `pos` を含むマス（前後の空白は除く） */
export function cellAt(value: string, pos: number): Range {
    let start = Math.max(0, Math.min(pos, value.length));
    let end = start;

    while (start > 0 && !DELIMITER.test(value[start - 1]!)) {
        start -= 1;
    }

    while (end < value.length && !DELIMITER.test(value[end]!)) {
        end += 1;
    }

    while (start < end && /\s/.test(value[start]!)) {
        start += 1;
    }

    while (end > start && /\s/.test(value[end - 1]!)) {
        end -= 1;
    }

    return [start, end];
}

/** 見本の文字か（〇 を含む、またはブロックの名前のまま） */
export function isSample(text: string, componentLabel = ''): boolean {
    const trimmed = text.trim();

    return (
        trimmed !== '' &&
        (trimmed.includes('〇') ||
            (componentLabel !== '' && trimmed === componentLabel))
    );
}

/** 位置 `pos` のマスが見本なら、その範囲。店が書いた文字なら null */
export function sampleCellAt(
    value: string,
    pos: number,
    componentLabel = '',
): Range | null {
    // 欄の中身がまるごとブロックの名前（ボタンの「ボタン」）なら全部
    if (componentLabel !== '' && value.trim() === componentLabel) {
        return [0, value.length];
    }

    // 欄の中身がまるごと見本（メニュー表を置いたまま）なら全部。打てば見本の行が全部置き換わる
    // （5 周目 R4-2: 欄を押して 3 行打つと、見本の行のあいだに混ざった）
    if (allSample(value, componentLabel)) {
        return [0, value.length];
    }

    const [start, end] = cellAt(value, pos);

    return end > start && isSample(value.slice(start, end), componentLabel)
        ? [start, end]
        : null;
}

/** すべてのマス（「｜」と改行で区切ったもの。空は数えない）が見本か */
export function allSample(value: string, componentLabel = ''): boolean {
    const cells = value
        .split(DELIMITER)
        .map((c) => c.trim())
        .filter((c) => c !== '');

    return cells.length > 0 && cells.every((c) => isSample(c, componentLabel));
}

/**
 * プレビューで押した文字（`hint`）がある欄を選ぶ。
 * 同じ文字の欄 → その文字を含む欄（メニュー表の 1 品）→ 押した文字に含まれる欄 → 無ければ undefined。
 */
export function fieldForHint(
    fields: { key: string; text: string }[],
    hint: string,
): string | undefined {
    const wanted = hint.replace(/\s+/g, ' ').trim();

    if (wanted === '') {
        return undefined;
    }

    const flat = (text: string): string => text.replace(/\s+/g, ' ').trim();

    return (
        fields.find((f) => flat(f.text) === wanted)?.key ??
        fields.find((f) => flat(f.text) !== '' && flat(f.text).includes(wanted))
            ?.key ??
        fields.find((f) => flat(f.text) !== '' && wanted.includes(flat(f.text)))
            ?.key
    );
}

/**
 * 置いたばかりのブロックの、文字の欄の仮の文字（UX 一周 3 周目 N3）。
 *
 * 以前は「見出し」「リッチテキスト」を**値として**入れていたので、欄を押して打つと
 * `見出し腰痛・肩こりの施術` のようにくっつき、そのまま保存・公開された。
 * いまは空のまま置き（`PageAdminController::PLACEHOLDER_ONLY`）、何を書く場所かは
 * **打てば消える仮の文字**で出す。値ではないので保存されない。
 *
 * Canvas とプレビューの view（`components/document/{heading,text,richtext}.blade.php`）も
 * 同じ文言を出す。どちらかを変えたら両方変える（`PlaceholderBlocksTest` が見張る）。
 */

/** 値を入れずに置く欄（サーバーの `PLACEHOLDER_ONLY` と同じ並び） */
const PLACEHOLDER_ONLY: Record<string, readonly string[]> = {
    heading: ['text'],
    text: ['text'],
    richtext: ['html'],
};

/**
 * その欄の仮の文字。仮の文字を出す欄でなければ `undefined`。
 *
 * 文言は「〇〇を入力」。〇〇は欄の名前だが、名前が「テキスト」だけのときは
 * ブロックの名前を使う（見出しの欄は「テキスト」なので「見出しを入力」）。
 */
export function propPlaceholder(
    componentType: string,
    componentLabel: string,
    key: string,
    propLabel?: string,
): string | undefined {
    if (!(PLACEHOLDER_ONLY[componentType] ?? []).includes(key)) {
        return undefined;
    }

    const name =
        propLabel !== undefined && propLabel !== '' && propLabel !== 'テキスト'
            ? propLabel
            : componentLabel;

    return `${name}を入力`;
}

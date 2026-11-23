import { FIELD_TYPE_LABELS } from '@/lib/field-type-labels';
import type { OperationConfirmation } from '@/types/operation';

/**
 * 確認ダイアログの文言を、フォームの画面の言葉へ言い換える（R-8A ② 監査 指摘 16）。
 *
 * ## なぜ表示層でやるか
 *
 * 文言はサーバー（operation）が決める契約で、Admin / API / MCP が同じものを読む。
 * **契約は変えない。** 変えるのは、フォームの画面が見せるときの言い方だけである。
 *
 * ## 一番読まれる場所だから
 *
 * 消す操作の確認文は、画面のどこよりも**読まれる**。そこに「カスタムフィールド」
 * 「束」が出ていては、他をいくら言い換えても意味が無い。
 *
 * ## 事実は 1 つも変えない
 *
 * 言い換えるのは**呼び名だけ**。「データは失われます」を柔らかくしたりしない。
 * 項目の**キー**（`field_1` のような機械の名前）は、利用者が付けた**表示名**へ差し替える —
 * 見せる相手にとって、そちらが同じものの正しい呼び名である。
 */
const WORDS: [string, string][] = [
    // 長いものから当てる（「カスタムフィールド」を「フィールド」より先に）
    ['カスタムフィールド', '入力項目'],
    ['フィールドグループ', '入力項目'],
    ['フィールド', '項目'],
    ['束', '入力項目'],
];

function rephrase(text: string): string {
    return WORDS.reduce(
        (out, [from, to]) => out.split(from).join(to),
        rephraseTypeChange(text),
    );
}

/**
 * 種類を替えたときの理由を、店の人の言葉にする（UX 一周 9 周目 R9-2: 「型の変更は値の変換が必要です
 * （text → date）」と出て、自分が何かしないといけないように読めた）。事実は変えない —
 * フォームの画面は届いた送信の中身を書き換えないので、そう言う。
 */
function rephraseTypeChange(text: string): string {
    // 必須にする・必須の項目を足す（UX 一周 11 周目 R11-2: 「不正にします」「埋め戻しが必要です」と出た）。
    // フォームの画面は届いた送信の中身を書き換えないので、事実はこのとおり
    if (/^必須化は、空のままの既存データを不正にします$/.test(text)) {
        return 'この項目を必須にします。これから送る人は入力が必要になります。すでに届いた送信で空のものは、そのまま残ります。';
    }

    if (/^必須(項目|フィールド)の追加は、既存データの埋め戻しが必要です$/.test(text)) {
        return '必須の項目を足します。これから送る人は入力が必要になります。すでに届いた送信では、この項目は空のまま残ります。';
    }

    const match = /^型の変更は値の変換が必要です（([a-z_]+) → ([a-z_]+)）$/.exec(text);

    if (!match) {
        return text;
    }

    const name = (type: string): string => FIELD_TYPE_LABELS[type] ?? type;

    return `入力の種類を「${name(match[1]!)}」から「${name(match[2]!)}」に替えます。すでに届いた送信に入っている値は、書き換えずにそのまま残ります。`;
}

/**
 * キーと表示名を持つもの。**画面の行（`FieldRow`）と保存済みの宣言、両方が当てはまる。**
 *
 * 消した項目は画面の行から消えているので、**保存済み側も見ないと
 * 「知っているはずのキーを知らない扱い」**になる（監査 指摘 20）。
 */
export interface KeyedLabel {
    key: string;
    label: string;
    fields?: KeyedLabel[];
}

/** 項目のキー → 利用者が付けた表示名（入れ子も辿る） */
export function labelsByKey(rows: KeyedLabel[]): Record<string, string> {
    const out: Record<string, string> = {};

    const walk = (list: KeyedLabel[]): void => {
        for (const row of list) {
            if (row.key !== '' && row.label !== '') {
                out[row.key] = row.label;
            }

            walk(row.fields ?? []);
        }
    };

    walk(rows);

    return out;
}

export function customerConfirmation(
    confirmation: OperationConfirmation | null,
    labels: Record<string, string>,
): OperationConfirmation | null {
    if (confirmation === null) {
        return null;
    }

    return {
        ...confirmation,
        summary: rephraseSummary(confirmation.summary),
        impact: confirmation.impact.map((line) => rephraseImpact(line, labels)),
    };
}

/**
 * 見出し。**どの束か**は言わない — この画面で編集できるのは
 * このフォームの項目だけなので、束の名前は利用者にとって余分である。
 */
function rephraseSummary(summary: string): string {
    return /^カスタムフィールド「.*」の定義を変更します$/.test(summary)
        ? 'このフォームの入力項目を変更します'
        : rephrase(summary);
}

/** `key: 理由` の `key` を表示名へ。知らないキーはそのまま（作り話をしない） */
function rephraseImpact(line: string, labels: Record<string, string>): string {
    const separator = line.indexOf(': ');

    if (separator === -1) {
        return rephrase(line);
    }

    const key = line.slice(0, separator);
    const rest = line.slice(separator + 2);

    return `${labels[key] ?? key}: ${rephrase(rest)}`;
}

/**
 * このフォームの項目を変えても、**影響を受けるデータが 1 件も無い**か（UX 一周 3 周目 F2）。
 *
 * 2 周目、受信 0 件のフォームで選択肢を替えただけで、赤い「この内容で保存する」が出て
 * 「何かが消える」と読まれた。項目の束がこのフォームにしか付いておらず、受信が 0 件なら、
 * 外した選択肢が入っているデータは**どこにも無い**。
 *
 * 束が投稿タイプなど**フォーム以外にも付いていれば**、そちらのデータは数えていないので false。
 * 分からないときは false（怖くない側へ倒さない）。
 */
export function noStoredValues(
    submissions: number | null | undefined,
    locations: { kind: string }[] | null | undefined,
): boolean {
    if (submissions !== 0 || !locations || locations.length === 0) {
        return false;
    }

    return locations.every((location) => location.kind === 'form');
}

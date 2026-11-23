import type { FieldGroupField } from '@/types/field-group';

/**
 * 定義フォームが持つ 1 行（Phase 9.5-C / ADR-0017）。
 *
 * `rowId` は **画面だけの識別子**で、送らない。並べ替えても Vue が行を
 * 取り違えないようにするためのもの。サーバーが採番した `id`（ULID）とは別物で、
 * `id` は編集のたびにそのまま返して同一性を保つ。
 */
export interface FieldRow {
    rowId: number;
    /** サーバーが採番した immutable ID。新規行は null */
    id: string | null;
    key: string;
    type: string;
    label: string;
    required: boolean;
    help: string;
    /** 選択肢は 1 行 1 件で編集する（送るときに list へ戻す） */
    choicesText: string;
    /** `repeater` の行数上限。未入力ならサーバーが既定値を入れる */
    max: number | null;
    /** `relation` / `relations` の参照先 */
    target: string | null;
    /** `group` / `repeater` の子（同じ形で再帰する） */
    fields: FieldRow[];
}

let rowId = 0;

export function nextRowId(): number {
    return ++rowId;
}

export function emptyFieldRow(type = 'text'): FieldRow {
    return {
        rowId: nextRowId(),
        id: null,
        key: '',
        type,
        label: '',
        required: false,
        help: '',
        choicesText: '',
        max: null,
        target: null,
        fields: [],
    };
}

/**
 * サーバーが返した定義をフォームの行へ写す。
 *
 * **`id` / `fields` / `max` / `target` を落とさない。** 落とすと編集のたびに
 * 子が消えたり ID が振り直されたりして、binding が切れる。
 */
export function toFieldRow(field: FieldGroupField): FieldRow {
    return {
        rowId: nextRowId(),
        id: field.id,
        key: field.key,
        type: field.type,
        label: field.label,
        required: field.required,
        help: field.help,
        choicesText: field.choices.join('\n'),
        max: field.max,
        target: field.target ?? null,
        fields: (field.fields ?? []).map(toFieldRow),
    };
}

const CHOICE_TYPES = ['select', 'radio', 'checkbox'];
const NESTED_TYPES = ['group', 'repeater'];
const REFERENCE_TYPES = ['relation', 'relations'];

/**
 * 送る形へ戻す。
 *
 * 画面だけの値（`rowId` / 改行区切りの選択肢）は送らない。
 * その型に意味の無いキーも送らない（サーバーは未知キーを拒否する）。
 */
export function toPayload(row: FieldRow): Record<string, unknown> {
    return {
        // 新規行は id を送らない（サーバーが ULID を採番する）
        ...(row.id ? { id: row.id } : {}),
        key: row.key,
        type: row.type,
        label: row.label,
        required: row.required,
        help: row.help,
        choices: CHOICE_TYPES.includes(row.type)
            ? row.choicesText
                  .split('\n')
                  .map((choice) => choice.trim())
                  .filter((choice) => choice !== '')
            : [],
        ...(row.type === 'repeater' && row.max !== null
            ? { max: row.max }
            : {}),
        ...(REFERENCE_TYPES.includes(row.type)
            ? { target: row.target ?? '' }
            : {}),
        ...(NESTED_TYPES.includes(row.type)
            ? { fields: row.fields.map(toPayload) }
            : {}),
    };
}

/** 入れ子を含めた項目の総数（上限の表示に使う。判定はサーバーが正本） */
export function countRows(rows: FieldRow[]): number {
    return rows.reduce((total, row) => total + 1 + countRows(row.fields), 0);
}

/**
 * カスタムフィールド（Field Group）— Phase 9.5-A / ADR-0015。
 *
 * サーバー（`ListFieldGroups`）が返す形をそのまま写す。
 * 画面側で別の形へ組み替えない。
 */

/** 割当先。`kindLabel` が null = この版では扱えない kind（page_template 等） */
export interface FieldGroupLocation {
    kind: string;
    kindLabel: string | null;
    value: string;
    /** 解決できた場合の表示名。導出値なので保存はしていない */
    label: string | null;
}

/** 束の中の 1 項目。`id` は immutable で、rename しても変わらない */
export interface FieldGroupField {
    id: string | null;
    key: string;
    type: string;
    label: string;
    required: boolean;
    help: string;
    choices: string[];
    max: number | null;
    /** `relation` / `relations` の参照先（Phase 9.5-C） */
    target?: string | null;
    /** `group` / `repeater` の子（同じ形で再帰する） */
    fields?: FieldGroupField[];
}

/** サーバーが authoritative に渡す選択肢 */
export interface FieldGroupOptions {
    contentTypes: { value: string; label: string }[];
    fieldTypes: string[];
    /** フォームに割り当てる束では選べない型 */
    formForbiddenTypes: string[];
    /** 子を持つ型（Phase 9.5-C） */
    nestedTypes: string[];
    /** 参照先の指定が要る型 */
    referenceTypes: string[];
    /** 入れ子に置けない型（image） */
    topLevelOnlyTypes: string[];
    /** 参照先に選べる投稿タイプ */
    referenceTargets: { value: string; label: string }[];
    /** 上限。判定はサーバーが正本で、画面は押せるものを正しく見せるために使う */
    limits: {
        depth: number;
        fields: number;
        repeaterDefaultMax: number;
        repeaterMax: number;
    };
}

export interface FieldGroup {
    id: string;
    key: string;
    label: string;
    /** draft | published | archived、または DB に入っていた未知の値 */
    status: string;
    fieldCount: number;
    locations: FieldGroupLocation[];
    /** 定義として読めているか */
    valid: boolean;
    invalidReason: string | null;
    /** 実際に投稿タイプへ項目を足しているか（published かつ有効） */
    active: boolean;
}

/** 編集画面が受け取る形（一覧の内容 + 項目の中身） */
export interface FieldGroupDetail extends FieldGroup {
    fields: FieldGroupField[];
}

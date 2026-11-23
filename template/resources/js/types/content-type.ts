// 投稿タイプ（Content Type）の管理画面向け表現 — Phase 9.4 / STAGE #17A。
// core の ListContentTypeDefinitions / ContentTypeBuilderPresets / ContentTypePlan と対応する。

/**
 * 運用状態（利用者が動かす）と登録の進行状態（システムが動かす）— Phase 9.7 / SPEC §F.1。
 * **1 つの値に混ぜない。**
 */
export type ContentTypeOperationalStatus = 'active' | 'archived';
export type ContentTypeLifecycle =
    'ready' | 'provisioning' | 'deprovisioning' | 'tombstoned';

/** `content_types` から解決した設定（行が無い型にも既定が入る） */
export interface ContentTypeSettings {
    status: ContentTypeOperationalStatus;
    lifecycle: ContentTypeLifecycle;
    menuOrder: number;
    commentsEnabled: boolean;
    revisionsEnabled: boolean;
    publicSlug: string | null;
    registration: Record<string, boolean | string>;
    displayOverrides: Record<string, boolean | string>;
    lockVersion: number;
    /** 設定行が保存されているか（false = 既定のまま） */
    stored: boolean;
    /** 読めなかった値（診断用。黙って隠さない） */
    unrecognized: string[];
}

/** 「この型はいま何をしてよいか」— 判定はサーバーの ContentTypeLifecyclePolicy が持つ */
export interface ContentTypeCapabilities {
    canRead: boolean;
    canWrite: boolean;
    canCreate: boolean;
    showInMenu: boolean;
    isPublic: boolean;
    commentsEnabled: boolean;
}

/** 一覧の 1 行 */
export interface ContentTypeDefinition {
    id: string;
    label: string;
    /** 保存先テーブル。定義が見つからない行（orphan）は null */
    table: string | null;
    /** 構造の管理主体。code = リポジトリのファイルが正（この画面からは変更できない） */
    managedBy: 'code' | 'cms' | null;
    /** 編集画面を出せるか（cms 管理の投稿タイプだけ） */
    editable: boolean;
    fieldCount: number | null;
    taxonomyCount: number | null;
    /** 取得できなかった場合は null（画面を落とさない） */
    entryCount: number | null;
    /** 投稿一覧への導線。管理画面 route が無い型は null */
    adminUrl: string | null;
    /** 利用可能かの診断。運用状態（settings.status）とは別の軸である */
    status:
        | 'ready'
        | 'missing_table'
        | 'no_admin_route'
        | 'unavailable'
        | 'orphan_settings';
    settings: ContentTypeSettings;
    capabilities: ContentTypeCapabilities;
}

/** 設定表そのものを読めたか（SPEC §J.4） */
export type ContentTypeReadiness = 'ready' | 'unmigrated' | 'indeterminate';

/** 編集画面が受け取る 1 型 */
export interface ContentTypeEditable {
    id: string;
    label: string;
    table: string;
    managedBy: 'code' | 'cms';
    settings: ContentTypeSettings;
    /** 型側でコメント機能を使えるか（受け付ける仕組みは後続 Stage） */
    commentsEnabled: boolean;
    /** schema 由来の基本項目。**この画面では編集できない** */
    baseFields: {
        key: string;
        label: string;
        type: string;
        required: boolean;
    }[];
    /** 割り当てられている Field Group（表示と導線だけ） */
    fieldGroups: {
        id: string;
        label: string;
        status: string;
        fieldCount: number;
        editUrl: string;
    }[];
    fieldGroupsUrl: string;
}

export interface ContentTypeEditOptions {
    registrationKeys: string[];
    displayKeys: string[];
    icons: string[];
    slugPattern: string;
    /** URL の構造（P1 ③）。**名前と例はサーバー（enum）が持つ** */
    permalinks: PermalinkOption[];
}

/** URL の構造の選択肢 1 つ */
export interface PermalinkOption {
    value: string;
    label: string;
    example: string;
}

/** 作成フォームで選べるフィールド型 */
export interface BuilderFieldTypeOption {
    value: string;
    label: string;
    needsChoices: boolean;
    supportsMax: boolean;
    defaultMax: number;
    maxLimit: number;
    supportsSearchable: boolean;
    supportsIndex: boolean;
}

/** 作成フォームが編集する 1 フィールド */
export interface BuilderFieldDraft {
    key: string;
    type: string;
    label: string;
    required: boolean;
    max: number | null;
    choices: string[];
    help: string;
    searchable: boolean;
    filterable: boolean;
    sortable: boolean;
}

export interface ContentTypeBuilderOptions {
    fieldTypes: BuilderFieldTypeOption[];
    defaultFields: BuilderFieldDraft[];
    /** 標準提案での一覧列 / 検索対象（サーバーが authoritative） */
    defaultList: string[];
    defaultSearch: string[];
    idPattern: string;
    reservedFieldKeys: string[];
}

/** 確認画面に出す「適用したら何が起きるか」 */
export interface ContentTypePlan {
    id: string;
    label: string;
    table: string;
    modelClass: string;
    adminUrl: string;
    files: { schema: string; model: string; migration: string };
    fields: {
        key: string;
        type: string;
        typeLabel: string;
        label: string;
        required: boolean;
        choices: string[];
        searchable: boolean;
        column: string;
    }[];
    list: string[];
    search: string[];
    notes: string[];
}

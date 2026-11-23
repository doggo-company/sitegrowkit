// 分類（Phase 9.3B / #16）。core の TaxonomyPresenter / ReferenceReport と対応する。

/** TaxonomyPresenter::taxonomy() */
export interface AdminTaxonomy {
    id: string;
    key: string;
    label: string;
    description: string | null;
    hierarchical: boolean;
    /** `code` はコード管理。label / key を read-only 表示する（§R） */
    managedBy: 'code' | 'cms';
    /** 分類そのものの運用状態。**項目の archived とは別の軸**（Phase 9.7 / STAGE 20D） */
    status: 'active' | 'archived';
    lockVersion: number;
    termCount: number;
    activeTermCount: number;
    archivedTermCount: number;
    /** 関連付けられている投稿タイプの内部 ID */
    contentTypes: string[];
}

/** 定義の編集画面が受け取る 1 件 */
export interface TaxonomyDefinition {
    key: string;
    label: string;
    description: string | null;
    hierarchical: boolean;
    status: 'active' | 'archived';
    managedBy: 'code' | 'cms';
    lockVersion: number;
    termCount: number;
    contentTypes: {
        contentType: string;
        multiple: boolean;
        required: boolean;
    }[];
}

export interface TaxonomyDefinitionOptions {
    keyPattern?: string;
    keyRequirement?: string;
    contentTypes: { id: string; label: string }[];
}

/** TaxonomyPresenter::term()。archived でも name / slug / parentId を保つ（AR-6 / HR-6） */
export interface AdminTerm {
    id: string;
    taxonomyId: string;
    taxonomyKey: string;
    name: string;
    slug: string;
    description: string | null;
    parentId: string | null;
    sortOrder: number;
    archived: boolean;
    archivedAt: string | null;
    /** 自動生成 slug（意味を持たない）。rename を案内できる */
    generatedSlug: boolean;
}

/**
 * 管理 tree の 1 ノード。`keptForHierarchy` はフィルタ対象だが
 * **子孫の階層を保つために表示している**行（HR-4）。
 */
export interface TermNode {
    term: AdminTerm;
    children: TermNode[];
    keptForHierarchy: boolean;
    canMoveUp: boolean;
    canMoveDown: boolean;
}

/** Reference::toArray() */
export interface TermReference {
    kind: string;
    label: string;
    count: number;
    samples: string[];
}

/** ReferenceReport::toArray()。`complete` と `deletable` を混同しない（fail closed の区別） */
export interface TermReferenceReport {
    complete: boolean;
    deletable: boolean;
    total_count: number;
    kinds: string[];
    references: TermReference[];
    undetermined: string[];
    summary: string;
}

/** 削除が RESTRICT された理由。confirmation とは**別物**（conflict を確認へ変換しない） */
export interface TermDeleteConflict {
    termId: string;
    message: string | null;
    references: TermReferenceReport | null;
}

/** Content 編集フォームに既に付与されている項目（archived を含む — AS-11 / AS-12） */
export interface AssignedTerm {
    id: string;
    name: string;
    slug: string;
    archived: boolean;
}

/** ContentTaxonomyReader が返す read response の 1 分類ぶん */
export interface ContentTaxonomyBlock {
    label: string;
    multiple: boolean;
    required: boolean;
    terms: AssignedTerm[];
}

/** SchemaResourceController::taxonomyOptions()。**候補は active のみ**（AR-3 / HR-1） */
export interface TaxonomyOptionGroup {
    key: string;
    label: string;
    multiple: boolean;
    required: boolean;
    hierarchical: boolean;
    /** 未同期・未登録のときは false。理由を message に出して選べない状態にする */
    available: boolean;
    message: string | null;
    terms: AdminTerm[];
}

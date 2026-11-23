// Content Schema の管理画面向け表現（core の ContentSchema::toAdminSpec() と対応）
import type { ContentTaxonomyBlock } from '@/types/taxonomy';

/**
 * ContentSchema が宣言する分類（core の TaxonomyDeclaration）。
 * **fields とは別ブロック**。key / multiple / required だけで、term 一覧は含まない（§J.1）。
 */
export interface SchemaTaxonomy {
    key: string;
    multiple: boolean;
    required: boolean;
}

/**
 * 管理画面フォームが入力を描ける field type。
 *
 * **core の `Schema\Field::TYPES` の部分集合ではなく、実際に
 * `SchemaFieldInput.vue` が描ける型の一覧**である。Content Type Builder
 * （Phase 9.4）が選ばせる型は必ずここに含まれていなければならない。
 */
export type SchemaFieldType =
    | 'text'
    | 'textarea'
    | 'richtext'
    | 'email'
    | 'url'
    | 'tel'
    | 'number'
    | 'image'
    | 'select'
    | 'radio'
    | 'checkbox'
    | 'date'
    | 'datetime'
    | 'slug'
    | 'status'
    | 'boolean'
    | 'media'
    // Phase 9.5-D（ADR-0018）。Media が種別を持ち、一般ファイルを安全に扱えるようになった
    | 'file'
    | 'group'
    | 'repeater'
    | 'relation'
    | 'relations'
    // R-5。ページを 1 つ指す（値は id。候補は schema ではなく画面が受け取る）
    | 'page'
    | 'images';

export interface SchemaField {
    key: string;
    type: SchemaFieldType;
    label: string;
    required: boolean;
    max: number | null;
    choices: string[];
    from: string | null;
    group: string | null;
    /** 入力欄の下に出す補足説明（core の Field::help。空なら出さない） */
    help?: string;
    /** 値が無いときの既定（core の Field::default。無ければ出ない） */
    default?: unknown;
    /** `group` / `repeater` の子（Phase 9.5-C） */
    fields?: SchemaField[];
    /** `relation` / `relations` の参照先 Content Type */
    target?: string | null;
}

export interface AdminSchema {
    id: string;
    label: string;
    kind: 'collection' | 'settings' | 'submissions';
    fields: SchemaField[];
    /** 宣言が無い schema でも空配列が返る（決定的） */
    taxonomies: SchemaTaxonomy[];
    list: string[];
    search: string[];
    /** 一覧で編集画面へのリンクにする列（core の titleKey()。null なら列リンクなし） */
    titleKey: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export type ContentItem = Record<string, unknown> & {
    id: number;
    /** 編集画面 URL（core が付与） */
    editUrl?: string;
    /** 公開ページ URL。開けない状態（下書き等）では null */
    publicUrl?: string | null;
    /**
     * 付与済みの分類（core の ContentTaxonomyReader）。**通常 field とは別キー**。
     * archived な assignment も消さずに返る（AS-11）。
     */
    taxonomies?: Record<string, ContentTaxonomyBlock>;
};

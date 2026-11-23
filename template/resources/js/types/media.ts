// Media Entity の管理画面向け表現（Media::toPickerArray() と対応）

/** メディアの種別（Phase 9.5-D / ADR-0018） */
export type MediaKind = 'image' | 'file';

/** 一般ファイルの公開範囲。画像は常に public */
export type MediaVisibility = 'public' | 'private';

/** 原本から作った派生物。**DB に行があるものだけ**が入る */
export interface MediaVariantSource {
    format: string;
    mime: string;
    url: string;
}

export interface MediaItem {
    id: number;
    name: string;
    alt: string | null;
    /**
     * 画像の直 URL。**一般ファイルは null**。
     *
     * 一般ファイルは private disk に在り、直リンクで到達させない。
     * 開く手段は `download_url` だけである。
     */
    url: string | null;
    /** 一般ファイルには寸法が無い */
    width: number | null;
    height: number | null;
    size: number;
    mime: string;
    created_at: string | null;
    kind: MediaKind;
    visibility: MediaVisibility;
    /** 利用者がアップロードしたときの名前（配信時の保存名の素） */
    original_name: string | null;
    /** 一般ファイルの配信 URL。画像は null */
    download_url: string | null;
    variants: MediaVariantSource[];
}

/** 拡張子バッジの文字（`資料.pdf` → `PDF`）。分からなければ空 */
export function extensionLabel(item: MediaItem): string {
    const name = item.original_name ?? item.name;
    const dot = name.lastIndexOf('.');

    return dot > 0 ? name.slice(dot + 1).toUpperCase() : '';
}

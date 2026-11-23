// ページ（Phase 9.3B / #19）。core の GetPage::present() / ListPages と対応する。

export type PageStatus = 'draft' | 'published' | 'trashed';

/**
 * 画面が出す公開状態（`Page::publishState()`）。
 *
 * `scheduled` は **status が published のまま日時が未来**。日時が来ると行は変わらず
 * `published` になる（cron は無い）。状態の表示は `status` ではなくこちらを見る。
 */
export type PagePublishState = PageStatus | 'scheduled';

/** ListPages が返す一覧の 1 行 */
export interface PageListItem {
    id: number;
    slug: string;
    title: string;
    status: PageStatus;
    /** 予約中は「公開予約」（サーバーが決める） */
    statusLabel: string;
    publishState: PagePublishState;
    /** `document` は本文をブロックで組む。`blade` はコードで管理され編集できない */
    managedBy: 'document' | 'blade';
    publishedVersionId: number | null;
    publishedAt: string | null;
    /** サイトの timezone で `YYYY-MM-DD HH:mm` */
    publishedAtLocal: string | null;
    updatedAt: string | null;
}

/**
 * 構成サマリの 1 行（`App\Support\PageEditorSummary` と対応）。
 *
 * 本文の全文は入らない。見出しの骨格（種類・階層・見出し文言）だけ。
 */
export interface PageOutlineBlock {
    /** 入れ子の深さ。0 が最上位 */
    depth: number;
    type: string;
    /** 日本語の表示名（セクション / 見出し） */
    label: string;
    /** 見出しの文言。見出し以外・未入力は null */
    text: string | null;
    /** 見出しレベル（h1〜h6 の数値）。見出し以外は null */
    level: number | null;
}

/**
 * 編集画面の read-only サマリ。
 *
 * `publishable` は **サーバー側の `DocumentValidator::validateForPublish()`** の結論。
 * 画面が独自に判定しない（無効化は補助であって検証の実体ではない）。
 */
export interface PageEditorSummary {
    blocks: PageOutlineBlock[];
    /**
     * document に何か入っているか。
     *
     * `blocks` は骨格（セクション / 見出し）だけなので、**件数 0 は空を意味しない**。
     * 本文だけのページを「空」と呼ばないために、空判定はこちらを見る。
     */
    hasBlocks: boolean;
    publishable: boolean;
    /** 公開できない理由。公開できるときは null */
    reason: string | null;
    /** 何も入っていないときに出す文言 */
    emptyMessage: string;
    /** 中身はあるが骨格として出せるものが無いときの文言 */
    outlineEmptyMessage: string;
    /** 骨組み（section / heading）だけを出していることの断り */
    outlineNote: string;
    /** 骨組みに出さない種類ごとの数（テキスト 2・営業時間 1 …）。中身は渡らない */
    others: { type: string; label: string; count: number }[];
    /** AI エージェントへ貼るための依頼文（送信先は無い） */
    aiPrompt: string;
}

/** GetPage::present() */
export interface PageDetail {
    id: number;
    slug: string;
    title: string;
    status: PageStatus;
    managedBy: 'document' | 'blade';
    seo: { description?: string | null; noindex?: boolean | null } | null;
    draftVersionId: number | null;
    publishedVersionId: number | null;
    publishedAt: string | null;
    publishState: PagePublishState;
    publishStateLabel: string;
    scheduled: boolean;
    /** サイトの timezone で `YYYY-MM-DD HH:mm` */
    publishedAtLocal: string | null;
    /** `publishedAtLocal` の timezone 名（例 `Asia/Tokyo`） */
    timezone: string;
}

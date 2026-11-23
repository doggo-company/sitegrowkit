/**
 * Template Inspect Manifest v2 — 機械可読な HTML テンプレート調査結果。
 * AI エージェントは生のテンプレートファイル群を読む前に必ずこれを読む（Token Efficiency）。
 * 形式変更は docs/adr/0010 の改訂として扱うこと（v1 は ADR-0004）。
 */

export interface Manifest {
  version: 2;
  source: string;
  generated_by: string;
  fingerprint: Fingerprint;
  source_stats: SourceStats;
  /**
   * home (/) として扱うページ。優先順: --entry 明示指定 > 単一ファイル inspect の対象 >
   * 実質空でない index.html。どれも無ければ null（Plan が REVIEW にする）
   */
  entry: string | null;
  pages: PageEntry[];
  assets: AssetIndex;
  /**
   * HTML 属性（href/src）と CSS url() から実際に参照されている local asset（ソート済み）。
   * public へのコピー候補はこの集合に限る（Reference-Based Asset Allowlist — 未参照は copy しない）
   */
  referenced_assets: string[];
  /**
   * CSS（外部ファイル + inline <style>）の url() から参照される local asset（ソート済み）。
   * これらは Media 化されても background 用に static copy が必要（デュアル参照）
   */
  css_referenced: string[];
  external: ExternalDep[];
  shared_candidates: SharedCandidate[];
  common_structures: CommonStructure[];
  interactive_regions: InteractiveRegion[];
  forms: FormEntry[];
  repeats: RepeatEntry[];
  css_analysis: CssAnalysis;
  js_analysis: JsAnalysis;
  image_classification: ImageClassification;
  warnings: Warning[];
}

/** Source 変更検出用（古い Plan の Apply を拒否する） */
export interface Fingerprint {
  algorithm: "sha256";
  /** 全ファイルの (path:hash) を結合したハッシュ */
  total: string;
  files: FileFingerprint[];
}

export interface FileFingerprint {
  path: string;
  sha256: string;
  bytes: number;
}

/** Token Efficiency 計測用の原テンプレート統計 */
export interface SourceStats {
  files: number;
  text_lines: number;
  bytes: number;
  by_type: Record<string, { files: number; lines: number; bytes: number }>;
}

export interface PageEntry {
  path: string;
  title: string | null;
  head: HeadMetadata;
  h1: string | null;
  /** main 直下の section（構造の俯瞰用） */
  sections: string[];
  /** id 属性の一覧（anchor リンク変換の検証用） */
  ids: string[];
  css: string[];
  js: string[];
  images: string[];
  links_internal: string[];
  /** fragment 付き内部リンク（例: service.html#plans）。anchor 保持の検証用 */
  links_anchor: string[];
  links_external: string[];
  /** src の無い inline <script> の個数と合計バイト */
  inline_scripts: { count: number; bytes: number };
}

/** SEO / メタデータ保持（spec §27） */
export interface HeadMetadata {
  lang: string | null;
  description: string | null;
  canonical: string | null;
  robots: string | null;
  og: Record<string, string>;
}

export interface AssetIndex {
  css: string[];
  js: string[];
  images: string[];
  fonts: string[];
  other: string[];
}

export type ExternalKind =
  "script" | "style" | "font" | "img" | "iframe" | "other";

export type ExternalCategory =
  | "google-fonts"
  | "adobe-fonts"
  | "cdn-script"
  | "analytics"
  | "maps"
  | "turnstile"
  | "embed"
  | "unknown";

export interface ExternalDep {
  url: string;
  kind: ExternalKind;
  category: ExternalCategory;
  /** URL から推定したライブラリ名（分かる場合のみ） */
  library: string | null;
  pages: string[];
}

/** 複数ページで同一内容の要素 = 共通化（partial 化）候補 */
export interface SharedCandidate {
  kind: string; // 'header' | 'footer' | 'nav' | 'section' など
  selector: string;
  pages: string[];
  hash: string; // 正規化 outerHTML の sha256 先頭 12 桁
  bytes: number;
}

export type Confidence = "high" | "medium" | "low";

/** header / footer / breadcrumb / CTA など、意味のある共通・繰り返し構造（spec §10） */
export interface CommonStructure {
  kind:
    | "header"
    | "footer"
    | "navigation"
    | "breadcrumb"
    | "cta"
    | "section_heading"
    | "card_grid"
    | "form";
  selector: string;
  occurrences: { page: string; count: number }[];
  confidence: Confidence;
  reason: string;
}

/** JS がバインドしている DOM 領域（Vue 化ではなく既存 JS 保持の判断材料） */
export interface InteractiveRegion {
  selector: string;
  source: string; // JS ファイル
  events: string[];
  pages: string[];
}

export interface FormEntry {
  page: string;
  selector: string;
  action: string | null;
  method: string;
  external_action: boolean;
  has_file_upload: boolean;
  /**
   * 送信ボタンの文字（原文のまま）。ボタンが無い / 文字が無ければ `null`。
   *
   * 他の項目から導けない（HTML に書かれた文字そのもの）ので持つ。
   * plan は manifest だけを見て判断するので、ここに無いと
   * 「押せるのに『準備中』のまま」を**言うことすらできない**（R-16 ③）。
   */
  submit_label: string | null;
  fields: FormField[];
}

export interface FormField {
  name: string;
  type: string;
  required: boolean;
  label: string | null;
  options?: string[];
}

/** 同一シグネチャの子要素が繰り返される構造 = コレクション化候補 */
export interface RepeatEntry {
  page: string;
  container: string;
  item: string;
  count: number;
  /** 各アイテムから決定論的に抽出した内容（Plan / seed 生成用。原 HTML の再読を不要にする） */
  items: RepeatItem[];
}

export interface RepeatItem {
  heading: string | null;
  image: string | null;
  link: string | null;
  link_text: string | null;
  time: string | null;
  meta: string | null;
  text: string | null;
}

export interface CssAnalysis {
  files: CssFileAnalysis[];
}

export interface CssFileAnalysis {
  path: string;
  lines: number;
  bytes: number;
  custom_properties: string[];
  media_queries: string[];
  font_faces: { family: string | null; src: string[] }[];
  imports: string[];
  /** url() 参照（source 相対へ解決済み。missing は verify で WARN） */
  url_refs: { ref: string; resolved: string | null; exists: boolean }[];
}

export interface JsAnalysis {
  files: JsFileAnalysis[];
}

export interface JsFileAnalysis {
  path: string;
  lines: number;
  bytes: number;
  dom_selectors: string[];
  event_listeners: string[];
  libraries: string[];
  uses_modules: boolean;
}

/** Static Design Asset と CMS Media 候補の分類（spec §15） */
export interface ImageClassification {
  static_assets: ClassifiedImage[];
  media_candidates: ClassifiedImage[];
}

export interface ClassifiedImage {
  path: string;
  reason: string;
  confidence: Confidence;
  /** media candidate の場合: どの繰り返し構造で使われているか */
  repeat: string | null;
}

export type WarningKind =
  | "secret"
  | "unsupported"
  | "build-tool"
  | "external-form-action"
  | "missing-asset"
  | "empty-page"
  | "security";

export interface Warning {
  kind: WarningKind;
  file: string;
  message: string;
}

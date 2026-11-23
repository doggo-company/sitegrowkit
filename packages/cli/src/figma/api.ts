/**
 * Figma API 境界（ADR-0011 §2、SPEC §85）。
 * CLI ロジックはこの interface 越しにのみ Figma へ触れる。
 * 本番は HttpFigmaApi、テスト / offline は FixtureFigmaApi が実装する。
 * Phase 4 の RemoteExecutor / XserverApi と同型の Provider Adapter。
 */

/** API 失敗の分類。診断（{what,why,fix}）へ変換できる粒度を保つ */
export type FigmaApiFailureKind =
  | "auth" // 401: token 無効・失効
  | "forbidden" // 403: scope 不足 / plan 制限（Variables 等）
  | "not_found" // 404: file key 誤り・アクセス権なし
  | "rate_limit" // 429: retry 上限まで再試行した後も回復せず
  | "network" // 接続不可・タイムアウト
  | "server"; // 5xx ほか

export interface FigmaApiFailure {
  ok: false;
  kind: FigmaApiFailureKind;
  status: number;
  /** token を含めてはならない（redactFigmaSecrets 済みであること） */
  message: string;
  retryAfterSeconds?: number;
}

export type FigmaApiResult<T> = { ok: true; data: T } | FigmaApiFailure;

/** 記録される API call（Rate Limit First の実測・benchmark 用） */
export interface FigmaApiCall {
  endpoint:
    | "file_meta"
    | "file"
    | "file_nodes"
    | "image_renders"
    | "image_fills"
    | "variables"
    | "download";
  detail: string;
}

// ---- Raw API レスポンス型（必要 subset のみ。2026-08 公式 Docs 準拠） ----

export interface FigmaColor {
  r: number;
  g: number;
  b: number;
  a: number;
}

export interface FigmaPaint {
  type: string; // SOLID / GRADIENT_* / IMAGE
  visible?: boolean;
  opacity?: number;
  color?: FigmaColor;
  imageRef?: string;
  scaleMode?: string;
  /** scaleMode=CROP のときの正規化変換（2x3 行列）。crop 意図の保持に使う */
  imageTransform?: number[][];
}

export interface FigmaTypeStyle {
  fontFamily?: string;
  fontPostScriptName?: string | null;
  fontWeight?: number;
  fontSize?: number;
  lineHeightPx?: number;
  letterSpacing?: number;
  textAlignHorizontal?: string;
  textCase?: string;
  italic?: boolean;
  /** TEXT 全体に設定された hyperlink（destination が実在する場合のみ <a> 化に使う） */
  hyperlink?: { type?: string; url?: string } | null;
}

export interface FigmaEffect {
  type: string; // DROP_SHADOW / INNER_SHADOW / LAYER_BLUR ...
  visible?: boolean;
  radius?: number;
  color?: FigmaColor;
  offset?: { x: number; y: number };
  spread?: number;
}

export interface FigmaNode {
  id: string;
  name: string;
  type: string;
  visible?: boolean;
  children?: FigmaNode[];
  absoluteBoundingBox?: {
    x: number;
    y: number;
    width: number;
    height: number;
  } | null;
  // Auto Layout（layoutMode が無い FRAME は NONE 扱い）
  layoutMode?: "NONE" | "HORIZONTAL" | "VERTICAL" | "GRID";
  itemSpacing?: number;
  paddingLeft?: number;
  paddingRight?: number;
  paddingTop?: number;
  paddingBottom?: number;
  primaryAxisAlignItems?: string;
  counterAxisAlignItems?: string;
  layoutWrap?: string;
  fills?: FigmaPaint[];
  strokes?: FigmaPaint[];
  strokeWeight?: number;
  cornerRadius?: number;
  rectangleCornerRadii?: number[];
  effects?: FigmaEffect[];
  characters?: string;
  style?: FigmaTypeStyle;
  /** node が参照する published style（fill / text / effect / grid → style id） */
  styles?: Record<string, string>;
  componentId?: string; // INSTANCE のみ
  componentProperties?: Record<
    string,
    { value: string | boolean; type: string }
  >;
  boundVariables?: Record<string, unknown>;
  isMask?: boolean;
}

export interface FigmaComponentMeta {
  key: string;
  name: string;
  description?: string;
  componentSetId?: string;
}

export interface FigmaStyleMeta {
  key: string;
  name: string;
  styleType: string; // FILL / TEXT / EFFECT / GRID
  description?: string;
}

export interface FigmaFileMeta {
  name: string;
  version: string;
  editorType: string;
  last_touched_at?: string;
  folder_name?: string | null;
  role?: string;
  link_access?: string;
}

export interface FigmaFileResponse {
  document: FigmaNode;
  components: Record<string, FigmaComponentMeta>;
  componentSets: Record<string, FigmaComponentMeta>;
  styles: Record<string, FigmaStyleMeta>;
  name: string;
  version: string;
  lastModified?: string;
  editorType?: string;
}

export interface FigmaNodesResponse {
  name: string;
  version: string;
  editorType?: string;
  nodes: Record<
    string,
    {
      document: FigmaNode;
      components?: Record<string, FigmaComponentMeta>;
      componentSets?: Record<string, FigmaComponentMeta>;
      styles?: Record<string, FigmaStyleMeta>;
    } | null
  >;
}

export interface FigmaImagesResponse {
  err?: string | null;
  images: Record<string, string | null>;
}

export interface FigmaImageFillsResponse {
  meta: { images: Record<string, string> };
}

export interface FigmaVariable {
  id: string;
  name: string;
  resolvedType: string; // COLOR / FLOAT / STRING / BOOLEAN
  valuesByMode: Record<string, unknown>;
  variableCollectionId: string;
}

export interface FigmaVariableCollection {
  id: string;
  name: string;
  defaultModeId: string;
  modes: { modeId: string; name: string }[];
}

export interface FigmaVariablesResponse {
  meta: {
    variables: Record<string, FigmaVariable>;
    variableCollections: Record<string, FigmaVariableCollection>;
  };
}

// ---- 境界 interface ----

export interface FigmaApi {
  /** GET /v1/files/:key/meta（Tier 3, file_metadata:read）— Metadata First の起点 */
  getFileMeta(fileKey: string): Promise<FigmaApiResult<FigmaFileMeta>>;
  /** GET /v1/files/:key（Tier 1, file_content:read）— entry_nodes 未指定時のみ */
  getFile(
    fileKey: string,
    opts?: { depth?: number },
  ): Promise<FigmaApiResult<FigmaFileResponse>>;
  /** GET /v1/files/:key/nodes?ids=（Tier 1）— Node Targeting の主経路。ids は 1 call へまとめる */
  getFileNodes(
    fileKey: string,
    ids: string[],
    opts?: { version?: string },
  ): Promise<FigmaApiResult<FigmaNodesResponse>>;
  /** GET /v1/images/:key（Tier 1）— Frame render（Visual Baseline 用） */
  getImageRenders(
    fileKey: string,
    ids: string[],
    opts: { format: "png" | "svg"; scale?: number },
  ): Promise<FigmaApiResult<FigmaImagesResponse>>;
  /** GET /v1/files/:key/images（Tier 2）— image fill の download URL */
  getImageFills(
    fileKey: string,
  ): Promise<FigmaApiResult<FigmaImageFillsResponse>>;
  /** GET /v1/files/:key/variables/local（Tier 2, file_variables:read, Enterprise 限定） */
  getLocalVariables(
    fileKey: string,
  ): Promise<FigmaApiResult<FigmaVariablesResponse>>;
  /** render / image fill URL からのダウンロード（API rate limit 対象外の CDN URL） */
  download(url: string): Promise<FigmaApiResult<Buffer>>;
  /** 表示用ラベル。秘密情報を含めない */
  label(): string;
  /** 実行した API call の記録（benchmark: API calls / cache hits の実測に使う） */
  readonly calls: FigmaApiCall[];
}

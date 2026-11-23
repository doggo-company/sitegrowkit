/**
 * Figma Implementation Plan（SPEC §56–59）。
 * Design Manifest のみから決定論的に生成。各判断に reason + confidence を必ず付ける。
 * AI が不確実性を隠さない（CONFIDENT / REVIEW / UNKNOWN）。
 */
import type {
  AssetClassification,
  CmsClassification,
  FigmaConfidence,
  FigmaWarning,
  TokenSource,
} from "./manifest.js";

export interface FigmaPlan {
  version: 1;
  generated_by: "agentcms figma plan";
  /** Design Manifest の fingerprint（stale Plan 拒否: SPEC §23/§64） */
  manifest_fingerprint: string;
  source: { file_key: string; figma_version: string };
  screens: ScreenPlan[];
  components: ComponentPlan[];
  tokens: TokenPlan[];
  cms: CmsPlan[];
  assets: AssetPlan[];
  fonts: FontPlan[];
  schema_overrides: SchemaOverridePlan[];
  uncertainties: Uncertainty[];
  warnings: FigmaWarning[];
}

export interface ScreenPlan {
  screen_id: string;
  route: string;
  target: string; // Blade view path
  breakpoints: { breakpoint: string; node_id: string; width: number }[];
  /** Mobile design が無い場合 "MOBILE DESIGN: REVIEW"（SPEC §26） */
  responsive_note: string;
  confidence: FigmaConfidence;
  reason: string;
}

export type ComponentAction = "reuse-existing" | "generate" | "layout" | "skip";

export interface ComponentPlan {
  component_id: string;
  action: ComponentAction;
  /** reuse-existing の場合: 既存ファイル */
  existing_target: string | null;
  /** generate / layout の場合: 生成先 */
  target: string | null;
  implementation: "blade"; // Blade First（SPEC §61。Vue は必要になった案件でのみ）
  classification: string;
  confidence: FigmaConfidence;
  reason: string;
}

export type TokenStatus = "MATCH" | "CONFLICT" | "NEW_CANDIDATE";

export interface TokenPlan {
  name: string;
  value: string;
  source: TokenSource;
  status: TokenStatus;
  existing_name: string | null;
  existing_value: string | null;
  confidence: FigmaConfidence;
  reason: string;
}

export type CmsAction = "existing-module" | "static" | "review";

export interface CmsPlan {
  candidate_id: string;
  action: CmsAction;
  module: string | null;
  classification: CmsClassification;
  seed_items: number;
  confidence: FigmaConfidence;
  reason: string;
}

export type AssetAction = "static" | "media-seed" | "css-only" | "review";

export interface AssetPlan {
  asset_id: string;
  classification: AssetClassification;
  action: AssetAction;
  target: string | null;
  confidence: FigmaConfidence;
  reason: string;
}

export interface FontPlan {
  family: string;
  weights: number[];
  availability: "PROJECT" | "MISSING";
  action: "use" | "review";
  confidence: FigmaConfidence;
  reason: string;
}

export interface SchemaOverridePlan {
  schema: string;
  field: string;
  patch: Record<string, unknown>;
  reason: string;
}

export interface Uncertainty {
  area: string;
  message: string;
}

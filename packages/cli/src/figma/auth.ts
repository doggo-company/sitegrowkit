/**
 * Figma Personal Access Token の解決と redaction（ADR-0011 §3）。
 * Token は環境変数 FIGMA_ACCESS_TOKEN のみ。CLI option / config / manifest へは置かない
 * （--token は shell history に残るため提供しない。SPEC §8）。
 */

/** 環境変数から token を解決。未設定なら null（呼び出し側が {what,why,fix} で案内する） */
export function resolveFigmaToken(
  env: NodeJS.ProcessEnv = process.env,
): string | null {
  const token = env.FIGMA_ACCESS_TOKEN?.trim();
  return token ? token : null;
}

/**
 * エラーメッセージ・ログへ万一 token が混入した場合の保険。
 * Figma PAT は figd_ プレフィックス（2026-08 時点の公式仕様）。
 */
export function redactFigmaSecrets(text: string): string {
  return text.replace(/figd_[A-Za-z0-9_-]{8,}/g, "figd_***");
}

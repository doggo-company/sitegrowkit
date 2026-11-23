/**
 * Secret / placeholder スキャンのパターン定義（単一情報源）。
 * verify の secret scan（secret-scan.ts）と release packaging
 * （scripts/package-release.mjs）の両方がここを参照する。
 * 注: 作者・クライアント識別子のリストは配布物に含めないため、
 * packaging スクリプト側にのみ存在する（この module は dist として配布される）。
 */

export const FORBIDDEN_FILES: RegExp[] = [
  /^\.env$/,
  /^\.env\.(?!example$)/,
  /\.pem$/,
  /\.key$/,
  /^auth\.json$/,
];

export const CONTENT_PATTERNS: { name: string; re: RegExp }[] = [
  { name: "AWS Access Key", re: /AKIA[0-9A-Z]{16}/ },
  {
    name: "Private key block",
    re: /-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/,
  },
  { name: "Stripe secret key", re: /sk_live_[0-9a-zA-Z]{16,}/ },
  { name: "GitHub token", re: /gh[pousr]_[0-9a-zA-Z]{30,}/ },
  { name: "Anthropic API key", re: /sk-ant-[0-9a-zA-Z-]{20,}/ },
  // Figma PAT（Phase 6 / ADR-0011）。redaction 済みの figd_*** は除外
  { name: "Figma access token", re: /figd_(?!\*\*\*)[0-9a-zA-Z_-]{8,}/ },
  {
    name: "Generic secret assignment",
    re: /(SECRET|PASSWORD|API_KEY)\s*=\s*['"][^'"\s]{16,}['"]/i,
  },
];

// テストキー等、公開して安全と Cloudflare / Laravel が明示しているもの
export const ALLOWLIST: RegExp[] = [
  /1x0000000000000000000000000000000AA/, // Turnstile official test secret
  /1x00000000000000000000AA/, // Turnstile official test sitekey
  /base64:[A-Za-z0-9+/=]+/, // .env.example のダミー APP_KEY は含めない前提
  /figd_(test|UNSAFE|LEAKED|SHOULD_NOT|abc123456789)/, // Figma テスト用ダミー token
];

/**
 * 未解決 placeholder の検出（Phase 7 RC2 Blocker 1 の一般化）。
 * ツールが書き込んだ「値を決めろ」系の文言や未置換トークンが
 * 配布物に残っていたら packaging を FAIL させる。
 */
export const PLACEHOLDER_PATTERNS: { name: string; re: RegExp }[] = [
  { name: "未解決の boolean placeholder", re: /set this to (true|false)/i },
  { name: "REPLACE_ME placeholder", re: /\bREPLACE_?ME\b/ },
  { name: "CHANGEME placeholder", re: /\bCHANGE_?ME\b/ },
  { name: "未解決トークン", re: /__(PLACEHOLDER|FIXME|TBD)__/i },
  {
    name: "未解決 mustache placeholder",
    re: /\{\{\s*(TODO|PLACEHOLDER|FIXME|REPLACE)[^}]*\}\}/i,
  },
];

/** テキストに未解決 placeholder が含まれるか（違反名の配列を返す） */
export function findPlaceholders(content: string): string[] {
  return PLACEHOLDER_PATTERNS.filter((p) => p.re.test(content)).map(
    (p) => p.name,
  );
}

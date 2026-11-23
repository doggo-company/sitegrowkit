import { randomBytes } from "node:crypto";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  readFileSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { join } from "node:path";

/**
 * 管理者認証情報の生成（Phase 7 Decision 4）。
 * 共有 default password は存在してはならない。project ごとに
 * cryptographically secure random password を生成し、
 * .env（seed が参照）と .agentcms/local/admin-credentials.json（人間の参照先、
 * gitignored・POSIX では 0600）へ保存する。
 * 値を CLI 出力・ログ・ベンチマークへ書き出すことは禁止。
 */

// 紛らわしい文字（0/O/1/l/I）を除いた 57 文字。24 文字で約 140 bit
const ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";

export function generatePassword(length = 24): string {
  const limit = Math.floor(256 / ALPHABET.length) * ALPHABET.length; // 剰余バイアス除去
  let out = "";
  while (out.length < length) {
    for (const b of randomBytes(length * 2)) {
      if (b < limit) out += ALPHABET[b % ALPHABET.length]!;
      if (out.length >= length) break;
    }
  }
  return out;
}

/** .env 形式文字列から KEY の値を得る（クォート除去） */
export function readEnvValue(content: string, key: string): string | null {
  const m = new RegExp(`^${key}=(.*)$`, "m").exec(content);
  if (!m) return null;
  return (m[1] ?? "").trim().replace(/^"(.*)"$/, "$1");
}

/** .env 形式文字列へ KEY=VALUE を反映する（既存行は置換、無ければ末尾に追記） */
export function setEnvValue(
  content: string,
  key: string,
  value: string,
): string {
  const re = new RegExp(`^${key}=.*$`, "m");
  if (re.test(content)) return content.replace(re, `${key}=${value}`);
  return `${content.replace(/\n?$/, "\n")}${key}=${value}\n`;
}

export const CREDENTIALS_REL = ".agentcms/local/admin-credentials.json";

export interface EnsureCredentialResult {
  created: boolean;
  file: string;
  /** POSIX で 0600 が実効していない（例: WSL2 の /mnt/c 配下は 777 になる） */
  insecurePermissions: boolean;
}

/**
 * project の管理者認証情報を保証する（冪等）。
 * .env の ADMIN_PASSWORD が空なら生成して設定し、credentials ファイルを書く。
 * 既に両方が揃っていれば何もしない。
 */
export function ensureAdminCredential(root: string): EnsureCredentialResult {
  const envPath = join(root, ".env");
  const credPath = join(root, CREDENTIALS_REL);
  let env = readFileSync(envPath, "utf8");

  const existing = readEnvValue(env, "ADMIN_PASSWORD");
  if (existing && existsSync(credPath)) {
    return {
      created: false,
      file: credPath,
      insecurePermissions: hasInsecurePermissions(credPath),
    };
  }

  const email = readEnvValue(env, "ADMIN_EMAIL") || "admin@example.test";
  const password = existing || generatePassword();

  env = setEnvValue(env, "ADMIN_EMAIL", `"${email}"`);
  env = setEnvValue(env, "ADMIN_PASSWORD", password);
  writeFileSync(envPath, env, "utf8");

  mkdirSync(join(root, ".agentcms/local"), { recursive: true });
  writeFileSync(
    credPath,
    `${JSON.stringify({ email, password }, null, 2)}\n`,
    "utf8",
  );
  try {
    chmodSync(credPath, 0o600);
  } catch {
    // Windows 等 chmod 不可の環境では無視（gitignore による保護は維持される）
  }
  ensureLocalGitignored(root);
  return {
    created: true,
    file: credPath,
    insecurePermissions: hasInsecurePermissions(credPath),
  };
}

/**
 * chmod 0600 が実効しているかを stat で検証する（POSIX のみ）。
 * WSL2 の DrvFS（/mnt/c 等）では chmod が無視され 777 のままになる —
 * その場合は呼び出し側が明確に WARNING を出す（Windows RC1 実測の一般化）。
 */
function hasInsecurePermissions(file: string): boolean {
  if (process.platform === "win32") return false;
  try {
    return (statSync(file).mode & 0o077) !== 0;
  } catch {
    return false;
  }
}

/** .gitignore が .agentcms/local/ を除外していることを保証する（無ければ追記） */
export function ensureLocalGitignored(root: string): void {
  const gi = join(root, ".gitignore");
  if (!existsSync(gi)) {
    writeFileSync(gi, "/.agentcms/local/\n", "utf8");
    return;
  }
  const content = readFileSync(gi, "utf8");
  if (/^\/?\.agentcms\/local\/?$/m.test(content)) return;
  writeFileSync(
    gi,
    `${content.replace(/\n?$/, "\n")}\n# ローカル秘密情報（管理者認証情報等）— コミット禁止\n/.agentcms/local/\n`,
    "utf8",
  );
}

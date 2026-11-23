import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

import { readEnvValue, setEnvValue } from "./credentials.js";

/**
 * Runtime user mapping（Phase 7 RC2 Blocker 2）。
 * WSL2/Linux では host uid と container の PHP-FPM 実行 uid が一致しないと
 * bind mount した storage/ 等へ書き込めない。HOST_UID/HOST_GID を .env に記録し、
 * Docker build args と `docker compose exec --user` の単一情報源にする。
 * chmod 777 / chown による回避・root-owned files を host に残すことは禁止。
 */

export function hostUserIds(): { uid: number; gid: number } | null {
  if (
    typeof process.getuid === "function" &&
    typeof process.getgid === "function"
  ) {
    return { uid: process.getuid(), gid: process.getgid() };
  }
  return null; // Windows ネイティブ等（WSL2 内は linux なので取得できる）
}

/** .env に HOST_UID / HOST_GID を記録する（冪等） */
export function ensureHostUserEnv(root: string): void {
  const ids = hostUserIds();
  if (!ids) return;
  const envPath = join(root, ".env");
  let env = readFileSync(envPath, "utf8");
  if (readEnvValue(env, "HOST_UID") && readEnvValue(env, "HOST_GID")) return;
  env = setEnvValue(env, "HOST_UID", String(ids.uid));
  env = setEnvValue(env, "HOST_GID", String(ids.gid));
  writeFileSync(envPath, env, "utf8");
}

/**
 * `docker compose exec` に渡す --user 引数。
 * container 内での composer / artisan 実行が host uid で行われることで、
 * bind mount 上に root-owned ファイルが残らない。
 */
export function composeUserArgs(root: string): string[] {
  try {
    const env = readFileSync(join(root, ".env"), "utf8");
    const uid = readEnvValue(env, "HOST_UID");
    const gid = readEnvValue(env, "HOST_GID");
    if (uid && gid) return ["--user", `${uid}:${gid}`];
  } catch {
    // .env なし — fallback へ
  }
  const ids = hostUserIds();
  return ids ? ["--user", `${ids.uid}:${ids.gid}`] : [];
}

/** container runtime user が書き込めなければならないパス（RC2 Blocker 2） */
export const CONTAINER_WRITABLE_PATHS = [
  "storage",
  "storage/logs",
  "storage/framework",
  "storage/framework/cache",
  "storage/framework/sessions",
  "storage/framework/views",
  "storage/app",
  "bootstrap/cache",
] as const;

/** container 内書き込み検査用のシェルコマンド（compose exec sh -c で実行） */
export function containerWriteProbeScript(): string {
  const checks = CONTAINER_WRITABLE_PATHS.map(
    (d) =>
      `{ [ -d '${d}' ] && touch '${d}/.agentcms-probe' && rm -f '${d}/.agentcms-probe'; } || { echo 'NOT WRITABLE: ${d}'; exit 1; }`,
  );
  return checks.join("; ");
}

/** WSL2 の Windows mounted filesystem (DrvFS: /mnt/c 等) 配下か */
export function isDrvfsPath(p: string): boolean {
  return /^\/mnt\/[a-z]\//i.test(p);
}

export function isWsl(): boolean {
  try {
    return (
      process.platform === "linux" &&
      /microsoft/i.test(readFileSync("/proc/version", "utf8"))
    );
  } catch {
    return false;
  }
}

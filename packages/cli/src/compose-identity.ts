import { randomBytes } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { basename, join } from "node:path";

import { readEnvValue, setEnvValue } from "./credentials.js";

/**
 * Docker Compose project identity（Phase 7 Decision 9）。
 * 固定名 `agentcms` は廃止。project ごとに slug + short id の固有 identity を
 * .env の COMPOSE_PROJECT_NAME として記録し、container / volume / network が
 * 他の SiteGrowKit のプロジェクト と衝突・暗黙再利用されないようにする。
 */

export function projectSlug(name: string): string {
  const slug = name
    .toLowerCase()
    .replace(/[^a-z0-9-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 30);
  return slug || "site";
}

export function composeProjectName(dirName: string): string {
  return `agentcms-${projectSlug(dirName)}-${randomBytes(3).toString("hex")}`;
}

export interface ComposeIdentityResult {
  name: string;
  created: boolean;
}

/** .env に COMPOSE_PROJECT_NAME が無ければ固有 identity を生成して記録する（冪等） */
export function ensureComposeIdentity(root: string): ComposeIdentityResult {
  const envPath = join(root, ".env");
  const env = readFileSync(envPath, "utf8");
  const existing = readEnvValue(env, "COMPOSE_PROJECT_NAME");
  if (existing) return { name: existing, created: false };
  const name = composeProjectName(basename(root));
  writeFileSync(
    envPath,
    setEnvValue(env, "COMPOSE_PROJECT_NAME", name),
    "utf8",
  );
  return { name, created: true };
}

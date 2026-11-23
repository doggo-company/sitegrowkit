/**
 * Project Figma Config（SPEC §18）: .agentcms/figma/project.json
 * Client 固有 metadata のため gitignore（tracked example: project.example.json）。
 * Access Token は絶対に含めない（token は env FIGMA_ACCESS_TOKEN のみ）。
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

import type { FigmaFileType } from "./url.js";

export interface FigmaProjectConfig {
  version: 1;
  file_key: string;
  file_name: string | null;
  file_type: FigmaFileType;
  /** 取得対象の node（Page / Section / Frame / Component）。空なら file 全体 */
  entry_nodes: string[];
}

export function figmaConfigPath(agentcmsDir: string): string {
  return join(agentcmsDir, "figma", "project.json");
}

export interface FigmaConfigLoad {
  config?: FigmaProjectConfig;
  error?: { what: string; why: string; fix: string };
}

export function loadFigmaConfig(agentcmsDir: string): FigmaConfigLoad {
  const path = figmaConfigPath(agentcmsDir);
  if (!existsSync(path)) {
    return {
      error: {
        what: "Figma project config が見つかりません",
        why: `${path} がありません`,
        fix: 'agentcms figma connect "https://www.figma.com/design/..." を先に実行してください',
      },
    };
  }
  let raw: unknown;
  try {
    raw = JSON.parse(readFileSync(path, "utf8"));
  } catch {
    return {
      error: {
        what: "Figma project config を読めません",
        why: `${path} が JSON として不正です`,
        fix: "ファイルを修正するか削除して figma connect をやり直してください",
      },
    };
  }
  const cfg = raw as Partial<FigmaProjectConfig> & Record<string, unknown>;
  // Token 混入の拒否（SPEC §17: 保存する Project Config に Access Token を含めない）
  const text = JSON.stringify(raw);
  if (/figd_[A-Za-z0-9_-]{8,}/.test(text) || "token" in cfg) {
    return {
      error: {
        what: "Figma project config に token らしき値が含まれています",
        why: "config へ Access Token を保存してはいけません（SPEC §8/§17）",
        fix: "config から token を削除し、環境変数 FIGMA_ACCESS_TOKEN を使ってください。漏えいした token は必ず revoke してください",
      },
    };
  }
  if (
    cfg.version !== 1 ||
    typeof cfg.file_key !== "string" ||
    !Array.isArray(cfg.entry_nodes)
  ) {
    return {
      error: {
        what: "Figma project config の形式が不正です",
        why: "version / file_key / entry_nodes を確認してください",
        fix: "figma connect をやり直してください",
      },
    };
  }
  return {
    config: {
      version: 1,
      file_key: cfg.file_key,
      file_name: typeof cfg.file_name === "string" ? cfg.file_name : null,
      file_type: (cfg.file_type as FigmaFileType) ?? "design",
      entry_nodes: [...(cfg.entry_nodes as string[])].sort(),
    },
  };
}

export function saveFigmaConfig(
  agentcmsDir: string,
  config: FigmaProjectConfig,
): string {
  const path = figmaConfigPath(agentcmsDir);
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, `${JSON.stringify(config, null, 2)}\n`, "utf8");
  return path;
}

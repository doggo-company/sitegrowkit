import { dirname, join } from "node:path";

import { writeAgentEntrypoints } from "../agent-context.js";
import { findAgentcmsDir } from "../project.js";
import { failAndExit, heading, ok } from "../ui.js";

/**
 * .agentcms/（SSoT）から CLAUDE.md / AGENTS.md を生成する。
 * 生成物は「入口」であり、ルール本文は .agentcms/ にのみ存在させる（重複コピー禁止）。
 * kit repo / 生成プロジェクトのどちらでも動作する（audience は自動判定）。
 */
export async function syncAgents(): Promise<number> {
  const agentcms = findAgentcmsDir();
  if (!agentcms) {
    failAndExit({
      what: ".agentcms/ が見つかりません",
      why: "カレントディレクトリ（の上位）に SSoT ディレクトリがありません",
      fix: "SiteGrowKit リポジトリまたは生成プロジェクト内で実行してください",
    });
  }
  const root = dirname(agentcms);
  heading("agentcms sync-agents — CLAUDE.md / AGENTS.md を生成します");
  writeAgentEntrypoints(root, agentcms);
  ok(`生成しました: ${join(root, "CLAUDE.md")}, ${join(root, "AGENTS.md")}`);
  return 0;
}

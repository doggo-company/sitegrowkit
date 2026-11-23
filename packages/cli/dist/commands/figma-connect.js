/**
 * agentcms figma connect <figma-url>（SPEC §17–18）。
 * URL から file key / node id を解析し、.agentcms/figma/project.json へ保存する。
 * Access Token は保存しない（env FIGMA_ACCESS_TOKEN のみ）。API call は発生しない。
 */
import { findAgentcmsDir } from "../project.js";
import { fail, info, ok } from "../ui.js";
import { normalizeNodeId, parseFigmaUrl } from "../figma/url.js";
import { saveFigmaConfig } from "../figma/config.js";
export async function figmaConnect(url, opts = {}) {
    const agentcmsDir = findAgentcmsDir();
    if (!agentcmsDir) {
        fail({
            what: "Figma を接続できません",
            why: ".agentcms/ ディレクトリが見つかりません",
            fix: "SiteGrowKit のプロジェクト内（または kit リポジトリ内）で実行してください",
        });
        return 1;
    }
    const parsed = parseFigmaUrl(url);
    if (!parsed.info) {
        fail({
            what: "Figma URL を解析できません",
            why: parsed.error ?? "unknown",
            fix: '例: agentcms figma connect "https://www.figma.com/design/<fileKey>/<name>?node-id=1-2"',
        });
        return 1;
    }
    const extraNodes = (opts.nodes ?? "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean)
        .map(normalizeNodeId);
    const entryNodes = [
        ...new Set([...parsed.info.node_ids, ...extraNodes]),
    ].sort();
    const path = saveFigmaConfig(agentcmsDir, {
        version: 1,
        file_key: parsed.info.file_key,
        file_name: parsed.info.file_name,
        file_type: parsed.info.file_type,
        entry_nodes: entryNodes,
    });
    ok(`Figma file を接続しました: ${parsed.info.file_key}`);
    info(`file type: ${parsed.info.file_type}`);
    info(entryNodes.length > 0
        ? `entry nodes: ${entryNodes.join(", ")}`
        : "entry nodes: （未指定 — inspect は file 全体を対象にします。--nodes で絞れます）");
    info(`保存先: ${path}（gitignore 対象 / token は含みません）`);
    info("次: agentcms figma inspect");
    return 0;
}

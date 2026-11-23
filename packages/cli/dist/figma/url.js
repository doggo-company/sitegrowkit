/**
 * Figma URL の解析（SPEC §17）。
 * https://www.figma.com/design/<fileKey>/<name>?node-id=1-2 形式から
 * file key / node id / file type を取り出す。token 等の秘密情報は扱わない。
 */
const PATH_TYPES = {
    design: "design",
    file: "file",
    proto: "proto",
    board: "board",
};
/** URL の node-id は ":" が "-" で表現される（"12-34" → "12:34"）。instance path の ";" はそのまま */
export function normalizeNodeId(raw) {
    return decodeURIComponent(raw)
        .split(";")
        .map((part) => part.replace(/-/g, ":"))
        .join(";");
}
export function parseFigmaUrl(url) {
    let parsed;
    try {
        parsed = new URL(url);
    }
    catch {
        return { error: `URL として解析できません: ${url}` };
    }
    if (!/(^|\.)figma\.com$/.test(parsed.hostname)) {
        return { error: `figma.com の URL ではありません: ${parsed.hostname}` };
    }
    const segments = parsed.pathname.split("/").filter(Boolean);
    const typeSegment = segments[0] ?? "";
    const fileType = PATH_TYPES[typeSegment];
    if (!fileType) {
        return {
            error: `対応していない Figma URL パスです: /${typeSegment}（対応: design / file / proto / board）`,
        };
    }
    const fileKey = segments[1] ?? "";
    if (!/^[A-Za-z0-9]{10,}$/.test(fileKey)) {
        return { error: "URL から file key を取得できません" };
    }
    const nodeParam = parsed.searchParams.get("node-id");
    const nodeIds = nodeParam ? [normalizeNodeId(nodeParam)] : [];
    const fileName = segments[2] ? decodeURIComponent(segments[2]) : null;
    return {
        info: {
            file_key: fileKey,
            file_type: fileType,
            file_name: fileName,
            node_ids: nodeIds,
        },
    };
}

import { bboxSize, isVisible, slugify } from "./normalize-util.js";
import { findPairingCandidates } from "./pairing.js";
import { buildElementTree } from "./tree.js";
const MIN_SCREEN_WIDTH = 320;
const MIN_SCREEN_HEIGHT = 480;
const BP_NAME = [
    { re: /desktop|pc|web|wide/i, bp: "desktop" },
    { re: /mobile|sp|phone|smartphone/i, bp: "mobile" },
    { re: /tablet|tab\b/i, bp: "tablet" },
];
/** roots（CANVAS / SECTION / FRAME）から screen candidate frame を列挙 */
export function collectScreenFrames(roots) {
    const frames = [];
    const visit = (node) => {
        if (!isVisible(node))
            return;
        if (node.type === "DOCUMENT" ||
            node.type === "CANVAS" ||
            node.type === "SECTION") {
            for (const child of node.children ?? [])
                visit(child);
            return;
        }
        if (node.type !== "FRAME")
            return; // COMPONENT / COMPONENT_SET は screen ではない
        const { width, height } = bboxSize(node);
        if (width < MIN_SCREEN_WIDTH || height < MIN_SCREEN_HEIGHT)
            return;
        frames.push(parseFrameName(node, width, height));
    };
    for (const root of roots)
        visit(root);
    return frames;
}
function parseFrameName(node, width, height) {
    const parts = node.name.split("/").map((p) => p.trim());
    let breakpoint = null;
    let byName = false;
    let base = node.name.trim();
    if (parts.length >= 2) {
        const label = parts.at(-1) ?? "";
        for (const { re, bp } of BP_NAME) {
            if (re.test(label)) {
                breakpoint = bp;
                byName = true;
                base = parts.slice(0, -1).join(" ").trim();
                break;
            }
        }
    }
    if (!breakpoint) {
        // 幅による推定（名前が無い場合は REVIEW 側に倒す）
        breakpoint = width >= 1024 ? "desktop" : width <= 480 ? "mobile" : "tablet";
    }
    return { base, breakpoint, byName, node, width, height };
}
export function detectScreens(roots, ctx, warnings) {
    const frames = collectScreenFrames(roots);
    const groups = new Map();
    for (const frame of frames) {
        const id = slugify(frame.base);
        const list = groups.get(id) ?? [];
        list.push(frame);
        groups.set(id, list);
    }
    const screens = [];
    for (const [screenId, group] of groups) {
        const byBp = new Map();
        for (const frame of group) {
            const bp = frame.breakpoint;
            if (!byBp.has(bp))
                byBp.set(bp, frame);
        }
        const allNamed = group.every((f) => f.byName);
        const widthsConsistent = checkWidths(byBp);
        let pairing;
        let reason;
        if (allNamed && widthsConsistent) {
            pairing = "CONFIDENT";
            reason = `Frame 名の breakpoint ラベルと幅（${[...byBp.values()]
                .map((f) => `${f.breakpoint}:${f.width}px`)
                .join(" / ")}）が一致`;
        }
        else if (allNamed) {
            pairing = "REVIEW";
            reason = "Frame 名に breakpoint ラベルはあるが幅が名前と整合しない";
        }
        else {
            pairing = "REVIEW";
            reason = "breakpoint を幅からのみ推定（Frame 名にラベルが無い）";
        }
        const missing = [];
        if (!byBp.has("mobile"))
            missing.push("mobile");
        if (!byBp.has("desktop"))
            missing.push("desktop");
        if (missing.includes("mobile")) {
            warnings.push({
                code: "MOBILE_DESIGN_REVIEW",
                message: `Screen "${screenId}" に Mobile design がありません。AI が Mobile を勝手にデザインせず、Plan で REVIEW してください（SPEC §26）`,
            });
        }
        const order = ["desktop", "tablet", "mobile"];
        const variants = order
            .filter((bp) => byBp.has(bp))
            .map((bp) => {
            const frame = byBp.get(bp);
            const tree = buildElementTree(frame.node, ctx);
            return {
                breakpoint: bp,
                node_id: frame.node.id,
                frame_name: frame.node.name,
                width: frame.width,
                height: frame.height,
                tree: tree ?? {
                    id: frame.node.id,
                    role: "frame",
                    name: frame.node.name,
                },
            };
        });
        screens.push({
            screen_id: screenId,
            name: group[0]?.base ?? screenId,
            variants,
            pairing,
            reason,
            missing,
        });
    }
    // 名前 pairing できず breakpoint が欠けた screen 同士の Human-approved pairing 候補
    // （generic signal のみ。自動確定はしない — Phase 6.1 実測の一般化）
    const soloFrames = [...groups.entries()]
        .filter(([, g]) => g.length === 1 && g[0] !== undefined)
        .map(([id, g]) => ({ screenId: id, frame: g[0] }));
    for (const screen of screens) {
        if (screen.missing.length === 0)
            continue;
        const solo = soloFrames.find((s) => s.screenId === screen.screen_id);
        if (!solo)
            continue;
        const candidates = findPairingCandidates(solo.frame, screen.screen_id, soloFrames);
        if (candidates.length === 0)
            continue;
        screen.pairing_candidates = candidates;
        if (!warnings.some((w) => w.code === "PAIRING_CANDIDATE" && w.node_id === solo.frame.node.id)) {
            warnings.push({
                code: "PAIRING_CANDIDATE",
                message: `Screen "${screen.screen_id}" は名前で pairing できませんが、内容 signal が一致する候補があります: ${candidates.map((c) => `${c.screen_id}（text ${Math.round(c.text_overlap * 100)}%）`).join(", ")}。pairing は人が承認してください`,
                node_id: solo.frame.node.id,
            });
        }
    }
    return screens.sort((a, b) => a.screen_id.localeCompare(b.screen_id));
}
function checkWidths(byBp) {
    const desktop = byBp.get("desktop");
    const mobile = byBp.get("mobile");
    if (desktop && desktop.width < 1000)
        return false;
    if (mobile && mobile.width > 600)
        return false;
    if (desktop && mobile && desktop.width <= mobile.width)
        return false;
    return true;
}

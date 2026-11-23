import { collectTexts, firstImageFill, hasTextDescendant, isVisible, slugify, structureSignature, walkVisible, } from "./normalize-util.js";
import { redactDesignText } from "./redact.js";
/** 繰り返しと見なす最低アイテム数 */
const MIN_REPEAT = 3;
/** module hint の対応表（kit 標準 module は EXISTING_MODULE 候補になる） */
const MODULE_HINTS = [
    {
        re: /works?|portfolio|project|jirei|実績|制作/i,
        hint: "works",
        existing: true,
    },
    {
        re: /news|blog|topics?|article|お知らせ|新着/i,
        hint: "news",
        existing: true,
    },
    { re: /staff|member|team|スタッフ/i, hint: "staff", existing: false },
    { re: /faq|question|よくある/i, hint: "faq", existing: false },
    { re: /gallery|photo|ギャラリー/i, hint: "gallery", existing: false },
    {
        re: /voice|testimonial|review|お客様の声/i,
        hint: "testimonials",
        existing: false,
    },
];
/** screen frame 内の「同一構造 × MIN_REPEAT 以上」の兄弟グループを検出 */
export function detectRepeatGroups(screenRoot) {
    const groups = [];
    for (const { node, ancestors } of walkVisible(screenRoot)) {
        const children = (node.children ?? []).filter(isVisible);
        if (children.length < MIN_REPEAT)
            continue;
        const bySig = new Map();
        for (const child of children) {
            const sig = structureSignature(child);
            const list = bySig.get(sig) ?? [];
            list.push(child);
            bySig.set(sig, list);
        }
        for (const [signature, items] of bySig) {
            if (items.length < MIN_REPEAT)
                continue;
            // テキストを持たない繰り返し（区切り線など）は対象外
            if (!items.some(hasTextDescendant))
                continue;
            // 裸の TEXT / VECTOR の並び（nav リンク・アイコン列）は content 繰り返しではない
            if (signature === "T" || signature === "V" || signature === "I")
                continue;
            groups.push({
                container: node,
                items,
                signature,
                contextNames: [...ancestors.map((a) => a.name), node.name],
            });
        }
    }
    return groups;
}
export function detectCmsCandidates(screens) {
    const candidates = [];
    const cmsImageNodeIds = new Set();
    const instanceComponentIds = new Map();
    const seenSignatures = new Set(); // Desktop/Mobile で同じ繰り返しを重複させない
    const containersByCandidate = new Map();
    for (const screen of screens) {
        for (const group of detectRepeatGroups(screen.root)) {
            const hint = findModuleHint(group);
            const fields = extractFields(group.items);
            const textVariation = hasTextVariation(group.items);
            const { classification, confidence, reason } = classify(group, hint, textVariation, fields);
            const candidateId = hint
                ? `${hint.hint}-repeat`
                : `${slugify(group.items[0]?.name ?? "repeat")}-repeat`;
            // 画像 / component 対応の収集は全 screen 分行う（Desktop / Mobile / 一覧ページ）
            if (classification === "CMS_CANDIDATE" ||
                classification === "EXISTING_MODULE") {
                for (const item of group.items) {
                    for (const { node } of walkVisible(item)) {
                        if (firstImageFill(node))
                            cmsImageNodeIds.add(node.id);
                    }
                }
            }
            const firstItem = group.items[0];
            if (firstItem?.type === "INSTANCE" && firstItem.componentId) {
                instanceComponentIds.set(candidateId, firstItem.componentId);
            }
            const containers = containersByCandidate.get(candidateId) ?? new Set();
            containers.add(group.container.id);
            containersByCandidate.set(candidateId, containers);
            // candidate エントリ自体は同一の繰り返しにつき 1 件（Desktop/Mobile で重複させない）
            const sigKey = `${hint?.hint ?? group.signature}`;
            if (seenSignatures.has(sigKey))
                continue;
            seenSignatures.add(sigKey);
            candidates.push({
                candidate_id: candidateId,
                screen_id: screen.screen_id,
                node_id: group.container.id,
                container_node_ids: [],
                module_hint: hint?.hint ?? null,
                classification,
                confidence,
                reason,
                item_count: group.items.length,
                fields,
            });
        }
    }
    for (const c of candidates) {
        c.container_node_ids = [
            ...(containersByCandidate.get(c.candidate_id) ?? []),
        ].sort();
    }
    return {
        candidates: candidates.sort((a, b) => a.candidate_id.localeCompare(b.candidate_id)),
        cmsImageNodeIds,
        instanceComponentIds,
    };
}
function findModuleHint(group) {
    const names = [
        ...group.contextNames,
        ...group.items.slice(0, 1).map((i) => i.name),
    ];
    for (const name of names) {
        for (const m of MODULE_HINTS) {
            if (m.re.test(name))
                return { hint: m.hint, existing: m.existing };
        }
    }
    return null;
}
function classify(group, hint, textVariation, fields) {
    const n = group.items.length;
    if (!textVariation) {
        return {
            classification: "STATIC",
            confidence: "CONFIDENT",
            reason: `${n} 回繰り返されるが全アイテムのテキストが同一 — 装飾的な繰り返しであり CMS 化の根拠がない`,
        };
    }
    const hasImage = fields.some((f) => f.kind === "image");
    if (hint?.existing) {
        return {
            classification: "EXISTING_MODULE",
            confidence: "CONFIDENT",
            reason: `同一構造の ${n} アイテムが「${group.contextNames.at(-1)}」内に存在し、名前が既存 ${hint.hint} module に対応。検出フィールド（${fields.map((f) => f.name).join(" / ")}）が schema へ対応可能`,
        };
    }
    if (hint) {
        return {
            classification: "CMS_CANDIDATE",
            confidence: "REVIEW",
            reason: `同一構造の ${n} アイテム。名前から ${hint.hint} と推定されるが、対応する既存 module が無い（新規 schema が必要 — 人の判断が必要）`,
        };
    }
    return {
        classification: "REVIEW",
        confidence: "REVIEW",
        reason: `同一構造の ${n} アイテム（テキスト差分あり${hasImage ? "・画像あり" : ""}）。用途を特定できる名前が無いため CMS 化は人の判断が必要`,
    };
}
function hasTextVariation(items) {
    const textsOf = (item) => collectTexts(item)
        .map((t) => t.characters ?? "")
        .join("\0");
    const first = textsOf(items[0]);
    return items.some((item) => textsOf(item) !== first);
}
const DATE_RE = /\d{4}[./年-]\s?\d{1,2}([./月-]\s?\d{1,2})?/;
/** 最初のアイテムの構造から field を推定し、全アイテムから sample を集める */
function extractFields(items) {
    const first = items[0];
    if (!first)
        return [];
    const texts = collectTexts(first);
    const fields = [];
    const usedNames = new Set();
    const maxFont = Math.max(...texts.map((t) => t.style?.fontSize ?? 0), 0);
    for (const [index, text] of texts.entries()) {
        const chars = text.characters ?? "";
        const layerSlug = slugify(text.name);
        let name;
        let kind = "text";
        if (DATE_RE.test(chars)) {
            name = "date";
            kind = "date";
        }
        else if (/title|heading|見出し/i.test(text.name)) {
            name = "title";
        }
        else if ((text.style?.fontSize ?? 0) === maxFont &&
            !usedNames.has("title")) {
            name = "title";
        }
        else if (/category|tag|label/i.test(text.name)) {
            name = "category";
        }
        else if (layerSlug && !/^text/.test(layerSlug)) {
            name = layerSlug;
        }
        else {
            name = "excerpt";
        }
        if (usedNames.has(name))
            name = `${name}-${index + 1}`;
        usedNames.add(name);
        const samples = items
            .slice(0, 3)
            .map((item) => collectTexts(item)[index]?.characters ?? "")
            .filter(Boolean)
            .map((s) => redactDesignText(s).text.slice(0, 80));
        fields.push({ name, kind, samples });
    }
    const hasImage = walkVisible(first).some(({ node }) => firstImageFill(node));
    if (hasImage)
        fields.push({ name: "image", kind: "image", samples: [] });
    return fields;
}

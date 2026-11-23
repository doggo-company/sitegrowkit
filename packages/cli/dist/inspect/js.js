import { readFileSync } from "node:fs";
import { join } from "node:path";
/** URL やグローバル参照から推定する既知ライブラリ */
const KNOWN_LIBRARIES = [
    "gsap",
    "jQuery",
    "Swiper",
    "Alpine",
    "bootstrap",
    "anime",
    "AOS",
    "ScrollTrigger",
];
/**
 * JavaScript の決定論的解析（spec §12）。
 * 既存 JS は保持が原則 — この解析は「どこで何が動いているか」を AI が原文を読まずに知るためのもの。
 */
export function analyzeJs(sourceDir, jsFiles) {
    return { files: jsFiles.map((f) => analyzeJsFile(sourceDir, f)) };
}
function analyzeJsFile(sourceDir, path) {
    const text = readFileSync(join(sourceDir, path), "utf8");
    const dom_selectors = unique([
        ...matchAll(text, /querySelector(?:All)?\(\s*['"`]([^'"`]+)['"`]/g).map((m) => m[1] ?? ""),
        ...matchAll(text, /getElementById\(\s*['"`]([^'"`]+)['"`]/g).map((m) => `#${m[1]}`),
        ...matchAll(text, /getElementsByClassName\(\s*['"`]([^'"`]+)['"`]/g).map((m) => `.${m[1]}`),
        ...matchAll(text, /gsap\.(?:from|to|fromTo|set|timeline)\(\s*['"`]([^'"`]+)['"`]/g).map((m) => m[1] ?? ""),
    ]);
    const event_listeners = unique(matchAll(text, /addEventListener\(\s*['"`]([\w:-]+)['"`]/g).map((m) => m[1] ?? ""));
    const libraries = KNOWN_LIBRARIES.filter((lib) => new RegExp(`\\b(?:window\\.)?${lib}\\b`).test(text)).sort();
    const uses_modules = /^\s*import\s.+from\s/m.test(text) || /^\s*export\s/m.test(text);
    return {
        path,
        lines: text.split("\n").length,
        bytes: Buffer.byteLength(text),
        dom_selectors,
        event_listeners,
        libraries,
        uses_modules,
    };
}
function matchAll(text, re) {
    return [...text.matchAll(re)];
}
function unique(values) {
    return [...new Set(values)].sort();
}

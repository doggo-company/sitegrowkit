import { createHash } from "node:crypto";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { HTMLElement, parse } from "node-html-parser";

import { analyzeCss, resolveRelative } from "./css.js";
import { fingerprintSource } from "./fingerprint.js";
import { analyzeJs } from "./js.js";
import type {
  AssetIndex,
  ExternalCategory,
  ExternalDep,
  ExternalKind,
  FormEntry,
  FormField,
  HeadMetadata,
  Manifest,
  PageEntry,
  RepeatEntry,
  RepeatItem,
  SharedCandidate,
  SourceStats,
} from "./manifest.js";
import {
  classifyImages,
  deriveCommonStructures,
  deriveInteractiveRegions,
  selectorOf,
} from "./structures.js";
import { collectWarnings } from "./warnings.js";

const IMAGE_EXT = [
  ".png",
  ".jpg",
  ".jpeg",
  ".webp",
  ".avif",
  ".gif",
  ".ico",
  ".svg",
];
const FONT_EXT = [".woff", ".woff2", ".ttf", ".otf", ".eot"];
const TEXT_EXT = [
  ".html",
  ".htm",
  ".css",
  ".js",
  ".mjs",
  ".svg",
  ".json",
  ".txt",
];
const SKIP_DIRS = new Set([
  "node_modules",
  ".git",
  ".agentcms",
  "dist",
  "vendor",
]);

/** ページ解析中に集める分類用コンテキスト */
interface PageContext {
  /** コンテンツ繰り返しの代表画像（item.image）→ item シグネチャ */
  contentImages: Map<string, string>;
  /** コンテンツ繰り返し内の付随画像（装飾）→ item シグネチャ */
  auxiliaryImages: Map<string, string>;
  /** テキストを持たない装飾繰り返しの画像 → item シグネチャ */
  decorativeImages: Map<string, string>;
  sharedImages: Set<string>;
  pageImages: Set<string>;
  emptyMountPages: { page: string; selector: string }[];
  /** 実質空のためページ候補から除外した HTML */
  emptyPages: string[];
}

export interface AnalyzeOptions {
  /** home (/) として扱うページの明示指定（source ルート相対） */
  entry?: string;
  /** 単一 HTML ファイル inspect: このページと参照 asset のみを対象にする */
  onlyPage?: string;
}

/** ディレクトリを決定論的に解析して Manifest を返す（AI 判断を含まない純粋な静的解析） */
export function analyzeTemplateDir(
  sourceDir: string,
  sourceLabel?: string,
  opts: AnalyzeOptions = {},
): Manifest {
  const allFiles = walk(sourceDir).sort();
  const htmlFiles = opts.onlyPage
    ? [opts.onlyPage]
    : allFiles.filter((f) => f.endsWith(".html") || f.endsWith(".htm"));

  const pages: PageEntry[] = [];
  const externalMap = new Map<string, ExternalDep>();
  const forms: FormEntry[] = [];
  const repeats: RepeatEntry[] = [];
  const sharedMap = new Map<
    string,
    { kind: string; selector: string; pages: string[]; bytes: number }
  >();
  const parsed = new Map<string, HTMLElement>();
  const ctx: PageContext = {
    contentImages: new Map(),
    auxiliaryImages: new Map(),
    decorativeImages: new Map(),
    sharedImages: new Set(),
    pageImages: new Set(),
    emptyMountPages: [],
    emptyPages: [],
  };

  for (const pagePath of htmlFiles) {
    const html = readFileSync(join(sourceDir, pagePath), "utf8");
    const root = parse(html, { comment: false });
    // 実質空の HTML はページ候補にしない（0 byte の index.html が home へ誤マッチする事故防止）
    if (isEffectivelyEmpty(html, root)) {
      ctx.emptyPages.push(pagePath);
      continue;
    }
    parsed.set(pagePath, root);

    pages.push(analyzePage(root, pagePath, externalMap, ctx));
    collectForms(root, pagePath, forms);
    collectRepeats(root, pagePath, repeats, ctx);
    collectSharedCandidates(root, pagePath, sharedMap, ctx);
    detectEmptyMounts(root, pagePath, ctx);
  }

  // inline <script> 内の外部 URL（Typekit 等、loader が動的に外部 script を注入するパターン）。
  // 誤検出を CSP へ流さないため、既知カテゴリに分類できたものだけを external として記録する
  for (const [pagePath, root] of parsed) {
    for (const s of root.querySelectorAll("script")) {
      if (s.getAttribute("src")) continue;
      for (const m of s.text.matchAll(
        /https?:\/\/[a-z0-9.-]+\.[a-z]{2,}[^\s"'`)]*/gi,
      )) {
        const url = m[0] ?? "";
        if (categorize(url).category === "unknown") continue;
        addExternal(externalMap, url, "script", pagePath);
      }
    }
  }

  const entry = resolveEntry(opts, pages);

  // HTML 属性（href/src）からの参照集合。CSS url() は css 解析後に追加する
  const referenced = new Set<string>();
  const cssReferenced = new Set<string>();
  for (const p of pages) {
    for (const ref of [...p.css, ...p.js, ...p.images]) referenced.add(ref);
    // ページ以外への内部リンク（pdf 等のダウンロード資材）も参照として扱う
    for (const ref of p.links_internal) {
      const ext = extOf(ref);
      if (ext && ext !== ".html" && ext !== ".htm") referenced.add(ref);
    }
  }

  // inline <style> の url() 参照（実案件は CSS が全て inline のことがある — 背景画像等）
  for (const [pagePath, root] of parsed) {
    for (const styleEl of root.querySelectorAll("style")) {
      for (const ref of cssUrlRefs(styleEl.text)) {
        const resolved = normalizeRef(pagePath, ref);
        if (existsSync(join(sourceDir, resolved))) {
          referenced.add(resolved);
          cssReferenced.add(resolved);
        }
      }
    }
  }

  // 単一ファイル mode では「対象ページ + 参照される asset」だけを source とみなす
  const scopedCss = opts.onlyPage
    ? [...referenced]
        .filter((f) => extOf(f) === ".css" && existsSync(join(sourceDir, f)))
        .sort()
    : allFiles.filter((f) => extOf(f) === ".css");
  const css_analysis = analyzeCss(sourceDir, scopedCss);
  for (const file of css_analysis.files) {
    for (const ref of file.url_refs) {
      if (ref.resolved && ref.exists) {
        referenced.add(ref.resolved);
        cssReferenced.add(ref.resolved);
      }
    }
  }
  const referenced_assets = [...referenced].sort();
  const css_referenced = [...cssReferenced].sort();

  const files = opts.onlyPage
    ? [
        ...new Set([
          ...htmlFiles.filter((f) => existsSync(join(sourceDir, f))),
          ...referenced_assets.filter((f) => existsSync(join(sourceDir, f))),
        ]),
      ].sort()
    : allFiles;

  const assets: AssetIndex = {
    css: [],
    js: [],
    images: [],
    fonts: [],
    other: [],
  };
  for (const f of files) {
    if (htmlFiles.includes(f)) continue;
    const ext = extOf(f);
    if (ext === ".css") assets.css.push(f);
    else if (ext === ".js" || ext === ".mjs") assets.js.push(f);
    else if (IMAGE_EXT.includes(ext)) assets.images.push(f);
    else if (FONT_EXT.includes(ext)) assets.fonts.push(f);
    else assets.other.push(f);
  }

  const shared_candidates: SharedCandidate[] = [...sharedMap.entries()]
    .filter(([, v]) => v.pages.length >= 2)
    .map(([hash, v]) => ({
      kind: v.kind,
      selector: v.selector,
      pages: v.pages.sort(),
      hash,
      bytes: v.bytes,
    }))
    .sort(
      (a, b) =>
        a.selector.localeCompare(b.selector) || a.hash.localeCompare(b.hash),
    );

  const js_analysis = analyzeJs(sourceDir, assets.js);

  const cssImages = new Set<string>();
  for (const ref of cssReferenced) {
    if (IMAGE_EXT.includes(extOf(ref))) cssImages.add(ref);
  }

  return {
    version: 2,
    source: sourceLabel ?? sourceDir,
    generated_by: "agentcms template inspect",
    fingerprint: fingerprintSource(sourceDir, files),
    source_stats: sourceStats(sourceDir, files, assets, htmlFiles),
    entry,
    pages,
    assets,
    referenced_assets,
    css_referenced,
    external: [...externalMap.values()]
      .map((e) => ({ ...e, pages: e.pages.sort() }))
      .sort((a, b) => a.url.localeCompare(b.url)),
    shared_candidates,
    common_structures: deriveCommonStructures({
      shared: shared_candidates,
      repeats,
      forms,
      parsed,
    }),
    interactive_regions: deriveInteractiveRegions(js_analysis, parsed),
    forms,
    repeats,
    css_analysis,
    js_analysis,
    image_classification: classifyImages({
      images: assets.images,
      contentImages: ctx.contentImages,
      auxiliaryImages: ctx.auxiliaryImages,
      decorativeImages: ctx.decorativeImages,
      sharedImages: ctx.sharedImages,
      cssImages,
      pageImages: ctx.pageImages,
    }),
    warnings: collectWarnings(sourceDir, files, {
      forms,
      css: css_analysis,
      js: js_analysis,
      emptyMountPages: ctx.emptyMountPages,
      emptyPages: ctx.emptyPages,
    }),
  };
}

function sourceStats(
  sourceDir: string,
  files: string[],
  assets: AssetIndex,
  htmlFiles: string[],
): SourceStats {
  const typeOf = (f: string): string => {
    if (htmlFiles.includes(f)) return "html";
    if (assets.css.includes(f)) return "css";
    if (assets.js.includes(f)) return "js";
    if (assets.images.includes(f)) return "images";
    if (assets.fonts.includes(f)) return "fonts";
    return "other";
  };
  const by_type: Record<
    string,
    { files: number; lines: number; bytes: number }
  > = {};
  let text_lines = 0;
  let bytes = 0;
  for (const f of files) {
    const type = typeOf(f);
    const entry = (by_type[type] ??= { files: 0, lines: 0, bytes: 0 });
    const size = statSync(join(sourceDir, f)).size;
    entry.files++;
    entry.bytes += size;
    bytes += size;
    if (TEXT_EXT.includes(extOf(f))) {
      const lines = readFileSync(join(sourceDir, f), "utf8").split("\n").length;
      entry.lines += lines;
      text_lines += lines;
    }
  }
  return {
    files: files.length,
    text_lines,
    bytes,
    by_type: Object.fromEntries(
      Object.entries(by_type).sort(([a], [b]) => a.localeCompare(b)),
    ),
  };
}

function analyzePage(
  root: HTMLElement,
  pagePath: string,
  externalMap: Map<string, ExternalDep>,
  ctx: PageContext,
): PageEntry {
  const css: string[] = [];
  const js: string[] = [];
  const images: string[] = [];
  const links_internal: string[] = [];
  const links_anchor: string[] = [];
  const links_external: string[] = [];

  for (const el of root.querySelectorAll(
    'link[rel="stylesheet"], link[rel="preload"][as="style"]',
  )) {
    classifyRef(el.getAttribute("href"), pagePath, css, externalMap, "style");
  }
  for (const el of root.querySelectorAll("script[src]")) {
    classifyRef(el.getAttribute("src"), pagePath, js, externalMap, "script");
  }
  for (const el of root.querySelectorAll("img[src]")) {
    classifyRef(el.getAttribute("src"), pagePath, images, externalMap, "img");
  }
  for (const el of root.querySelectorAll("iframe[src]")) {
    classifyRef(el.getAttribute("src"), pagePath, [], externalMap, "iframe");
  }
  for (const el of root.querySelectorAll(
    'link[rel="preconnect"], link[href*="fonts."]',
  )) {
    const href = el.getAttribute("href");
    if (href && isExternal(href))
      addExternal(externalMap, href, "font", pagePath);
  }
  for (const img of images) ctx.pageImages.add(img);

  for (const a of root.querySelectorAll("a[href]")) {
    const href = a.getAttribute("href");
    if (!href || href.startsWith("mailto:") || href.startsWith("tel:"))
      continue;
    if (href.startsWith("#")) {
      if (href.length > 1) pushUnique(links_anchor, href);
      continue;
    }
    if (isExternal(href)) {
      pushUnique(links_external, href);
      continue;
    }
    if (href.includes("#")) pushUnique(links_anchor, href);
    pushUnique(links_internal, normalizeRef(pagePath, href));
  }

  const inlineScripts = root
    .querySelectorAll("script")
    .filter((s) => !s.getAttribute("src"))
    .map((s) => Buffer.byteLength(s.text));

  const main = root.querySelector("main");
  const sections = (main ?? root)
    .querySelectorAll("section")
    .filter((s) => {
      // 直下の section のみ（入れ子は構造俯瞰ではノイズ）
      const parent = s.parentNode;
      return parent === main || parent === root || parent?.tagName === "BODY";
    })
    .map((s) => selectorOf(s));

  const ids = [
    ...new Set(
      root
        .querySelectorAll("[id]")
        .map((el) => el.getAttribute("id") ?? "")
        .filter(Boolean),
    ),
  ].sort();

  return {
    path: pagePath,
    title: root.querySelector("title")?.text.trim() ?? null,
    head: headMetadata(root),
    h1: root.querySelector("h1")?.text.trim().replace(/\s+/g, " ") ?? null,
    sections,
    ids,
    css: css.sort(),
    js: js.sort(),
    images: images.sort(),
    links_internal: links_internal.sort(),
    links_anchor: links_anchor.sort(),
    links_external: links_external.sort(),
    inline_scripts: {
      count: inlineScripts.length,
      bytes: inlineScripts.reduce((a, b) => a + b, 0),
    },
  };
}

function headMetadata(root: HTMLElement): HeadMetadata {
  const meta = (name: string): string | null =>
    root
      .querySelector(`meta[name="${name}"]`)
      ?.getAttribute("content")
      ?.trim() ?? null;

  const og: Record<string, string> = {};
  for (const el of root.querySelectorAll("meta[property]")) {
    const prop = el.getAttribute("property") ?? "";
    if (prop.startsWith("og:"))
      og[prop] = el.getAttribute("content")?.trim() ?? "";
  }

  return {
    lang: root.querySelector("html")?.getAttribute("lang") ?? null,
    description: meta("description"),
    canonical:
      root
        .querySelector('link[rel="canonical"]')
        ?.getAttribute("href")
        ?.trim() ?? null,
    robots: meta("robots"),
    og: Object.fromEntries(
      Object.entries(og).sort(([a], [b]) => a.localeCompare(b)),
    ),
  };
}

function collectForms(
  root: HTMLElement,
  pagePath: string,
  out: FormEntry[],
): void {
  for (const form of root.querySelectorAll("form")) {
    const labelFor = new Map<string, string>();
    for (const label of form.querySelectorAll("label[for]")) {
      labelFor.set(
        label.getAttribute("for") ?? "",
        label.text.trim().replace(/\s+/g, " "),
      );
    }
    const fields: FormField[] = form
      .querySelectorAll("input[name], textarea[name], select[name]")
      .map((f) => {
        const type =
          f.tagName === "INPUT"
            ? (f.getAttribute("type") ?? "text")
            : f.tagName.toLowerCase();
        const field: FormField = {
          name: f.getAttribute("name") ?? "",
          type,
          required: f.hasAttribute("required"),
          label:
            labelFor.get(f.getAttribute("id") ?? "") ??
            nearestLabelText(f) ??
            null,
        };
        if (f.tagName === "SELECT") {
          field.options = f
            .querySelectorAll("option")
            .map((o) => o.getAttribute("value") ?? o.text.trim());
        }
        return field;
      })
      .sort((a, b) => a.name.localeCompare(b.name));

    const action = form.getAttribute("action") ?? null;
    const submit = form.querySelector(
      'button[type="submit"], input[type="submit"], button:not([type])',
    );
    // input[type=submit] は value に、button は中身に文字が入る
    const submitLabel = (
      submit?.getAttribute("value") ??
      submit?.text ??
      ""
    )
      .trim()
      .replace(/\s+/g, " ");

    out.push({
      page: pagePath,
      selector: selectorOf(form),
      action,
      method: (form.getAttribute("method") ?? "get").toLowerCase(),
      external_action: action !== null && isExternal(action),
      has_file_upload: form.querySelectorAll('input[type="file"]').length > 0,
      submit_label: submitLabel === "" ? null : submitLabel,
      fields,
    });
  }
}

/** label が for を持たない場合、祖先 label のテキストを使う（checkbox パターン） */
function nearestLabelText(el: HTMLElement): string | null {
  let cur: HTMLElement | null = el.parentNode;
  for (let depth = 0; cur && depth < 3; depth++) {
    if (cur.tagName === "LABEL")
      return cur.text.trim().replace(/\s+/g, " ").slice(0, 80);
    cur = cur.parentNode;
  }
  return null;
}

/** 同一タグ+クラスの子が 3 つ以上並ぶコンテナ = 繰り返し構造（コレクション化候補） */
function collectRepeats(
  root: HTMLElement,
  pagePath: string,
  out: RepeatEntry[],
  ctx: PageContext,
): void {
  const seen = new Set<string>();
  for (const el of root.querySelectorAll("*")) {
    const children = el.childNodes.filter(
      (c): c is HTMLElement => c instanceof HTMLElement,
    );
    if (children.length < 3) continue;
    const sig = (c: HTMLElement) =>
      `${c.tagName}.${(c.getAttribute("class") ?? "").trim()}`;
    const groups = new Map<string, number>();
    for (const c of children) groups.set(sig(c), (groups.get(sig(c)) ?? 0) + 1);
    for (const [itemSig, count] of groups) {
      // クラス無し要素の羅列（p の連続等）はノイズなのでクラス付きのみ採用
      if (count >= 3 && itemSig.split(".")[1]) {
        const key = `${pagePath}|${selectorOf(el)}|${itemSig}`;
        if (seen.has(key)) continue;
        seen.add(key);
        const itemEls = children.filter((c) => sig(c) === itemSig);
        const items = itemEls.map((c) => extractItem(c, pagePath));
        // コンテンツ繰り返し（テキストを持つ）の代表画像だけが Media 候補。
        // テキストを持たない繰り返し（sticker / 装飾）や付随画像は Static Design Asset
        const isContent = items.some(
          (i) => i.heading !== null || i.link_text !== null || i.text !== null,
        );
        const representative = new Set(
          items.map((i) => i.image).filter((v): v is string => v !== null),
        );
        for (const c of itemEls) {
          for (const img of c.querySelectorAll("img[src]")) {
            const src = img.getAttribute("src");
            if (!src || isExternal(src) || src.startsWith("data:")) continue;
            const path = normalizeRef(pagePath, src);
            if (isContent && representative.has(path))
              ctx.contentImages.set(path, itemSig);
            else if (isContent) ctx.auxiliaryImages.set(path, itemSig);
            else ctx.decorativeImages.set(path, itemSig);
          }
        }
        out.push({
          page: pagePath,
          container: selectorOf(el),
          item: itemSig,
          count,
          items,
        });
      }
    }
  }
}

/** 繰り返しアイテムから Plan / seed 生成に十分な内容を決定論的に抽出する */
function extractItem(el: HTMLElement, pagePath: string): RepeatItem {
  const text = (v: string | undefined | null, max: number): string | null => {
    const t = (v ?? "").trim().replace(/\s+/g, " ");
    return t ? t.slice(0, max) : null;
  };
  const heading = el.querySelector("h1, h2, h3, h4, h5, h6");
  const img = el.querySelector("img[src]");
  const a = el.querySelector("a[href]");
  const time = el.querySelector("time");
  const metaEl =
    el
      .querySelectorAll("*")
      .find((c) =>
        /(^|[\s-])meta([\s-]|$)/.test(c.getAttribute("class") ?? ""),
      ) ?? el.querySelector("p");
  const imgSrc = img?.getAttribute("src");
  return {
    heading: text(heading?.text, 120),
    image:
      imgSrc && !isExternal(imgSrc) && !imgSrc.startsWith("data:")
        ? normalizeRef(pagePath, imgSrc)
        : null,
    link: text(a?.getAttribute("href"), 200),
    link_text: text(a?.text, 120),
    time: text(time?.getAttribute("datetime") ?? time?.text, 40),
    meta: metaEl === heading ? null : text(metaEl?.text, 160),
    text: text(el.text, 160),
  };
}

function collectSharedCandidates(
  root: HTMLElement,
  pagePath: string,
  map: Map<
    string,
    { kind: string; selector: string; pages: string[]; bytes: number }
  >,
  ctx: PageContext,
): void {
  for (const tag of ["header", "footer", "nav", "aside", "section"]) {
    for (const el of root.querySelectorAll(tag)) {
      if (["header", "footer", "nav"].includes(tag)) {
        for (const img of el.querySelectorAll("img[src]")) {
          const src = img.getAttribute("src");
          if (src && !isExternal(src) && !src.startsWith("data:"))
            ctx.sharedImages.add(normalizeRef(pagePath, src));
        }
      }
      const normalized = el.outerHTML.replace(/\s+/g, " ").trim();
      if (normalized.length < 40) continue;
      const hash = createHash("sha256")
        .update(normalized)
        .digest("hex")
        .slice(0, 12);
      const entry = map.get(hash) ?? {
        kind: tag,
        selector: selectorOf(el),
        pages: [],
        bytes: normalized.length,
      };
      if (!entry.pages.includes(pagePath)) entry.pages.push(pagePath);
      map.set(hash, entry);
    }
  }
}

/** SPA mount point らしき空要素の検出（unsupported 判定の材料） */
function detectEmptyMounts(
  root: HTMLElement,
  pagePath: string,
  ctx: PageContext,
): void {
  for (const id of ["app", "root"]) {
    const el = root.querySelector(`div#${id}`);
    if (!el) continue;
    const hasElementChild = el.childNodes.some((c) => c instanceof HTMLElement);
    if (!hasElementChild && el.text.trim() === "") {
      ctx.emptyMountPages.push({ page: pagePath, selector: `div#${id}` });
    }
  }
}

// ---- helpers ----

const CONTENT_TAGS =
  "img, form, section, main, article, div, p, h1, h2, h3, h4, h5, h6, ul, ol, table, a, iframe, video";

/** CSS テキスト中の local url() 参照を抽出する（data: / 外部 URL は除く） */
function cssUrlRefs(cssText: string): string[] {
  const out: string[] = [];
  for (const m of cssText.matchAll(/url\(\s*['"]?([^'")]+?)['"]?\s*\)/g)) {
    const ref = m[1] ?? "";
    if (ref && !ref.startsWith("data:") && !/^(https?:)?\/\//.test(ref))
      out.push(ref);
  }
  return out;
}

/** 0 byte / 本文なしの実質空 HTML かどうか（ページ候補から除外する） */
function isEffectivelyEmpty(html: string, root: HTMLElement): boolean {
  if (html.trim() === "") return true;
  const body = root.querySelector("body") ?? root;
  if (body.querySelectorAll(CONTENT_TAGS).length > 0) return false;
  return body.text.trim() === "";
}

/** entry page（home 扱い）の決定。明示指定 > 単一ファイル対象 > 空でない index.html */
function resolveEntry(
  opts: AnalyzeOptions,
  pages: { path: string }[],
): string | null {
  const has = (p: string): boolean => pages.some((x) => x.path === p);
  if (opts.entry) {
    if (!has(opts.entry)) {
      throw new Error(
        `--entry ${opts.entry} が検出ページに存在しません（実質空の HTML はページ候補から除外されます）`,
      );
    }
    return opts.entry;
  }
  if (opts.onlyPage && has(opts.onlyPage)) return opts.onlyPage;
  return has("index.html") ? "index.html" : null;
}

function walk(dir: string, base: string = dir): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    if (SKIP_DIRS.has(name) || name.startsWith(".")) continue;
    const full = join(dir, name);
    const st = statSync(full);
    if (st.isDirectory()) out.push(...walk(full, base));
    else out.push(relative(base, full).split(sep).join("/"));
  }
  return out;
}

function extOf(path: string): string {
  const i = path.lastIndexOf(".");
  return i === -1 ? "" : path.slice(i).toLowerCase();
}

function isExternal(ref: string): boolean {
  return /^(https?:)?\/\//.test(ref);
}

/** ページからの相対参照を source ルート相対へ正規化する */
function normalizeRef(fromFile: string, ref: string): string {
  const clean = ref.replace(/^\.\//, "");
  return resolveRelative(fromFile, clean) ?? clean.split("?")[0] ?? clean;
}

function classifyRef(
  ref: string | undefined,
  pagePath: string,
  local: string[],
  externalMap: Map<string, ExternalDep>,
  kind: ExternalKind,
): void {
  if (!ref) return;
  if (isExternal(ref)) addExternal(externalMap, ref, kind, pagePath);
  else if (!ref.startsWith("data:"))
    pushUnique(local, normalizeRef(pagePath, ref));
}

function categorize(url: string): {
  category: ExternalCategory;
  library: string | null;
} {
  if (/fonts\.(googleapis|gstatic)\.com/.test(url))
    return { category: "google-fonts", library: null };
  if (/use\.typekit\.net|fonts\.adobe\.com/.test(url))
    return { category: "adobe-fonts", library: null };
  if (/challenges\.cloudflare\.com/.test(url))
    return { category: "turnstile", library: null };
  if (/googletagmanager|google-analytics|gtag/.test(url))
    return { category: "analytics", library: null };
  if (/maps\.google|google\.com\/maps/.test(url))
    return { category: "maps", library: null };
  const cdn = /(?:cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com)/.test(
    url,
  );
  if (cdn) {
    const stripped = url.replace(
      /^.*?(cdn\.jsdelivr\.net\/npm\/|unpkg\.com\/|cdnjs\.cloudflare\.com\/ajax\/libs\/)/,
      "",
    );
    const lib =
      stripped === url
        ? null
        : (/^(@[a-z0-9._-]+\/[a-z0-9._-]+|[a-z0-9._-]+)/i.exec(stripped)?.[1] ??
          null);
    return { category: "cdn-script", library: lib };
  }
  if (/youtube\.com|vimeo\.com|maps/.test(url))
    return { category: "embed", library: null };
  return { category: "unknown", library: null };
}

function addExternal(
  map: Map<string, ExternalDep>,
  url: string,
  kind: ExternalKind,
  page: string,
): void {
  const entry = map.get(url) ?? {
    url,
    kind,
    ...categorize(url),
    pages: [] as string[],
  };
  if (!entry.pages.includes(page)) entry.pages.push(page);
  map.set(url, entry);
}

function pushUnique(arr: string[], v: string): void {
  if (!arr.includes(v)) arr.push(v);
}

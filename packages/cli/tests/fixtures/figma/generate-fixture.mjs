#!/usr/bin/env node
/**
 * Synthetic Figma Fixture generator（SPEC §83–84）。
 * 匿名の合成デザイン「Northwind Studio」の Figma API レスポンス一式を生成する。
 * 実データ・実 client 情報は一切含まない。決定論的（時刻・乱数なし）。
 *
 * 実行: node packages/cli/tests/fixtures/figma/generate-fixture.mjs
 * 出力: meta.json / file.json / image-fills.json / images/*.png / renders/*.png
 */
import { deflateSync } from "node:zlib";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const OUT = dirname(fileURLToPath(import.meta.url));

// ---- palette ----
const ACCENT = { r: 15 / 255, g: 76 / 255, b: 70 / 255, a: 1 }; // #0f4c46（既存 token と MATCH）
const TEXT = { r: 34 / 255, g: 34 / 255, b: 34 / 255, a: 1 }; // #222222（既存 color-text と CONFLICT）
const MUTED = { r: 107 / 255, g: 111 / 255, b: 118 / 255, a: 1 }; // #6b6f76（INFERRED）
const WHITE = { r: 1, g: 1, b: 1, a: 1 };
const SUN = { r: 242 / 255, g: 183 / 255, b: 5 / 255, a: 1 }; // #f2b705（NEW CANDIDATE）

const S = (styleId) => ({ fill: styleId });

let bboxX = 0;
function box(w, h) {
  // 決定論的なダミー座標（x は連番。normalizer は絶対座標を manifest へ持ち込まない）
  bboxX += 10;
  return { x: bboxX, y: 0, width: w, height: h };
}

function text(id, name, chars, opts = {}) {
  return {
    id,
    name,
    type: "TEXT",
    characters: chars,
    absoluteBoundingBox: box(opts.w ?? 200, opts.h ?? 24),
    fills: [{ type: "SOLID", color: opts.color ?? TEXT }],
    style: {
      fontFamily: opts.family ?? "Inter",
      fontWeight: opts.weight ?? 400,
      fontSize: opts.size ?? 16,
      lineHeightPx: opts.lineHeight ?? (opts.size ?? 16) * 1.5,
      letterSpacing: 0,
    },
    styles: opts.styles,
  };
}

function imageRect(id, name, ref, w, h) {
  return {
    id,
    name,
    type: "RECTANGLE",
    absoluteBoundingBox: box(w, h),
    fills: [{ type: "IMAGE", imageRef: ref, scaleMode: "FILL" }],
  };
}

function vector(id, name, w, h, color, styles) {
  return {
    id,
    name,
    type: "VECTOR",
    absoluteBoundingBox: box(w, h),
    fills: [{ type: "SOLID", color }],
    styles,
  };
}

function autoFrame(id, name, mode, children, opts = {}) {
  return {
    id,
    name,
    type: opts.type ?? "FRAME",
    layoutMode: mode,
    itemSpacing: opts.gap ?? 24,
    paddingTop: opts.pt ?? 0,
    paddingRight: opts.pr ?? 0,
    paddingBottom: opts.pb ?? 0,
    paddingLeft: opts.pl ?? 0,
    layoutWrap: opts.wrap ? "WRAP" : undefined,
    absoluteBoundingBox: box(opts.w ?? 800, opts.h ?? 400),
    fills: opts.fill ? [{ type: "SOLID", color: opts.fill }] : [],
    styles: opts.styles,
    cornerRadius: opts.radius,
    effects: opts.shadow
      ? [
          {
            type: "DROP_SHADOW",
            visible: true,
            color: { r: 0, g: 0, b: 0, a: 0.12 },
            offset: { x: 0, y: 4 },
            radius: 16,
          },
        ]
      : [],
    children,
  };
}

// ---- components ----

function headerComponent(idPrefix) {
  return autoFrame(
    `${idPrefix}:1`,
    "Header",
    "HORIZONTAL",
    [
      vector(`${idPrefix}:2`, "Logo", 120, 32, ACCENT, S("S:1")),
      autoFrame(
        `${idPrefix}:3`,
        "Nav",
        "HORIZONTAL",
        [
          text(`${idPrefix}:4`, "Nav Link", "Home"),
          text(`${idPrefix}:5`, "Nav Link", "Works"),
          text(`${idPrefix}:6`, "Nav Link", "About"),
          text(`${idPrefix}:7`, "Nav Link", "Contact"),
        ],
        { gap: 24, w: 400, h: 32 },
      ),
    ],
    { type: "COMPONENT", gap: 48, pt: 24, pb: 24, pl: 48, pr: 48, w: 1440, h: 88, fill: WHITE },
  );
}

function footerComponent(idPrefix) {
  return autoFrame(
    `${idPrefix}:1`,
    "Footer",
    "VERTICAL",
    [
      text(`${idPrefix}:2`, "Footer Text", "Northwind Studio", { color: WHITE }),
      text(`${idPrefix}:3`, "Copyright", "© 2026 Northwind Studio", {
        size: 14,
        color: WHITE,
      }),
    ],
    { type: "COMPONENT", gap: 16, pt: 48, pb: 48, pl: 48, pr: 48, w: 1440, h: 180, fill: TEXT, styles: S("S:2") },
  );
}

function buttonComponent(id, variantName, primary) {
  return autoFrame(
    id,
    variantName,
    "HORIZONTAL",
    [
      text(`${id.replace(":", "")}:t`, "Label", "Button", {
        color: primary ? WHITE : ACCENT,
        weight: 700,
        size: 16,
      }),
    ],
    {
      type: "COMPONENT",
      gap: 8,
      pt: 12,
      pb: 12,
      pl: 24,
      pr: 24,
      w: 160,
      h: 48,
      radius: 8,
      fill: primary ? ACCENT : WHITE,
      styles: primary ? S("S:1") : undefined,
    },
  );
}

function worksCardComponent(idPrefix) {
  return autoFrame(
    `${idPrefix}:10`,
    "Works Card",
    "VERTICAL",
    [
      imageRect(`${idPrefix}:11`, "Thumb", "imgref-card", 400, 260),
      text(`${idPrefix}:12`, "Title", "Works Title", { weight: 700, size: 20 }),
      text(`${idPrefix}:13`, "Date", "2026.01.01", { size: 14, color: MUTED }),
      text(`${idPrefix}:14`, "Category", "Category", { size: 12, color: MUTED }),
    ],
    { type: "COMPONENT", gap: 16, w: 400, h: 380, radius: 12, fill: WHITE, shadow: true },
  );
}

// ---- instances ----

function headerInstance(id) {
  const c = headerComponent("h");
  return { ...c, id, type: "INSTANCE", componentId: "100:1", name: "Header" };
}

function footerInstance(id) {
  const c = footerComponent("f");
  return { ...c, id, type: "INSTANCE", componentId: "100:2", name: "Footer" };
}

function buttonInstance(id, type, label) {
  const primary = type === "Primary";
  const c = buttonComponent(`bi${id}`, `Type=${type}`, primary);
  return {
    ...c,
    id,
    type: "INSTANCE",
    componentId: primary ? "300:2" : "300:3",
    name: "Button",
    componentProperties: { Type: { value: type, type: "VARIANT" } },
    children: [
      text(`${id}t`, "Label", label, {
        color: primary ? WHITE : ACCENT,
        weight: 700,
        size: 16,
      }),
    ],
  };
}

function worksCardInstance(id, title, date, category, ref) {
  const c = worksCardComponent(`wc${id}`);
  return {
    ...c,
    id,
    type: "INSTANCE",
    componentId: "200:10",
    name: "Works Card",
    children: [
      imageRect(`${id}i`, "Thumb", ref, 400, 260),
      text(`${id}t`, "Title", title, { weight: 700, size: 20 }),
      text(`${id}d`, "Date", date, { size: 14, color: MUTED }),
      text(`${id}c`, "Category", category, { size: 12, color: MUTED }),
    ],
  };
}

function feature(id, iconName, title, body) {
  return autoFrame(
    id,
    "Feature",
    "VERTICAL",
    [
      vector(`${id}v`, iconName, 32, 32, ACCENT, S("S:1")),
      text(`${id}t`, "Feature Title", title, { weight: 700, size: 20 }),
      text(`${id}b`, "Feature Body", body, { color: MUTED, styles: { text: "S:4" } }),
    ],
    { gap: 16, w: 420, h: 220 },
  );
}

// ---- screens ----

function heroSection(idPrefix, vertical) {
  return autoFrame(
    `${idPrefix}:2`,
    "Hero",
    vertical ? "VERTICAL" : "HORIZONTAL",
    [
      autoFrame(
        `${idPrefix}:3`,
        "Hero Copy",
        "VERTICAL",
        [
          text(`${idPrefix}:4`, "Hero Title", "Design that ships", {
            family: "Clash Display",
            weight: 600,
            size: 48,
            styles: S("S:2"),
          }),
          text(
            `${idPrefix}:5`,
            "Hero Lead",
            "Northwind Studio は小さなチームの Web 制作スタジオです。",
            { color: MUTED, styles: { text: "S:4" } },
          ),
          buttonInstance(`${idPrefix}:6`, "Primary", "View Works"),
        ],
        { gap: 24, w: 560, h: 320 },
      ),
      imageRect(`${idPrefix}:7`, "Hero Image", "imgref-hero", 640, 480),
      vector(`${idPrefix}:8`, "Deco Blob", 320, 320, SUN, S("S:5")),
    ],
    { gap: 48, pt: 96, pb: 96, pl: 96, pr: 96, w: 1440, h: 640, fill: WHITE },
  );
}

function worksSection(idPrefix, cards) {
  return autoFrame(
    `${idPrefix}:30`,
    "Works",
    "VERTICAL",
    [
      text(`${idPrefix}:31`, "Works Heading", "Works", {
        weight: 700,
        size: 32,
        styles: { text: "S:3" },
      }),
      autoFrame(`${idPrefix}:32`, "Works Grid", "HORIZONTAL", cards, {
        gap: 24,
        wrap: true,
        w: 1300,
        h: 800,
      }),
    ],
    { gap: 24, pt: 48, pb: 96, pl: 48, pr: 48, w: 1440, h: 980 },
  );
}

function ctaSection(idPrefix) {
  return autoFrame(
    `${idPrefix}:50`,
    "CTA",
    "VERTICAL",
    [
      text(`${idPrefix}:51`, "CTA Title", "Let's build together", {
        weight: 700,
        size: 32,
        color: WHITE,
      }),
      buttonInstance(`${idPrefix}:52`, "Secondary", "Contact"),
    ],
    { gap: 24, pt: 96, pb: 96, pl: 48, pr: 48, w: 1440, h: 360, fill: ACCENT, styles: S("S:1") },
  );
}

const worksData = [
  ["WORKS A", "2026.04.01", "Branding", "imgref-w1"],
  ["WORKS B", "2026.03.10", "Web", "imgref-w2"],
  ["WORKS C", "2026.02.22", "Branding", "imgref-w3"],
  ["WORKS D", "2026.01.15", "Web", "imgref-w4"],
  ["WORKS E", "2025.12.02", "Branding", "imgref-w5"],
  ["WORKS F", "2025.11.20", "Web", "imgref-w6"],
];

function homeScreen(id, idPrefix, name, width, vertical) {
  return {
    ...autoFrame(
      id,
      name,
      "VERTICAL",
      [
        headerInstance(`${idPrefix}:1`),
        heroSection(idPrefix, vertical),
        autoFrame(
          `${idPrefix}:10`,
          "Features",
          vertical ? "VERTICAL" : "HORIZONTAL",
          [
            feature(`${idPrefix}:11`, "Icon Spark", "Fast", "小さく作ってすぐ出す。"),
            feature(`${idPrefix}:15`, "Icon Shield", "Solid", "壊れない土台を作る。"),
            feature(`${idPrefix}:19`, "Icon Leaf", "Clear", "運用しやすく引き継ぐ。"),
          ],
          { gap: 24, pt: 48, pb: 48, pl: 48, pr: 48, w: 1440, h: 320 },
        ),
        worksSection(
          idPrefix,
          worksData
            .slice(0, 3)
            .map(([t, d, c, ref], i) =>
              worksCardInstance(`${idPrefix}:${33 + i * 5}`, t, d, c, ref),
            ),
        ),
        ctaSection(idPrefix),
        footerInstance(`${idPrefix}:60`),
      ],
      { gap: 0, w: width, h: vertical ? 3400 : 2600, fill: WHITE },
    ),
    absoluteBoundingBox: { x: 0, y: 0, width, height: vertical ? 3400 : 2600 },
  };
}

function worksScreen(id, idPrefix, name, width, vertical) {
  return {
    ...autoFrame(
      id,
      name,
      "VERTICAL",
      [
        headerInstance(`${idPrefix}:1`),
        worksSection(
          idPrefix,
          worksData.map(([t, d, c, ref], i) =>
            worksCardInstance(`${idPrefix}:${33 + i * 5}`, t, d, c, ref),
          ),
        ),
        footerInstance(`${idPrefix}:60`),
      ],
      { gap: 0, w: width, h: vertical ? 2600 : 2000, fill: WHITE },
    ),
    absoluteBoundingBox: { x: 0, y: 0, width, height: vertical ? 2600 : 2000 },
  };
}

// ---- document ----

const document_ = {
  id: "0:0",
  name: "Document",
  type: "DOCUMENT",
  children: [
    {
      id: "0:1",
      name: "Pages",
      type: "CANVAS",
      children: [
        homeScreen("1:2", "10", "Home / Desktop", 1440, false),
        homeScreen("1:3", "11", "Home / Mobile", 375, true),
        worksScreen("1:4", "12", "Works / Desktop", 1440, false),
        worksScreen("1:5", "13", "Works / Mobile", 375, true),
      ],
    },
    {
      id: "0:2",
      name: "Components",
      type: "CANVAS",
      children: [
        headerComponent("100"),
        { ...footerComponent("101"), id: "100:2" },
        {
          id: "300:1",
          name: "Button",
          type: "COMPONENT_SET",
          absoluteBoundingBox: box(360, 80),
          children: [
            { ...buttonComponent("300:2", "Type=Primary", true) },
            { ...buttonComponent("300:3", "Type=Secondary", false) },
          ],
        },
        worksCardComponent("200"),
      ],
    },
  ],
};

const file = {
  document: document_,
  components: {
    "100:1": { key: "key-header-0001", name: "Header" },
    "100:2": { key: "key-footer-0001", name: "Footer" },
    "300:2": { key: "key-button-primary", name: "Type=Primary", componentSetId: "300:1" },
    "300:3": { key: "key-button-secondary", name: "Type=Secondary", componentSetId: "300:1" },
    "200:10": { key: "key-works-card", name: "Works Card" },
  },
  componentSets: {
    "300:1": { key: "key-button-set", name: "Button" },
  },
  styles: {
    "S:1": { key: "sk1", name: "Color/Accent", styleType: "FILL" },
    "S:2": { key: "sk2", name: "Color/Text", styleType: "FILL" },
    "S:3": { key: "sk3", name: "Type/Heading M", styleType: "TEXT" },
    "S:4": { key: "sk4", name: "Type/Body", styleType: "TEXT" },
    "S:5": { key: "sk5", name: "Brand/Sun", styleType: "FILL" },
  },
  name: "Northwind Studio Site",
  version: "2001",
  lastModified: "2026-08-01T09:00:00Z",
  editorType: "figma",
};

const meta = {
  name: "Northwind Studio Site",
  folder_name: null,
  last_touched_at: "2026-08-01T09:00:00Z",
  thumbnail_url: "",
  editorType: "figma",
  version: "2001",
  role: "viewer",
  link_access: "inherit",
};

const imageFills = {
  meta: {
    images: {
      "imgref-hero": "fixture://images/hero.png",
      "imgref-card": "fixture://images/card.png",
      "imgref-w1": "fixture://images/works-a.png",
      "imgref-w2": "fixture://images/works-b.png",
      "imgref-w3": "fixture://images/works-c.png",
      "imgref-w4": "fixture://images/works-d.png",
      "imgref-w5": "fixture://images/works-e.png",
      "imgref-w6": "fixture://images/works-f.png",
    },
  },
};

// ---- minimal PNG encoder（単色画像。依存なし・決定論） ----

function crc32(buf) {
  let c;
  const table = [];
  for (let n = 0; n < 256; n++) {
    c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  let crc = 0xffffffff;
  for (const b of buf) crc = table[(crc ^ b) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, "ascii"), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
}

function png(width, height, [r, g, b]) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // color type RGB
  const raw = Buffer.alloc(height * (1 + width * 3));
  for (let y = 0; y < height; y++) {
    const rowStart = y * (1 + width * 3);
    raw[rowStart] = 0;
    for (let x = 0; x < width; x++) {
      const p = rowStart + 1 + x * 3;
      raw[p] = r;
      raw[p + 1] = g;
      raw[p + 2] = b;
    }
  }
  return Buffer.concat([
    sig,
    chunk("IHDR", ihdr),
    chunk("IDAT", deflateSync(raw, { level: 9 })),
    chunk("IEND", Buffer.alloc(0)),
  ]);
}

// ---- write ----

function writeJson(name, value) {
  writeFileSync(join(OUT, name), `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

writeJson("meta.json", meta);
writeJson("file.json", file);
writeJson("image-fills.json", imageFills);

mkdirSync(join(OUT, "images"), { recursive: true });
// Figma node 実寸の 2x で生成する（実運用で Image API を scale=2 で叩くのと同じ前提。
// 低解像度だと mobile DPR で Lighthouse image-size-responsive を落とす）
const IMAGES = {
  "hero.png": [1280, 960, [15, 76, 70]],
  "card.png": [800, 520, [227, 228, 226]],
  "works-a.png": [800, 520, [242, 183, 5]],
  "works-b.png": [800, 520, [15, 76, 70]],
  "works-c.png": [800, 520, [107, 111, 118]],
  "works-d.png": [800, 520, [34, 34, 34]],
  "works-e.png": [800, 520, [200, 90, 60]],
  "works-f.png": [800, 520, [60, 120, 200]],
};
for (const [name, [w, h, color]] of Object.entries(IMAGES)) {
  writeFileSync(join(OUT, "images", name), png(w, h, color));
}

mkdirSync(join(OUT, "renders"), { recursive: true });
const RENDERS = {
  "1-2.png": [144, 260, [246, 246, 244]],
  "1-3.png": [76, 680, [246, 246, 244]],
  "1-4.png": [144, 200, [255, 255, 255]],
  "1-5.png": [76, 520, [255, 255, 255]],
};
for (const [name, [w, h, color]] of Object.entries(RENDERS)) {
  writeFileSync(join(OUT, "renders", name), png(w, h, color));
}

console.log(`fixture generated in ${OUT}`);

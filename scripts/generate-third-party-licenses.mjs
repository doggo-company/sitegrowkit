#!/usr/bin/env node
/**
 * THIRD-PARTY-LICENSES.md の依存テーブル生成 / currency 検証（Phase 8 Stage L）。
 *
 * 実行:
 *   node scripts/generate-third-party-licenses.mjs           … AUTO-GENERATED ブロックを再生成して書き込む
 *   node scripts/generate-third-party-licenses.mjs --check   … 再生成結果と現在のファイルを比較（drift で exit 1）
 *
 * データ源（すべて git tracked）:
 *   - template/composer.lock          … Composer 依存の name / version / license（lock が license を記録する）
 *   - template/package.json           … npm 依存の name / specifier
 *   - packages/cli/package.json       … CLI 依存の name / specifier
 *
 * npm の license 値は lock に記録されないため、解決順は:
 *   node_modules の実メタデータ → 既存ファイルに記録済みの値 → 既知の platform variant → UNKNOWN
 * UNKNOWN が残ると --check が失敗する（依存追加時に必ず棚卸しを更新させるための仕組み）。
 * このスクリプトは license 名の記録のみを行い、法的判断はしない。
 */
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const DOC_PATH = join(repoRoot, "THIRD-PARTY-LICENSES.md");
const START = "<!-- AUTO-GENERATED:DEPS START — edit via scripts/generate-third-party-licenses.mjs, not by hand -->";
const END = "<!-- AUTO-GENERATED:DEPS END -->";
const checkMode = process.argv.includes("--check");

const readJson = (p) => JSON.parse(readFileSync(join(repoRoot, p), "utf8"));

/** platform variant は同一 family の上流 metadata と同じ license（インストールされないため直接読めない） */
const PLATFORM_VARIANT_LICENSES = {
  "@rollup/rollup-linux-x64-gnu": "MIT",
  "@rollup/rollup-win32-x64-msvc": "MIT",
  "@tailwindcss/oxide-linux-x64-gnu": "MIT",
  "@tailwindcss/oxide-win32-x64-msvc": "MIT",
  "lightningcss-linux-x64-gnu": "MPL-2.0",
  "lightningcss-win32-x64-msvc": "MPL-2.0",
};

const normalizeLicense = (l) => {
  if (typeof l === "string") return l;
  if (Array.isArray(l)) return l.map((x) => x?.type ?? x).join(" / ");
  if (l && typeof l === "object" && l.type) return l.type;
  return null;
};

/** 既存ファイルの生成ブロックから「section|name → license」を回収（node_modules 不在環境での fallback） */
function recordedLicenses() {
  const map = new Map();
  if (!existsSync(DOC_PATH)) return map;
  const doc = readFileSync(DOC_PATH, "utf8");
  const s = doc.indexOf(START);
  const e = doc.indexOf(END);
  if (s === -1 || e === -1) return map;
  let section = "";
  for (const line of doc.slice(s, e).split("\n")) {
    const h = line.match(/^### (.+)$/);
    if (h) section = h[1];
    const row = line.match(/^\| `([^`]+)` \| [^|]+ \| ([^|]+) \|$/);
    if (row) map.set(`${section}|${row[1]}`, row[2].trim());
  }
  return map;
}

function npmLicense(name, baseDir, section, recorded) {
  const pkgPath = join(repoRoot, baseDir, "node_modules", name, "package.json");
  if (existsSync(pkgPath)) {
    const meta = JSON.parse(readFileSync(pkgPath, "utf8"));
    const l = normalizeLicense(meta.license ?? meta.licenses);
    if (l) return l;
  }
  return (
    recorded.get(`${section}|${name}`) ??
    PLATFORM_VARIANT_LICENSES[name] ??
    "UNKNOWN"
  );
}

const table = (rows) =>
  [
    "| Package | Version | License |",
    "| --- | --- | --- |",
    ...rows
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([n, v, l]) => `| \`${n}\` | ${v} | ${l} |`),
  ].join("\n");

function composerRows(pkgs) {
  return pkgs
    .filter((p) => !p.name.startsWith("agentcms/"))
    .map((p) => [p.name, p.version, (p.license ?? ["UNKNOWN"]).join(" / ")]);
}

function npmRows(deps, baseDir, section, recorded) {
  return Object.entries(deps ?? {}).map(([name, spec]) => [
    name,
    spec,
    npmLicense(name, baseDir, section, recorded),
  ]);
}

function generateBlock() {
  const recorded = recordedLicenses();
  const composerLock = readJson("template/composer.lock");
  const cliPkg = readJson("packages/cli/package.json");
  const tplPkg = readJson("template/package.json");

  const sections = [
    [
      "Composer — runtime（template/composer.lock）",
      table(composerRows(composerLock.packages)),
      "利用者の `composer install`（`agentcms dev` が実行）で取得される実行時依存。`agentcms/core` は first-party（本キット自体・GPL-3.0-or-later）のため除外。",
    ],
    [
      "Composer — dev（template/composer.lock）",
      table(composerRows(composerLock["packages-dev"])),
      "開発・検証時（`agentcms test` / `verify`）のみに使われる依存。配布物・本番成果物には含まれない。",
    ],
    [
      "npm — CLI runtime（packages/cli/package.json）",
      table(npmRows(cliPkg.dependencies, "packages/cli", "npm — CLI runtime（packages/cli/package.json）", recorded)),
      "`npm install -g` 時に取得される CLI の実行時依存。",
    ],
    [
      "npm — CLI dev（packages/cli/package.json）",
      table(npmRows(cliPkg.devDependencies, "packages/cli", "npm — CLI dev（packages/cli/package.json）", recorded)),
      "Kit 開発時のみ。利用者環境にはインストールされない。",
    ],
    [
      "npm — template runtime/build（template/package.json dependencies）",
      table(npmRows(tplPkg.dependencies, "template", "npm — template runtime/build（template/package.json dependencies）", recorded)),
      "生成プロジェクトの `pnpm install` で取得され、ビルド成果物（管理画面 JS 等）に含まれ得る依存。",
    ],
    [
      "npm — template optional（platform binaries）",
      table(npmRows(tplPkg.optionalDependencies, "template", "npm — template optional（platform binaries）", recorded)),
      "プラットフォーム別のビルド用バイナリ。ビルド時のみ使用され、成果物には含まれない。",
    ],
    [
      "npm — template dev（template/package.json devDependencies）",
      table(npmRows(tplPkg.devDependencies, "template", "npm — template dev（template/package.json devDependencies）", recorded)),
      "生成プロジェクトの開発・検証時のみ。ビルド成果物には含まれない。",
    ],
  ];

  return [
    START,
    "",
    ...sections.flatMap(([title, tbl, note]) => [
      `### ${title}`,
      "",
      note,
      "",
      tbl,
      "",
    ]),
    END,
  ].join("\n");
}

const block = generateBlock();

const unknowns = [...block.matchAll(/^\| `([^`]+)` \| [^|]+ \| UNKNOWN \|$/gm)];
if (unknowns.length > 0) {
  console.error("✖ license を解決できない依存があります（node_modules を install して再生成し、必要なら手動確認で記録してください）:");
  for (const [, name] of unknowns) console.error(`  - ${name}`);
  process.exit(1);
}

if (!existsSync(DOC_PATH)) {
  console.error(
    "✖ THIRD-PARTY-LICENSES.md がありません。手書きセクションを含む初期ファイルを作成してから再実行してください",
  );
  process.exit(1);
}

const doc = readFileSync(DOC_PATH, "utf8");
const s = doc.indexOf(START);
const e = doc.indexOf(END);
if (s === -1 || e === -1) {
  console.error("✖ THIRD-PARTY-LICENSES.md に AUTO-GENERATED マーカーがありません");
  process.exit(1);
}
const current = doc.slice(s, e + END.length);
const next = doc.slice(0, s) + block + doc.slice(e + END.length);

if (checkMode) {
  if (current === block) {
    console.log("✔ THIRD-PARTY-LICENSES.md は依存セットと同期しています");
  } else {
    console.error(
      "✖ THIRD-PARTY-LICENSES.md が依存セットと乖離しています。`node scripts/generate-third-party-licenses.mjs` で再生成してください",
    );
    process.exit(1);
  }
} else {
  writeFileSync(DOC_PATH, next, "utf8");
  console.log("✔ THIRD-PARTY-LICENSES.md の依存テーブルを再生成しました");
}

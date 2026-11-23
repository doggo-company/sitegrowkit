#!/usr/bin/env node
/**
 * Agent CMS Kit — GUI 配布物（bundled runtime + launcher）の生成（Phase 8.5-D）。
 *
 * 実行: node scripts/package-gui.mjs [--platform macos|windows|all]
 *
 * 生成物（release/ 配下・gitignored。過去 RC は上書きしない）:
 *   agent-cms-kit-v<version>-macos-arm64.zip    … Agent CMS Kit.app（ダブルクリック起動）
 *   agent-cms-kit-v<version>-windows-x64.zip    … Agent CMS Kit.exe + WSL2 用 runtime
 *
 * 固定事項:
 *   - 同梱 Node runtime は runtime-manifest.json で version と SHA-256 を pin する
 *     （floating latest を使わない。checksum 不一致は FAIL）
 *   - 配布物内に integrity.json（SHA-256 一覧）を生成し、起動時の自己検査に使う
 *   - 利用者の PATH / global npm / host Node には一切依存しない
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  chmodSync,
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { normalizeBundleLinks } from "./lib/macos-bundle.mjs";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const releaseDir = join(repoRoot, "release");
const workDir = join(releaseDir, "work-gui-2.1");
const runtimeCache = join(releaseDir, "runtime-cache");

const fail = (msg) => {
  console.error(`\n✖ ${msg}`);
  process.exit(1);
};
const info = (msg) => console.log(`• ${msg}`);
const ok = (msg) => console.log(`✔ ${msg}`);

const run = (cmd, args, opts = {}) =>
  execFileSync(cmd, args, { encoding: "utf8", cwd: repoRoot, ...opts });

const version = readFileSync(join(repoRoot, "VERSION"), "utf8").trim();
const cliPkg = JSON.parse(
  readFileSync(join(repoRoot, "packages/cli/package.json"), "utf8"),
);
if (cliPkg.version !== version) {
  fail(`VERSION (${version}) と packages/cli/package.json (${cliPkg.version}) が一致しません`);
}

const manifest = JSON.parse(
  readFileSync(
    join(repoRoot, "packages/launcher/runtime/runtime-manifest.json"),
    "utf8",
  ),
);

const requested = (() => {
  const i = process.argv.indexOf("--platform");
  return i >= 0 ? process.argv[i + 1] : "all";
})();
if (!["macos", "windows", "all"].includes(requested)) fail("platform が不正です");
// 検証済み GUI アーカイブも暗黙に上書きしない。
for (const platform of requested === "all" ? ["macos", "windows"] : [requested]) {
  const arch = platform === "macos" ? "arm64" : "x64";
  if (existsSync(join(releaseDir, `sitegrowkit-desktop-2.1-${version}-${platform}-${arch}.zip`))) fail("GUI 配布物が既にあります。別の版として作成してください");
}

/* ---------------- 1. 同梱 Node runtime の取得と検証 ---------------- */

function sha256(file) {
  return createHash("sha256").update(readFileSync(file)).digest("hex");
}

function ensureRuntime(target) {
  mkdirSync(runtimeCache, { recursive: true });
  const archive = join(runtimeCache, target.file);
  if (!existsSync(archive)) {
    info(`Node runtime を取得します: ${target.file}`);
    run("curl", ["-sSfL", "-o", archive, target.url]);
  }
  const actual = sha256(archive);
  if (actual !== target.sha256) {
    fail(
      `Node runtime の checksum が一致しません: ${target.file}\n  expected ${target.sha256}\n  actual   ${actual}`,
    );
  }
  ok(`Node runtime checksum 一致: ${target.file}`);

  const extractDir = join(runtimeCache, `extracted-${target.platform}`);
  // 展開済みキャッシュを信用せず、照合済みアーカイブから毎回展開する。
  rmSync(extractDir, { recursive: true, force: true });
  mkdirSync(extractDir, { recursive: true });
  run("tar", ["-xf", archive, "-C", extractDir]);
  const inner = readdirSync(extractDir).find((d) => d.startsWith("node-"));
  if (!inner) fail(`Node runtime の展開に失敗しました: ${target.file}`);
  return join(extractDir, inner);
}

/* ---------------- 2. CLI アプリ本体（依存解決済み）の用意 ---------------- */

function buildAppTree() {
  const appStage = join(workDir, "app-stage");
  rmSync(appStage, { recursive: true, force: true });
  mkdirSync(appStage, { recursive: true });

  // CLI 配布物（package-release.mjs が生成した .tgz）を単一情報源として使う。
  // packages/cli から直接 npm pack すると template/（repo 直下）が入らないため、
  // 検証済みの release tgz を再利用して GUI と CLI で同一 engine を配る。
  const tgz = join(
    releaseDir,
    `agent-cms-kit-v${version}`,
    `agent-cms-kit-${version}.tgz`,
  );
  if (!existsSync(tgz)) {
    fail(
      `CLI 配布物が見つかりません: ${tgz}\n  先に \`node scripts/package-release.mjs\` を実行してください`,
    );
  }
  info(`CLI 配布物を再利用します: ${tgz.split("/").pop()}`);

  info("同梱用に production 依存を解決します");
  run("npm", ["install", "--omit=dev", "--prefix", appStage, tgz], {
    stdio: "ignore",
  });
  const installedModules = join(appStage, "node_modules");
  const pkgDir = join(installedModules, "agent-cms-kit");
  if (!existsSync(join(pkgDir, "dist/index.js"))) {
    fail("同梱用 CLI の生成に失敗しました（dist/index.js が無い）");
  }
  if (!existsSync(join(pkgDir, "template/artisan"))) {
    fail("同梱用 CLI に template/ が含まれていません（CLI 配布物を作り直してください）");
  }

  // 配布用の app ツリーを組み立てる:
  //   app/            … packaged CLI（dist / template / package.json）
  //   app/node_modules… production 依存（同階層の解決で完結させる）
  const appOut = join(workDir, "app");
  rmSync(appOut, { recursive: true, force: true });
  cpSync(pkgDir, appOut, { recursive: true, verbatimSymlinks: true });
  const depsOut = join(appOut, "node_modules");
  mkdirSync(depsOut, { recursive: true });
  for (const entry of readdirSync(installedModules)) {
    if (entry === "agent-cms-kit" || entry === ".package-lock.json") continue;
    cpSync(join(installedModules, entry), join(depsOut, entry), {
      recursive: true,
      verbatimSymlinks: true,
    });
  }
  // CLI を node_modules から app 直下へ移した後の相対位置に合わせる。
  for (const [name, target] of [["agentcms", "../../dist/index.js"], ["create-agent-cms", "../../dist/create-entry.js"]]) {
    const link = join(depsOut, ".bin", name);
    mkdirSync(dirname(link), { recursive: true });
    rmSync(link, { force: true });
    symlinkSync(target, link);
  }
  return { appDir: appOut, tgz };
}

/* ---------------- 3. integrity manifest ---------------- */

function collectFiles(root) {
  const out = [];
  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const abs = join(dir, entry.name);
      if (entry.isSymbolicLink()) continue;
      if (entry.isDirectory()) walk(abs);
      else if (entry.isFile()) out.push(abs);
    }
  };
  walk(root);
  return out;
}

/**
 * 起動時に検証する重要ファイルの一覧を作る。
 * 全ファイルを対象にすると起動が遅くなるため、
 * **改変されると動作・安全性に直結するもの**に絞る。
 */
function writeIntegrityManifest(baseDir, appRel, runtimeRel, nodeBinRel) {
  const targets = [];
  const push = (abs) => {
    if (!existsSync(abs)) return;
    targets.push({
      path: relative(baseDir, abs).split("\\").join("/"),
      sha256: sha256(abs),
      size: statSync(abs).size,
    });
  };

  // packaged CLI（engine 本体）
  for (const f of collectFiles(join(baseDir, appRel, "dist"))) push(f);
  for (const f of collectFiles(join(baseDir, "desktop"))) push(f);
  // bundled runtime の実行ファイル
  push(join(baseDir, nodeBinRel));
  // critical wizard assets
  for (const f of collectFiles(join(baseDir, appRel, "dist/wizard/assets")))
    push(f);

  const manifestPath = join(baseDir, "integrity.json");
  writeFileSync(
    manifestPath,
    `${JSON.stringify(
      { version: 1, distribution: `agent-cms-kit-${version}`, files: targets },
      null,
      2,
    )}\n`,
  );
  ok(`integrity.json を生成しました（${targets.length} ファイル）`);
  void runtimeRel;
}

/* ---------------- 4. platform 別の組み立て ---------------- */

function copyDocs(dest) {
  for (const f of [
    "README_FIRST.md",
    "LICENSE",
    "THIRD-PARTY-LICENSES.md",
    "CHANGELOG.md",
  ]) {
    const src = join(repoRoot, f);
    if (existsSync(src)) cpSync(src, join(dest, f));
  }
  cpSync(join(repoRoot, "docs"), join(dest, "docs"), { recursive: true });
  const guide = join(releaseDir, `README_FIRST-${version}.html`);
  if (existsSync(guide)) cpSync(guide, join(dest, "README_FIRST.html"));
  // GUI版の入口にCLIのインストール操作を案内しない。
  cpSync(join(repoRoot, "packages/launcher/README_FIRST.md"), join(dest, "README_FIRST.md"));
}

function buildMacos(appDir) {
  const target = manifest.node.targets.find((t) => t.platform === "macos");
  const runtimeSrc = ensureRuntime(target);

  const stage = join(workDir, "macos");
  rmSync(stage, { recursive: true, force: true });
  const appBundle = join(stage, "SiteGrowKit.app");
  const contents = join(appBundle, "Contents");
  mkdirSync(join(contents, "MacOS"), { recursive: true });
  mkdirSync(join(contents, "Resources"), { recursive: true });

  const plist = readFileSync(
    join(repoRoot, "packages/launcher/macos/Info.plist.template"),
    "utf8",
  ).replaceAll("__VERSION__", version);
  writeFileSync(join(contents, "Info.plist"), plist);

  const launcher = join(contents, "MacOS/agent-cms-kit");
  run("xcrun", ["swiftc", "-O", "-target", "arm64-apple-macosx13.0", join(repoRoot, "packages/launcher/macos/Launcher.swift"), "-o", launcher]);
  chmodSync(launcher, 0o755);

  const resources = join(contents, "Resources");
  // 小さい表示から Retina まで対応したブランドアイコンを同梱する。
  cpSync(join(repoRoot, "packages/launcher/macos/SiteGrowKit.icns"), join(resources, "SiteGrowKit.icns"));
  cpSync(appDir, join(resources, "app"), { recursive: true, verbatimSymlinks: true });
  cpSync(join(repoRoot, "packages/launcher/desktop"), join(resources, "desktop"), { recursive: true });
  cpSync(runtimeSrc, join(resources, "runtime"), { recursive: true, verbatimSymlinks: true });
  chmodSync(join(resources, "runtime/bin/node"), 0o755);
  // Node の LICENSE（同梱第三者ライセンス全文を含む）を verbatim で同梱
  cpSync(
    join(runtimeSrc, "LICENSE"),
    join(resources, "runtime/LICENSE-nodejs.txt"),
  );

  normalizeBundleLinks(appBundle);
  writeIntegrityManifest(resources, "app", "runtime", "runtime/bin/node");
  copyDocs(stage);

  const zip = join(releaseDir, `sitegrowkit-desktop-2.1-${version}-macos-arm64.zip`);
  rmSync(zip, { force: true });
  run("zip", ["-qry", zip, "."], { cwd: stage });
  return zip;
}

function buildWindows(appDir) {
  const target = manifest.node.targets.find((t) => t.platform === "windows");
  const runtimeSrc = ensureRuntime(target);

  const stage = join(workDir, "windows");
  rmSync(stage, { recursive: true, force: true });
  mkdirSync(stage, { recursive: true });

  const exe = join(repoRoot, "packages/launcher/windows/dist/Agent CMS Kit.exe");
  if (!existsSync(exe)) {
    fail(
      "Windows launcher（Agent CMS Kit.exe）がありません。packages/launcher/windows/README.md の手順でビルドしてください",
    );
  }
  cpSync(exe, join(stage, "SiteGrowKit.exe"));

  mkdirSync(join(stage, "launcher"), { recursive: true });
  const startSh = join(stage, "launcher/start.sh");
  cpSync(join(repoRoot, "packages/launcher/windows/start.sh"), startSh);
  chmodSync(startSh, 0o755);
  writeFileSync(
    join(stage, "launcher/RUNTIME_VERSION"),
    `${manifest.node.version}\n`,
  );

  cpSync(appDir, join(stage, "app"), { recursive: true, verbatimSymlinks: true });
  cpSync(join(repoRoot, "packages/launcher/desktop"), join(stage, "desktop"), { recursive: true });
  cpSync(runtimeSrc, join(stage, "runtime"), { recursive: true, verbatimSymlinks: true });
  cpSync(join(runtimeSrc, "LICENSE"), join(stage, "runtime/LICENSE-nodejs.txt"));

  // stage 済み runtime の検証に使う（start.sh が参照）
  writeFileSync(
    join(stage, "launcher/node.sha256"),
    `${sha256(join(stage, "runtime/bin/node"))}\n`,
  );

  writeIntegrityManifest(stage, "app", "runtime", "runtime/bin/node");
  copyDocs(stage);

  const zip = join(releaseDir, `sitegrowkit-desktop-2.1-${version}-windows-x64.zip`);
  rmSync(zip, { force: true });
  run("zip", ["-qry", zip, "."], { cwd: stage });
  return zip;
}

/* ---------------- main ---------------- */

mkdirSync(releaseDir, { recursive: true });
rmSync(workDir, { recursive: true, force: true });
mkdirSync(workDir, { recursive: true });

info(`GUI 配布物を生成します（version ${version} / platform ${requested}）`);
const { appDir } = buildAppTree();

const produced = [];
if (requested === "all" || requested === "macos") produced.push(buildMacos(appDir));
if (requested === "all" || requested === "windows")
  produced.push(buildWindows(appDir));

console.log("");
for (const zip of produced) {
  const sum = sha256(zip);
  writeFileSync(`${zip}.sha256`, `${sum}  ${zip.split("/").pop()}\n`);
  const mb = (statSync(zip).size / 1024 / 1024).toFixed(1);
  ok(`${zip.split("/").pop()}  (${mb} MB)`);
  console.log(`    sha256: ${sum}`);
}
console.log("\n完了。GUI 配布物は release/ 配下に生成されました。");

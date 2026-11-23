#!/usr/bin/env node
/**
 * Agent CMS Kit — Release Bundle 生成（Phase 7 Decision 1 / RC2）。
 *
 * 実行: node scripts/package-release.mjs [--skip-smoke]
 *
 * 生成物（release/ 配下・gitignored。過去 RC は上書きしない）:
 *   agent-cms-kit-v<version>/          … bundle ディレクトリ
 *   agent-cms-kit-v<version>.zip       … 配布 bundle
 *   agent-cms-kit-v<version>.zip.sha256… outer checksum（supply chain 検証用）
 *
 * 安全装置:
 *   - VERSION と cli package.json の不一致は FAIL（Decision 2）
 *   - git 作業ツリーが汚れていたら FAIL（配布物は committed 状態から決定論的に生成）
 *   - template/.agentcms の canonical ドリフトは FAIL（Decision 3）
 *   - 禁止パス / secret / client identifier / 未解決 placeholder スキャン（RC2 Blocker 1）
 *   - npm pack が落とす dotfile（.gitignore / .npmrc）の `.dist` 同梱と検証
 *   - unpack smoke test（install → --version → create ×2 → pnpm install → 一意性）
 */
import { execFileSync } from "node:child_process";
import {
  cpSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { createHash } from "node:crypto";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { CUSTOMER_DOCS } from "./customer-docs.mjs";
import { findBrokenDocLinks } from "./doc-links.mjs";
import { overwriteRefusal } from "./release-guard.mjs";
import { jmdictRefusal } from "./jmdict-freshness.mjs";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
// Local acceptance can use a fresh directory while preserving every release artifact.
const outputArg = process.argv.find((arg) => arg.startsWith("--output-dir="));
if (outputArg === "--output-dir=") throw new Error("--output-dir requires a path");
const releaseDir = outputArg ? resolve(outputArg.slice("--output-dir=".length)) : join(repoRoot, "release");
const skipSmoke = process.argv.includes("--skip-smoke");
// **在るものを黙って作り直さない**（F-15）。判断は `release-guard.mjs` が持つ
const force = process.argv.includes("--force");

const fail = (msg) => {
  console.error(`\n✖ ${msg}`);
  process.exit(1);
};
const ok = (msg) => console.log(`✔ ${msg}`);
const info = (msg) => console.log(`• ${msg}`);
const run = (cmd, args, opts = {}) =>
  execFileSync(cmd, args, { encoding: "utf8", cwd: repoRoot, ...opts });

/* ---------------- 1. Version SSoT 検証 ---------------- */
const version = readFileSync(join(repoRoot, "VERSION"), "utf8").trim();
const cliPkg = JSON.parse(
  readFileSync(join(repoRoot, "packages/cli/package.json"), "utf8"),
);
if (cliPkg.version !== version) {
  fail(
    `VERSION (${version}) と packages/cli/package.json (${cliPkg.version}) が一致しません。両方を揃えてから再実行してください`,
  );
}
ok(`Version SSoT: ${version}`);

/*
 * JMdict（カタカナ語の表）は新しい版から作り直してあるか。EDRDG の許諾が定期的な作り直しを求める
 * （守らないと許諾違反）。古ければ版を切らない。判断は `jmdict-freshness.mjs`
 */
{
  const readingDir = join(repoRoot, "template/core/resources/reading");
  const tablePath = join(readingDir, "katakana-english.tsv.gz");
  const metaPath = join(readingDir, "katakana-english.json");
  const refusal = jmdictRefusal({
    meta: existsSync(metaPath)
      ? JSON.parse(readFileSync(metaPath, "utf8"))
      : null,
    tableSha256: existsSync(tablePath)
      ? createHash("sha256").update(readFileSync(tablePath)).digest("hex")
      : null,
    today: new Date(),
  });
  if (refusal) fail(refusal);
  ok("JMdict の表は新しい版から作ってあります");
}

/* ---------------- 2. クリーン状態と同期の検証 ---------------- */
if (run("git", ["status", "--porcelain"]).trim() !== "") {
  fail(
    "git 作業ツリーが汚れています。配布物は committed 状態から生成します（commit してから再実行）",
  );
}
const commit = run("git", ["rev-parse", "HEAD"]).trim();

info("CLI を build して dist が最新か検証します");
run("pnpm", ["-C", "packages/cli", "build"], { stdio: "inherit" });
if (run("git", ["status", "--porcelain"]).trim() !== "") {
  fail(
    "build 後に差分が出ました（dist が最新ではありません）。build 結果を commit してから再実行してください",
  );
}
execFileSync(
  "node",
  ["packages/cli/dist/index.js", "sync-template-context", "--check"],
  { cwd: repoRoot, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
);
ok("template/.agentcms は canonical と同期しています");

// スキャンパターンは CLI と単一情報源（build 済み dist から読み込む）
const { ALLOWLIST, CONTENT_PATTERNS, PLACEHOLDER_PATTERNS } = await import(
  join(repoRoot, "packages/cli/dist/scan-patterns.js")
);

/* ---------------- 3. Staging（git archive = tracked のみ・過去 RC 非破壊） ---------------- */
const workDir = join(releaseDir, "work");
const bundleName = `agent-cms-kit-v${version}`;
const bundleDir = join(releaseDir, bundleName);
const zipPath = join(releaseDir, `${bundleName}.zip`);

/*
 * **同じ版を作り直すのは、既に在るものを消すことである。**
 *
 * 「過去 RC 非破壊」は版が違うときの話で、**同じ版の再実行は守られていなかった**
 * （F-15 — accepted な rc15 がそれで失われた）。訊いてから消す。
 */
const refusal = overwriteRefusal({
  bundleName,
  bundleExists: existsSync(bundleDir),
  zipExists: existsSync(zipPath),
  force,
});

if (refusal) fail(refusal);

if (force && (existsSync(bundleDir) || existsSync(zipPath))) {
  info(`--force が指定されています。${bundleName} の配布物を置き換えます`);
}

rmSync(workDir, { recursive: true, force: true });
rmSync(bundleDir, { recursive: true, force: true });
rmSync(zipPath, { force: true });
rmSync(`${zipPath}.sha256`, { force: true });

const srcDir = join(workDir, "src");
const pkgDir = join(workDir, "package");
mkdirSync(srcDir, { recursive: true });
run("bash", [
  "-c",
  `git archive ${commit} | tar -x -C ${JSON.stringify(srcDir)}`,
]);

mkdirSync(pkgDir, { recursive: true });
cpSync(join(srcDir, "packages/cli/package.json"), join(pkgDir, "package.json"));
cpSync(join(srcDir, "packages/cli/dist"), join(pkgDir, "dist"), {
  recursive: true,
});
cpSync(join(srcDir, "template"), join(pkgDir, "template"), { recursive: true });
// npm pack は一部 dotfile（.gitignore / .npmrc）を tarball から除外するため、
// `.dist` 接尾辞へ改名して同梱する（agentcms create が生成時に元へ戻す）
const renameDistDotfiles = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) renameDistDotfiles(p);
    else if (e.name === ".gitignore" || e.name === ".npmrc") {
      cpSync(p, `${p}.dist`);
      rmSync(p);
    }
  }
};
renameDistDotfiles(join(pkgDir, "template"));
for (const doc of CUSTOMER_DOCS) {
  const from = join(srcDir, "docs", doc);
  if (!existsSync(from)) fail(`顧客向けドキュメントがありません: docs/${doc}`);
  mkdirSync(dirname(join(pkgDir, "docs", doc)), { recursive: true });
  cpSync(from, join(pkgDir, "docs", doc));
}
cpSync(join(srcDir, "docs/adr"), join(pkgDir, "docs/adr"), { recursive: true });
for (const f of [
  "README.md",
  "CHANGELOG.md",
  "LICENSE",
  "COPYRIGHT",
  "THIRD-PARTY-LICENSES.md",
]) {
  cpSync(join(srcDir, f), join(pkgDir, f));
}
ok("staging を構築しました（git tracked ファイルのみ）");

/* -------- 3b. THIRD-PARTY-LICENSES currency（Phase 8 Stage L） -------- */
// 棚卸しが committed 状態の依存セット（lock / manifest）と同期していることを検証
execFileSync(
  "node",
  [join(srcDir, "scripts/generate-third-party-licenses.mjs"), "--check"],
  { cwd: srcDir, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] },
);
ok("THIRD-PARTY-LICENSES.md は依存セットと同期しています");

/* -------- 3c. First-party license metadata（GPL-3.0-or-later・LICENSE と揃える） -------- */
for (const rel of ["template/composer.json", "template/core/composer.json"]) {
  const manifest = JSON.parse(readFileSync(join(pkgDir, rel), "utf8"));
  if (manifest.license !== "GPL-3.0-or-later") {
    fail(
      `${rel}: first-party license metadata が "GPL-3.0-or-later" ではありません（検出値: ${JSON.stringify(manifest.license)}）`,
    );
  }
}
ok("first-party package metadata は GPL-3.0-or-later です");

/* ---------------- 4. スキャン ---------------- */
const files = [];
const walk = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) walk(p);
    else files.push(relative(pkgDir, p));
  }
};
walk(pkgDir);

const PROHIBITED = [
  { re: /(^|\/)\.env$/, why: "実 .env" },
  { re: /(^|\/)\.env\.(?!example$|staging\.example$)/, why: ".env 派生" },
  { re: /\.pem$/, why: "秘密鍵" },
  { re: /(^|\/)(?!.*chisel)[^/]*\.key$/, why: "秘密鍵" },
  { re: /(^|\/)auth\.json$/, why: "認証情報" },
  { re: /(^|\/)node_modules\//, why: "依存" },
  { re: /(^|\/)vendor\//, why: "依存" },
  { re: /(^|\/)\.git\//, why: "git" },
  { re: /\.lighthouseci/, why: "ローカル計測生成物" },
  { re: /admin-credentials/, why: "管理者認証情報" },
  { re: /\.agentcms\/local\//, why: "ローカル秘密領域" },
  { re: /\.agentcms\/cache\//, why: "クライアントデザインデータ" },
  { re: /storage\/logs\/.+\.log$/, why: "ログ" },
  // entry document は README_FIRST.md ただ一つ（Phase 8 D11 / R10）
  { re: /(^|\/)START_HERE\.md$/i, why: "第二の entry document" },
  /*
   * **開発機のエージェント設定は配らない。**
   *
   * `.claude/` には skill・agent 定義・ローカル設定が入り、**この開発機の都合**
   * （path・鍵の置き場所・作業中の手順）が混ざる。利用者の環境で意味を持たないどころか、
   * **他人の環境の話を配る**ことになる。`content/schemas/overrides` を配布から外したのと同じ理由。
   *
   * いまは配布物に入っていない（実測）。**次に skills を触った人が同じ実測をしなくて済むように**
   * 門にしておく。
   *
   * **ディレクトリ形だけでは足りない。** `.claude.json` は実在する設定ファイルで、
   * `.claude/` を要求する形では素通りする。**同じ名前で 2 つの形がある**ものは、
   * 片方だけ塞いでも守ったことにならない。
   */
  { re: /(^|\/)\.claude(\/|\.json$|$)/, why: "開発機のエージェント設定" },
];
// 作者・クライアント・環境の識別子（配布物に混入してはならない文字列）。
//
// **一覧そのものはリポジトリに置かない。** 一覧に書く文字列（お客さんの名前・
// 社内のサーバー名など）は、それ自体が公開してはいけない情報だから。
// リポジトリの外のファイルを環境変数 `SGK_RELEASE_IDENTIFIERS_FILE` で指す
// （1 行 1 件・空行と `#` で始まる行は無視）。指していなければ一覧は空で、
// その旨を表示する（秘密・placeholder・禁止パスの検査はそのまま走る）。
const loadClientIdentifiers = () => {
  const file = process.env.SGK_RELEASE_IDENTIFIERS_FILE;
  if (!file) {
    console.log(
      "  · 識別子の一覧なし（SGK_RELEASE_IDENTIFIERS_FILE 未設定）— 識別子の検査は飛ばす",
    );
    return [];
  }
  if (!existsSync(file)) {
    console.error(`✖ SGK_RELEASE_IDENTIFIERS_FILE が見つかりません: ${file}`);
    process.exit(1);
  }
  const list = readFileSync(file, "utf8")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "" && !line.startsWith("#"));
  console.log(`  · 識別子の一覧 ${list.length} 件で検査する`);
  return list;
};
const CLIENT_IDENTIFIERS = loadClientIdentifiers();
const TEXT_EXT =
  /\.(php|ts|js|mjs|vue|json|yml|yaml|md|css|html|xml|ini|conf|example|stub|txt|dist)$/;

/** 禁止パス + 内容（secret / placeholder / 識別子）のスキャン。違反メッセージの配列を返す */
const scanTree = (rootDir, relFiles) => {
  const found = [];
  for (const f of relFiles) {
    for (const p of PROHIBITED) {
      if (p.re.test(f)) found.push(`${f}: 禁止パス（${p.why}）`);
    }
    // スキャンパターン定義ファイル自身は自己参照になるため内容スキャン対象外
    if (f === "dist/scan-patterns.js") continue;
    if (!TEXT_EXT.test(f) && !f.endsWith(".gitignore")) continue;
    const content = readFileSync(join(rootDir, f), "utf8");
    for (const s of CONTENT_PATTERNS) {
      const m = content.match(s.re);
      if (m && !ALLOWLIST.some((a) => a.test(m[0]))) {
        found.push(`${f}: ${s.name} を検出`);
      }
    }
    for (const s of PLACEHOLDER_PATTERNS) {
      if (s.re.test(content))
        found.push(`${f}: ${s.name}（未解決 placeholder）`);
    }
    for (const id of CLIENT_IDENTIFIERS) {
      if (content.includes(id)) found.push(`${f}: 識別子 "${id}" を検出`);
    }
  }
  return found;
};

/**
 * 配布物の中の Markdown リンクが、配布物の中で生きているか（2026-09-06 / F-16）。
 *
 * リポジトリで開けば通るリンクが、**docs を選んで入れている都合で配布物では死ぬ**。
 * 死んだリンクは買った人の手元にしか現れないので、機械で言わせる。
 */
const assertDocLinks = (rootDir, relFiles, what) => {
  const broken = findBrokenDocLinks({
    files: relFiles,
    read: (rel) => readFileSync(join(rootDir, rel), "utf8"),
  });

  if (broken.length > 0) {
    console.error(`✖ ${what}のリンクが配布物の中で切れています:`);
    for (const b of broken) console.error(`  - ${b}`);
    process.exit(1);
  }

  ok(`${what}のリンクは配布物の中で生きています`);
};

const violations = scanTree(pkgDir, files);
if (violations.length > 0) {
  console.error("✖ Release スキャンで問題を検出:");
  for (const v of violations) console.error(`  - ${v}`);
  process.exit(1);
}
ok(
  `Release スキャン通過（${files.length} ファイル: 禁止パス / secret / placeholder / 識別子）`,
);
assertDocLinks(pkgDir, files, ".tgz の文書");

/* ---------------- 5. npm pack ---------------- */
info("npm pack で .tgz を生成します");
const packOut = run("npm", ["pack", "--json"], { cwd: pkgDir });
const packInfo = JSON.parse(packOut)[0];
const tgzName = packInfo.filename;
const tgzPath = join(pkgDir, tgzName);
if (!existsSync(tgzPath)) fail(`npm pack の出力が見つかりません: ${tgzName}`);

// tgz 内容の検証: 必須ファイルが入り、禁止ファイルが入っていないこと
const tgzList = run("tar", ["-tzf", tgzPath]).split("\n").filter(Boolean);
for (const required of [
  "package/dist/index.js",
  "package/template/artisan",
  "package/template/.gitignore.dist",
  "package/template/.npmrc.dist",
  "package/template/storage/framework/.gitignore.dist",
  "package/template/.env.example",
  "package/template/pnpm-workspace.yaml",
  "package/template/.agentcms/architecture.md",
  "package/docs/GETTING_STARTED.md",
  "package/LICENSE",
  "package/COPYRIGHT",
  "package/THIRD-PARTY-LICENSES.md",
]) {
  if (!tgzList.includes(required)) {
    fail(
      `.tgz に必須ファイルがありません: ${required}（npm pack のフィルタ挙動を確認）`,
    );
  }
}
for (const entry of tgzList) {
  if (
    /(\.env$|admin-credentials|\.agentcms\/local\/|node_modules\/|vendor\/)/.test(
      entry,
    )
  ) {
    fail(`.tgz に禁止ファイルが含まれています: ${entry}`);
  }
}
ok(`.tgz 生成と内容検証: ${tgzName}（${tgzList.length} entries）`);

/* ---------------- 6. Unpack smoke test ---------------- */
if (!skipSmoke) {
  info("unpack smoke test（install → --version → create ×2 → pnpm install）");
  const smoke = join(workDir, "smoke");
  const prefix = join(smoke, "prefix");
  const work = join(smoke, "work");
  mkdirSync(prefix, { recursive: true });
  mkdirSync(work, { recursive: true });
  run("npm", ["install", "-g", "--prefix", prefix, tgzPath], { cwd: smoke });
  const agentcms = join(prefix, "bin", "agentcms");
  const v = run(agentcms, ["--version"], { cwd: work }).trim();
  if (v !== version)
    fail(`installed CLI の --version が不一致: ${v} != ${version}`);
  run(agentcms, ["create", "smoke-a"], { cwd: work });
  run(agentcms, ["create", "smoke-b"], { cwd: work });
  for (const rel of [
    "smoke-a/CLAUDE.md",
    "smoke-a/AGENTS.md",
    "smoke-a/.agentcms/architecture.md",
    "smoke-a/.agentcms/project.json",
    "smoke-a/.agentcms/local/admin-credentials.json",
    "smoke-a/.gitignore",
    "smoke-a/.npmrc",
    "smoke-a/storage/framework/.gitignore",
    "smoke-a/pnpm-workspace.yaml",
  ]) {
    if (!existsSync(join(work, rel)))
      fail(`smoke: ${rel} が生成されていません`);
  }
  const smokeGitignore = readFileSync(join(work, "smoke-a/.gitignore"), "utf8");
  if (
    !smokeGitignore.includes("/vendor") ||
    !smokeGitignore.includes(".agentcms/local")
  ) {
    fail(
      "smoke: .gitignore が完全に復元されていません（vendor / .agentcms/local ルール欠落）",
    );
  }
  if (existsSync(join(work, "smoke-a/.gitignore.dist"))) {
    fail("smoke: .gitignore.dist が残っています（restore 漏れ）");
  }
  const credA = JSON.parse(
    readFileSync(
      join(work, "smoke-a/.agentcms/local/admin-credentials.json"),
      "utf8",
    ),
  );
  const credB = JSON.parse(
    readFileSync(
      join(work, "smoke-b/.agentcms/local/admin-credentials.json"),
      "utf8",
    ),
  );
  if (credA.password === credB.password)
    fail("smoke: credential が project 間で同一です");
  if (credA.password === "password")
    fail("smoke: 共有 default password が残っています");
  const envA = readFileSync(join(work, "smoke-a/.env"), "utf8");
  const envB = readFileSync(join(work, "smoke-b/.env"), "utf8");
  const nameA = /^COMPOSE_PROJECT_NAME=(.+)$/m.exec(envA)?.[1];
  const nameB = /^COMPOSE_PROJECT_NAME=(.+)$/m.exec(envB)?.[1];
  if (!nameA || nameA === nameB || nameA === "agentcms") {
    fail("smoke: compose identity が一意ではありません");
  }

  // pnpm install が承認手動介入なしで exit 0（RC2 Blocker 1 の regression）。
  // 検証は製品契約に合わせる: pin された pnpm 10 系で、警告/エラーなしに exit 0。
  // （pnpm 10.33 は未承認 build を pendingBuilds として静かに記録するのみで機能影響なし。
  //   RC1 の Windows 失敗は pnpm 11 の hard fail — pin がそれを構造的に防ぐ）
  info("smoke: pnpm install（build 承認の手動介入なしで成立すること）");
  const pnpmOut = run("pnpm", ["install"], { cwd: join(work, "smoke-a") });
  if (/Ignored build scripts|ERR_PNPM_IGNORED_BUILDS/i.test(pnpmOut)) {
    fail("smoke: pnpm が build script を無視/拒否しました（承認設定が不足）");
  }
  if (!/using pnpm v10\./.test(pnpmOut)) {
    fail(
      "smoke: pnpm 10 系以外で install が実行されました（packageManager pin / corepack を確認）",
    );
  }
  ok(
    "unpack smoke test 通過（version / context / credential / identity / dotfile 復元 / pnpm install）",
  );
}

/* ---------------- 7. Release bundle + manifest + checksums ---------------- */
mkdirSync(bundleDir, { recursive: true });
cpSync(tgzPath, join(bundleDir, tgzName));
cpSync(join(srcDir, "LICENSE"), join(bundleDir, "LICENSE"));
cpSync(join(srcDir, "COPYRIGHT"), join(bundleDir, "COPYRIGHT"));
cpSync(
  join(srcDir, "THIRD-PARTY-LICENSES.md"),
  join(bundleDir, "THIRD-PARTY-LICENSES.md"),
);
{
  // README_FIRST は <version> プレースホルダを実値へ（配布物はそのまま実行できる記述にする）
  const rf = readFileSync(join(srcDir, "README_FIRST.md"), "utf8").replaceAll(
    "<version>",
    version,
  );
  writeFileSync(join(bundleDir, "README_FIRST.md"), rf, "utf8");
}
mkdirSync(join(bundleDir, "docs"), { recursive: true });
for (const doc of CUSTOMER_DOCS) {
  mkdirSync(dirname(join(bundleDir, "docs", doc)), { recursive: true });
  cpSync(join(srcDir, "docs", doc), join(bundleDir, "docs", doc));
}

const sha256 = (p) =>
  createHash("sha256").update(readFileSync(p)).digest("hex");
const templateComposer = JSON.parse(
  readFileSync(join(srcDir, "template/composer.json"), "utf8"),
);
const templatePkg = JSON.parse(
  readFileSync(join(srcDir, "template/package.json"), "utf8"),
);
const manifest = {
  product: "Agent CMS Kit",
  version,
  template_schema_version: 1,
  agent_context_version: 1,
  minimums: {
    php: templateComposer.require?.php ?? null, // supported minimum（Docker 既定 runtime は 8.5）
    node: cliPkg.engines?.node ?? null,
    pnpm: templatePkg.packageManager ?? null,
    docker: "Docker Desktop (Compose v2)",
  },
  runtime_modes: ["docker", "compatible-host (verify only)"],
  artifact: tgzName,
  artifact_sha256: sha256(tgzPath),
  created_from_commit: commit,
  created_at: new Date().toISOString(),
};
writeFileSync(
  join(bundleDir, "release-manifest.json"),
  `${JSON.stringify(manifest, null, 2)}\n`,
  "utf8",
);

// bundle 直下に置くファイル（tgz 経由でないもの）も同一 scan を通す（Phase 8 §15.1）
const bundleFiles = [];
const walkBundle = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) walkBundle(p);
    else bundleFiles.push(relative(bundleDir, p));
  }
};
walkBundle(bundleDir);
const bundleViolations = scanTree(bundleDir, bundleFiles);
if (bundleViolations.length > 0) {
  console.error("✖ Bundle スキャンで問題を検出:");
  for (const v of bundleViolations) console.error(`  - ${v}`);
  process.exit(1);
}
ok(`Bundle スキャン通過（${bundleFiles.length} ファイル）`);
assertDocLinks(bundleDir, bundleFiles, "bundle の文書");

const sumTargets = [
  tgzName,
  "LICENSE",
  "COPYRIGHT",
  "THIRD-PARTY-LICENSES.md",
  "README_FIRST.md",
  "release-manifest.json",
];
writeFileSync(
  join(bundleDir, "SHA256SUMS"),
  sumTargets.map((f) => `${sha256(join(bundleDir, f))}  ${f}`).join("\n") +
    "\n",
  "utf8",
);

run("zip", ["-r", "-q", `${bundleName}.zip`, bundleName], { cwd: releaseDir });
// outer checksum（ZIP 自体の integrity を自己完結で検証できるようにする — supply chain note）
writeFileSync(
  `${zipPath}.sha256`,
  `${sha256(zipPath)}  ${bundleName}.zip\n`,
  "utf8",
);
ok(`Release bundle: ${relative(repoRoot, zipPath)}`);
ok(`Outer checksum: ${relative(repoRoot, zipPath)}.sha256`);
info(`  tgz sha256: ${manifest.artifact_sha256}`);
info(`  zip size: ${(statSync(zipPath).size / 1024 / 1024).toFixed(1)} MB`);
info(`  commit: ${commit}`);
console.log(
  "\n完了。配布前チェックリスト: .zip.sha256 検証 / SHA256SUMS 検証 / README_FIRST 確認 / 別環境での受け入れテスト",
);

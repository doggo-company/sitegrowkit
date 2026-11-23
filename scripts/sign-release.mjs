#!/usr/bin/env node
/**
 * SiteGrowKit — 配布物の署名（発行側）。
 *
 * 実行:
 *   node scripts/sign-release.mjs \
 *     --archive release/agent-cms-kit-v<ver>/agent-cms-kit-<ver>.tgz \
 *     --version <ver> \
 *     --template-schema-version 1 --agent-context-version 1 \
 *     --private-key <path>            （または env SITEGROWKIT_SIGNING_KEY_FILE）\
 *     [--out <file>]                  （既定: <archive dir>/signed-release.json）\
 *     [--released-at <ISO 8601>]      （明示したときだけ manifest に入る）\
 *     [--export-public-key <file>]    （検証側へ別経路で渡す公開鍵 PEM を書き出す）
 *
 * 生成物:
 *   <out>       … 署名対象の manifest（sha256 / bytes / version / compatibility）
 *   <out>.sig   … manifest の元 byte に対する Ed25519 detached 署名（base64）
 *
 * 守ること:
 *   - 秘密鍵は**生成しない・表示しない・同梱しない**。読むのはファイルからだけ
 *   - 既にあるファイルは**上書きしない**（--force も無い。別名か別の場所へ）
 *   - 発行日時・URL は**明示されない限り書かない**（捏造しない）
 *   - schema / 署名の実装は CLI と同じ module（build 済み dist）を使う。
 *     発行側と検証側が別の定義を持つと、片方だけ変わったときに黙ってずれる
 */
import {
  existsSync,
  mkdirSync,
  readFileSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");

const fail = (msg) => {
  console.error(`\n✖ ${msg}`);
  process.exit(1);
};
const ok = (msg) => console.log(`✔ ${msg}`);
const info = (msg) => console.log(`• ${msg}`);

/* ---------------- 引数 ---------------- */
const args = process.argv.slice(2);
const opt = (name) => {
  const i = args.indexOf(name);
  if (i === -1) return undefined;
  const v = args[i + 1];
  if (v === undefined || v.startsWith("--")) fail(`${name} には値が必要です`);
  return v;
};
const KNOWN = new Set([
  "--archive",
  "--version",
  "--template-schema-version",
  "--agent-context-version",
  "--private-key",
  "--out",
  "--released-at",
  "--export-public-key",
]);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a.startsWith("--")) {
    if (!KNOWN.has(a)) fail(`不明なオプションです: ${a}`);
    i++; // 値を飛ばす
  } else {
    fail(`位置引数は受け付けません: ${a}`);
  }
}

const archiveArg = opt("--archive");
const versionArg = opt("--version");
const tsvArg = opt("--template-schema-version");
const acvArg = opt("--agent-context-version");
const keyArg = opt("--private-key") ?? process.env.SITEGROWKIT_SIGNING_KEY_FILE;
const outArg = opt("--out");
const releasedAt = opt("--released-at");
const exportPub = opt("--export-public-key");

if (!archiveArg) fail("--archive <file> は必須です（署名する配布アーカイブ）");
if (!versionArg) fail("--version <semver> は必須です（配布物の版）");
if (!tsvArg) fail("--template-schema-version <n> は必須です");
if (!acvArg) fail("--agent-context-version <n> は必須です");
if (!keyArg) {
  fail(
    "署名鍵の場所が指定されていません。--private-key <file> か env SITEGROWKIT_SIGNING_KEY_FILE で指定してください（鍵はこのスクリプトでは生成しません）",
  );
}

/* ---------------- 共有 module（build 済み dist） ---------------- */
const distModule = join(repoRoot, "packages/cli/dist/updates/release-manifest.js");
if (!existsSync(distModule)) {
  fail(
    "packages/cli/dist/updates/release-manifest.js がありません。先に `pnpm -C packages/cli build` を実行してください（発行側と検証側で同じ定義を使うため）",
  );
}
const {
  MANIFEST_FORMAT,
  MANIFEST_FORMAT_VERSION,
  PRODUCT_NAME,
  digestFile,
  encodeManifest,
  loadSigningPrivateKey,
  parseManifest,
  signManifestBytes,
  decodeSignature,
  verifyManifestBytes,
} = await import(distModule);
const { parseSemver } = await import(
  join(repoRoot, "packages/cli/dist/updates/semver.js")
);

/* ---------------- 入力検証 ---------------- */
const archivePath = resolve(archiveArg);
if (!existsSync(archivePath) || !statSync(archivePath).isFile()) {
  fail(`アーカイブが見つかりません: ${archivePath}`);
}
if (!parseSemver(versionArg)) {
  fail(`--version が semver ではありません: ${versionArg}`);
}
const toInt = (label, v) => {
  if (!/^(0|[1-9]\d*)$/.test(v)) fail(`${label} は 0 以上の整数で指定してください: ${v}`);
  return Number(v);
};
const templateSchemaVersion = toInt("--template-schema-version", tsvArg);
const agentContextVersion = toInt("--agent-context-version", acvArg);
if (releasedAt !== undefined) {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/.test(releasedAt)) {
    fail(`--released-at は ISO 8601（例: 2026-09-10T00:00:00Z）で指定してください: ${releasedAt}`);
  }
  if (Number.isNaN(Date.parse(releasedAt))) fail(`--released-at が日時として不正です: ${releasedAt}`);
}

const outPath = resolve(outArg ?? join(dirname(archivePath), "signed-release.json"));
const sigPath = `${outPath}.sig`;
for (const p of [outPath, sigPath]) {
  if (existsSync(p)) {
    fail(
      `${p} は既に在ります。上書きしません（既存の配布記録を消さないため）。--out で別の場所を指定してください`,
    );
  }
}
if (exportPub && existsSync(resolve(exportPub))) {
  fail(`${resolve(exportPub)} は既に在ります。上書きしません`);
}

/* ---------------- 署名鍵（中身は一切表示しない） ---------------- */
const keyPath = resolve(keyArg);
if (!existsSync(keyPath) || !statSync(keyPath).isFile()) {
  fail("署名鍵ファイルが見つかりません（パスは表示しません。--private-key / SITEGROWKIT_SIGNING_KEY_FILE を確認してください）");
}
const keyLoaded = loadSigningPrivateKey(readFileSync(keyPath));
if (!keyLoaded.ok) fail(`署名鍵を使えません: ${keyLoaded.reason}`);
ok(`署名鍵: Ed25519（公開鍵指紋 ${keyLoaded.fingerprint}）`);

/* ---------------- manifest ---------------- */
const digest = digestFile(archivePath);
if (!digest.ok) fail(`アーカイブを読めません: ${digest.reason}`);
const manifest = {
  format: MANIFEST_FORMAT,
  format_version: MANIFEST_FORMAT_VERSION,
  product: PRODUCT_NAME,
  version: versionArg,
  compatibility: {
    template_schema_version: templateSchemaVersion,
    agent_context_version: agentContextVersion,
  },
  artifact: {
    name: basename(archivePath),
    sha256: digest.sha256,
    bytes: digest.bytes,
  },
  signing_key_fingerprint: keyLoaded.fingerprint,
  ...(releasedAt !== undefined ? { released_at: releasedAt } : {}),
};

// 自分が書くものが、検証側の厳密 schema を通ることを先に確かめる
const parsed = parseManifest(encodeManifest(manifest));
if (!parsed.ok) fail(`生成した manifest が schema を通りません: ${parsed.reason}`);

const bytes = encodeManifest(parsed.manifest);
const signature = signManifestBytes(bytes, keyLoaded.key);

// 書く前に、公開鍵で検証が通ることを自己確認する
const sigRaw = decodeSignature(signature);
if (!sigRaw || !verifyManifestBytes(bytes, sigRaw, keyLoaded.publicKey)) {
  fail("生成した署名が公開鍵で検証できません（鍵ペアの不整合）");
}

mkdirSync(dirname(outPath), { recursive: true });
writeFileSync(outPath, bytes, { flag: "wx" });
writeFileSync(sigPath, signature, { flag: "wx" });
ok(`manifest: ${outPath}`);
ok(`署名:     ${sigPath}`);
info(`  version: ${manifest.version}`);
info(`  artifact: ${manifest.artifact.name} (${manifest.artifact.bytes} bytes)`);
info(`  sha256:  ${manifest.artifact.sha256}`);
info(
  `  compatibility: template_schema_version ${templateSchemaVersion} / agent_context_version ${agentContextVersion}`,
);
info(`  released_at: ${releasedAt ?? "（未指定 — 書いていません）"}`);

if (exportPub) {
  const pem = keyLoaded.publicKey.export({ type: "spki", format: "pem" });
  writeFileSync(resolve(exportPub), pem, { flag: "wx" });
  ok(`公開鍵 PEM: ${resolve(exportPub)}（検証側へは配布物とは別の経路で渡す）`);
}

console.log(
  "\n完了。次: 検証側で `agentcms update check --manifest <out> --signature <out>.sig --public-key <別経路で渡した公開鍵> --archive <tgz>` が通ることを、配布物を置く前に確認してください。",
);

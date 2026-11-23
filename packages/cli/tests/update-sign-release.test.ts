import { spawnSync } from "node:child_process";
import { createHash, generateKeyPairSync } from "node:crypto";
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, beforeAll, describe, expect, it } from "vitest";

import {
  parseManifest,
  verifySignedManifest,
} from "../src/updates/release-manifest.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, "..", "..", "..");
const script = join(repoRoot, "scripts", "sign-release.mjs");
const cliDist = join(here, "..", "dist", "index.js");

/**
 * 発行側 `scripts/sign-release.mjs`。
 *
 * 守ること: 秘密鍵を生成・表示・同梱しない / 在るものを上書きしない /
 * 発行日時を捏造しない / 検証側と同じ schema で書く。
 * 鍵はテスト用 tmp dir に毎回生成する（repo に鍵を置かない）。
 */

const made: string[] = [];
afterEach(() => {
  for (const d of made.splice(0)) rmSync(d, { recursive: true, force: true });
});

function world() {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-sign-"));
  made.push(dir);
  const { privateKey, publicKey } = generateKeyPairSync("ed25519");
  const privPem = privateKey.export({ type: "pkcs8", format: "pem" }) as string;
  const pubPem = publicKey.export({ type: "spki", format: "pem" }) as string;
  writeFileSync(join(dir, "signing.pem"), privPem, { mode: 0o600 });
  writeFileSync(join(dir, "trusted.pem"), pubPem);
  const archiveBytes = Buffer.alloc(10_000, 0x33);
  writeFileSync(join(dir, "agent-cms-kit-0.9.5.tgz"), archiveBytes);
  // 検証側の比較対象（利用中の版が無いと導入可能性は blocked になるため）
  mkdirSync(join(dir, "proj", ".agentcms"), { recursive: true });
  writeFileSync(
    join(dir, "proj", ".agentcms", "project.json"),
    `${JSON.stringify({ name: "proj", kit_version: "0.9.4", template_schema_version: 1, agent_context_version: 1 })}\n`,
  );
  return { dir, privPem, pubPem, archiveBytes, project: join(dir, "proj") };
}

const baseArgs = (dir: string) => [
  "--archive",
  join(dir, "agent-cms-kit-0.9.5.tgz"),
  "--version",
  "0.9.5",
  "--template-schema-version",
  "1",
  "--agent-context-version",
  "1",
];

function run(args: string[], env: Record<string, string | undefined> = {}) {
  return spawnSync(process.execPath, [script, ...args], {
    cwd: repoRoot,
    encoding: "utf8",
    env: { ...process.env, SITEGROWKIT_SIGNING_KEY_FILE: undefined, ...env },
  });
}

describe("scripts/sign-release.mjs", () => {
  beforeAll(() => {
    if (
      !existsSync(join(here, "..", "dist", "updates", "release-manifest.js"))
    ) {
      throw new Error("dist がありません。pnpm build を先に実行してください");
    }
  });

  it("manifest と署名を書き、検証側の update check が通る（実体照合まで）", () => {
    const w = world();
    const out = join(w.dir, "out", "release-manifest.json");
    const r = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
    ]);
    expect(r.status, r.stderr).toBe(0);
    expect(existsSync(out)).toBe(true);
    expect(existsSync(`${out}.sig`)).toBe(true);

    // 秘密鍵の中身も、そのパスも出力に出ない
    expect(r.stdout + r.stderr).not.toContain("PRIVATE KEY");
    expect(r.stdout + r.stderr).not.toContain("signing.pem");
    // 発行日時は指定していないので書かない
    const parsed = parseManifest(readFileSync(out));
    expect(parsed.ok).toBe(true);
    if (!parsed.ok) return;
    expect(parsed.manifest.released_at).toBeUndefined();
    expect(parsed.manifest.version).toBe("0.9.5");
    expect(parsed.manifest.artifact).toEqual({
      name: "agent-cms-kit-0.9.5.tgz",
      sha256: createHash("sha256").update(w.archiveBytes).digest("hex"),
      bytes: 10_000,
    });
    expect(JSON.stringify(parsed.manifest)).not.toMatch(/https?:\/\//);

    // 検証側と同じ定義で通る
    const v = verifySignedManifest({
      manifestBytes: readFileSync(out),
      signatureRaw: readFileSync(`${out}.sig`),
      publicKeyPem: Buffer.from(w.pubPem),
    });
    expect(v.ok).toBe(true);

    // 実物の CLI でも通る
    const check = spawnSync(
      process.execPath,
      [
        cliDist,
        "update",
        "check",
        "--manifest",
        out,
        "--signature",
        `${out}.sig`,
        "--public-key",
        join(w.dir, "trusted.pem"),
        "--archive",
        join(w.dir, "agent-cms-kit-0.9.5.tgz"),
        "--project",
        w.project,
        "--json",
        "--no-record",
      ],
      { cwd: w.dir, encoding: "utf8" },
    );
    expect(check.status, check.stderr).toBe(0);
    const json = JSON.parse(check.stdout);
    expect(json.archive.status).toBe("verified");
    expect(json.applied).toBe(false);
    expect(json.admissibility.status).toBe("review_required");
    expect(json.admissibility.archive_verified).toBe(true);
  });

  it("鍵パスは env SITEGROWKIT_SIGNING_KEY_FILE でも渡せる。無ければ生成せず止まる", () => {
    const w = world();
    const out = join(w.dir, "release-manifest.json");
    const none = run([...baseArgs(w.dir), "--out", out]);
    expect(none.status).toBe(1);
    expect(none.stderr).toContain("生成しません");
    expect(existsSync(out)).toBe(false);

    const viaEnv = run([...baseArgs(w.dir), "--out", out], {
      SITEGROWKIT_SIGNING_KEY_FILE: join(w.dir, "signing.pem"),
    });
    expect(viaEnv.status, viaEnv.stderr).toBe(0);
  });

  it("既にある manifest / 署名 / 公開鍵出力を上書きしない", () => {
    const w = world();
    const out = join(w.dir, "release-manifest.json");
    writeFileSync(out, "existing\n");
    const a = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
    ]);
    expect(a.status).toBe(1);
    expect(a.stderr).toContain("上書きしません");
    expect(readFileSync(out, "utf8")).toBe("existing\n");
    expect(existsSync(`${out}.sig`)).toBe(false);

    rmSync(out);
    writeFileSync(`${out}.sig`, "existing-sig\n");
    const b = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
    ]);
    expect(b.status).toBe(1);
    expect(existsSync(out)).toBe(false);

    rmSync(`${out}.sig`);
    const pubOut = join(w.dir, "trusted.pem"); // 既に在る
    const c = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
      "--export-public-key",
      pubOut,
    ]);
    expect(c.status).toBe(1);
    expect(readFileSync(pubOut, "utf8")).toBe(w.pubPem);
  });

  it("--released-at を明示したときだけ書く。形式が違えば止める", () => {
    const w = world();
    const out = join(w.dir, "release-manifest.json");
    const bad = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
      "--released-at",
      "2026/09/10",
    ]);
    expect(bad.status).toBe(1);
    expect(existsSync(out)).toBe(false);

    const good = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
      "--released-at",
      "2026-09-10T00:00:00Z",
    ]);
    expect(good.status, good.stderr).toBe(0);
    const parsed = parseManifest(readFileSync(out));
    expect(parsed.ok && parsed.manifest.released_at).toBe(
      "2026-09-10T00:00:00Z",
    );
  });

  it("Ed25519 以外の鍵・存在しない鍵・不正な version・未知オプションは止める", () => {
    const w = world();
    const out = join(w.dir, "release-manifest.json");
    const rsa = generateKeyPairSync("rsa", { modulusLength: 2048 });
    writeFileSync(
      join(w.dir, "rsa.pem"),
      rsa.privateKey.export({ type: "pkcs8", format: "pem" }),
    );
    const notEd = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "rsa.pem"),
      "--out",
      out,
    ]);
    expect(notEd.status).toBe(1);
    expect(notEd.stderr).toContain("Ed25519");

    const missing = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "nope.pem"),
      "--out",
      out,
    ]);
    expect(missing.status).toBe(1);
    expect(missing.stderr).not.toContain("nope.pem"); // 鍵のパスを表示しない

    const badVer = run([
      "--archive",
      join(w.dir, "agent-cms-kit-0.9.5.tgz"),
      "--version",
      "v0.9.5",
      "--template-schema-version",
      "1",
      "--agent-context-version",
      "1",
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
    ]);
    expect(badVer.status).toBe(1);
    expect(badVer.stderr).toContain("semver");

    const unknown = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
      "--url",
      "https://example.invalid",
    ]);
    expect(unknown.status).toBe(1);
    expect(unknown.stderr).toContain("不明なオプション");

    expect(existsSync(out)).toBe(false);
  });

  it("--export-public-key は公開鍵だけを書く（秘密鍵は書かない）", () => {
    const w = world();
    const out = join(w.dir, "release-manifest.json");
    const pubOut = join(w.dir, "exported-public.pem");
    const r = run([
      ...baseArgs(w.dir),
      "--private-key",
      join(w.dir, "signing.pem"),
      "--out",
      out,
      "--export-public-key",
      pubOut,
    ]);
    expect(r.status, r.stderr).toBe(0);
    const exported = readFileSync(pubOut, "utf8");
    expect(exported).toContain("BEGIN PUBLIC KEY");
    expect(exported).not.toContain("PRIVATE");
    expect(exported).toBe(w.pubPem);
  });
});

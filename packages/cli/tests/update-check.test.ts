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
  encodeManifest,
  loadSigningPrivateKey,
  MAX_MANIFEST_BYTES,
  MAX_SIGNATURE_BYTES,
  releaseManifestSchema,
  signManifestBytes,
  type ReleaseManifest,
  verifySignedManifest,
} from "../src/updates/release-manifest.js";
import { runUpdateCheck } from "../src/updates/update-check.js";

const here = dirname(fileURLToPath(import.meta.url));
const cliDist = join(here, "..", "dist", "index.js");

/**
 * `agentcms update check` — 署名が通ったものだけを配布物として認め、
 * **通っただけでは「更新済み」と言わない**。
 *
 * fixture の鍵は毎回テスト用 tmp dir に生成する。**repo に秘密鍵を置かない**
 * （置けば secret scan が拾うし、置いた鍵は fixture ではなく漏れである）。
 */

interface World {
  dir: string;
  privPem: string;
  pubPem: string;
  otherPubPem: string;
  archive: string;
  archiveBytes: Buffer;
  projectRoot: string;
}

const made: string[] = [];
afterEach(() => {
  for (const d of made.splice(0)) rmSync(d, { recursive: true, force: true });
});

function keyPairPem(): { priv: string; pub: string } {
  const { privateKey, publicKey } = generateKeyPairSync("ed25519");
  return {
    priv: privateKey.export({ type: "pkcs8", format: "pem" }) as string,
    pub: publicKey.export({ type: "spki", format: "pem" }) as string,
  };
}

function makeWorld(projectJson: unknown = defaultProject()): World {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-update-"));
  made.push(dir);
  const me = keyPairPem();
  const other = keyPairPem();
  const archiveBytes = Buffer.alloc(4096, 0x5a);
  const archive = join(dir, "agent-cms-kit-0.9.5.tgz");
  writeFileSync(archive, archiveBytes);
  writeFileSync(join(dir, "priv.pem"), me.priv, { mode: 0o600 });
  writeFileSync(join(dir, "pub.pem"), me.pub);
  writeFileSync(join(dir, "other-pub.pem"), other.pub);
  const projectRoot = join(dir, "proj");
  mkdirSync(join(projectRoot, ".agentcms"), { recursive: true });
  if (projectJson !== undefined) {
    writeFileSync(
      join(projectRoot, ".agentcms", "project.json"),
      typeof projectJson === "string"
        ? projectJson
        : `${JSON.stringify(projectJson, null, 2)}\n`,
    );
  }
  return {
    dir,
    privPem: join(dir, "priv.pem"),
    pubPem: join(dir, "pub.pem"),
    otherPubPem: join(dir, "other-pub.pem"),
    archive,
    archiveBytes,
    projectRoot,
  };
}

function defaultProject() {
  return {
    name: "proj",
    compose_project: "proj",
    kit_version: "0.9.4-rc3",
    template_schema_version: 1,
    agent_context_version: 1,
    created_at: "2026-09-01T00:00:00.000Z",
  };
}

function sha256Hex(buf: Buffer): string {
  return createHash("sha256").update(buf).digest("hex");
}

function manifestFor(
  w: World,
  over: Partial<ReleaseManifest> = {},
): ReleaseManifest {
  const loaded = loadSigningPrivateKey(readFileSync(w.privPem));
  if (!loaded.ok) throw new Error(loaded.reason);
  return {
    format: "sitegrowkit-release-manifest",
    format_version: 1,
    product: "SiteGrowKit",
    version: "0.9.5",
    compatibility: { template_schema_version: 1, agent_context_version: 1 },
    artifact: {
      name: "agent-cms-kit-0.9.5.tgz",
      sha256: sha256Hex(w.archiveBytes),
      bytes: w.archiveBytes.byteLength,
    },
    signing_key_fingerprint: loaded.fingerprint,
    ...over,
  };
}

/** manifest（任意の byte 列）を書き、その byte に署名する */
function writeSigned(
  w: World,
  manifestBytes: Buffer,
  name = "release-manifest.json",
): { manifest: string; signature: string } {
  const loaded = loadSigningPrivateKey(readFileSync(w.privPem));
  if (!loaded.ok) throw new Error(loaded.reason);
  const manifest = join(w.dir, name);
  const signature = `${manifest}.sig`;
  writeFileSync(manifest, manifestBytes);
  writeFileSync(signature, signManifestBytes(manifestBytes, loaded.key));
  return { manifest, signature };
}

function writeSignedManifest(w: World, over: Partial<ReleaseManifest> = {}) {
  return writeSigned(w, encodeManifest(manifestFor(w, over)));
}

const check = (
  w: World,
  files: { manifest: string; signature: string },
  extra: Partial<Parameters<typeof runUpdateCheck>[0]> = {},
) =>
  runUpdateCheck({
    manifest: files.manifest,
    signature: files.signature,
    publicKey: w.pubPem,
    project: w.projectRoot,
    ...extra,
  });

describe("update check — 署名と実体", () => {
  it("正しい署名 + 実体一致 → verified / newer / applied=false", async () => {
    const w = makeWorld();
    const r = await check(w, writeSignedManifest(w), { archive: w.archive });
    expect(r.status).toBe("verified");
    if (r.status !== "verified") return;
    expect(r.verdict).toBe("newer");
    expect(r.applied).toBe(false);
    expect(r.archive.status).toBe("verified");
    expect(r.compatibility.status).toBe("same");
  });

  it("アーカイブ未指定は『未照合』と分けて返す（実体検証済みと混ぜない）", async () => {
    const w = makeWorld();
    const r = await check(w, writeSignedManifest(w));
    expect(r.status).toBe("verified");
    if (r.status !== "verified") return;
    expect(r.archive.status).toBe("not_checked");
  });

  it("誤った鍵 → signature_invalid、manifest の値は結果に出ない", async () => {
    const w = makeWorld();
    const r = await check(w, writeSignedManifest(w), {
      publicKey: w.otherPubPem,
    });
    expect(r.status).toBe("failed");
    if (r.status !== "failed") return;
    expect(r.code).toBe("update.signature_invalid");
    expect(r.manifest).toBeUndefined();
    // 鍵指紋の取り違え hint は出るが、信頼の根拠ではないと明記されている
    expect(r.failure.why).toContain("信頼の根拠ではありません");
  });

  it("署名後に manifest を 1 文字変える → signature_invalid（version は信頼されない）", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    const tampered = readFileSync(files.manifest, "utf8").replace(
      '"version": "0.9.5"',
      '"version": "9.9.9"',
    );
    expect(tampered).not.toBe(readFileSync(files.manifest, "utf8"));
    writeFileSync(files.manifest, tampered);
    const r = await check(w, files);
    expect(r.status).toBe("failed");
    if (r.status !== "failed") return;
    expect(r.code).toBe("update.signature_invalid");
    expect(JSON.stringify(r)).not.toContain("9.9.9");
  });

  it("空白 1 つの追加でも別の byte 列（署名は元 byte に対して）", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    writeFileSync(files.manifest, `${readFileSync(files.manifest, "utf8")}\n`);
    const r = await check(w, files);
    expect(r.status).toBe("failed");
    if (r.status === "failed") expect(r.code).toBe("update.signature_invalid");
  });

  it("アーカイブ改変（同サイズ） → archive_mismatch、署名は通った事実と分けて返す", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    const evil = Buffer.from(w.archiveBytes);
    evil[100] = (evil[100] ?? 0) ^ 0xff;
    writeFileSync(w.archive, evil);
    const r = await check(w, files, { archive: w.archive });
    expect(r.status).toBe("failed");
    if (r.status !== "failed") return;
    expect(r.code).toBe("update.archive_mismatch");
    expect(r.manifest?.version).toBe("0.9.5");
    expect(r.archive?.status).toBe("mismatch");
    expect(r.failure.why).toContain("sha256");
  });

  it("アーカイブのサイズ違い → hash を計算せず不一致", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    writeFileSync(w.archive, Buffer.concat([w.archiveBytes, Buffer.from([1])]));
    const r = await check(w, files, { archive: w.archive });
    expect(r.status).toBe("failed");
    if (r.status !== "failed") return;
    expect(r.code).toBe("update.archive_mismatch");
    expect(r.failure.why).toContain("サイズ");
  });

  it("アーカイブが無い → archive_missing", async () => {
    const w = makeWorld();
    const r = await check(w, writeSignedManifest(w), {
      archive: join(w.dir, "nope.tgz"),
    });
    expect(r.status).toBe("failed");
    if (r.status === "failed") expect(r.code).toBe("update.archive_missing");
  });

  it("署名ファイル欠落 → signature_missing（真正性は確認できない）", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    rmSync(files.signature);
    const r = await check(w, files);
    expect(r.status).toBe("failed");
    if (r.status === "failed") {
      expect(r.code).toBe("update.signature_missing");
      expect(r.failure.fix).toContain("導入しないでください");
    }
  });

  it("manifest 欠落 → manifest_missing", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    rmSync(files.manifest);
    const r = await check(w, files);
    expect(r.status).toBe("failed");
    if (r.status === "failed") expect(r.code).toBe("update.manifest_missing");
  });

  it("信頼鍵の欠落 / 秘密鍵を渡す / Ed25519 以外 → 拒否", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);

    const missing = await check(w, files, {
      publicKey: join(w.dir, "nope.pem"),
    });
    expect(missing.status === "failed" && missing.code).toBe(
      "update.public_key_missing",
    );

    const priv = await check(w, files, { publicKey: w.privPem });
    expect(priv.status === "failed" && priv.code).toBe(
      "update.public_key_invalid",
    );
    if (priv.status === "failed") {
      expect(priv.failure.why).toContain("秘密鍵");
      expect(JSON.stringify(priv)).not.toContain("PRIVATE KEY");
    }

    const rsa = generateKeyPairSync("rsa", { modulusLength: 2048 });
    writeFileSync(
      join(w.dir, "rsa.pem"),
      rsa.publicKey.export({ type: "spki", format: "pem" }),
    );
    const notEd = await check(w, files, { publicKey: join(w.dir, "rsa.pem") });
    expect(notEd.status === "failed" && notEd.code).toBe(
      "update.public_key_invalid",
    );
    if (notEd.status === "failed")
      expect(notEd.failure.why).toContain("Ed25519");
  });

  it("署名ファイルが壊れている（base64 でない / 長さ違い） → signature_malformed", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    writeFileSync(files.signature, "not base64!!\n");
    const a = await check(w, files);
    expect(a.status === "failed" && a.code).toBe("update.signature_malformed");

    writeFileSync(files.signature, Buffer.alloc(32, 1).toString("base64"));
    const b = await check(w, files);
    expect(b.status === "failed" && b.code).toBe("update.signature_malformed");
  });

  it("サイズ上限: 大きすぎる manifest / 署名は読まずに止める", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    writeFileSync(files.manifest, Buffer.alloc(MAX_MANIFEST_BYTES + 1, 0x20));
    const a = await check(w, files);
    expect(a.status === "failed" && a.code).toBe("update.manifest_missing");
    if (a.status === "failed") expect(a.failure.why).toContain("大きすぎます");

    const files2 = writeSignedManifest(w, {});
    writeFileSync(
      files2.signature,
      Buffer.alloc(MAX_SIGNATURE_BYTES + 1, 0x41),
    );
    const b = await check(w, files2);
    expect(b.status === "failed" && b.code).toBe("update.signature_missing");
    if (b.status === "failed") expect(b.failure.why).toContain("大きすぎます");
  });
});

describe("update check — schema は署名の後に厳密に", () => {
  it("正しく署名された未知キー付き manifest → schema_invalid", async () => {
    const w = makeWorld();
    const bad = {
      ...manifestFor(w),
      download_url: "https://example.invalid/x",
    };
    const files = writeSigned(
      w,
      Buffer.from(`${JSON.stringify(bad, null, 2)}\n`),
    );
    const r = await check(w, files);
    expect(r.status === "failed" && r.code).toBe("update.schema_invalid");
  });

  it.each([
    ["version が semver でない", { version: "v0.9.5" }],
    [
      "sha256 が hex でない",
      { artifact: { name: "a.tgz", sha256: "xyz", bytes: 1 } },
    ],
    [
      "artifact 名に path 区切り",
      { artifact: { name: "../a.tgz", sha256: "a".repeat(64), bytes: 1 } },
    ],
    [
      "bytes が 0",
      { artifact: { name: "a.tgz", sha256: "a".repeat(64), bytes: 0 } },
    ],
    ["format_version が違う", { format_version: 2 }],
    ["product が違う", { product: "Other" }],
    [
      "compatibility が負",
      {
        compatibility: {
          template_schema_version: -1,
          agent_context_version: 1,
        },
      },
    ],
    ["released_at が日時でない", { released_at: "yesterday" }],
  ])("%s → schema_invalid", async (_label, over) => {
    const w = makeWorld();
    const bad = { ...manifestFor(w), ...(over as object) };
    const files = writeSigned(
      w,
      Buffer.from(`${JSON.stringify(bad, null, 2)}\n`),
    );
    const r = await check(w, files);
    expect(r.status === "failed" && r.code).toBe("update.schema_invalid");
  });

  it("JSON でない byte 列に正しい署名 → schema_invalid（署名検証は byte に対して成立する）", async () => {
    const w = makeWorld();
    const files = writeSigned(w, Buffer.from("this is not json"));
    const r = await check(w, files);
    expect(r.status === "failed" && r.code).toBe("update.schema_invalid");
  });

  it("schema 定義は未知キーを拒む（発行側と共有する定義そのもの）", () => {
    const w = makeWorld();
    const ok = releaseManifestSchema.safeParse(manifestFor(w));
    expect(ok.success).toBe(true);
    const extra = releaseManifestSchema.safeParse({
      ...manifestFor(w),
      url: "x",
    });
    expect(extra.success).toBe(false);
  });

  it("verifySignedManifest は署名 → schema の順（署名不一致なら schema を報告しない）", () => {
    const w = makeWorld();
    const bad = Buffer.from(JSON.stringify({ ...manifestFor(w), extra: 1 }));
    const r = verifySignedManifest({
      manifestBytes: bad,
      signatureRaw: Buffer.from(Buffer.alloc(64, 3).toString("base64")),
      publicKeyPem: readFileSync(w.pubPem),
    });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("signature_invalid");
  });
});

describe("update check — 版と互換性の判定", () => {
  it("候補が古い → older（導入しない）", async () => {
    const w = makeWorld({ ...defaultProject(), kit_version: "0.9.5" });
    const r = await check(w, writeSignedManifest(w, { version: "0.9.4" }));
    expect(r.status === "verified" && r.verdict).toBe("older");
  });

  it("同じ版 → same", async () => {
    const w = makeWorld({ ...defaultProject(), kit_version: "0.9.5" });
    const r = await check(w, writeSignedManifest(w));
    expect(r.status === "verified" && r.verdict).toBe("same");
  });

  it("rc を使っている人に stable は newer、stable の人に rc は older", async () => {
    const rcUser = makeWorld({ ...defaultProject(), kit_version: "0.9.5-rc3" });
    const a = await check(
      rcUser,
      writeSignedManifest(rcUser, { version: "0.9.5" }),
    );
    expect(a.status === "verified" && a.verdict).toBe("newer");

    const stableUser = makeWorld({ ...defaultProject(), kit_version: "0.9.5" });
    const b = await check(
      stableUser,
      writeSignedManifest(stableUser, { version: "0.9.5-rc3" }),
    );
    expect(b.status === "verified" && b.verdict).toBe("older");
  });

  it("互換性: 候補の契約が進む → migration_required、遅れる → candidate_behind", async () => {
    const w = makeWorld();
    const up = await check(
      w,
      writeSignedManifest(w, {
        compatibility: { template_schema_version: 2, agent_context_version: 1 },
      }),
    );
    expect(up.status === "verified" && up.compatibility.status).toBe(
      "migration_required",
    );
    if (up.status === "verified") {
      // この CLI 自身の契約より進んでいる → 候補版の CLI で確認せよ（導入可能性の理由に載る）
      expect(up.admissibility.status).toBe("migration_review_required");
      expect(up.admissibility.reasons.join("\n")).toContain("候補版の CLI");
    }

    const w2 = makeWorld({ ...defaultProject(), agent_context_version: 3 });
    const behind = await check(w2, writeSignedManifest(w2));
    expect(behind.status === "verified" && behind.compatibility.status).toBe(
      "candidate_behind",
    );
  });

  it("プロジェクトが無い → unknown（最新と言わない）", async () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    const empty = mkdtempSync(join(tmpdir(), "agentcms-noproj-"));
    made.push(empty);
    const r = await runUpdateCheck({
      manifest: files.manifest,
      signature: files.signature,
      publicKey: w.pubPem,
      cwd: empty,
    });
    expect(r.status).toBe("verified");
    if (r.status !== "verified") return;
    expect(r.verdict).toBe("unknown");
    expect(r.compatibility.status).toBe("unknown");
    expect(r.project).toBeNull();
    expect(r.warnings.join("\n")).toContain("利用中の版は不明");
  });

  it("project.json の kit_version が欠けている / 壊れている → unknown + 理由", async () => {
    const w = makeWorld({ name: "p" });
    const r = await check(w, writeSignedManifest(w));
    expect(r.status === "verified" && r.verdict).toBe("unknown");
    if (r.status === "verified")
      expect(r.project?.problem).toContain("kit_version");

    const w2 = makeWorld("{not json");
    const r2 = await check(w2, writeSignedManifest(w2));
    expect(r2.status === "verified" && r2.verdict).toBe("unknown");
  });

  it("released_at は書いてあるときだけ返す（無ければ捏造しない）", async () => {
    const w = makeWorld();
    const none = await check(w, writeSignedManifest(w));
    expect(
      none.status === "verified" && none.manifest.released_at,
    ).toBeUndefined();
    const some = await check(
      w,
      writeSignedManifest(w, { released_at: "2026-09-10T00:00:00Z" }),
    );
    expect(some.status === "verified" && some.manifest.released_at).toBe(
      "2026-09-10T00:00:00Z",
    );
  });
});

describe("update check — CLI プロセス実起動", () => {
  beforeAll(() => {
    if (!existsSync(cliDist)) {
      throw new Error(
        "dist/index.js がありません。pnpm build を先に実行してください",
      );
    }
  });

  const runCli = (args: string[], cwd: string) =>
    spawnSync(process.execPath, [cliDist, "update", "check", ...args], {
      cwd,
      encoding: "utf8",
      env: { ...process.env, FORCE_COLOR: "0", NO_COLOR: "1" },
    });

  it("成功: exit 0、『更新済み』とは言わず、project.json を変えず、記録を書く", () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    const before = readFileSync(
      join(w.projectRoot, ".agentcms/project.json"),
      "utf8",
    );
    const r = runCli(
      [
        "--manifest",
        files.manifest,
        "--signature",
        files.signature,
        "--public-key",
        w.pubPem,
        "--archive",
        w.archive,
      ],
      w.projectRoot,
    );
    expect(r.status, r.stdout + r.stderr).toBe(0);
    expect(r.stdout).toContain("署名: 検証済み");
    expect(r.stdout).toContain("実体照合済み");
    expect(r.stdout).toContain("新しい版があります");
    expect(r.stdout).toContain("まだ何も適用していません");
    expect(r.stdout).not.toMatch(/更新済み|更新完了|自動更新/);
    // 利用中の版は project.json から
    expect(r.stdout).toContain("利用中: v0.9.4-rc3");
    // 秘密鍵の中身・パスは出ない
    expect(r.stdout + r.stderr).not.toContain("PRIVATE KEY");
    expect(r.stdout + r.stderr).not.toContain("priv.pem");
    // 何も適用していない
    expect(
      readFileSync(join(w.projectRoot, ".agentcms/project.json"), "utf8"),
    ).toBe(before);
    // 非公開の記録
    const rec = JSON.parse(
      readFileSync(
        join(w.projectRoot, ".agentcms/local/update-check.json"),
        "utf8",
      ),
    );
    expect(rec.status).toBe("verified");
    expect(rec.applied).toBe(false);
    expect(JSON.stringify(rec)).not.toMatch(/https?:\/\//);
    expect(JSON.stringify(rec)).not.toContain(w.dir); // 入力の絶対パスも入れない
  });

  it("--json: 機械可読・秘密なし。--no-record で記録しない", () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    const r = runCli(
      [
        "--manifest",
        files.manifest,
        "--signature",
        files.signature,
        "--public-key",
        w.pubPem,
        "--json",
        "--no-record",
      ],
      w.projectRoot,
    );
    expect(r.status, r.stderr).toBe(0);
    const out = JSON.parse(r.stdout);
    expect(out.status).toBe("verified");
    expect(out.applied).toBe(false);
    expect(out.archive.status).toBe("not_checked");
    expect(out.verdict).toBe("newer");
    expect(
      existsSync(join(w.projectRoot, ".agentcms/local/update-check.json")),
    ).toBe(false);
  });

  it("誤鍵: exit 1、what/why/fix、失敗の記録は成功項目を持たない", () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    // 先に成功を記録しておき、失敗で上書きされ「前回の成功」が残らないことを見る
    runCli(
      [
        "--manifest",
        files.manifest,
        "--signature",
        files.signature,
        "--public-key",
        w.pubPem,
      ],
      w.projectRoot,
    );
    const r = runCli(
      [
        "--manifest",
        files.manifest,
        "--signature",
        files.signature,
        "--public-key",
        w.otherPubPem,
      ],
      w.projectRoot,
    );
    expect(r.status).toBe(1);
    expect(r.stderr).toContain("真正性を確認できませんでした");
    expect(r.stderr).toContain("導入しないでください");
    expect(r.stdout).not.toContain("新しい版があります");
    const rec = JSON.parse(
      readFileSync(
        join(w.projectRoot, ".agentcms/local/update-check.json"),
        "utf8",
      ),
    );
    expect(rec.status).toBe("failed");
    expect(rec.signature).toBe("not_verified");
    expect(rec.verdict).toBeUndefined();
    expect(rec.candidate).toBeUndefined();
  });

  it("古い版の候補: 署名は通るが exit 2（導入を進めない）", () => {
    const w = makeWorld({ ...defaultProject(), kit_version: "1.0.0" });
    const files = writeSignedManifest(w);
    const r = runCli(
      [
        "--manifest",
        files.manifest,
        "--signature",
        files.signature,
        "--public-key",
        w.pubPem,
      ],
      w.projectRoot,
    );
    expect(r.status).toBe(2);
    expect(r.stdout).toContain("署名: 検証済み");
    expect(r.stdout).toContain("古い版です");
    expect(r.stderr).toContain("導入を進めません");
    expect(r.stdout).not.toContain("次の手順");
  });

  it("プロジェクト外で実行: 『利用中: 不明』、記録は書かない、最新とは言わない", () => {
    const w = makeWorld();
    const files = writeSignedManifest(w);
    const outside = mkdtempSync(join(tmpdir(), "agentcms-outside-"));
    made.push(outside);
    const r = runCli(
      [
        "--manifest",
        files.manifest,
        "--signature",
        files.signature,
        "--public-key",
        w.pubPem,
      ],
      outside,
    );
    expect(r.status).toBe(2);
    expect(r.stdout).toContain("利用中: 不明");
    expect(r.stdout).toContain("比較できません");
    expect(r.stderr).toContain("導入を進めません");
    expect(r.stdout).not.toContain("新しい版があります");
    expect(existsSync(join(outside, ".agentcms"))).toBe(false);
  });

  it("必須オプション欠落は commander が止める（ネットへ出る既定値は無い）", () => {
    const w = makeWorld();
    const r = runCli(["--manifest", "x.json"], w.projectRoot);
    expect(r.status).not.toBe(0);
    expect(r.stderr).toMatch(/required option/);
  });
});

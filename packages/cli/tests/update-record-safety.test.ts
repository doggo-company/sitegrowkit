import { spawn, spawnSync } from "node:child_process";
import { generateKeyPairSync } from "node:crypto";
import {
  chmodSync,
  existsSync,
  linkSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  realpathSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

import {
  exitCodeFor,
  toRecordBody,
  writeUpdateCheckRecord,
} from "../src/commands/update.js";
import {
  checkArchiveAgainstManifest,
  encodeManifest,
  loadSigningPrivateKey,
  signManifestBytes,
  type ReleaseManifest,
} from "../src/updates/release-manifest.js";
import {
  digestFileSafe,
  readFileBounded,
  writePrivateFileAtomically,
} from "../src/updates/safe-io.js";
import {
  MAX_PROJECT_JSON_BYTES,
  readCurrentProject,
  runUpdateCheck,
  type UpdateCheckReport,
} from "../src/updates/update-check.js";

const here = dirname(fileURLToPath(import.meta.url));
const cliDist = join(here, "..", "dist", "index.js");

/**
 * 独立レビュー（2026-09-10）で再現された欠陥と、その周辺。
 *
 * 1. 記録の tmp 名が予測でき、先に symlink を置くと**リンク先の無関係なファイルが
 *    書き換わった**（sentinel の上書きを実測）。→ 乱数名 + O_EXCL|O_NOFOLLOW、
 *    親・最終保存先の link 拒否、失敗時の片付け。
 * 2. 署名が通れば older / candidate_behind / unknown でも exit 0 で導入手順を出していた。
 *    → admissibility を分け、進めないものは exit 2。
 * 3. 「契約番号が同じ = migration 不要の見込み」と言っていた。→ 言わない。
 * 4. stat → readFileSync の隙間・権限・削除で未処理例外。→ fd で上限つき実読込、Result 化。
 * 5. 記録に絶対パス・例外 raw を入れない。失敗時は成功項目を残さない。
 */

const made: string[] = [];
afterEach(() => {
  for (const d of made.splice(0)) {
    try {
      chmodSync(d, 0o700);
    } catch {
      // 既に無い
    }
    rmSync(d, { recursive: true, force: true });
  }
});

const SENTINEL = "DO NOT CHANGE\n";

function temp(prefix: string): string {
  const d = mkdtempSync(join(tmpdir(), prefix));
  made.push(d);
  return d;
}

function project(dir: string, json: unknown = defaultProject()): string {
  const root = join(dir, "proj");
  mkdirSync(join(root, ".agentcms"), { recursive: true });
  writeFileSync(
    join(root, ".agentcms", "project.json"),
    typeof json === "string" ? json : `${JSON.stringify(json, null, 2)}\n`,
  );
  return root;
}

function defaultProject(kit_version = "0.9.4") {
  return {
    name: "proj",
    kit_version,
    template_schema_version: 1,
    agent_context_version: 1,
  };
}

/** 記録の中身を試すための最小の report（署名検証は別ファイルで見ている） */
function fakeVerified(): UpdateCheckReport {
  return {
    status: "verified",
    cli_version: "0.9.4-rc3",
    trusted_key_fingerprint: `sha256:${"ab".repeat(32)}`,
    manifest: {
      format: "sitegrowkit-release-manifest",
      format_version: 1,
      product: "SiteGrowKit",
      version: "0.9.5",
      compatibility: { template_schema_version: 1, agent_context_version: 1 },
      artifact: { name: "a.tgz", sha256: "cd".repeat(32), bytes: 10 },
      signing_key_fingerprint: `sha256:${"ab".repeat(32)}`,
    },
    archive: { status: "not_checked" },
    project: {
      root: "/should/not/appear",
      kit_version: "0.9.4",
      template_schema_version: 1,
      agent_context_version: 1,
    },
    verdict: "newer",
    compatibility: {
      status: "same",
      template_schema: { current: 1, candidate: 1 },
      agent_context: { current: 1, candidate: 1 },
    },
    admissibility: {
      status: "review_required",
      reasons: ["ok"],
      archive_verified: false,
    },
    applied: false,
    warnings: [],
  };
}

function listTmpLeftovers(localDir: string): string[] {
  if (!existsSync(localDir)) return [];
  return readdirSync(localDir).filter((n) => n.endsWith(".tmp"));
}

describe("記録の書き込み — link を辿らない", () => {
  it("既に存在する通常の一時ファイルも削除しない", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const localDir = join(root, ".agentcms", "local");
    mkdirSync(localDir, { mode: 0o700 });
    const token = "deadbeefdeadbeef";
    const existing = join(localDir, `.update-check.json.${token}.tmp`);
    writeFileSync(existing, SENTINEL);
    const result = writeUpdateCheckRecord(root, fakeVerified(), token);
    expect(result.ok).toBe(false);
    expect(existsSync(existing)).toBe(true);
    expect(readFileSync(existing, "utf8")).toBe(SENTINEL);
    expect(existsSync(join(localDir, "update-check.json"))).toBe(false);
  });

  it("🔴 再現: 予め置かれた symlink と同名の tmp に書いても、リンク先の sentinel は変わらない", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const sentinel = join(dir, "sentinel.txt");
    writeFileSync(sentinel, SENTINEL);
    const localDir = join(root, ".agentcms", "local");
    mkdirSync(localDir, { mode: 0o700 });
    // 攻撃者が tmp 名を知っていたとしても（試験では token を固定して再現）
    const token = "deadbeefdeadbeef";
    symlinkSync(sentinel, join(localDir, `.update-check.json.${token}.tmp`));

    const r = writeUpdateCheckRecord(root, fakeVerified(), token);

    expect(r.ok).toBe(false);
    expect(readFileSync(sentinel, "utf8")).toBe(SENTINEL);
    expect(existsSync(join(localDir, "update-check.json"))).toBe(false);
    // 置かれていた symlink はこちらの物ではないので消さない（辿らない・触らない）
    expect(
      lstatSync(
        join(localDir, `.update-check.json.${token}.tmp`),
      ).isSymbolicLink(),
    ).toBe(true);
  });

  it("最終保存先が symlink なら書かない（リンク先は不変）", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const sentinel = join(dir, "sentinel.txt");
    writeFileSync(sentinel, SENTINEL);
    const localDir = join(root, ".agentcms", "local");
    mkdirSync(localDir, { mode: 0o700 });
    symlinkSync(sentinel, join(localDir, "update-check.json"));

    const r = writeUpdateCheckRecord(root, fakeVerified());

    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("symlink_refused");
    expect(readFileSync(sentinel, "utf8")).toBe(SENTINEL);
    expect(
      lstatSync(join(localDir, "update-check.json")).isSymbolicLink(),
    ).toBe(true);
    expect(listTmpLeftovers(localDir)).toEqual([]);
  });

  it("最終保存先が hardlink なら書かない（もう一方の名前から見た中身は不変）", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const localDir = join(root, ".agentcms", "local");
    mkdirSync(localDir, { mode: 0o700 });
    const other = join(dir, "other-name.json");
    writeFileSync(other, SENTINEL);
    linkSync(other, join(localDir, "update-check.json"));

    const r = writeUpdateCheckRecord(root, fakeVerified());

    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("hardlink_refused");
    expect(readFileSync(other, "utf8")).toBe(SENTINEL);
    expect(listTmpLeftovers(localDir)).toEqual([]);
  });

  it(".agentcms/local が symlink なら書かない", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const elsewhere = join(dir, "elsewhere");
    mkdirSync(elsewhere);
    symlinkSync(elsewhere, join(root, ".agentcms", "local"));

    const r = writeUpdateCheckRecord(root, fakeVerified());

    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("symlink_refused");
    expect(readdirSync(elsewhere)).toEqual([]);
  });

  it(".agentcms 自体が symlink なら書かない", () => {
    const dir = temp("agentcms-rec-");
    const root = join(dir, "proj");
    mkdirSync(root);
    const elsewhere = join(dir, "elsewhere");
    mkdirSync(elsewhere);
    symlinkSync(elsewhere, join(root, ".agentcms"));

    const r = writeUpdateCheckRecord(root, fakeVerified());

    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("symlink_refused");
    expect(readdirSync(elsewhere)).toEqual([]);
  });

  it("root は realpath に解決してから使う（symlink 越しの root でも実体の下に書く）", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const alias = join(dir, "alias");
    symlinkSync(root, alias);

    const r = writeUpdateCheckRecord(alias, fakeVerified());

    expect(r.ok).toBe(true);
    if (r.ok) expect(r.path.startsWith(realpathSync(root))).toBe(true);
    expect(
      existsSync(join(root, ".agentcms", "local", "update-check.json")),
    ).toBe(true);
  });

  it("既存の regular file は置き換わる。失敗時は既存が残り、tmp は残らない", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const localDir = join(root, ".agentcms", "local");
    mkdirSync(localDir, { mode: 0o700 });
    const target = join(localDir, "update-check.json");
    writeFileSync(target, '{"old":true}\n');

    const okWrite = writeUpdateCheckRecord(root, fakeVerified());
    expect(okWrite.ok).toBe(true);
    expect(JSON.parse(readFileSync(target, "utf8")).status).toBe("verified");
    expect(listTmpLeftovers(localDir)).toEqual([]);

    // 失敗させる: local を読み取り専用にして tmp を作れなくする
    if (process.getuid?.() === 0) return; // root は権限で止まらない
    const before = readFileSync(target, "utf8");
    chmodSync(localDir, 0o500);
    try {
      const failWrite = writeUpdateCheckRecord(root, fakeVerified());
      expect(failWrite.ok).toBe(false);
      if (!failWrite.ok) {
        expect(failWrite.code).toBe("permission_denied");
        expect(failWrite.reason).not.toMatch(/at |\n|Error:/); // stack / raw を持ち出さない
      }
    } finally {
      chmodSync(localDir, 0o700);
    }
    expect(readFileSync(target, "utf8")).toBe(before);
    expect(listTmpLeftovers(localDir)).toEqual([]);
  });

  it("反復 50 回で残骸なし・1 ファイル・常に有効な JSON", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const localDir = join(root, ".agentcms", "local");
    for (let i = 0; i < 50; i++) {
      const r = writeUpdateCheckRecord(root, fakeVerified());
      expect(r.ok).toBe(true);
    }
    expect(readdirSync(localDir)).toEqual(["update-check.json"]);
    expect(
      JSON.parse(readFileSync(join(localDir, "update-check.json"), "utf8"))
        .applied,
    ).toBe(false);
    expect(
      (lstatSync(join(localDir, "update-check.json")).mode & 0o777).toString(8),
    ).toBe("600");
  });

  it("ファイル名に path 区切りは渡せない", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    const r = writePrivateFileAtomically({
      root,
      dirSegments: [".agentcms", "local"],
      fileName: "../escape.json",
      content: "x",
    });
    expect(r.ok).toBe(false);
    expect(existsSync(join(root, ".agentcms", "escape.json"))).toBe(false);
  });
});

describe("記録の中身", () => {
  it("絶対パスを含まない。失敗の記録は成功項目を持たず why/fix も持たない", () => {
    const success = toRecordBody(
      fakeVerified(),
      new Date("2026-09-10T00:00:00Z"),
    );
    expect(JSON.stringify(success)).not.toContain("/should/not/appear");
    expect(success.checked_at).toBe("2026-09-10T00:00:00.000Z");
    expect(success.admissibility).toMatchObject({ status: "review_required" });

    const failure: UpdateCheckReport = {
      status: "failed",
      cli_version: "0.9.4-rc3",
      code: "update.manifest_missing",
      failure: {
        what: "配布物の manifest を読めません",
        why: "manifestが見つかりません: /Users/someone/secret-dir/release-manifest.json",
        fix: "…",
      },
      project: {
        root: "/Users/someone/proj",
        kit_version: "0.9.4",
        template_schema_version: 1,
        agent_context_version: 1,
      },
    };
    const rec = toRecordBody(failure);
    const text = JSON.stringify(rec);
    expect(text).not.toContain("/Users/");
    expect(rec.why).toBeUndefined();
    expect(rec.fix).toBeUndefined();
    expect(rec.status).toBe("failed");
    expect(rec.verdict).toBeUndefined();
    expect(rec.candidate).toBeUndefined();
    expect(rec.admissibility).toBeUndefined();
    expect(rec.signature).toBe("not_verified");
  });

  it("成功のあとの失敗は、成功を消して失敗を残す（前回の成功が今回の成功に見えない）", () => {
    const dir = temp("agentcms-rec-");
    const root = project(dir);
    expect(writeUpdateCheckRecord(root, fakeVerified()).ok).toBe(true);
    const failure: UpdateCheckReport = {
      status: "failed",
      cli_version: "0.9.4-rc3",
      code: "update.signature_invalid",
      failure: { what: "x", why: "y", fix: "z" },
      project: fakeVerified().project,
    };
    expect(writeUpdateCheckRecord(root, failure).ok).toBe(true);
    const rec = JSON.parse(
      readFileSync(
        join(root, ".agentcms", "local", "update-check.json"),
        "utf8",
      ),
    );
    expect(rec.status).toBe("failed");
    expect(rec.candidate).toBeUndefined();
    expect(rec.verdict).toBeUndefined();
  });
});

describe("上限つき実読込と安全な失敗", () => {
  it("readFileBounded: 上限ちょうどは読み、1 byte 超えは too_large", () => {
    const dir = temp("agentcms-io-");
    writeFileSync(join(dir, "exact"), Buffer.alloc(100, 1));
    writeFileSync(join(dir, "over"), Buffer.alloc(101, 1));
    const exact = readFileBounded(join(dir, "exact"), 100);
    expect(exact.ok).toBe(true);
    if (exact.ok) expect(exact.bytes.byteLength).toBe(100);
    const over = readFileBounded(join(dir, "over"), 100);
    expect(over.ok === false && over.code).toBe("too_large");
  });

  it("readFileBounded: 無い / ディレクトリ / 権限なし は Result（投げない）", () => {
    const dir = temp("agentcms-io-");
    const missing = readFileBounded(join(dir, "nope"), 10);
    expect(missing.ok === false && missing.code).toBe("not_found");
    const isDir = readFileBounded(dir, 10);
    expect(isDir.ok === false && isDir.code).toBe("not_a_file");
    if (process.getuid?.() !== 0) {
      writeFileSync(join(dir, "locked"), "x");
      chmodSync(join(dir, "locked"), 0o000);
      const denied = readFileBounded(join(dir, "locked"), 10);
      expect(denied.ok === false && denied.code).toBe("permission_denied");
      chmodSync(join(dir, "locked"), 0o600);
    }
  });

  it("digestFileSafe: ディレクトリ / 無い は Result。読めたら bytes と sha256", () => {
    const dir = temp("agentcms-io-");
    writeFileSync(join(dir, "a"), "abc");
    const ok = digestFileSafe(join(dir, "a"));
    expect(ok.ok).toBe(true);
    if (ok.ok) {
      expect(ok.bytes).toBe(3);
      expect(ok.sha256).toBe(
        "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
      );
    }
    expect(digestFileSafe(dir).ok).toBe(false);
    expect(digestFileSafe(join(dir, "nope")).ok).toBe(false);
  });

  it("checkArchiveAgainstManifest: 読めないものは unreadable（成功にしない）", () => {
    const dir = temp("agentcms-io-");
    const m = fakeVerified();
    if (m.status !== "verified") return;
    const asDir = checkArchiveAgainstManifest(dir, m.manifest);
    expect(asDir.status).toBe("unreadable");
    const missing = checkArchiveAgainstManifest(
      join(dir, "nope.tgz"),
      m.manifest,
    );
    expect(missing.status).toBe("unreadable");
    if (missing.status === "unreadable") expect(missing.code).toBe("not_found");
  });

  it("project.json: 上限超過 / 負の整数 / 範囲外 / 配列 は unknown（problem 付き）", () => {
    const dir = temp("agentcms-io-");
    const big = project(
      dir,
      `{"kit_version":"0.9.4","pad":"${"x".repeat(MAX_PROJECT_JSON_BYTES)}"}`,
    );
    const r1 = readCurrentProject(big);
    expect(r1.kit_version).toBeNull();
    expect(r1.problem).toContain("大きすぎます");

    const neg = project(temp("agentcms-io-"), {
      kit_version: "0.9.4",
      template_schema_version: -1,
      agent_context_version: 1,
    });
    const r2 = readCurrentProject(neg);
    expect(r2.kit_version).toBe("0.9.4");
    expect(r2.template_schema_version).toBeNull();
    expect(r2.problem).toContain("template_schema_version");

    const huge = project(temp("agentcms-io-"), {
      kit_version: "0.9.4",
      template_schema_version: 1,
      agent_context_version: 10_000_000,
    });
    expect(readCurrentProject(huge).agent_context_version).toBeNull();

    const arr = project(temp("agentcms-io-"), "[1,2]");
    expect(readCurrentProject(arr).problem).toContain("オブジェクト");

    if (process.getuid?.() !== 0) {
      const locked = project(temp("agentcms-io-"));
      chmodSync(join(locked, ".agentcms", "project.json"), 0o000);
      const r = readCurrentProject(locked);
      expect(r.kit_version).toBeNull();
      expect(r.problem).toContain("権限");
      chmodSync(join(locked, ".agentcms", "project.json"), 0o600);
    }
  });
});

/* ---------------- admissibility（署名 OK でも導入可能とは別） ---------------- */

function signedWorld(
  projectJson: unknown,
  over: Partial<ReleaseManifest> = {},
) {
  const dir = temp("agentcms-adm-");
  const { privateKey, publicKey } = generateKeyPairSync("ed25519");
  const privPem = privateKey.export({ type: "pkcs8", format: "pem" }) as string;
  writeFileSync(
    join(dir, "pub.pem"),
    publicKey.export({ type: "spki", format: "pem" }),
  );
  const loaded = loadSigningPrivateKey(privPem);
  if (!loaded.ok) throw new Error(loaded.reason);
  const manifest: ReleaseManifest = {
    format: "sitegrowkit-release-manifest",
    format_version: 1,
    product: "SiteGrowKit",
    version: "0.9.5",
    compatibility: { template_schema_version: 1, agent_context_version: 1 },
    artifact: {
      name: "agent-cms-kit-0.9.5.tgz",
      sha256: "00".repeat(32),
      bytes: 1,
    },
    signing_key_fingerprint: loaded.fingerprint,
    ...over,
  };
  const bytes = encodeManifest(manifest);
  writeFileSync(join(dir, "m.json"), bytes);
  writeFileSync(join(dir, "m.json.sig"), signManifestBytes(bytes, loaded.key));
  const root = projectJson === null ? null : project(dir, projectJson);
  return {
    dir,
    root,
    args: {
      manifest: join(dir, "m.json"),
      signature: join(dir, "m.json.sig"),
      publicKey: join(dir, "pub.pem"),
    },
  };
}

describe("admissibility — 署名検証と導入可能性は別", () => {
  it("newer + 契約同じ → review_required / exit 0。migration 不要とは言わない", async () => {
    const w = signedWorld(defaultProject("0.9.4"));
    const r = await runUpdateCheck({ ...w.args, project: w.root! });
    expect(r.status).toBe("verified");
    if (r.status !== "verified") return;
    expect(r.admissibility.status).toBe("review_required");
    expect(r.admissibility.archive_verified).toBe(false);
    expect(r.admissibility.reasons.join("\n")).toContain("未照合");
    expect(r.admissibility.reasons.join("\n")).toContain("database/migrations");
    expect(r.admissibility.reasons.join("\n")).not.toMatch(
      /不要の見込み|移行手順は不要/,
    );
    expect(exitCodeFor(r)).toBe(0);
  });

  it("same → review_required（導入するものは無い）/ exit 0", async () => {
    const w = signedWorld(defaultProject("0.9.5"));
    const r = await runUpdateCheck({ ...w.args, project: w.root! });
    expect(r.status === "verified" && r.admissibility.status).toBe(
      "review_required",
    );
    expect(exitCodeFor(r)).toBe(0);
  });

  it("older → blocked / exit 2、理由に両方の版", async () => {
    const w = signedWorld(defaultProject("1.0.0"));
    const r = await runUpdateCheck({ ...w.args, project: w.root! });
    expect(r.status).toBe("verified");
    if (r.status !== "verified") return;
    expect(r.verdict).toBe("older");
    expect(r.admissibility.status).toBe("blocked");
    expect(r.admissibility.reasons.join("\n")).toContain("v0.9.5");
    expect(r.admissibility.reasons.join("\n")).toContain("v1.0.0");
    expect(exitCodeFor(r)).toBe(2);
  });

  it("candidate_behind → blocked / exit 2", async () => {
    const w = signedWorld({
      ...defaultProject("0.9.4"),
      agent_context_version: 2,
    });
    const r = await runUpdateCheck({ ...w.args, project: w.root! });
    expect(r.status === "verified" && r.admissibility.status).toBe("blocked");
    if (r.status === "verified") {
      expect(r.admissibility.reasons.join("\n")).toContain("後退");
    }
    expect(exitCodeFor(r)).toBe(2);
  });

  it("利用中の版が不明（project 無し / kit_version 無し / 契約が読めない）→ blocked / exit 2", async () => {
    const none = signedWorld(null);
    const outside = temp("agentcms-adm-out-");
    const r1 = await runUpdateCheck({ ...none.args, cwd: outside });
    expect(r1.status === "verified" && r1.admissibility.status).toBe("blocked");
    expect(exitCodeFor(r1)).toBe(2);

    const noKv = signedWorld({ name: "p" });
    const r2 = await runUpdateCheck({ ...noKv.args, project: noKv.root! });
    expect(r2.status === "verified" && r2.admissibility.status).toBe("blocked");
    if (r2.status === "verified") {
      expect(r2.admissibility.reasons.join("\n")).toContain("利用中の版が不明");
    }

    const badContract = signedWorld({
      kit_version: "0.9.4",
      template_schema_version: "1",
      agent_context_version: 1,
    });
    const r3 = await runUpdateCheck({
      ...badContract.args,
      project: badContract.root!,
    });
    expect(r3.status === "verified" && r3.admissibility.status).toBe("blocked");
    if (r3.status === "verified") {
      expect(r3.admissibility.reasons.join("\n")).toContain(
        "構造契約を読めません",
      );
    }
  });

  it("migration_required → migration_review_required / exit 2", async () => {
    const w = signedWorld(defaultProject("0.9.4"), {
      compatibility: { template_schema_version: 1, agent_context_version: 2 },
    });
    const r = await runUpdateCheck({ ...w.args, project: w.root! });
    expect(r.status === "verified" && r.admissibility.status).toBe(
      "migration_review_required",
    );
    if (r.status === "verified") {
      const text = r.admissibility.reasons.join("\n");
      expect(text).toContain("移行手順");
      // この CLI の契約（1/1）より候補（1/2）が進んでいる → 候補版の CLI でも確認
      expect(text).toContain("候補版の CLI");
    }
    expect(exitCodeFor(r)).toBe(2);
  });

  it("blocked は hold より強い（older かつ migration_required → blocked）", async () => {
    const w = signedWorld(defaultProject("2.0.0"), {
      compatibility: { template_schema_version: 2, agent_context_version: 1 },
    });
    const r = await runUpdateCheck({ ...w.args, project: w.root! });
    expect(r.status === "verified" && r.admissibility.status).toBe("blocked");
  });
});

describe("CLI プロセス — exit code と表示、同時書込", () => {
  const runCli = (args: string[], cwd: string) =>
    spawnSync(process.execPath, [cliDist, "update", "check", ...args], {
      cwd,
      encoding: "utf8",
      env: { ...process.env, FORCE_COLOR: "0", NO_COLOR: "1" },
    });
  const argv = (w: ReturnType<typeof signedWorld>) => [
    "--manifest",
    w.args.manifest,
    "--signature",
    w.args.signature,
    "--public-key",
    w.args.publicKey,
  ];

  it("older: exit 2、導入手順を出さず、止める理由を出す。記録は blocked", () => {
    const w = signedWorld(defaultProject("1.0.0"));
    const r = runCli(argv(w), w.root!);
    expect(r.status).toBe(2);
    expect(r.stderr).toContain("導入を進めません");
    expect(r.stderr).toContain("古い版");
    expect(r.stdout).not.toContain("次の手順");
    expect(r.stdout).not.toContain("kit_version を新しい版へ");
    const rec = JSON.parse(
      readFileSync(join(w.root!, ".agentcms/local/update-check.json"), "utf8"),
    );
    expect(rec.admissibility.status).toBe("blocked");
    expect(rec.applied).toBe(false);
  });

  it("プロジェクト外: exit 2、記録は書かない", () => {
    const w = signedWorld(null);
    const outside = temp("agentcms-adm-out-");
    const r = runCli(argv(w), outside);
    expect(r.status).toBe(2);
    expect(r.stdout).toContain("利用中: 不明");
    expect(existsSync(join(outside, ".agentcms"))).toBe(false);
  });

  it("migration_required: exit 2、『移行確認待ち』", () => {
    const w = signedWorld(defaultProject("0.9.4"), {
      compatibility: { template_schema_version: 2, agent_context_version: 1 },
    });
    const r = runCli(argv(w), w.root!);
    expect(r.status).toBe(2);
    expect(r.stdout).toContain("移行確認待ち");
    expect(r.stdout).not.toContain("次の手順");
  });

  it("newer + 契約同じ: exit 0、次工程へ。『不要の見込み』は言わない", () => {
    const w = signedWorld(defaultProject("0.9.4"));
    const r = runCli([...argv(w), "--json"], w.root!);
    expect(r.status, r.stderr).toBe(0);
    const out = JSON.parse(r.stdout);
    expect(out.admissibility.status).toBe("review_required");
    const human = runCli(argv(w), w.root!);
    expect(human.status).toBe(0);
    expect(human.stdout).toContain("次工程へ進めます");
    expect(human.stdout).toContain("database/migrations");
    expect(human.stdout).not.toMatch(/不要の見込み|移行手順は不要/);
  });

  it("同時に 8 プロセスが記録を書いても、残骸なし・有効な JSON・sentinel 不変", async () => {
    const w = signedWorld(defaultProject("0.9.4"));
    const localDir = join(w.root!, ".agentcms", "local");
    mkdirSync(localDir, { mode: 0o700 });
    const sentinel = join(w.dir, "sentinel.txt");
    writeFileSync(sentinel, SENTINEL);
    // 予測できそうな名前を先に埋めておく（乱数名なので当たらないが、当たっても辿らない）
    for (const token of ["0000000000000000", "ffffffffffffffff"]) {
      symlinkSync(sentinel, join(localDir, `.update-check.json.${token}.tmp`));
    }
    const runs = Array.from(
      { length: 8 },
      () =>
        new Promise<number>((resolve) => {
          const child = spawn(
            process.execPath,
            [cliDist, "update", "check", ...argv(w)],
            {
              cwd: w.root!,
              stdio: "ignore",
            },
          );
          child.on("exit", (code) => resolve(code ?? -1));
        }),
    );
    const codes = await Promise.all(runs);
    expect(codes.every((c) => c === 0)).toBe(true);
    const files = readdirSync(localDir).sort();
    expect(
      files.filter(
        (f) =>
          f.endsWith(".tmp") &&
          !f.includes("0000000000000000") &&
          !f.includes("ffffffffffffffff"),
      ),
    ).toEqual([]);
    expect(files).toContain("update-check.json");
    const rec = JSON.parse(
      readFileSync(join(localDir, "update-check.json"), "utf8"),
    );
    expect(rec.status).toBe("verified");
    expect(readFileSync(sentinel, "utf8")).toBe(SENTINEL);
  });
});

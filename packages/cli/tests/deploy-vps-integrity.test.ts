import { spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import {
  decodeCurrent,
  integrityCheck,
  readCurrent,
  rebuildReleaseFromArtifact,
  uploadAndExtractVps,
  vpsDoctor,
} from "../src/deploy/providers/vps.js";
import { vpsNames } from "../src/deploy/providers/vps-assets.js";
import type { DeployContext } from "../src/deploy/release.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * サイトが書き換えられる物（current の値・置き場所の中の release）を、サーバーの判断の材料にしない（ADR-0021）。
 * - current の値は base64 の 1 行で受け取り、改行で診断の行を足させない
 * - 送った artifact は control に残し、展開はそこから標準入力で（サイトの tmp/ を経由しない）
 * - rollback は戻す先を artifact から作り直す・doctor は公開中のコードを artifact と比べる
 */

const run = process.platform === "win32" ? describe.skip : describe;
const b64 = (s: string) => Buffer.from(s).toString("base64");

function cfgAt(root: string): DeployTargetConfig {
  return {
    provider: "vps",
    target: "staging",
    ssh: { host: "h", port: 22, user: "deploy" },
    remote: { appDir: `${root}/sites/shop-a`, docroot: "", phpBin: "php", dbHost: "agentcms-mysql", container: "agentcms-shop-a-app" },
    app: { url: "https://a.example.com", healthPath: "/healthz" },
    keepReleases: 3,
    vps: { site: "shop-a", root },
  };
}

class Canned implements RemoteExecutor {
  constructor(private out: (cmd: string) => ExecResult) {}
  label() {
    return "canned";
  }
  async exec(cmd: string) {
    return this.out(cmd);
  }
  async upload() {
    return { code: 0, stdout: "", stderr: "" };
  }
}

describe("current の値で診断の行を足させない", () => {
  const n = vpsNames(cfgAt("/srv/agentcms"));

  it("このサイトの releases/<14 桁> だけを信じる", () => {
    expect(decodeCurrent(n, b64("/srv/agentcms/sites/shop-a/releases/20261124000000"))).toEqual({ target: "/srv/agentcms/sites/shop-a/releases/20261124000000", odd: false });
    expect(decodeCurrent(n, b64("/srv/agentcms/sites/shop-a/releases/20261124000000\ncaddy=true")).odd).toBe(true);
    expect(decodeCurrent(n, b64("/srv/agentcms/sites/shop-b/releases/20261124000000")).odd).toBe(true);
    expect(decodeCurrent(n, "")).toEqual({ target: null, odd: false });
  });

  it("doctor: 先に出た本物の行を、後の行で上書きさせない（同じ鍵は最初の行）", async () => {
    const lines = ["docker=28", "compose=2", "root_writable=yes", "caddy=false", "mysql=false", "php_image=no", "env=no", `current_b64=${b64("/srv/agentcms/sites/shop-a/releases/20261124000000")}`, "caddy=true", "mysql=true", "php_image=yes", "env=yes", "integrity=none", "backup_status=none", "backup_container=running", "server_now=1"];
    const ex = new Canned((cmd) => (cmd.includes("agentcms-ssh-ok") ? { code: 0, stdout: "agentcms-ssh-ok", stderr: "" } : { code: 0, stdout: lines.join("\n"), stderr: "" }));
    const { capabilities } = await vpsDoctor(ex, cfgAt("/srv/agentcms"));
    const st = Object.fromEntries(capabilities.checks.map((c) => [c.key, c.status]));
    expect(st.edge).toBe("FAIL");
    expect(st.php_image).toBe("FAIL");
    expect(st.env).toBe("FAIL");
  });

  it("readCurrent: 改行で「current-none」を足しても、初回とは読まない", async () => {
    const ctx = (stdout: string): DeployContext => ({ cfg: cfgAt("/srv/agentcms"), exec: new Canned(() => ({ code: 0, stdout, stderr: "" })), phpBin: "php", releaseId: "20261124000000", log: () => {} });
    expect((await readCurrent(ctx(`current_b64=${b64("/srv/agentcms/sites/shop-a/releases/20261124000000")}`))).ok).toBe(true);
    expect(await readCurrent(ctx("current-none"))).toEqual({ ok: true, target: null });
    expect((await readCurrent(ctx(`current_b64=${b64("x")}\ncurrent-none`))).ok).toBe(false);
    expect((await readCurrent(ctx(`current_b64=${b64("/srv/agentcms/sites/shop-a/releases/1\ncurrent-none")}`))).ok).toBe(false);
  });

  it("公開中のコードが送った物と違えば WARN（違いの数だけ・ファイル名は出さない）", () => {
    expect(integrityCheck(n, "none")).toBeNull();
    expect(integrityCheck(n, "diff=0 extra=0")?.status).toBe("PASS");
    const w = integrityCheck(n, "diff=2 extra=1");
    expect(w?.status).toBe("WARN");
    expect(w?.what).toContain("中身の違い 2 か所・足された物 1 個");
    expect(integrityCheck(n, "error")?.status).toBe("WARN");
  });
});

// ---------------------------------------------------------------- 台本を流す（docker は偽物・tar は本物）

let root = "";
const FAKE_DOCKER = `#!/bin/bash
printf '%s\\n' "$*" >> "$FAKE_LOG"
if [ "$1" = run ]; then
  shift; w=""; vols=0; vol=""
  while [ $# -gt 0 ]; do
    case "$1" in
      -w) w="$2"; shift 2 ;;
      -v) vols=$((vols + 1)); vol="$2"; shift 2 ;;
      -c) shift; break ;;
      *) shift ;;
    esac
  done
  [ "$vols" = 1 ] && [ "$vol" = "$w:$w" ] || { echo "fake: 置き場所だけを入れていない"; exit 99; }
  cd "$w" && exec sh -c "$1"
fi
exit 0
`;
class Local implements RemoteExecutor {
  label() {
    return "local";
  }
  async exec(command: string): Promise<ExecResult> {
    const r = spawnSync("sh", ["-c", command], { encoding: "utf8", env: { ...process.env, PATH: `${join(root, "bin")}:${process.env.PATH}`, FAKE_LOG: join(root, "docker.log") } });
    return { code: r.status ?? 1, stdout: r.stdout.trim(), stderr: r.stderr.trim() };
  }
  async upload(local?: string, remote?: string): Promise<ExecResult> {
    const r = spawnSync("cp", [local!, remote!]);
    return { code: r.status ?? 1, stdout: "", stderr: "" };
  }
}
function artifact(v: string): string {
  const src = join(root, `art-src-${v}`);
  mkdirSync(join(src, "app"), { recursive: true });
  writeFileSync(join(src, "artisan"), "#!/usr/bin/env php\n");
  writeFileSync(join(src, "app", "Kernel.php"), `<?php // ${v}\n`);
  const out = join(root, `art-${v}.tar.gz`);
  spawnSync("tar", ["-czf", out, "-C", src, "."]);
  return out;
}

run("送った artifact を control に残し、そこから展開・作り直す", () => {
  beforeEach(() => {
    root = mkdtempSync(join(tmpdir(), "agentcms-vps-art-"));
    mkdirSync(join(root, "bin"));
    writeFileSync(join(root, "bin", "docker"), FAKE_DOCKER);
    chmodSync(join(root, "bin", "docker"), 0o755);
    mkdirSync(join(root, "sites", "shop-a", "releases"), { recursive: true });
  });
  afterEach(() => rmSync(root, { recursive: true, force: true }));
  const ctx = (id: string): DeployContext => ({ cfg: cfgAt(root), exec: new Local(), phpBin: "php", releaseId: id, log: () => {} });

  it("展開は control の artifact から（サイトの tmp/ を経由しない）・artifact と checksum が残る", async () => {
    const r = await uploadAndExtractVps(ctx("20261124000000"), artifact("v1"));
    expect(r.ok, r.detail).toBe(true);
    const n = vpsNames(cfgAt(root));
    expect(readFileSync(join(n.appDir, "releases/20261124000000/app/Kernel.php"), "utf8")).toContain("v1");
    expect(existsSync(join(n.controlDir, "artifacts/20261124000000.tar.gz"))).toBe(true);
    expect(readFileSync(join(n.controlDir, "artifacts/20261124000000.sha256"), "utf8")).toMatch(/^[0-9a-f]{64} {2}20261124000000\.tar\.gz/);
    expect(existsSync(join(n.appDir, "tmp/20261124000000.tar.gz"))).toBe(false);
    expect(existsSync(join(n.controlDir, "upload/20261124000000.tar.gz"))).toBe(false);
  });

  it("rollback の前に、書き換えられた古い release を artifact から作り直す", async () => {
    const n = vpsNames(cfgAt(root));
    await uploadAndExtractVps(ctx("20261124000000"), artifact("v1"));
    // サイトの中から古い release にバックドアを置いた
    writeFileSync(join(n.appDir, "releases/20261124000000/app/Kernel.php"), "<?php // BACKDOOR\n");
    writeFileSync(join(n.appDir, "releases/20261124000000/app/evil.php"), "<?php // BACKDOOR\n");
    const r = await rebuildReleaseFromArtifact(ctx("rollback"), "20261124000000");
    expect(r).toEqual({ ok: true, rebuilt: true });
    expect(readFileSync(join(n.appDir, "releases/20261124000000/app/Kernel.php"), "utf8")).toContain("v1");
    expect(existsSync(join(n.appDir, "releases/20261124000000/app/evil.php"))).toBe(false);
    expect(existsSync(join(n.appDir, "releases/20261124000000/.agentcms-healthy"))).toBe(true);
  });

  it("artifact の checksum が合わなければ作り直さずに止まる・artifact が無ければそう言う", async () => {
    const n = vpsNames(cfgAt(root));
    await uploadAndExtractVps(ctx("20261124000000"), artifact("v1"));
    writeFileSync(join(n.controlDir, "artifacts/20261124000000.tar.gz"), "x");
    expect((await rebuildReleaseFromArtifact(ctx("rollback"), "20261124000000")).ok).toBe(false);
    expect(await rebuildReleaseFromArtifact(ctx("rollback"), "20261123000000")).toEqual({ ok: true, rebuilt: false });
  });
});

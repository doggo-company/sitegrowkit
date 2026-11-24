import { spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  symlinkSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { vpsDeploySteps } from "../src/commands/deploy-vps.js";
import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import {
  VIEWS_LINE_RE,
  appendFirstRenderViews,
  recordViews,
  viewsCheck,
  viewsCommand,
  vpsDoctor,
} from "../src/deploy/providers/vps.js";
import { MYSQL_IMAGE, vpsNames } from "../src/deploy/providers/vps-assets.js";
import type { DeployContext } from "../src/deploy/release.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * 画面の部品（storage の中のコンパイル済みの view）と bootstrap/cache は、サイトのコンテナから書ける場所か
 * deploy が作る物なので、送った artifact とは比べられない。deploy はキャッシュを作ったあとに一覧と checksum を
 * control（サイトから見えない）に残し、doctor はいまの一覧をそれと比べる（ADR-0021）。
 */

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

describe("doctor: 画面の部品の行", () => {
  const n = vpsNames(cfgAt("/srv/agentcms"));

  it("同じなら PASS・消えた物だけなら PASS（作り直せる）", () => {
    expect(viewsCheck(n, "changed=0 extra=0 gone=0")?.status).toBe("PASS");
    const g = viewsCheck(n, "changed=0 extra=0 gone=3");
    expect(g?.status).toBe("PASS");
    expect(g?.detected).toContain("消えた物 3 個");
  });

  it("書き換え・足された物は WARN（数だけ・名前は出さない）", () => {
    const w = viewsCheck(n, "changed=2 extra=1 gone=0");
    expect(w?.status).toBe("WARN");
    expect(w?.what).toContain("書き換え 2 個・足された物 1 個");
    expect(w?.why).toContain("乗っ取られた可能性");
    const e = viewsCheck(n, "changed=0 extra=4 gone=0");
    expect(e?.status).toBe("WARN");
    expect(e?.what).toContain("書き換え 0 個・足された物 4 個");
    expect(e?.why).toContain("区別できません");
  });

  it("出したことがあるのに比べられない・記録が無い・公開の記録に無い → WARN（黙らない）", () => {
    expect(viewsCheck(n, "none")).toBeNull();
    expect(viewsCheck(n, "")).toBeNull();
    expect(viewsCheck(n, "none", true)?.status).toBe("WARN");
    expect(viewsCheck(n, "unknown", true)?.what).toContain("公開の記録");
    expect(viewsCheck(n, "error")?.status).toBe("WARN");
    expect(viewsCheck(n, "missing")?.status).toBe("WARN");
    // 形の違う値は PASS にしない
    expect(viewsCheck(n, "changed=0 extra=0")?.status).toBe("WARN");
    expect(viewsCheck(n, "changed=0 extra=0 gone=0\nx")?.status).toBe("WARN");
  });

  it("doctor はサーバーの材料（views=…）から WARN にする", async () => {
    const lines = ["docker=28", "compose=2", "root_writable=yes", "caddy=true", "mysql=true", "php_image=yes", "env=yes", "history_n=1", "current_kind=link", `current_b64=${b64("/srv/agentcms/sites/shop-a/releases/20261124000000")}`, "integrity=diff=0 extra=0", "views=changed=1 extra=0 gone=0", "backup_status=none", "backup_container=running", "server_now=1800000100"];
    const ex = new Canned((cmd) => (cmd.includes("agentcms-ssh-ok") ? { code: 0, stdout: "agentcms-ssh-ok", stderr: "" } : { code: 0, stdout: lines.join("\n"), stderr: "" }));
    const { capabilities } = await vpsDoctor(ex, cfgAt("/srv/agentcms"));
    const by = Object.fromEntries(capabilities.checks.map((c) => [c.key, c]));
    expect(by.integrity?.status).toBe("PASS");
    expect(by.views?.status).toBe("WARN");
    expect(by.views?.what).toContain("書き換え 1 個");
  });

  it("deploy は、キャッシュを作ったあと・切り替えて起こす前（サイトのコンテナを止めている間）に記録を残す", async () => {
    const ex = new Canned(() => ({ code: 0, stdout: "views-ok 3", stderr: "" }));
    const ctx: DeployContext = { cfg: cfgAt("/srv/agentcms"), exec: ex, phpBin: "php", releaseId: "20261124000000", log: () => {} };
    const steps = vpsDeploySteps(ctx, "/tmp/a.tar.gz");
    const at = (l: string) => steps.findIndex(([x]) => x.includes(l));
    expect(at("画面の部品の checksum")).toBeGreaterThan(at("config / view キャッシュ"));
    expect(at("画面の部品の checksum")).toBeGreaterThan(at("止め、storage"));
    expect(at("画面の部品の checksum")).toBeLessThan(at("current 切り替え"));
    expect(at("画面の部品の checksum")).toBeLessThan(at("サイトのコンテナを起こす"));
    const r = await steps[at("画面の部品の checksum")]![1]();
    expect(r.ok).toBe(true);
    // 記録の置き場所は control（サイトのコンテナから見えない）
    expect(r.detail).toContain("/srv/agentcms/control/shop-a/artifacts/20261124000000.views");
  });

  it("doctor の台本は、記録が control の物で、公開の記録にある release のときだけ比べる", async () => {
    let script = "";
    const ex = new Canned((cmd) => {
      if (cmd.includes("agentcms-ssh-ok")) return { code: 0, stdout: "agentcms-ssh-ok", stderr: "" };
      script += cmd;
      return { code: 0, stdout: "docker=28", stderr: "" };
    });
    await vpsDoctor(ex, cfgAt("/srv/agentcms"));
    expect(script).toContain("echo views=unknown");
    expect(script).toContain("/srv/agentcms/control/shop-a/artifacts'/\"$id\".views");
    expect(script).toContain("[ ! -L '/srv/agentcms/control/shop-a/artifacts'/\"$id\".views ]");
    // 比べるコンテナは置き場所も記録も読むだけで入れ、ネットワークも権限も無い
    expect(script).toMatch(/-v '[^']*sites\/shop-a:\/site:ro'/);
    expect(script).toContain('.views:/m:ro');
    expect(script).toContain("--network none --cap-drop ALL");
  });
});

// ---------------------------------------------------------------- 台本を本物の Docker（MySQL の公式イメージ）で流す

const hasDocker =
  process.platform !== "win32" &&
  spawnSync("docker", ["image", "inspect", MYSQL_IMAGE], { stdio: "ignore" }).status === 0;
const withDocker = hasDocker ? describe : describe.skip;

class Local implements RemoteExecutor {
  label() {
    return "local";
  }
  async exec(command: string): Promise<ExecResult> {
    const r = spawnSync("bash", ["-c", command], { encoding: "utf8" });
    return { code: r.status ?? 1, stdout: r.stdout.trim(), stderr: r.stderr.trim() };
  }
  async upload(): Promise<ExecResult> {
    return { code: 0, stdout: "", stderr: "" };
  }
}

withDocker("画面の部品の記録と比べ（本物の Docker・MySQL のイメージの GNU find / awk）", () => {
  let root = "";
  const ID = "20261124000000";
  beforeEach(() => {
    root = realpathSync(mkdtempSync(join(tmpdir(), "agentcms-vps-views-")));
    const n = vpsNames(cfgAt(root));
    mkdirSync(join(n.appDir, `releases/${ID}/bootstrap/cache`), { recursive: true });
    mkdirSync(join(n.appDir, "shared/storage/framework/views"), { recursive: true });
    mkdirSync(n.controlDir, { recursive: true });
    writeFileSync(join(n.appDir, `releases/${ID}/bootstrap/cache/config.php`), "<?php return [];\n");
    writeFileSync(join(n.appDir, `releases/${ID}/bootstrap/cache/packages.php`), "<?php return [];\n");
    writeFileSync(join(n.appDir, "shared/storage/framework/views/.gitignore"), "*\n!.gitignore\n");
    writeFileSync(join(n.appDir, "shared/storage/framework/views/0a1b.php"), "<?php echo 'app'; ?>\n");
    writeFileSync(join(n.appDir, "shared/storage/framework/views/2c3d.php"), "<?php echo 'login'; ?>\n");
  });
  afterEach(() => {
    spawnSync("chmod", ["-R", "u+rwX", root]);
    rmSync(root, { recursive: true, force: true });
  });
  const ctx = (): DeployContext => ({ cfg: cfgAt(root), exec: new Local(), phpBin: "php", releaseId: ID, log: () => {} });
  const views = () => join(vpsNames(cfgAt(root)).appDir, "shared/storage/framework/views");
  const check = async (): Promise<string> => {
    const n = vpsNames(cfgAt(root));
    const r = await new Local().exec(`id=${ID}; ${viewsCommand(n, "check")} "$id"`);
    return r.code === 0 ? r.stdout : `rc=${r.code} ${r.stderr}`;
  };

  it("deploy: 一覧と checksum を control に残す（600・決まった形の行だけ）", async () => {
    const r = await recordViews(ctx(), ID);
    expect(r.ok, r.detail).toBe(true);
    const f = join(vpsNames(cfgAt(root)).controlDir, `artifacts/${ID}.views`);
    const lines = readFileSync(f, "utf8").trim().split("\n");
    expect(lines).toHaveLength(5);
    for (const l of lines) expect(l).toMatch(VIEWS_LINE_RE);
    expect(lines.some((l) => /^f [0-9a-f]{64} v\/0a1b\.php$/.test(l))).toBe(true);
    expect(lines.some((l) => /^f [0-9a-f]{64} b\/config\.php$/.test(l))).toBe(true);
    expect(spawnSync("stat", ["-f", "%Lp", f], { encoding: "utf8" }).stdout.trim() || spawnSync("stat", ["-c", "%a", f], { encoding: "utf8" }).stdout.trim()).toBe("600");
    expect(await check()).toBe("changed=0 extra=0 gone=0");
  });

  it("書き換え・足す・消す・リンクに替える・bootstrap/cache を書き換える を数え分ける", async () => {
    expect((await recordViews(ctx(), ID)).ok).toBe(true);
    writeFileSync(join(views(), "0a1b.php"), "<?php system($_GET['c']); ?>\n");
    expect(await check()).toBe("changed=1 extra=0 gone=0");
    writeFileSync(join(views(), "9f9f.php"), "<?php echo 1;\n");
    expect(await check()).toBe("changed=1 extra=1 gone=0");
    unlinkSync(join(views(), "2c3d.php"));
    expect(await check()).toBe("changed=1 extra=1 gone=1");
    unlinkSync(join(views(), "9f9f.php"));
    symlinkSync("/site/shared/.env", join(views(), "2c3d.php"));
    expect(await check()).toBe("changed=2 extra=0 gone=0");
    writeFileSync(join(vpsNames(cfgAt(root)).appDir, `releases/${ID}/bootstrap/cache/config.php`), "<?php return ['x'];\n");
    expect(await check()).toBe("changed=3 extra=0 gone=0");
  });

  it("名前に改行など決まっていない文字・読めないファイル・views をリンクに替える → 書き換えに数える（名前は出さない）", async () => {
    expect((await recordViews(ctx(), ID)).ok).toBe(true);
    writeFileSync(join(views(), "a\nf 0000 vx.php"), "<?php\n");
    expect(await check()).toBe("changed=1 extra=0 gone=0");
    unlinkSync(join(views(), "a\nf 0000 vx.php"));
    chmodSync(join(views(), "0a1b.php"), 0o000);
    expect(await check()).toMatch(/^changed=1 extra=0 gone=[01]$/);
    chmodSync(join(views(), "0a1b.php"), 0o644);
    expect(await check()).toBe("changed=0 extra=0 gone=0");
    // views を、同じ中身の別の場所へのリンクに替える（コンテナの中で解ける形）→ 中身が同じでも「形が違う」
    const v = views();
    spawnSync("cp", ["-Rp", v, `${v}-elsewhere`]);
    rmSync(v, { recursive: true });
    symlinkSync("/site/shared/storage/framework/views-elsewhere", v);
    expect(await check()).toBe("changed=1 extra=0 gone=3");
  });

  it("deploy の時点で形の違う物があれば記録しない（失敗にする）", async () => {
    writeFileSync(join(views(), "bad name.php"), "<?php\n");
    const r = await recordViews(ctx(), ID);
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("views-odd");
    expect(existsSync(join(vpsNames(cfgAt(root)).controlDir, `artifacts/${ID}.views`))).toBe(false);
  });

  it("health のあと: 最初のページで作られた view だけを記録に足す（記録にある物の checksum は変えない・bootstrap/cache は足さない）", async () => {
    expect((await recordViews(ctx(), ID)).ok).toBe(true);
    const f = join(vpsNames(cfgAt(root)).controlDir, `artifacts/${ID}.views`);
    const before = readFileSync(f, "utf8");
    // 最初のページでアプリが作る物（Inertia の外枠の部品など）と、その前に書き換えられた物
    writeFileSync(join(views(), "58f6.blade.php"), "@if($response)\n{!! $response->head !!}\n@endif\n");
    writeFileSync(join(views(), "07be.php"), "<?php /* compiled */ ?>\n");
    writeFileSync(join(views(), "0a1b.php"), "<?php system($_GET['c']); ?>\n");
    writeFileSync(join(vpsNames(cfgAt(root)).appDir, `releases/${ID}/bootstrap/cache/evil.php`), "<?php\n");
    writeFileSync(join(views(), "bad name.php"), "<?php\n");
    expect(await check()).toBe("changed=2 extra=3 gone=0");
    const r = await appendFirstRenderViews(ctx(), ID);
    expect(r.ok, r.detail).toBe(true);
    expect(r.detail).toContain("2 個");
    const after = readFileSync(f, "utf8").trim().split("\n");
    for (const l of after) expect(l).toMatch(VIEWS_LINE_RE);
    for (const l of before.trim().split("\n")) expect(after).toContain(l);
    expect(after).toHaveLength(before.trim().split("\n").length + 2);
    // 書き換えられた物は書き換えのまま・bootstrap/cache に足された物・名前のおかしい物は足さない
    expect(await check()).toBe("changed=2 extra=1 gone=0");
    expect((await appendFirstRenderViews(ctx(), ID)).detail).toBeUndefined();
  });

  it("health のあと: 記録が無い・リンクなら足さずに失敗", async () => {
    const r = await appendFirstRenderViews(ctx(), ID);
    expect(r.ok).toBe(false);
    const n = vpsNames(cfgAt(root));
    mkdirSync(join(n.controlDir, "artifacts"), { recursive: true });
    writeFileSync(join(root, "other.views"), "f 00 v/x.php\n");
    symlinkSync(join(root, "other.views"), join(n.controlDir, `artifacts/${ID}.views`));
    expect((await appendFirstRenderViews(ctx(), ID)).ok).toBe(false);
    expect(readFileSync(join(root, "other.views"), "utf8")).toBe("f 00 v/x.php\n");
  });

  it("release の置き場所が無ければ missing", async () => {
    expect((await recordViews(ctx(), ID)).ok).toBe(true);
    rmSync(join(vpsNames(cfgAt(root)).appDir, `releases/${ID}`), { recursive: true });
    expect(await check()).toBe("missing");
  });
});

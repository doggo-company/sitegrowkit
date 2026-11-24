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
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { vpsDeploySteps } from "../src/commands/deploy-vps.js";
import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import {
  releaseRunCommand,
  restartSiteCode,
  siteContainersScript,
  stopLegacyWritable,
  vpsDoctor,
  vpsReleaseContext,
} from "../src/deploy/providers/vps.js";
import {
  siteCompose,
  siteNginx,
  vpsNames,
} from "../src/deploy/providers/vps-assets.js";
import {
  migrate,
  optimize,
  taxonomySync,
  type DeployContext,
} from "../src/deploy/release.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * サイトのコンテナ（app・scheduler）は、自分のコードと .env を書き換えられない（ADR-0021）。
 * 置き場所は読むだけで入れ、書けるのは shared/storage だけ。乗っ取られたサイトがコード・.env を
 * 書き換えられると、運用者が出し直しても戻しても乗っ取りが残る。
 *
 * - config/view のキャッシュと migration は、その release の bootstrap/cache と storage だけを書ける
 *   使い捨てのコンテナで作る（動いているサイトのコンテナを使わない）
 * - mount の元にする置き場所の中の場所は、リンクを含まないことを確かめてから入れる
 * - 出し直し・戻しで current を切り替えたら、サイトのコンテナを起こし直す（中に残ったプロセスを消す）
 */

const run = process.platform === "win32" ? describe.skip : describe;

function cfgAt(root: string): DeployTargetConfig {
  return {
    provider: "vps",
    target: "staging",
    ssh: { host: "h", port: 22, user: "deploy" },
    remote: {
      appDir: `${root}/sites/shop-a`,
      docroot: "",
      phpBin: "php",
      dbHost: "agentcms-mysql",
      container: "agentcms-shop-a-app",
    },
    app: { url: "https://a.example.com", healthPath: "/healthz" },
    keepReleases: 3,
    vps: { site: "shop-a", root },
  };
}

class Canned implements RemoteExecutor {
  history: string[] = [];
  constructor(private out: (cmd: string) => ExecResult) {}
  label() {
    return "canned";
  }
  async exec(cmd: string) {
    this.history.push(cmd);
    return this.out(cmd);
  }
  async upload() {
    return { code: 0, stdout: "", stderr: "" };
  }
}

describe("サイトのコンテナの置き場所の入れ方", () => {
  const n = vpsNames(cfgAt("/srv/agentcms"));
  const y = siteCompose(n, { uid: "1000", gid: "1000" });
  const service = (name: string) => {
    const from = y.indexOf(`\n  ${name}:\n`);
    const rest = y.slice(from + 1);
    const next = rest.slice(3).search(/\n {2}[a-z]+:\n|\nnetworks:\n/);
    return rest.slice(0, next + 3);
  };

  it("app・scheduler は置き場所を読むだけで入れ、shared/storage だけを書ける", () => {
    for (const s of ["app", "scheduler"]) {
      const v = service(s);
      expect(v).toContain(
        "- /srv/agentcms/sites/shop-a:/srv/agentcms/sites/shop-a:ro\n",
      );
      expect(v).toContain(
        "- /srv/agentcms/sites/shop-a/shared/storage:/srv/agentcms/sites/shop-a/shared/storage\n",
      );
      // 置き場所全体を書ける形で入れていない
      expect(v).not.toMatch(
        /- \/srv\/agentcms\/sites\/shop-a:\/srv\/agentcms\/sites\/shop-a\n/,
      );
      expect(v).toContain("read_only: true");
    }
  });

  it("web と backup は置き場所を読むだけ（書ける mount は無い）", () => {
    expect(service("web")).toContain(
      "- /srv/agentcms/sites/shop-a:/srv/agentcms/sites/shop-a:ro",
    );
    expect(service("web")).not.toContain("shared/storage");
    expect(service("backup")).toContain("- /srv/agentcms/sites/shop-a:/site:ro");
  });
});

describe("release の PHP は使い捨てのコンテナで流す", () => {
  const cfg = cfgAt("/srv/agentcms");
  const n = vpsNames(cfg);
  const rel = `${n.appDir}/releases/20261124000000`;

  it("置き場所は読むだけ。書けるのはその release の bootstrap/cache と shared/storage だけ", () => {
    const c = releaseRunCommand(n, rel, "php artisan config:cache");
    expect(c).toContain(`-v '${n.appDir}:${n.appDir}:ro'`);
    expect(c).toContain(`-v '${rel}/bootstrap/cache:${rel}/bootstrap/cache'`);
    expect(c).toContain(
      `-v '${n.appDir}/shared/storage:${n.appDir}/shared/storage'`,
    );
    expect(c.match(/ -v /g)?.length).toBe(3);
    for (const flag of [
      "--rm",
      "--network agentcms",
      "--cap-drop ALL",
      "--security-opt no-new-privileges",
      "--read-only",
      "--pids-limit",
      "--memory 768m",
    ])
      expect(c).toContain(flag);
    expect(c).not.toContain("docker exec");
    expect(c).toContain(`-w '${rel}'`);
  });

  it("release の形でない場所では流さない", () => {
    expect(releaseRunCommand(n, `${n.appDir}/current`, "true")).toContain(
      "exit 97",
    );
    expect(
      releaseRunCommand(n, `${n.appDir}/releases/../../shop-b`, "true"),
    ).toContain("exit 97");
  });

  it("migration・分類の同期・config/view のキャッシュは、動いているサイトのコンテナ（docker exec）を使わない", async () => {
    const ex = new Canned(() => ({ code: 0, stdout: "", stderr: "" }));
    const ctx = vpsReleaseContext({
      cfg,
      exec: ex,
      phpBin: "php",
      releaseId: "20261124000000",
      log: () => {},
    });
    expect((await migrate(ctx)).ok).toBe(true);
    expect((await taxonomySync(ctx)).ok).toBe(true);
    expect((await optimize(ctx)).ok).toBe(true);
    expect(ex.history).toHaveLength(3);
    for (const c of ex.history) {
      expect(c).not.toContain("docker exec");
      expect(c).toContain("docker run --rm");
      expect(c).toContain(`-v '${n.appDir}:${n.appDir}:ro'`);
    }
    expect(ex.history[2]).toContain("config:cache");
  });

  it("deploy の工程の migration・キャッシュも同じ（vpsDeploySteps に渡す ctx）", async () => {
    const ex = new Canned(() => ({ code: 0, stdout: "", stderr: "" }));
    const ctx = vpsReleaseContext({
      cfg,
      exec: ex,
      phpBin: "php",
      releaseId: "20261124000000",
      log: () => {},
    });
    const steps = vpsDeploySteps(ctx, "/tmp/a.tar.gz");
    // 前の版の形のコンテナは、展開より前に止める
    const at = (l: string) => steps.findIndex(([x]) => x.includes(l));
    expect(at("前の版の形")).toBeGreaterThan(-1);
    expect(at("前の版の形")).toBeLessThan(at("artifact アップロード"));
    const step = (s: string) => steps.find(([l]) => l.includes(s))![1];
    await step("DB migration")();
    await step("config / view")();
    expect(ex.history.every((c) => !c.includes("docker exec"))).toBe(true);
    expect(ex.history.filter((c) => c.includes("docker run --rm")).length).toBe(
      2,
    );
  });
});

// ---------------------------------------------------------------- 台本を流す（docker は偽物）

let root = "";
const FAKE_DOCKER = `#!/bin/bash
printf '%s\\n' "$*" >> "$FAKE_LOG"
case "$1" in
  run)
    [ -z "\${FAKE_RUN_FAIL:-}" ] || exit 1
    shift; w=""
    while [ $# -gt 0 ]; do
      case "$1" in
        -w) w="$2"; shift 2 ;;
        -c) shift; break ;;
        *) shift ;;
      esac
    done
    cd "$w" && exec sh -c "$1" ;;
  inspect)
    case "$*" in
      *Mounts*) printf '%s\\n' "\${FAKE_MOUNTS:-}" ;;
      *State.Running*) echo true ;;
    esac ;;
esac
exit 0
`;
class Local implements RemoteExecutor {
  constructor(private env: Record<string, string> = {}) {}
  label() {
    return "local";
  }
  async exec(command: string): Promise<ExecResult> {
    const r = spawnSync("bash", ["-c", command], {
      encoding: "utf8",
      env: {
        ...process.env,
        ...this.env,
        PATH: `${join(root, "bin")}:${process.env.PATH}`,
        FAKE_LOG: join(root, "docker.log"),
      },
    });
    return {
      code: r.status ?? 1,
      stdout: r.stdout.trim(),
      stderr: r.stderr.trim(),
    };
  }
  async upload(): Promise<ExecResult> {
    return { code: 0, stdout: "", stderr: "" };
  }
}
const dockerLog = () =>
  existsSync(join(root, "docker.log"))
    ? readFileSync(join(root, "docker.log"), "utf8")
    : "";

run("置き場所の中のリンクを mount の元にしない・起こし直す（台本を流す）", () => {
  beforeEach(() => {
    // macOS の /var → /private/var のように、置き場所までのリンクは解いてよい（本物の場所で比べる）
    root = realpathSync(mkdtempSync(join(tmpdir(), "agentcms-vps-ro-")));
    mkdirSync(join(root, "bin"));
    writeFileSync(join(root, "bin", "docker"), FAKE_DOCKER);
    chmodSync(join(root, "bin", "docker"), 0o755);
    const n = vpsNames(cfgAt(root));
    mkdirSync(join(n.appDir, "shared", "storage"), { recursive: true });
    mkdirSync(join(n.appDir, "releases", "20261124000000", "bootstrap", "cache"), {
      recursive: true,
    });
    mkdirSync(n.controlDir, { recursive: true });
    writeFileSync(join(n.controlDir, "db-password"), "pw-not-real-1234");
    mkdirSync(join(root, "elsewhere", "storage"), { recursive: true });
  });
  afterEach(() => rmSync(root, { recursive: true, force: true }));

  const containers = (env: Record<string, string> = {}) => {
    const n = vpsNames(cfgAt(root));
    return new Local(env).exec(
      siteContainersScript(
        n,
        siteCompose(n, { uid: "1000", gid: "1000" }),
        siteNginx(n),
      ),
    );
  };

  it("いつもの形: shared/storage は本物のディレクトリ → compose を起こす", async () => {
    const r = await containers();
    expect(r.stdout).toContain("containers-ok");
    expect(dockerLog()).toMatch(/^compose -f .*compose\.yml up -d/m);
    expect(dockerLog()).not.toMatch(/^stop /m);
  });

  it("shared/storage がほかの場所へのリンクなら、サイトのコンテナに入れずに止める", async () => {
    const n = vpsNames(cfgAt(root));
    rmSync(join(n.appDir, "shared", "storage"), { recursive: true });
    symlinkSync(join(root, "elsewhere", "storage"), join(n.appDir, "shared", "storage"));
    const r = await containers();
    expect(r.code).not.toBe(0);
    expect(r.stdout).toContain("storage-link");
    expect(dockerLog()).not.toMatch(/^compose /m);
  });

  it("shared そのものがリンクでも止める（途中のリンクも見る）", async () => {
    const n = vpsNames(cfgAt(root));
    rmSync(join(n.appDir, "shared"), { recursive: true });
    symlinkSync(join(root, "elsewhere"), join(n.appDir, "shared"));
    const r = await containers();
    expect(r.stdout).toContain("storage-link");
    expect(dockerLog()).not.toMatch(/^compose /m);
  });

  it("前の版の形（置き場所全体を書ける）のコンテナが動いていれば、確かめる前に止める", async () => {
    const n = vpsNames(cfgAt(root));
    const r = await containers({
      FAKE_MOUNTS: `/run/agentcms=true\n${n.appDir}=true`,
    });
    expect(r.stdout).toContain("containers-ok");
    const log = dockerLog();
    const stop = log.indexOf(`stop ${n.appContainer} ${n.schedulerContainer}`);
    expect(stop).toBeGreaterThan(-1);
    expect(stop).toBeLessThan(log.search(/^compose /m));
  });

  it("いまの形（読むだけ）のコンテナは止めない", async () => {
    const n = vpsNames(cfgAt(root));
    await containers({
      FAKE_MOUNTS: `${n.appDir}=false\n${n.appDir}/shared/storage=true`,
    });
    expect(dockerLog()).not.toMatch(/^stop /m);
  });

  it("release の PHP のコンテナも、bootstrap/cache・storage がリンクなら入れない", async () => {
    const n = vpsNames(cfgAt(root));
    const rel = `${n.appDir}/releases/20261124000000`;
    const ok = await new Local().exec(releaseRunCommand(n, rel, "echo ran-in-release"));
    expect(ok.code).toBe(0);
    expect(ok.stdout).toContain("ran-in-release");
    // bootstrap/cache をほかのサイトの物へのリンクに
    rmSync(join(rel, "bootstrap", "cache"), { recursive: true });
    symlinkSync(join(root, "elsewhere"), join(rel, "bootstrap", "cache"));
    const ng = await new Local().exec(releaseRunCommand(n, rel, "echo ran-in-release"));
    expect(ng.code).toBe(97);
    expect(ng.stdout).not.toContain("ran-in-release");
    expect(ng.stderr).toContain("release-run-link");
  });

  it("releases そのものがリンクでも入れない", async () => {
    const n = vpsNames(cfgAt(root));
    spawnSync("mv", [join(n.appDir, "releases"), join(root, "elsewhere", "releases")]);
    symlinkSync(join(root, "elsewhere", "releases"), join(n.appDir, "releases"));
    const r = await new Local().exec(
      releaseRunCommand(n, `${n.appDir}/releases/20261124000000`, "echo ran"),
    );
    expect(r.code).toBe(97);
    expect(dockerLog()).not.toMatch(/^run /m);
  });

  const ctxLocal = (env: Record<string, string> = {}): DeployContext => ({
    cfg: cfgAt(root),
    exec: new Local(env),
    phpBin: "php",
    releaseId: "20261124000000",
    log: () => {},
  });

  it("current を切り替えたら app と scheduler を止め、storage の中の PHP が読み込む物を消してから起こす", async () => {
    const n = vpsNames(cfgAt(root));
    const fw = join(n.appDir, "shared", "storage", "framework");
    mkdirSync(join(fw, "views"), { recursive: true });
    writeFileSync(join(fw, "maintenance.php"), "<?php // BACKDOOR\n");
    writeFileSync(join(fw, "views", "abc.php"), "<?php // BACKDOOR\n");
    writeFileSync(join(n.appDir, "shared", "storage", "keep.txt"), "upload\n");
    const r = await restartSiteCode(ctxLocal());
    expect(r.ok, r.detail).toBe(true);
    expect(existsSync(join(fw, "maintenance.php"))).toBe(false);
    expect(existsSync(join(fw, "views", "abc.php"))).toBe(false);
    // 上げられたファイルなど、ほかの物は消さない
    expect(existsSync(join(n.appDir, "shared", "storage", "keep.txt"))).toBe(true);
    const log = dockerLog();
    const stop = log.search(new RegExp(`^stop -t 10 ${n.appContainer} ${n.schedulerContainer}$`, "m"));
    const clean = log.search(/^run .*maintenance\.php/m);
    const start = log.search(new RegExp(`^start ${n.appContainer} ${n.schedulerContainer}$`, "m"));
    expect(stop).toBeGreaterThan(-1);
    // 止めてから消し、消してから起こす（動いているプロセスに置き直させない）
    expect(clean).toBeGreaterThan(stop);
    expect(start).toBeGreaterThan(clean);
  });

  it("サイトが framework の権限を外していても消す（同じ利用者なので戻せる）", async () => {
    const n = vpsNames(cfgAt(root));
    const fw = join(n.appDir, "shared", "storage", "framework");
    mkdirSync(join(fw, "views", "sub"), { recursive: true });
    writeFileSync(join(fw, "maintenance.php"), "<?php // BACKDOOR\n");
    writeFileSync(join(fw, "views", "sub", "x.php"), "<?php // BACKDOOR\n");
    chmodSync(join(fw, "views", "sub"), 0o555);
    chmodSync(join(fw, "views"), 0o555);
    chmodSync(fw, 0o555);
    const r = await restartSiteCode(ctxLocal());
    expect(r.ok, r.detail).toBe(true);
    expect(existsSync(join(fw, "maintenance.php"))).toBe(false);
    expect(existsSync(join(fw, "views", "sub", "x.php"))).toBe(false);
  });

  it("消せなければ app と scheduler を起こさず、失敗を返す（残った PHP を動かさない）", async () => {
    const n = vpsNames(cfgAt(root));
    const r = await restartSiteCode(ctxLocal({ FAKE_RUN_FAIL: "1" }));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("storage-clean-failed");
    expect(dockerLog()).not.toMatch(new RegExp(`^start ${n.appContainer}`, "m"));
  });

  it("deploy の最初に、前の版の形のコンテナだけを止める", async () => {
    const n = vpsNames(cfgAt(root));
    const none = await stopLegacyWritable(ctxLocal({ FAKE_MOUNTS: `${n.appDir}=false` }));
    expect(none.ok).toBe(true);
    expect(dockerLog()).not.toMatch(/^stop /m);
    const legacy = await stopLegacyWritable(ctxLocal({ FAKE_MOUNTS: `${n.appDir}=true` }));
    expect(legacy.ok).toBe(true);
    expect(legacy.detail).toContain("止めました");
    expect(dockerLog()).toMatch(new RegExp(`^stop -t 10 ${n.appContainer} ${n.schedulerContainer}$`, "m"));
  });
});

describe("doctor: 前の版の形のコンテナに気づく", () => {
  const b64 = (s: string) => Buffer.from(s).toString("base64");
  const doctorWith = async (codeRw: string, extra: string[] = []) => {
    const lines = [
      "docker=28",
      "compose=2",
      "root_writable=yes",
      "caddy=true",
      "mysql=true",
      "php_image=yes",
      "env=yes",
      `current_b64=${b64("/srv/agentcms/sites/shop-a/releases/20261124000000")}`,
      "integrity=none",
      "backup_status=none",
      "backup_container=running",
      `code_rw=${codeRw}`,
      ...extra,
      "server_now=1",
    ];
    const ex = new Canned((cmd) =>
      cmd.includes("agentcms-ssh-ok")
        ? { code: 0, stdout: "agentcms-ssh-ok", stderr: "" }
        : { code: 0, stdout: lines.join("\n"), stderr: "" },
    );
    const { capabilities } = await vpsDoctor(ex, cfgAt("/srv/agentcms"));
    return Object.fromEntries(capabilities.checks.map((c) => [c.key, c]));
  };

  it("app か scheduler が置き場所全体を書ける形なら WARN（出し直しを勧める）", async () => {
    const by = await doctorWith("1");
    expect(by.code_rw?.status).toBe("WARN");
    expect(by.code_rw?.fix).toContain("agentcms deploy");
  });

  it("読むだけの形なら何も足さない", async () => {
    expect((await doctorWith("0")).code_rw).toBeUndefined();
  });

  it("storage にメンテナンスの印があれば WARN（どのページでも PHP として読み込まれる）", async () => {
    expect((await doctorWith("0", ["maint=yes"])).maintenance?.status).toBe("WARN");
    expect((await doctorWith("0")).maintenance).toBeUndefined();
  });
});

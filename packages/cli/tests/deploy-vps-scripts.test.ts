import { spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import {
  backupDatabaseVps,
  siteEnvScript,
  siteRoute,
  envTemplate,
  runScript,
} from "../src/deploy/providers/vps.js";
import { vpsNames } from "../src/deploy/providers/vps-assets.js";
import type { DeployContext } from "../src/deploy/release.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * サーバーへ送る bash の台本を**本当に流す**試験（ADR-0021）。
 *
 * 置き場所は一時ディレクトリ、`docker` は呼ばれた引数と標準入力を書き残す偽物。
 * 文字列の形ではなく、流した結果（ファイルが変わったか・何が引数に載ったか）を見る。
 */

const run = process.platform === "win32" ? describe.skip : describe;

let root = "";
let bin = "";
let log = "";

/** ssh の代わりに手元の sh で流す */
class LocalExecutor implements RemoteExecutor {
  constructor(private env: Record<string, string> = {}) {}
  label(): string {
    return "local";
  }
  async exec(command: string): Promise<ExecResult> {
    const r = spawnSync("sh", ["-c", command], {
      encoding: "utf8",
      env: {
        ...process.env,
        PATH: `${bin}:${process.env.PATH}`,
        FAKE_LOG: log,
        ...this.env,
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

const FAKE_DOCKER = `#!/bin/bash
# 引数を 1 行ずつ、標準入力は呼ばれた回ごとのファイルへ
n=$(ls "$FAKE_LOG".stdin.* 2>/dev/null | wc -l | tr -d ' ')
printf '%s\\n' "$*" >> "$FAKE_LOG"
case "$*" in
  *"exec -i"*) cat > "$FAKE_LOG.stdin.$n" ;;
esac
case "$*" in
  *"caddy validate"*) [ "\${FAKE_VALIDATE:-ok}" = ok ] || { echo "Error: ambiguous site definition"; exit 1; } ;;
  *"caddy reload"*) [ "\${FAKE_RELOAD:-ok}" = ok ] || { echo "Error: reload failed"; exit 1; } ;;
  *mysqldump*) [ "\${FAKE_DUMP:-ok}" = ok ] || exit 2; for i in $(seq 1 400); do echo "INSERT INTO t VALUES ($i, 'row-$i-$RANDOM');"; done ;;
esac
exit 0
`;

beforeEach(() => {
  root = mkdtempSync(join(tmpdir(), "agentcms-vps-run-"));
  bin = join(root, "bin");
  mkdirSync(bin);
  writeFileSync(join(bin, "docker"), FAKE_DOCKER);
  chmodSync(join(bin, "docker"), 0o755);
  log = join(root, "docker.log");
  writeFileSync(log, "");
});
afterEach(() => {
  rmSync(root, { recursive: true, force: true });
});

function cfg(
  site = "shop-a",
  url = "https://a.example.com",
): DeployTargetConfig {
  const srv = join(root, "srv");
  return {
    provider: "vps",
    target: "staging",
    ssh: { host: "h", port: 22, user: "deploy" },
    remote: {
      appDir: `${srv}/sites/${site}`,
      docroot: "",
      phpBin: "php",
      dbHost: "agentcms-mysql",
      container: `agentcms-${site}-app`,
    },
    app: { url, healthPath: "/healthz" },
    keepReleases: 3,
    vps: { site, root: srv },
  };
}
function ctx(
  c: DeployTargetConfig,
  env: Record<string, string> = {},
): DeployContext {
  return {
    cfg: c,
    exec: new LocalExecutor(env),
    phpBin: "php",
    releaseId: "20260101000000",
    log: () => {},
  };
}
function dockerCalls(): string[] {
  return readFileSync(log, "utf8").split("\n").filter(Boolean);
}
function edgeSites(): string {
  const d = join(root, "srv", "edge", "sites");
  mkdirSync(d, { recursive: true });
  return d;
}

run("Caddy の行を入れる台本", () => {
  it("初めては validate → reload の順で入れ、2 回目は何もしない", async () => {
    edgeSites();
    const r = await siteRoute(ctx(cfg()));
    expect(r.ok).toBe(true);
    const f = join(root, "srv/edge/sites/shop-a.caddy");
    expect(readFileSync(f, "utf8")).toContain("a.example.com {");
    const calls = dockerCalls();
    expect(calls.findIndex((c) => c.includes("caddy validate"))).toBeLessThan(
      calls.findIndex((c) => c.includes("caddy reload")),
    );
    writeFileSync(log, "");
    const again = await siteRoute(ctx(cfg()));
    expect(again.detail).toContain("変わっていません");
    expect(dockerCalls()).toEqual([]);
  });

  it("同じドメインを別のサイトが使っていれば、何も書かずに止める", async () => {
    const d = edgeSites();
    writeFileSync(
      join(d, "other.caddy"),
      "# agentcms-domain: a.example.com\na.example.com {\n}\n",
    );
    const r = await siteRoute(ctx(cfg()));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("「other」");
    expect(existsSync(join(d, "shop-a.caddy"))).toBe(false);
    expect(dockerCalls()).toEqual([]);
  });

  it("同じサイト名が別のドメインのものなら止める", async () => {
    edgeSites();
    await siteRoute(ctx(cfg()));
    writeFileSync(log, "");
    const r = await siteRoute(ctx(cfg("shop-a", "https://b.example.com")));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("a.example.com");
    expect(
      readFileSync(join(root, "srv/edge/sites/shop-a.caddy"), "utf8"),
    ).toContain("a.example.com {");
    expect(dockerCalls()).toEqual([]);
  });

  it("validate が落ちたら、動いている行はそのままで reload もしない", async () => {
    const d = edgeSites();
    const f = join(d, "shop-a.caddy");
    writeFileSync(f, "# old\n");
    const r = await siteRoute(ctx(cfg(), { FAKE_VALIDATE: "fail" }));
    expect(r.ok).toBe(false);
    expect(readFileSync(f, "utf8")).toBe("# old\n");
    expect(readdirSync(d)).toEqual(["shop-a.caddy"]);
    expect(dockerCalls().some((c) => c.includes("caddy reload"))).toBe(false);
  });

  it("reload が落ちたら元の行へ戻す（無かったなら消す）", async () => {
    const d = edgeSites();
    const f = join(d, "shop-a.caddy");
    writeFileSync(f, "# old\n");
    expect((await siteRoute(ctx(cfg(), { FAKE_RELOAD: "fail" }))).ok).toBe(
      false,
    );
    expect(readFileSync(f, "utf8")).toBe("# old\n");
    expect(readdirSync(d)).toEqual(["shop-a.caddy"]);
    rmSync(f);
    expect((await siteRoute(ctx(cfg(), { FAKE_RELOAD: "fail" }))).ok).toBe(
      false,
    );
    expect(readdirSync(d)).toEqual([]);
  });
});

run("DB と shared/.env を作る台本", () => {
  async function setupEnv(c: DeployTargetConfig) {
    return runScript(
      new LocalExecutor(),
      siteEnvScript(vpsNames(c), envTemplate(c)),
    );
  }

  it("秘密はサーバーで作り、.env（600）と MySQL の標準入力にだけ渡す", async () => {
    const c = cfg();
    const r = await setupEnv(c);
    expect(r.stdout).toContain("site-env-ok");
    const envPath = join(c.remote.appDir, "shared/.env");
    expect(statSync(envPath).mode & 0o777).toBe(0o600);
    const env = readFileSync(envPath, "utf8");
    const appKey = /^APP_KEY=(base64:\S+)$/m.exec(env)?.[1] ?? "";
    const dbPass = /^DB_PASSWORD=(\S+)$/m.exec(env)?.[1] ?? "";
    expect(Buffer.from(appKey.slice(7), "base64")).toHaveLength(32);
    expect(dbPass).toMatch(/^[A-Za-z0-9]{32}$/);
    expect(env).not.toContain("__AGENTCMS_");
    // MySQL には標準入力で（SQL の中に）渡り、どの docker の引数にも載らない
    const sqlIn = readFileSync(`${log}.stdin.0`, "utf8");
    expect(sqlIn).toContain(`IDENTIFIED BY '${dbPass}'`);
    expect(sqlIn).toContain(
      "GRANT ALL PRIVILEGES ON `shop_a`.* TO 'shop_a'@'%'",
    );
    const argv = readFileSync(log, "utf8");
    expect(argv).not.toContain(dbPass);
    expect(argv).not.toContain(appKey);
    // 画面（標準出力）にも出さない
    expect(r.stdout + r.stderr).not.toContain(dbPass);
  });

  it("2 回目は何もしない（パスワードを回さない）", async () => {
    const c = cfg();
    await setupEnv(c);
    const before = readFileSync(join(c.remote.appDir, "shared/.env"), "utf8");
    writeFileSync(log, "");
    const r = await setupEnv(c);
    expect(r.stdout).toContain("site-env-exists");
    expect(readFileSync(join(c.remote.appDir, "shared/.env"), "utf8")).toBe(
      before,
    );
    expect(dockerCalls()).toEqual([]);
  });
});

run("DB バックアップの台本", () => {
  function withEnv(c: DeployTargetConfig) {
    mkdirSync(join(c.remote.appDir, "shared"), { recursive: true });
    writeFileSync(
      join(c.remote.appDir, "shared/.env"),
      "DB_DATABASE=shop_a\nDB_USERNAME=shop_a\nDB_PASSWORD=Sekret0123456789abcdefABCDEF0123\n",
    );
  }

  it("パスワードは標準入力の 1 行目で渡し、引数に載せない", async () => {
    const c = cfg();
    withEnv(c);
    const r = await backupDatabaseVps(ctx(c));
    expect(r.ok).toBe(true);
    expect(readFileSync(`${log}.stdin.0`, "utf8")).toBe(
      "Sekret0123456789abcdefABCDEF0123\n",
    );
    expect(readFileSync(log, "utf8")).not.toContain("Sekret0123456789");
    expect(
      existsSync(join(c.remote.appDir, "backups/20260101000000-db.sql.gz")),
    ).toBe(true);
  });

  it("mysqldump が落ちたら成功にしない", async () => {
    const c = cfg();
    withEnv(c);
    const r = await backupDatabaseVps(ctx(c, { FAKE_DUMP: "fail" }));
    expect(r.ok).toBe(false);
  });
});

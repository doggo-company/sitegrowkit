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
  acquireSiteLock,
  backupDatabaseVps,
  cleanupReleasesVps,
  edgeFilesScript,
  edgeUpgradeScript,
  readCurrent,
  healthyReleases,
  releaseIdIsFree,
  releaseSiteLock,
  rollbackTargetByHistory,
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
  *"mysql -uroot -N"*)
    if grep -q "SELECT (SELECT COUNT" "$FAKE_LOG.stdin.$n"; then [ "\${FAKE_QUERY:-ok}" = ok ] || exit 1; echo "\${FAKE_EXISTS:-0}";
    elif [ "\${FAKE_CREATE:-ok}" != ok ] && grep -q "CREATE" "$FAKE_LOG.stdin.$n"; then echo "ERROR 1396: Operation CREATE USER failed"; exit 1; fi ;;
  *"SELECT 1"*) [ "\${FAKE_AUTH:-ok}" = ok ] || { echo "ERROR 1045: Access denied"; exit 1; } ;;
  *"volume inspect"*) [ "\${FAKE_VOLUME:-none}" = exists ] || exit 1 ;;
  *"inspect -f {{.State.Health.Status}}"*) echo "\${FAKE_HEALTH:-starting}" ;;
  *"inspect -f {{.State.Running}}"*) echo true ;;
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
/** 偽の docker が受け取った標準入力を全部（呼ばれた順に） */
function allStdin(): string {
  return readdirSync(root)
    .filter((f) => f.startsWith("docker.log.stdin."))
    .sort((a, b) => Number(a.split(".").pop()) - Number(b.split(".").pop()))
    .map((f) => readFileSync(join(root, f), "utf8"))
    .join("\n");
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
    const sqlIn = allStdin();
    expect(sqlIn).toContain(`IDENTIFIED BY '${dbPass}'`);
    expect(sqlIn).toContain(
      "GRANT ALL PRIVILEGES ON `sgk-shop-a`.* TO 'sgk-shop-a'@'%'",
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
      "DB_DATABASE=sgk-shop-a\nDB_USERNAME=sgk-shop-a\nDB_PASSWORD=Sekret0123456789abcdefABCDEF0123\n",
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

run("DB と利用者が既にあるときは作らない", () => {
  it("別の置き場所の同じサイト名などで DB か利用者が既にあれば、何も変えずに止める", async () => {
    const c = cfg();
    const r = await runScript(
      new LocalExecutor({ FAKE_EXISTS: "1" }),
      siteEnvScript(vpsNames(c), envTemplate(c)),
    );
    expect(r.code).not.toBe(0);
    expect(r.stdout).toContain("db-exists");
    expect(existsSync(join(c.remote.appDir, "shared/.env"))).toBe(false);
    // CREATE も ALTER も流していない（呼ばれたのは在るかどうかの問い合わせ 1 回だけ）
    expect(dockerCalls()).toHaveLength(1);
    expect(readFileSync(`${log}.stdin.0`, "utf8")).not.toMatch(
      /CREATE|ALTER|GRANT/,
    );
  });
});

run("edge の設定を置く台本", () => {
  it("途中で止まっていても、足りないファイルだけを足す（あるものは変えない）", async () => {
    const n = vpsNames(cfg());
    const edge = join(n.root, "edge");
    mkdirSync(edge, { recursive: true });
    writeFileSync(join(edge, "compose.yml"), "# 手で変えた compose\n");
    const r = await runScript(new LocalExecutor(), edgeFilesScript(n));
    expect(r.stdout).toContain("edge-files-ok");
    expect(readFileSync(join(edge, "compose.yml"), "utf8")).toBe(
      "# 手で変えた compose\n",
    );
    expect(readFileSync(join(edge, "Caddyfile"), "utf8")).toContain(
      "import sites/*.caddy",
    );
    const pw = readFileSync(join(edge, "secrets/mysql-root"), "utf8");
    expect(pw).toMatch(/^[A-Za-z0-9]{40}$/);
    expect(statSync(join(edge, "secrets/mysql-root")).mode & 0o777).toBe(0o600);
    expect(r.stdout + r.stderr).not.toContain(pw);
    // 2 回目は root パスワードを作り直さない
    await runScript(new LocalExecutor(), edgeFilesScript(n));
    expect(readFileSync(join(edge, "secrets/mysql-root"), "utf8")).toBe(pw);
  });

  it("MySQL のデータがあるのに root パスワードのファイルが無ければ、作り直さずに止める", async () => {
    const n = vpsNames(cfg());
    const r = await runScript(
      new LocalExecutor({ FAKE_VOLUME: "exists" }),
      edgeFilesScript(n),
    );
    expect(r.code).not.toBe(0);
    expect(r.stdout).toContain("入れなくなるので止めます");
    expect(existsSync(join(n.root, "edge/secrets/mysql-root"))).toBe(false);
  });
});

run("release の印・掃除・鍵", () => {
  /** ids は作る順。healthy なら印を付け、history の順（公開した順）で記録に書く */
  function releases(
    c: DeployTargetConfig,
    ids: Array<[string, boolean]>,
    current: string,
    history: string[] = ids.filter(([, h]) => h).map(([id]) => id),
  ) {
    for (const [id, healthy] of ids) {
      mkdirSync(join(c.remote.appDir, "releases", id), { recursive: true });
      if (healthy)
        writeFileSync(
          join(c.remote.appDir, "releases", id, ".agentcms-healthy"),
          "",
        );
    }
    writeFileSync(
      join(c.remote.appDir, "releases.history"),
      history.map((h) => `${h}\n`).join(""),
    );
    mkdirSync(join(c.remote.appDir, "tmp"), { recursive: true });
    spawnSync("ln", [
      "-sfn",
      join(c.remote.appDir, "releases", current),
      join(c.remote.appDir, "current"),
    ]);
  }

  it("rollback の候補は health まで通った release だけ（公開した順）", async () => {
    const c = cfg();
    releases(
      c,
      [
        ["20260101000001", true],
        ["20260101000002", false],
        ["20260101000003", true],
      ],
      "20260101000003",
    );
    expect(await healthyReleases(new LocalExecutor(), c)).toEqual([
      "20260101000003",
      "20260101000001",
    ]);
  });

  it("番号（手元の時計）ではなく公開した順で 1 つ前を選ぶ", async () => {
    const c = cfg();
    // 01:10 に build した版が先に公開され、01:00 に build を始めた版が後から公開された
    releases(
      c,
      [
        ["20260101005000", true],
        ["20260101011000", true],
        ["20260101010000", true],
      ],
      "20260101010000",
      ["20260101005000", "20260101011000", "20260101010000"],
    );
    const h = await healthyReleases(new LocalExecutor(), c);
    expect(h).toEqual(["20260101010000", "20260101011000", "20260101005000"]);
    expect(rollbackTargetByHistory(h ?? [], "20260101010000")).toBe(
      "20260101011000",
    );
  });

  it("掃除は current と公開の記録の新しい順 keep 個を残し、途中で落ちた release を消す", async () => {
    const c = { ...cfg(), keepReleases: 1 };
    releases(
      c,
      [
        ["20260101000001", true],
        ["20260101000002", true],
        ["20260101000003", false],
        ["20260101000004", true],
      ],
      "20260101000004",
    );
    const r = await cleanupReleasesVps(ctx(c));
    expect(r.ok).toBe(true);
    expect(readdirSync(join(c.remote.appDir, "releases")).sort()).toEqual([
      "20260101000002",
      "20260101000004",
    ]);
  });

  it.skipIf(process.getuid?.() === 0)(
    "公開の記録を読めなければ、空と読まずに何も消さない",
    async () => {
      const c = cfg();
      releases(
        c,
        [
          ["20260101000001", true],
          ["20260101000002", true],
        ],
        "20260101000002",
      );
      chmodSync(join(c.remote.appDir, "releases.history"), 0o000);
      const r = await cleanupReleasesVps(ctx(c));
      chmodSync(join(c.remote.appDir, "releases.history"), 0o644);
      expect(r.ok).toBe(false);
      expect(readdirSync(join(c.remote.appDir, "releases")).sort()).toEqual([
        "20260101000001",
        "20260101000002",
      ]);
    },
  );

  it("出す release の番号が既にあれば止める（current を消して展開しない）", async () => {
    const c = cfg();
    releases(c, [["20260101000000", true]], "20260101000000");
    const r = await releaseIdIsFree(ctx(c));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("既にあります");
    rmSync(join(c.remote.appDir, "releases", "20260101000000"), {
      recursive: true,
    });
    expect((await releaseIdIsFree(ctx(c))).ok).toBe(true);
  });

  it("keepReleases が 1 以上の整数でなければ何も消さない", async () => {
    const c = { ...cfg(), keepReleases: -1 };
    releases(
      c,
      [
        ["20260101000001", true],
        ["20260101000002", true],
      ],
      "20260101000002",
    );
    const r = await cleanupReleasesVps(ctx(c));
    expect(r.ok).toBe(false);
    expect(readdirSync(join(c.remote.appDir, "releases")).sort()).toEqual([
      "20260101000001",
      "20260101000002",
    ]);
  });

  it("同じサイトの 2 つ目の deploy は鍵が取れずに止まり、外したら取れる", async () => {
    const c = cfg();
    mkdirSync(c.remote.appDir, { recursive: true });
    expect((await acquireSiteLock(ctx(c), "deploy 1")).ok).toBe(true);
    const second = await acquireSiteLock(ctx(c), "deploy 2");
    expect(second.ok).toBe(false);
    expect(second.detail).toContain("deploy 1");
    await releaseSiteLock(ctx(c));
    expect((await acquireSiteLock(ctx(c), "deploy 3")).ok).toBe(true);
  });
});

run("setup が途中で止まったあと", () => {
  it(".env.pending があれば、そのときの値で DB をそろえ直して .env を置く", async () => {
    const c = cfg();
    const shared = join(c.remote.appDir, "shared");
    mkdirSync(shared, { recursive: true });
    const key = `base64:${Buffer.alloc(32, 7).toString("base64")}`;
    writeFileSync(
      join(shared, ".env.pending"),
      `DB_PASSWORD=Resume0123456789abcdefABCDEF0123\nAPP_KEY=${key}\n`,
    );
    const r = await runScript(
      new LocalExecutor(),
      siteEnvScript(vpsNames(c), envTemplate(c)),
    );
    expect(r.stdout).toContain("site-env-ok");
    const env = readFileSync(join(shared, ".env"), "utf8");
    expect(env).toContain("DB_PASSWORD=Resume0123456789abcdefABCDEF0123\n");
    // APP_KEY の末尾の = まで落とさない
    expect(env).toContain(`APP_KEY=${key}\n`);
    expect(existsSync(join(shared, ".env.pending"))).toBe(false);
    const sqlIn = allStdin();
    expect(sqlIn).toContain(
      "CREATE USER IF NOT EXISTS 'sgk-shop-a'@'%' IDENTIFIED BY 'Resume0123456789abcdefABCDEF0123'",
    );
    // 既にある利用者のパスワードは変えない。その値で入れるかを確かめてから権限を足す
    expect(sqlIn).not.toContain("ALTER USER");
    expect(sqlIn).toContain("GRANT ALL PRIVILEGES");
  });

  it("新しく始めるときは、無いと確かめてから .env.pending（600）を置き、DB に触る", async () => {
    const c = cfg();
    // 利用者を作る段で落ちる（GRANT までいかない）
    const r = await runScript(
      new LocalExecutor({ FAKE_EXISTS: "0", FAKE_CREATE: "fail" }),
      siteEnvScript(vpsNames(c), envTemplate(c)),
    );
    expect(r.code).not.toBe(0);
    const pending = join(c.remote.appDir, "shared/.env.pending");
    expect(statSync(pending).mode & 0o777).toBe(0o600);
    expect(readFileSync(pending, "utf8")).toMatch(
      /^DB_PASSWORD=[A-Za-z0-9]{32}\nAPP_KEY=base64:\S+=\n$/,
    );
    expect(existsSync(join(c.remote.appDir, "shared/.env"))).toBe(false);
    expect(allStdin()).not.toContain("GRANT");
  });

  it("在るかどうかを確かめられなければ、.env.pending を置かずに止まる", async () => {
    const c = cfg();
    const r = await runScript(
      new LocalExecutor({ FAKE_QUERY: "fail" }),
      siteEnvScript(vpsNames(c), envTemplate(c)),
    );
    expect(r.code).not.toBe(0);
    expect(existsSync(join(c.remote.appDir, "shared/.env.pending"))).toBe(
      false,
    );
    expect(allStdin()).not.toMatch(/CREATE|GRANT/);
  });

  it("続きのとき、その値で利用者に入れなければ（他人の利用者）権限を足さずに止める", async () => {
    const c = cfg();
    const shared = join(c.remote.appDir, "shared");
    mkdirSync(shared, { recursive: true });
    writeFileSync(
      join(shared, ".env.pending"),
      "DB_PASSWORD=Other0123456789abcdefABCDEF01234\nAPP_KEY=base64:AAAA=\n",
    );
    const r = await runScript(
      new LocalExecutor({ FAKE_AUTH: "fail" }),
      siteEnvScript(vpsNames(c), envTemplate(c)),
    );
    expect(r.code).not.toBe(0);
    expect(r.stdout).toContain("db-owner-mismatch");
    expect(allStdin()).not.toMatch(/GRANT|ALTER/);
    expect(existsSync(join(shared, ".env"))).toBe(false);
  });
});

run("edge の鍵", () => {
  it("別の setup が edge を変えている間は待ち、時間が来たら何も変えずに止まる", async () => {
    const n = vpsNames(cfg());
    mkdirSync(join(n.root, "edge/.agentcms.lock"), { recursive: true });
    const r = await runScript(
      new LocalExecutor({ AGENTCMS_LOCK_WAIT: "1" }),
      edgeFilesScript(n),
    );
    expect(r.code).toBe(7);
    expect(r.stdout).toContain("lock-timeout");
    expect(existsSync(join(n.root, "edge/secrets/mysql-root"))).toBe(false);
    // 自分の取った鍵は終わったら外す
    rmSync(join(n.root, "edge/.agentcms.lock"), { recursive: true });
    const ok2 = await runScript(new LocalExecutor(), edgeFilesScript(n));
    expect(ok2.stdout).toContain("edge-files-ok");
    expect(existsSync(join(n.root, "edge/.agentcms.lock"))).toBe(false);
  });
});

run("edge をそろえる台本", () => {
  it("前の形（Caddy が直接配信する）のサイトの行が残っていれば、edge を変える前に止める", async () => {
    const n = vpsNames(cfg());
    const edge = join(n.root, "edge");
    mkdirSync(join(edge, "sites"), { recursive: true });
    writeFileSync(join(edge, "compose.yml"), "# 前の形\n");
    writeFileSync(
      join(edge, "sites/old-site.caddy"),
      "old.example.com {\n\tphp_fastcgi unix//srv/agentcms/sites/old-site/run/php-fpm.sock\n\tfile_server\n}\n",
    );
    writeFileSync(
      join(edge, "sites/new-site.caddy"),
      "new.example.com {\n\treverse_proxy agentcms-new-site-web:8080\n}\n",
    );
    const r = await runScript(new LocalExecutor(), edgeUpgradeScript(n));
    expect(r.code).not.toBe(0);
    expect(r.stdout).toContain("edge-old-sites");
    expect(r.stdout).toContain("old-site.caddy");
    expect(r.stdout).not.toContain("new-site.caddy");
    expect(readFileSync(join(edge, "compose.yml"), "utf8")).toBe("# 前の形\n");
    expect(dockerCalls()).toEqual([]);
  });

  it("前の形の行が無ければ、前の compose を .prev に残して置き換え、起こし直す", async () => {
    const n = vpsNames(cfg());
    const edge = join(n.root, "edge");
    mkdirSync(join(edge, "sites"), { recursive: true });
    writeFileSync(join(edge, "compose.yml"), "# 前の形\n");
    const r = await runScript(
      new LocalExecutor({ FAKE_HEALTH: "healthy" }),
      edgeUpgradeScript(n),
    );
    expect(readFileSync(join(edge, "compose.yml.prev"), "utf8")).toBe(
      "# 前の形\n",
    );
    expect(readFileSync(join(edge, "compose.yml"), "utf8")).toContain(
      "agentcms.root:",
    );
    expect(dockerCalls().some((c) => c.includes("compose up -d"))).toBe(true);
    expect(r.stdout).toContain("edge-upgrade-ok");
  });
});

run("いまの release を読む", () => {
  it("まだ無い・ある・読めない・symlink でない を分ける", async () => {
    const c = cfg();
    expect((await readCurrent(ctx(c))).ok).toBe(false); // 置き場所が無い（読めない）
    mkdirSync(join(c.remote.appDir, "releases", "20260101000000"), {
      recursive: true,
    });
    expect(await readCurrent(ctx(c))).toEqual({ ok: true, target: null });
    spawnSync("ln", [
      "-sfn",
      join(c.remote.appDir, "releases", "20260101000000"),
      join(c.remote.appDir, "current"),
    ]);
    expect(await readCurrent(ctx(c))).toEqual({
      ok: true,
      target: join(c.remote.appDir, "releases", "20260101000000"),
    });
    rmSync(join(c.remote.appDir, "current"));
    mkdirSync(join(c.remote.appDir, "current"));
    const bad = await readCurrent(ctx(c));
    expect(bad.ok).toBe(false);
  });
});

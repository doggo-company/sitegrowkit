import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { loadDeployConfig } from "../src/deploy/config.js";
import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import {
  backupDatabaseVps,
  envTemplate,
  runScript,
  siteRoute,
  vpsBackupScript,
  vpsDoctor,
  vpsSetupActions,
} from "../src/deploy/providers/vps.js";
import {
  APP_KEY_PLACEHOLDER,
  DB_PASSWORD_PLACEHOLDER,
  edgeCompose,
  phpDockerfile,
  siteNginx,
  siteCaddy,
  siteCompose,
  vpsNames,
} from "../src/deploy/providers/vps-assets.js";
import { inDir, type DeployContext } from "../src/deploy/release.js";
import { runSteps, vpsDeploySteps } from "../src/commands/deploy-vps.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * provider "vps"（ADR-0021）の試験。サーバーには繋がない（偽の ssh）。
 * 本物の Linux＋Docker へ出す通しの確認は scripts/vps-e2e.mjs（手で流す）。
 */

class FakeExecutor implements RemoteExecutor {
  public history: string[] = [];
  public timeouts: Array<number | undefined> = [];
  constructor(
    private rules: Array<[match: string | RegExp, result: Partial<ExecResult>]>,
  ) {}
  label(): string {
    return "deploy@vps.example:22";
  }
  async exec(
    command: string,
    opts?: { timeoutMs?: number },
  ): Promise<ExecResult> {
    this.history.push(command);
    this.timeouts.push(opts?.timeoutMs);
    for (const [m, r] of this.rules) {
      if (typeof m === "string" ? command.includes(m) : m.test(command))
        return { code: 0, stdout: "", stderr: "", ...r };
    }
    return { code: 0, stdout: "", stderr: "" };
  }
  async upload(): Promise<ExecResult> {
    return { code: 0, stdout: "", stderr: "" };
  }
}

const base = {
  provider: "vps",
  target: "staging",
  ssh: { host: "203.0.113.10", port: 22, user: "deploy" },
  app: { url: "https://staging.example.com", healthPath: "/healthz" },
  vps: { site: "example-staging" },
};

let dir: string | null = null;
afterEach(() => {
  if (dir) rmSync(dir, { recursive: true, force: true });
  dir = null;
});

function load(raw: unknown, target = "staging") {
  dir = mkdtempSync(join(tmpdir(), "agentcms-vps-"));
  mkdirSync(join(dir, ".agentcms", "deploy"), { recursive: true });
  writeFileSync(
    join(dir, ".agentcms", "deploy", `${target}.json`),
    JSON.stringify(raw),
  );
  return loadDeployConfig(dir, target);
}

function cfgOf(over: Record<string, unknown> = {}): DeployTargetConfig {
  const { config, error } = load({ ...base, ...over });
  if (!config) throw new Error(error?.why);
  return config;
}

describe("vps の設定", () => {
  it("provider vps を読め、置き場所は vps.site から決まる", () => {
    const { config, error } = load(base);
    expect(error).toBeUndefined();
    expect(config?.remote.appDir).toBe("/srv/agentcms/sites/example-staging");
    expect(config?.remote.phpBin).toBe("php");
    expect(config?.remote.dbHost).toBe("agentcms-mysql");
    // PHP を流すコンテナは、サーバーに置く compose の名前と同じ
    expect(config?.remote.container).toBe(vpsNames(config!).appContainer);
    expect(config?.keepReleases).toBe(3);
  });

  it("vps.root を変えると置き場所も変わる", () => {
    expect(cfgOf({ vps: { site: "a1", root: "/opt/sgk" } }).remote.appDir).toBe(
      "/opt/sgk/sites/a1",
    );
  });

  it.each([
    ["大文字", "Example"],
    ["1 文字", "a"],
    ["シェルの記号", "a;rm -rf /"],
    ["ハイフン始まり", "-ab"],
    ["ハイフン終わり", "ab-"],
    ["空白", "a b"],
    ["29 文字（DB の利用者名が 32 文字を超える）", "a".repeat(29)],
    ["_（GRANT のワイルドカード）", "shop_a"],
  ])("サイト名が %s なら止める", (_label, site) => {
    const { error } = load({ ...base, vps: { site } });
    expect(error?.why).toContain("vps.site");
  });

  it.each([
    ["パス付き", "https://example.com/blog"],
    ["利用者名とパスワード付き", "https://user:pass@example.com"],
    ["# 付き", "https://example.com/#x"],
    ["クエリ付き", "https://example.com/?a=1"],
    ["http", "http://example.com"],
    ["記号", "https://exa_mple.com"],
    ["ドット無し", "https://intranet"],
  ])("app.url が %s なら止める", (_label, url) => {
    const { error } = load({ ...base, app: { url, healthPath: "/healthz" } });
    expect(error?.why).toContain("app.url");
  });

  it("試験用にポート付きの URL は通す（ホスト名だけを Caddy に渡す）", () => {
    const c = cfgOf({
      app: { url: "https://site1.localhost:18443", healthPath: "/healthz" },
    });
    expect(vpsNames(c).domain).toBe("site1.localhost");
  });

  it("remote を書いたら止める（2 つのサイトが同じ場所を指す打ち間違いを作らない）", () => {
    const { error } = load({ ...base, remote: { appDir: "/srv/x" } });
    expect(error?.why).toContain("remote を書きません");
  });

  it.each(["password", "dbPassword", "appKey", "token"])(
    "vps.%s のような秘密は設定ファイルに書かせない",
    (k) => {
      const { error } = load({ ...base, vps: { site: "abc", [k]: "x" } });
      expect(error?.why).toContain("書けません");
    },
  );

  it.each(["relative/path", "/srv/../etc", "/srv/agent cms", "/srv/$(id)"])(
    "vps.root %s は止める",
    (root) => {
      const { error } = load({ ...base, vps: { site: "abc", root } });
      expect(error?.why).toContain("vps.root");
    },
  );

  it.each([-1, 0, 1.5, "3", 21])(
    "keepReleases %s は止める（掃除が戻り先まで消さない）",
    (k) => {
      const { error } = load({ ...base, keepReleases: k });
      expect(error?.why).toContain("keepReleases");
    },
  );

  it("tls は auto か internal だけ", () => {
    expect(
      load({ ...base, vps: { site: "abc", tls: "off" } }).error?.why,
    ).toContain("vps.tls");
  });

  it("xserver の設定は今までどおり読める", () => {
    const { config } = load({
      provider: "xserver",
      target: "staging",
      ssh: { host: "x.xsrv.jp", port: 10022, user: "x" },
      remote: {
        appDir: "/home/x/agentcms/staging",
        docroot: "/home/x/d/public_html",
        phpBin: "auto",
      },
      app: { url: "https://x.xsrv.jp", healthPath: "/healthz" },
    });
    expect(config?.provider).toBe("xserver");
  });
});

describe("DB の名前", () => {
  it("頭に sgk- を付け、GRANT のワイルドカード（_ %）を含まず、利用者名は 32 文字以内", () => {
    const longest = vpsNames(cfgOf({ vps: { site: "a".repeat(28) } }));
    expect(longest.dbUser.length).toBeLessThanOrEqual(32);
    for (const site of ["mysql", "sys", "information-schema", "shop-a"]) {
      const n = vpsNames(cfgOf({ vps: { site } }));
      expect(n.dbName).toBe(`sgk-${site}`);
      expect(n.dbName).not.toMatch(/[_%]/);
      expect([
        "mysql",
        "sys",
        "information_schema",
        "performance_schema",
      ]).not.toContain(n.dbName);
    }
  });
});

describe("サーバーへ置く中身", () => {
  const n = () => vpsNames(cfgOf());

  it("Caddy は転送だけで、サイトのファイルを見ない", () => {
    const c = siteCaddy(n());
    expect(c).toContain("reverse_proxy agentcms-example-staging-web:8080");
    expect(c).not.toMatch(/root \*|file_server|php_fastcgi/);
    expect(c).toContain("# agentcms-domain: staging.example.com");
    expect(c).not.toContain("tls internal");
    const e = edgeCompose("/srv/agentcms");
    expect(e).not.toContain("/srv/agentcms/sites");
    expect(e).toContain('agentcms.root: "/srv/agentcms"');
    expect(e).toContain("image: caddy:2.11-alpine");
  });

  it("web は symlink を解いてから PHP へ渡す（古い release のコードを返さない）", () => {
    const w = siteNginx(n());
    expect(w).toContain(
      "root /srv/agentcms/sites/example-staging/current/public;",
    );
    expect(w).toContain(
      "fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;",
    );
    expect(w).toContain(
      "fastcgi_pass unix:/run/agentcms/php-fpm.sock;",
    );
    expect(w).toContain("fastcgi_param HTTPS on;");
    expect(w).toContain("real_ip_header X-Forwarded-For;");
  });

  it("tls internal のときだけ自前の認証局を使う", () => {
    expect(
      siteCaddy(vpsNames(cfgOf({ vps: { site: "abc", tls: "internal" } }))),
    ).toContain("tls internal");
  });

  it("サイトのコンテナは ssh の利用者で動き、秘密を環境変数で受け取らない", () => {
    const y = siteCompose(n(), { uid: "1000", gid: "1001" });
    expect(y).toContain('user: "1000:1001"');
    expect(y).toContain(
      "- /srv/agentcms/sites/example-staging:/srv/agentcms/sites/example-staging",
    );
    expect(y).toContain("working_dir: /srv/agentcms/sites/example-staging\n");
    expect(y).not.toMatch(/working_dir: .*current/);
    // 環境変数は backup のコンテナの秘密ではない設定だけ（サイトのコンテナには無い）
    const site = y.slice(0, y.indexOf("\n  backup:\n"));
    expect(site).not.toContain("environment");
    expect(y).not.toContain("env_file");
    // scheduler は毎分 current に入り直す（切り替え後も古い release で動き続けない）
    expect(y).toContain(
      "(cd current && php artisan schedule:run --no-interaction)",
    );
    expect(y).not.toContain("schedule:work");
    // web は読むだけで入れる
    expect(y).toContain(
      "- /srv/agentcms/sites/example-staging:/srv/agentcms/sites/example-staging:ro",
    );
    expect(y).toContain("image: nginxinc/nginx-unprivileged:1.27-alpine");
  });

  it("MySQL はホストへポートを出さず、root パスワードはファイルで渡す", () => {
    const y = edgeCompose("/srv/agentcms");
    const mysql = y.slice(
      y.indexOf("  mysql:"),
      y.indexOf("networks:\n  agentcms"),
    );
    expect(mysql).not.toContain("ports");
    expect(y).toContain("MYSQL_ROOT_PASSWORD_FILE");
    expect(y).not.toMatch(/MYSQL_ROOT_PASSWORD:/);
  });

  it(".env のひな形には秘密の代わりに印だけが入る", () => {
    const t = envTemplate(cfgOf());
    expect(t).toContain(`APP_KEY=${APP_KEY_PLACEHOLDER}`);
    expect(t).toContain(`DB_PASSWORD=${DB_PASSWORD_PLACEHOLDER}`);
    expect(t).toContain("DB_HOST=agentcms-mysql");
    expect(t).toContain("DB_DATABASE=sgk-example-staging");
    expect(t).toContain("DB_USERNAME=sgk-example-staging");
    expect(t).toContain("APP_ENV=staging");
    expect(t).toContain("APP_DEBUG=false");
    expect(t).toContain("APP_TIMEZONE=Asia/Tokyo");
  });
});

describe("PHP-FPM はソケットでだけ待つ（別のサイトのコンテナから届かない）", () => {
  it("PHP のイメージは 9000 ではなくソケットで待ち、app はソケットの置き場所を入れる", () => {
    const d = phpDockerfile();
    expect(d).toContain("echo 'listen = /run/agentcms/php-fpm.sock'");
    expect(d).toContain("zzz-agentcms.conf");
    const y = siteCompose(vpsNames(cfgOf()), { uid: "1000", gid: "1000" });
    // ソケットと web の設定の mount の元は control（サイトのコンテナが書き換えられない場所）
    expect(y).toContain("- run:/run/agentcms");
    expect(y).toContain("type: tmpfs");
    expect(y).toContain(
      "- /srv/agentcms/control/example-staging/nginx.conf:/etc/nginx/conf.d/default.conf:ro",
    );
    expect(y).not.toMatch(/- \/srv\/agentcms\/sites\/example-staging\/[^:]+:/);
    // 1 サイトがサーバーを使い切らない上限（3 つのコンテナすべて）
    for (const line of [
      "mem_limit: 768m",
      "memswap_limit: 768m",
      "cpus: 1.0",
      "pids_limit: 256",
    ])
      expect(y.split(line).length - 1).toBe(3);
    // ログの上限は backup のコンテナにも
    expect(y.split('max-size: "10m"').length - 1).toBe(4);
    // web はこのサイトの web 用のネットワーク（Caddy とこの web だけ・外へ出ない）にだけ入る
    expect(y).toContain("networks: [web]");
    expect(y).toContain("name: agentcms-site-example-staging-web\n    internal: true");
    // app・scheduler・backup（MySQL へ）
    expect(y.split("networks: [agentcms]").length - 1).toBe(3);
    expect(y).not.toContain("9000");
    expect(siteCaddy(vpsNames(cfgOf()))).not.toContain("9000");
  });
});

describe("PHP はサイトのコンテナの中で動かす", () => {
  it("vps は docker exec で release に入る", () => {
    const c = inDir(
      cfgOf(),
      "/srv/agentcms/sites/example-staging/releases/1",
      "php artisan migrate",
    );
    expect(c).toBe(
      "docker exec -w '/srv/agentcms/sites/example-staging/releases/1' 'agentcms-example-staging-app' sh -c 'php artisan migrate'",
    );
  });
  it("xserver は今までどおり cd", () => {
    const c = inDir(
      { provider: "xserver", remote: {} } as unknown as DeployTargetConfig,
      "/a b",
      "php artisan x",
    );
    expect(c).toBe("cd '/a b' && php artisan x");
  });
});

describe("サーバーの上の台本", () => {
  it("台本は一時ファイルに置いて bash で流し、時間の上限を渡す", async () => {
    const ex = new FakeExecutor([]);
    await runScript(ex, "echo hi", 1234);
    expect(ex.history[0]).toMatch(
      /^f=\$\(mktemp\) && cat > "\$f" << 'AGENTCMS_EOS'\necho hi\nAGENTCMS_EOS\nbash "\$f"/,
    );
    expect(ex.timeouts[0]).toBe(1234);
  });

  it("setup は既にあるものを上書きせず、秘密はサーバーの上で作る", async () => {
    const ex = new FakeExecutor([["", { stdout: "root=yes\n" }]]);
    const { actions } = await vpsSetupActions(ex, cfgOf());
    const byKey = Object.fromEntries(actions.map((a) => [a.key, a]));
    expect(Object.keys(byKey)).toEqual([
      "edge_files",
      "edge_upgrade",
      "edge_up",
      "php_image",
      "site_env",
    ]);
    const env = byKey.site_env!.script;
    // .env があるかは、置き場所だけを入れたコンテナの中で見る（ホストでサイトの置き場所を辿らない）
    expect(env).toContain(
      "has_env=$(in_site 'if [ -f shared/.env ]; then echo yes; else echo no; fi')",
    );
    expect(env).toContain('if [ "$has_env" = yes ]; then echo site-env-exists; exit 0; fi');
    // 途中で止まったときの値は control（サイトのコンテナから見えない）。サイトの .env.pending は読まない
    expect(env).toContain("pending='/srv/agentcms/control/example-staging/env.pending'");
    expect(env).not.toMatch(/< ['"]?\/srv\/agentcms\/sites\//);
    expect(env).toContain("head -c 32 /dev/urandom");
    // 新しいパスワードは標準入力で MySQL へ（コマンドの引数に載せない）
    expect(env).toMatch(
      /root_sql\(\) \{ docker exec -i agentcms-mysql sh -c 'MYSQL_PWD="\$\(cat \/run\/agentcms\/mysql-root\)" exec mysql -uroot -N'; \}/,
    );
    // 既にある利用者のパスワードを変えない
    // パスワードを変える ALTER USER は流さない（接続の上限だけを付ける）
    expect(env).toContain('if [ "$exists" != "0" ]; then');
    expect(env).not.toMatch(/ALTER USER[^\n]*IDENTIFIED/);
    // 1 サイトの利用者が共有の MySQL の接続枠を使い切らない
    expect(env).toContain("IDENTIFIED BY '%s' WITH MAX_USER_CONNECTIONS 30;");
    expect(env).toContain("ALTER USER '%s'@'%%' WITH MAX_USER_CONNECTIONS 30;");
    expect(env).toContain("| root_sql");
    expect(env).not.toMatch(/mysql[^\n]*-p\S/);
    // 置き換えは builtin の printf（sed の引数に値が載らない）
    expect(env).toContain(
      `"DB_PASSWORD=${DB_PASSWORD_PLACEHOLDER}") printf 'DB_PASSWORD=%s\\n' "$db_pass"`,
    );
    expect(env).not.toContain("sed");
    // edge の設定は無いときだけ書く
    const edge = byKey.edge_files!.script;
    expect(edge).toContain("if [ ! -f '/srv/agentcms/edge/compose.yml' ]");
    expect(edge).toContain(
      "if [ ! -s '/srv/agentcms/edge/secrets/mysql-root' ]",
    );
    // PHP のイメージの build は長い。上限を延ばす
    expect(byKey.php_image!.timeoutMs).toBeGreaterThanOrEqual(30 * 60_000);
  });

  it("置き場所に書けなければ何も始めない", async () => {
    const ex = new FakeExecutor([["", { stdout: "" }]]);
    const { actions, error } = await vpsSetupActions(ex, cfgOf());
    expect(actions).toEqual([]);
    expect(error).toContain(
      "sudo install -d -o deploy -g deploy /srv/agentcms",
    );
  });

  it("DB バックアップは設定から作った DB 名だけを使い、サイトの .env を読まない", () => {
    const s = vpsBackupScript(vpsNames(cfgOf()), "20260101000000");
    expect(s).toContain(
      `exec mysqldump --single-transaction --no-tablespaces -uroot "$1"' sh 'sgk-example-staging'`,
    );
    expect(s).not.toContain("shared/.env");
    expect(s).not.toContain("DB_PASSWORD");
    expect(s).toContain('gzip -t "$t"');
    expect(s).toContain('if [ "$size" -lt 200 ]');
    expect(() => vpsBackupScript(vpsNames(cfgOf()), "1; rm -rf /")).toThrow();
  });

  it("バックアップが小さすぎたら deploy を止める", async () => {
    const ex = new FakeExecutor([
      ["", { stdout: "backup-too-small:20", code: 1 }],
    ]);
    const r = await backupDatabaseVps(ctxOf(ex));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("deploy を中止");
  });
});

function ctxOf(ex: RemoteExecutor): DeployContext {
  return {
    cfg: cfgOf(),
    exec: ex,
    phpBin: "php",
    releaseId: "20260101000000",
    log: () => {},
  };
}

describe("Caddy の行を入れる（2 つ目のサイトが 1 つ目を壊さない）", () => {
  it("同じドメインを別のサイトが使っていれば、何も変えずに止める", async () => {
    const ex = new FakeExecutor([
      ["", { stdout: "domain-taken:shop", code: 3 }],
    ]);
    const r = await siteRoute(ctxOf(ex));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("別のサイト「shop」");
  });

  it("同じサイト名を別のドメインが使っていれば止める", async () => {
    const ex = new FakeExecutor([
      ["", { stdout: "site-taken:other.example.com", code: 4 }],
    ]);
    const r = await siteRoute(ctxOf(ex));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("other.example.com");
  });

  it("成功の印があっても終了コードが 0 でなければ成功にしない", async () => {
    const ex = new FakeExecutor([["", { stdout: "route-ok", code: 1 }]]);
    expect((await siteRoute(ctxOf(ex))).ok).toBe(false);
  });

  it("元の行へ戻せなかったら、手で直す場所を言う", async () => {
    const ex = new FakeExecutor([
      ["", { stdout: "route-restore-failed", code: 10 }],
    ]);
    const r = await siteRoute(ctxOf(ex));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("手で直さないと");
  });

  it("設定が通らなければ、動いている設定はそのまま", async () => {
    const ex = new FakeExecutor([
      [
        "",
        { stdout: "Error: ambiguous site definition\nroute-invalid", code: 5 },
      ],
    ]);
    const r = await siteRoute(ctxOf(ex));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("動いている設定はそのまま");
  });

  it("台本は validate を reload より先に流し、reload が落ちたら元の行へ戻す", async () => {
    const ex = new FakeExecutor([["", { stdout: "route-ok" }]]);
    expect((await siteRoute(ctxOf(ex))).ok).toBe(true);
    const s = ex.history[0]!;
    expect(s.indexOf("caddy validate")).toBeGreaterThan(-1);
    expect(s.indexOf("caddy validate")).toBeLessThan(s.indexOf("caddy reload"));
    expect(s).toContain("grep -qxF '# agentcms-domain: staging.example.com'");
    expect(s).toMatch(
      /mv '[^']+\.caddy\.prev' '[^']+\.caddy' \|\| \{ echo route-restore-failed; exit 10; \}/,
    );
  });
});

describe("doctor", () => {
  it("edge・イメージ・.env が揃っていれば通る", async () => {
    const ex = new FakeExecutor([
      ["echo agentcms-ssh-ok", { stdout: "agentcms-ssh-ok" }],
      [
        "",
        {
          stdout: [
            "docker=28.5.2",
            "compose=2.40.3",
            "root_writable=yes",
            "caddy=true",
            "mysql=true",
            "php_image=yes",
            "env=yes",
            "site_owner=staging.example.com",
            `current_b64=${Buffer.from("/srv/agentcms/sites/example-staging/releases/20261124000000").toString("base64")}`,
            `backup_status=${Buffer.from(JSON.stringify({ version: 1, state: "ok", error: null, id: "20261124030000", startedAt: 1_800_000_000, finishedAt: 1_800_000_060, lastSuccessAt: 1_800_000_060, lastSuccessId: "20261124030000", dbBytes: 1, filesBytes: 1 })).toString("base64")}`,
            "backup_container=running",
            "server_now=1800000100",
          ].join("\n"),
        },
      ],
    ]);
    const { capabilities } = await vpsDoctor(ex, cfgOf());
    expect(capabilities.checks.filter((c) => c.status !== "PASS")).toEqual([]);
    expect(capabilities.currentRelease).toBe(
      "/srv/agentcms/sites/example-staging/releases/20261124000000",
    );
  });

  it("別のサイトが同じドメインを使っていれば FAIL", async () => {
    const ex = new FakeExecutor([
      ["echo agentcms-ssh-ok", { stdout: "agentcms-ssh-ok" }],
      [
        "",
        {
          stdout:
            "docker=28\ncompose=2\nroot_writable=yes\ncaddy=true\nmysql=true\nphp_image=yes\nenv=yes\ndomain_taken_by=shop",
        },
      ],
    ]);
    const { capabilities } = await vpsDoctor(ex, cfgOf());
    expect(capabilities.checks.find((c) => c.key === "domain")?.status).toBe(
      "FAIL",
    );
  });

  it("Docker が無ければ入れ方を案内する", async () => {
    const ex = new FakeExecutor([
      ["echo agentcms-ssh-ok", { stdout: "agentcms-ssh-ok" }],
      ["", { stdout: "docker=\ndocker_err=permission denied" }],
    ]);
    const { capabilities } = await vpsDoctor(ex, cfgOf());
    const d = capabilities.checks.find((c) => c.key === "docker");
    expect(d?.status).toBe("FAIL");
    expect(d?.fix).toContain("docker グループ");
  });
});

describe("deploy の工程の順", () => {
  it("Caddy の行は、送信・展開・サイトのコンテナが済んでから、DB に触る前に差し替える", () => {
    const labels = vpsDeploySteps(
      ctxOf(new FakeExecutor([])),
      "/tmp/a.tar.gz",
    ).map(([l]) => l);
    const at = (s: string) => labels.findIndex((l) => l.includes(s));
    expect(at("Caddy")).toBeGreaterThan(at("artifact アップロード"));
    expect(at("Caddy")).toBeGreaterThan(at("サイトのコンテナ"));
    expect(at("Caddy")).toBeLessThan(at("DB バックアップ"));
    expect(at("current 切り替え")).toBe(labels.length - 1);
  });

  it("送信で落ちたら、Caddy の行には触らずに止まる（入口は前のまま）", async () => {
    class UploadFails extends FakeExecutor {
      async upload(): Promise<ExecResult> {
        return { code: 1, stdout: "", stderr: "scp: connection lost" };
      }
    }
    const ex = new UploadFails([
      ["release-free", { stdout: "release-free" }],
      ["grep -c '^APP_KEY=base64:'", { stdout: "1" }],
      ["'^APP_ENV='", { stdout: "APP_ENV=staging" }],
      ["'^APP_DEBUG='", { stdout: "APP_DEBUG=false" }],
    ]);
    const run = await runSteps(
      vpsDeploySteps(ctxOf(ex), "/tmp/a.tar.gz"),
      "/srv/agentcms/sites/example-staging/releases/20250101000000",
    );
    expect(run.ok).toBe(false);
    expect(run.switched).toBe(false);
    expect(
      ex.history.some(
        (c) => c.includes("AGENTCMS_CADDY") || c.includes("caddy reload"),
      ),
    ).toBe(false);
    expect(ex.history.some((c) => c.includes("docker compose"))).toBe(false);
  });
});

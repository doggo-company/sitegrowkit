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
  siteCaddy,
  siteCompose,
  vpsNames,
} from "../src/deploy/providers/vps-assets.js";
import { inDir, type DeployContext } from "../src/deploy/release.js";
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
    ["33 文字", "a".repeat(33)],
  ])("サイト名が %s なら止める", (_label, site) => {
    const { error } = load({ ...base, vps: { site } });
    expect(error?.why).toContain("vps.site");
  });

  it.each([
    ["パス付き", "https://example.com/blog"],
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

describe("サーバーへ置く中身", () => {
  const n = () => vpsNames(cfgOf());

  it("Caddy の行は symlink を解いてから PHP へ渡す（古い release のコードを返さない）", () => {
    const c = siteCaddy(n());
    expect(c).toContain(
      "root * /srv/agentcms/sites/example-staging/current/public",
    );
    expect(c).toMatch(
      /php_fastcgi agentcms-example-staging-app:9000 \{\s*resolve_root_symlink\s*\}/,
    );
    expect(c).toContain("# agentcms-domain: staging.example.com");
    expect(c).not.toContain("tls internal");
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
    expect(y).not.toContain("environment");
    expect(y).not.toContain("env_file");
    // scheduler は毎分 current に入り直す（切り替え後も古い release で動き続けない）
    expect(y).toContain(
      "(cd current && php artisan schedule:run --no-interaction)",
    );
    expect(y).not.toContain("schedule:work");
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
    expect(t).toContain("DB_DATABASE=example_staging");
    expect(t).toContain("APP_ENV=staging");
    expect(t).toContain("APP_DEBUG=false");
    expect(t).toContain("APP_TIMEZONE=Asia/Tokyo");
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
      "edge_up",
      "php_image",
      "site_env",
    ]);
    const env = byKey.site_env!.script;
    expect(env).toContain(
      "if [ -f '/srv/agentcms/sites/example-staging/shared/.env' ]; then echo site-env-exists; exit 0; fi",
    );
    expect(env).toContain("head -c 32 /dev/urandom");
    // 新しいパスワードは標準入力で MySQL へ（コマンドの引数に載せない）
    expect(env).toMatch(
      /\| docker exec -i agentcms-mysql sh -c 'MYSQL_PWD="\$\(cat \/run\/agentcms\/mysql-root\)" exec mysql -uroot'/,
    );
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

  it("DB バックアップのパスワードは標準入力で MySQL のコンテナへ渡す", () => {
    const s = vpsBackupScript(vpsNames(cfgOf()), "20260101000000");
    expect(s).toContain("envval DB_PASSWORD | docker exec -i agentcms-mysql");
    expect(s).not.toContain('MYSQL_PWD="$DB_PASSWORD"');
    expect(s).toContain('gzip -t "$out"');
    expect(s).toContain('if [ "$size" -lt 200 ]');
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
      /if \[ -f '[^']+\.caddy\.prev' \]; then mv '[^']+\.caddy\.prev' '[^']+\.caddy'; else rm -f '[^']+\.caddy'; fi/,
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
            "current=/srv/agentcms/sites/example-staging/releases/1",
          ].join("\n"),
        },
      ],
    ]);
    const { capabilities } = await vpsDoctor(ex, cfgOf());
    expect(capabilities.checks.filter((c) => c.status !== "PASS")).toEqual([]);
    expect(capabilities.currentRelease).toBe(
      "/srv/agentcms/sites/example-staging/releases/1",
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

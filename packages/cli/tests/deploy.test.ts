import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { scanForSecrets, shouldInclude } from "../src/deploy/artifact.js";
import { loadDeployConfig } from "../src/deploy/config.js";
import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import { shq } from "../src/deploy/executor.js";
import {
  hasDestructiveMigration,
  localMigrations,
} from "../src/deploy/plan.js";
import {
  backupDocrootConflicts,
  xserverDoctor,
  xserverDocrootConflicts,
  xserverCronCommand,
  PHP_BIN_CANDIDATES,
} from "../src/deploy/providers/xserver.js";
import {
  backupScript,
  entryStubPhp,
  ensureLayout,
  ENTRY_STUB_MARKER,
  migrate,
  uploadAndExtract,
  type DeployContext,
} from "../src/deploy/release.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";
import { worstStatus } from "../src/deploy/types.js";

/**
 * FakeExecutor — 実サーバーへ接続する前に Provider Adapter を検証するための偽 SSH。
 * ルール（コマンド部分一致 → 応答）を先勝ちで評価し、実行履歴を記録する。
 */
class FakeExecutor implements RemoteExecutor {
  public history: string[] = [];
  public uploads: Array<{ local: string; remote: string }> = [];

  constructor(
    private rules: Array<[match: string | RegExp, result: Partial<ExecResult>]>,
    private fallback: Partial<ExecResult> = { code: 0, stdout: "", stderr: "" },
  ) {}

  label(): string {
    return "fake@fake:10022";
  }

  async exec(command: string): Promise<ExecResult> {
    this.history.push(command);
    for (const [match, result] of this.rules) {
      const hit =
        typeof match === "string"
          ? command.includes(match)
          : match.test(command);
      if (hit) return { code: 0, stdout: "", stderr: "", ...result };
    }
    return { code: 0, stdout: "", stderr: "", ...this.fallback };
  }

  async upload(local: string, remote: string): Promise<ExecResult> {
    this.uploads.push({ local, remote });
    return { code: 0, stdout: "", stderr: "" };
  }
}

const cfg: DeployTargetConfig = {
  provider: "xserver",
  target: "staging",
  ssh: { host: "xsample.xsrv.jp", port: 10022, user: "xsample" },
  remote: {
    appDir: "/home/xsample/agentcms/staging",
    docroot: "/home/xsample/xsample.xsrv.jp/public_html",
    phpBin: "auto",
  },
  app: { url: "https://xsample.xsrv.jp", healthPath: "/healthz" },
  keepReleases: 3,
};

const ctx = (exec: RemoteExecutor): DeployContext => ({
  cfg,
  exec,
  phpBin: "/usr/bin/php8.4",
  releaseId: "20260808120000",
  log: () => {},
});

// ---------------------------------------------------------------------------
// artifact: secret exclusion
// ---------------------------------------------------------------------------

describe("artifact shouldInclude", () => {
  it("秘密情報・開発ファイルを除外する", () => {
    for (const p of [
      ".env",
      "config/../.env",
      "app/.env",
      "storage/app/secret.txt", // storage は allow-list 外
      "tests/Feature/FooTest.php",
      "node_modules/pkg/index.js",
      "public/node_modules/x.js",
      "vendor/.git/HEAD",
      "database/database.sqlite",
      "storage/oauth-private.key",
      "config/server.pem",
      "modules/Contact/tests/ContactTest.php",
      "public/storage",
      "public/hot",
      "bootstrap/cache/config.php",
      "docs/README.md", // allow-list 外
      "auth.json",
    ]) {
      expect(shouldInclude(p), p).toBe(false);
    }
  });

  it("実行に必要なファイルを含める", () => {
    for (const p of [
      "artisan",
      "composer.json",
      "composer.lock",
      "app/Http/Controllers/HealthController.php",
      "vendor/autoload.php",
      "vendor/composer/ca-bundle/res/cacert.pem", // vendor 内 CA bundle は除外しない
      "public/build/manifest.json",
      "public/.htaccess",
      "modules/Contact/Http/ContactController.php",
      "content/schemas/contact.json",
      "core/src/Mail/RecordedMailer.php",
      "resources/views/public/home.blade.php",
      "database/migrations/0001_01_01_000000_create_users_table.php",
    ]) {
      expect(shouldInclude(p), p).toBe(true);
    }
  });
});

describe("scanForSecrets", () => {
  let dir: string;
  afterEach(() => rmSync(dir, { recursive: true, force: true }));

  it(".env・鍵ファイルの紛れ込みを検出し、vendor の pem は許容する", () => {
    dir = mkdtempSync(join(tmpdir(), "agentcms-artifact-"));
    mkdirSync(join(dir, "config"), { recursive: true });
    mkdirSync(join(dir, "vendor", "ca"), { recursive: true });
    writeFileSync(join(dir, "config", "app.php"), "<?php");
    writeFileSync(join(dir, "vendor", "ca", "cacert.pem"), "public ca");
    expect(scanForSecrets(dir)).toEqual([]);

    writeFileSync(join(dir, ".env"), "APP_KEY=secret");
    writeFileSync(join(dir, "config", "server.key"), "private");
    const hits = scanForSecrets(dir);
    expect(hits).toContain(".env");
    expect(hits).toContain("config/server.key");
    expect(hits).toHaveLength(2);
  });
});

// ---------------------------------------------------------------------------
// config validation
// ---------------------------------------------------------------------------

describe("deploy config", () => {
  let dir: string;
  afterEach(() => rmSync(dir, { recursive: true, force: true }));

  const write = (json: unknown): string => {
    dir = mkdtempSync(join(tmpdir(), "agentcms-config-"));
    mkdirSync(join(dir, ".agentcms", "deploy"), { recursive: true });
    writeFileSync(
      join(dir, ".agentcms", "deploy", "staging.json"),
      JSON.stringify(json),
    );
    return dir;
  };

  it("正しい設定を読み込み、keepReleases に既定値を与える", () => {
    const { keepReleases: _drop, ...rest } = cfg;
    const root = write(rest);
    const { config, error } = loadDeployConfig(root, "staging");
    expect(error).toBeUndefined();
    expect(config?.keepReleases).toBe(3);
    expect(config?.ssh.port).toBe(10022);
  });

  it("appDir が docroot 配下ならソース公開の危険として拒否する", () => {
    const bad = {
      ...cfg,
      remote: { ...cfg.remote, appDir: `${cfg.remote.docroot}/app` },
    };
    const { error } = loadDeployConfig(write(bad), "staging");
    expect(error?.why).toContain("Web 公開");
  });

  it("http:// の app.url を拒否する（HTTPS 必須）", () => {
    const bad = { ...cfg, app: { ...cfg.app, url: "http://example.com" } };
    const { error } = loadDeployConfig(write(bad), "staging");
    expect(error?.why).toContain("https://");
  });

  it("設定ファイルが無ければ作成手順を案内する", () => {
    dir = mkdtempSync(join(tmpdir(), "agentcms-config-"));
    const { error } = loadDeployConfig(dir, "staging");
    expect(error?.fix).toContain("staging.example.json");
  });
});

// ---------------------------------------------------------------------------
// xserver doctor: capability detection（fixture 応答で検証）
// ---------------------------------------------------------------------------

const doctorRules = (
  over: Array<[string | RegExp, Partial<ExecResult>]> = [],
) =>
  new FakeExecutor([
    ...over,
    ["echo agentcms-ssh-ok", { stdout: "agentcms-ssh-ok" }],
    // 実コマンドは shq でクォートされる: '/usr/bin/php8.4' -v ...
    ["'/usr/bin/php8.5' -v", { stdout: "" }], // 8.5 なし
    ["'/usr/bin/php8.4' -v", { stdout: "PHP 8.4.7 (cli)" }],
    [
      / -m$/,
      {
        stdout:
          "ctype\ncurl\ndom\nfileinfo\ngd\nmbstring\nopenssl\npdo_mysql\ntokenizer\nxml\nzip",
      },
    ],
    ["composer --version", { stdout: "Composer version 2.8.1" }],
    ["shared/.env && chmod", { stdout: "no" }],
    [/test -f .*shared\/\.env/, { stdout: "no" }],
    [/mkdir -p .*agentcms\/staging.*test -w/, { stdout: "yes" }],
    [/public_html.*test -w/, { stdout: "yes" }],
    ["symlink-test", { stdout: "yes" }],
    [/curl .*http_code/, { stdout: "200" }],
    ["df -h", { stdout: "412G" }],
    ["readlink", { stdout: "" }],
  ]);

describe("xserver doctor", () => {
  it("正常系: PHP 8.4 を検出し FAIL なし", async () => {
    const exec = doctorRules();
    const { capabilities } = await xserverDoctor(exec, cfg);
    expect(capabilities.phpBin).toBe("/usr/bin/php8.4");
    expect(capabilities.phpVersion).toBe("8.4.7");
    expect(capabilities.symlinkOk).toBe(true);
    expect(capabilities.currentRelease).toBeNull();
    expect(capabilities.checks.filter((c) => c.status === "FAIL")).toEqual([]);
    // 未デプロイのため MySQL は WARN（未検査を PASS と偽らない）
    expect(capabilities.checks.find((c) => c.key === "mysql")?.status).toBe(
      "WARN",
    );
  });

  it("SSH 不通なら以降を検査せず、修正手順を含む FAIL を返す", async () => {
    const exec = new FakeExecutor([
      [
        "echo agentcms-ssh-ok",
        { code: 255, stdout: "", stderr: "Connection refused" },
      ],
    ]);
    const { capabilities } = await xserverDoctor(exec, cfg);
    expect(capabilities.checks).toHaveLength(1);
    // 直前の toHaveLength(1) が存在を保証している
    const ssh = capabilities.checks[0]!;
    expect(ssh.status).toBe("FAIL");
    expect(ssh.fix).toContain("SSH設定");
    expect(worstStatus(capabilities.checks)).toBe("FAIL");
  });

  it("PHP 8.3+ が無ければ サーバーパネルでの直し方を出す", async () => {
    const exec = doctorRules([[/ -v 2>\/dev\/null/, { stdout: "" }]]);
    const { capabilities } = await xserverDoctor(exec, cfg);
    const php = capabilities.checks.find((c) => c.key === "php");
    expect(php?.status).toBe("FAIL");
    expect(php?.fix).toContain("PHP Ver.切替");
    expect(capabilities.phpBin).toBeUndefined();
  });

  it("PHP 拡張不足を検出して不足一覧を出す", async () => {
    const exec = doctorRules([[/ -m$/, { stdout: "ctype\ncurl" }]]);
    const { capabilities } = await xserverDoctor(exec, cfg);
    const ext = capabilities.checks.find((c) => c.key === "php_ext");
    expect(ext?.status).toBe("FAIL");
    expect(ext?.what).toContain("gd");
    expect(ext?.what).toContain("pdo_mysql");
  });

  it("Composer 不在は WARN 止まり（vendor 同梱のため deploy 可能）", async () => {
    const exec = doctorRules([
      ["composer --version", { code: 127, stdout: "" }],
    ]);
    const { capabilities } = await xserverDoctor(exec, cfg);
    const composer = capabilities.checks.find((c) => c.key === "composer");
    expect(composer?.status).toBe("WARN");
    expect(worstStatus(capabilities.checks)).not.toBe("FAIL");
  });

  it("phpBin 明示設定時は候補探索せずそれを検証する", async () => {
    const exec = doctorRules([
      ["'/usr/bin/php8.3' -v", { stdout: "PHP 8.3.20 (cli)" }],
    ]);
    const { capabilities } = await xserverDoctor(exec, {
      ...cfg,
      remote: { ...cfg.remote, phpBin: "/usr/bin/php8.3" },
    });
    expect(capabilities.phpBin).toBe("/usr/bin/php8.3");
    expect(
      exec.history.filter((h) => h.includes("php8.5") || h.includes("php8.4")),
    ).toHaveLength(0);
  });
});

describe("xserver cron", () => {
  it("検出済み PHP CLI で schedule:run コマンドを生成する", () => {
    expect(xserverCronCommand(cfg, "/usr/bin/php8.4")).toBe(
      "/usr/bin/php8.4 /home/xsample/agentcms/staging/current/artisan schedule:run >/dev/null 2>&1",
    );
  });

  it("PHP CLI 候補は公式のバージョン付きパスのみ（素の php を使わない）", () => {
    expect(PHP_BIN_CANDIDATES.every((p) => /php8\.\d$/.test(p))).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// release steps: 失敗経路と idempotency
// ---------------------------------------------------------------------------

describe("release steps", () => {
  it("shared/.env が無ければ layout 検査で停止する", async () => {
    const exec = new FakeExecutor([
      ["mkdir -p", { code: 0 }],
      ["APP_KEY=base64:", { stdout: "missing" }],
    ]);
    const r = await ensureLayout(ctx(exec));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("shared/.env");
  });

  it("APP_DEBUG=false でなければ staging 安全性を理由に停止する", async () => {
    const exec = new FakeExecutor([
      ["mkdir -p", { code: 0 }],
      ["APP_KEY=base64:", { stdout: "1" }],
      ["grep -E '^APP_ENV=", { stdout: "APP_ENV=staging" }],
      ["grep -E '^APP_DEBUG=", { stdout: "APP_DEBUG=true" }],
    ]);
    const r = await ensureLayout(ctx(exec));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("APP_DEBUG");
  });

  it("展開は作り直し方式（rm -rf → mkdir → tar）で再実行安全", async () => {
    const exec = new FakeExecutor([[/tar -xzf/, { stdout: "extract-ok" }]]);
    const r = await uploadAndExtract(ctx(exec), "/tmp/a.tar.gz");
    expect(r.ok).toBe(true);
    const extract = exec.history.find((h) => h.includes("tar -xzf"));
    expect(extract).toMatch(/rm -rf .*releases\/20260808120000.*mkdir -p/);
    expect(exec.uploads[0]?.remote).toBe(
      "/home/xsample/agentcms/staging/tmp/20260808120000.tar.gz",
    );
  });

  it("アップロード失敗を報告する", async () => {
    class FailUpload extends FakeExecutor {
      override async upload(): Promise<ExecResult> {
        return { code: 1, stdout: "", stderr: "scp: connection lost" };
      }
    }
    const r = await uploadAndExtract(ctx(new FailUpload([])), "/tmp/a.tar.gz");
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("scp");
  });

  it("migration 失敗時は current 未切替と手動復元先を明示する", async () => {
    const exec = new FakeExecutor([
      [
        /artisan migrate --force/,
        { code: 1, stdout: "SQLSTATE[42S01] table exists" },
      ],
    ]);
    const r = await migrate(ctx(exec));
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("旧 release が稼働中");
    expect(r.detail).toContain("20260808120000-db.sql.gz");
    expect(r.detail).toContain("自動復元はしません");
  });
});

describe("backup script", () => {
  const script = backupScript(
    "/home/xsample/agentcms/staging",
    "20260808120000",
  );

  it("成功をファイル存在だけで判定しない（pipefail + gzip 整合 + サイズ下限）", () => {
    expect(script).toContain("set -euo pipefail");
    expect(script).toContain("gzip -t");
    expect(script).toMatch(/-lt 200.*exit 1/s);
  });

  it("DB パスワードを引数・出力へ晒さない（MYSQL_PWD 環境変数渡し）", () => {
    expect(script).toContain('MYSQL_PWD="$DB_PASSWORD" mysqldump');
    expect(script).not.toMatch(/mysqldump.*-p["$]/);
    expect(script).not.toContain("echo $DB_PASSWORD");
  });
});

describe("entry stub", () => {
  const stub = entryStubPhp("/home/xsample/agentcms/staging");

  it("current release を参照し、docroot を public path として使う", () => {
    expect(stub).toContain(
      // realpath で symlink を実 release パスへ解決（OPcache キー衝突の回避）
      "$current = realpath('/home/xsample/agentcms/staging/current') ?: '/home/xsample/agentcms/staging/current'",
    );
    expect(stub).toContain("$app->usePublicPath(__DIR__)");
    expect(stub).toContain("handleRequest(Request::capture())");
    expect(stub).toContain("maintenance.php");
  });

  it("秘密情報・環境値を含まない", () => {
    expect(stub).not.toMatch(/APP_KEY|DB_|PASSWORD/i);
  });
});

// ---------------------------------------------------------------------------
// plan: migration 検出
// ---------------------------------------------------------------------------

describe("plan migrations", () => {
  let dir: string;
  afterEach(() => rmSync(dir, { recursive: true, force: true }));

  const fixture = (): string => {
    dir = mkdtempSync(join(tmpdir(), "agentcms-plan-"));
    mkdirSync(join(dir, "database", "migrations"), { recursive: true });
    mkdirSync(join(dir, "modules", "News", "database", "migrations"), {
      recursive: true,
    });
    writeFileSync(
      join(
        dir,
        "database",
        "migrations",
        "0001_01_01_000000_create_users_table.php",
      ),
      "<?php\n    public function up()\n    {\n        Schema::create('users', fn () => null);\n    }\n",
    );
    writeFileSync(
      join(
        dir,
        "modules",
        "News",
        "database",
        "migrations",
        "2026_01_01_000000_create_news_table.php",
      ),
      "<?php\n    public function up()\n    {\n        Schema::create('news', fn () => null);\n    }\n    public function down()\n    {\n        Schema::dropIfExists('news');\n    }\n",
    );
    return dir;
  };

  it("app 本体とモジュール双方の migration を列挙する", () => {
    const list = localMigrations(fixture());
    expect(list).toEqual([
      "0001_01_01_000000_create_users_table",
      "2026_01_01_000000_create_news_table",
    ]);
  });

  it("down() の dropIfExists を破壊的と誤検出しない", () => {
    const root = fixture();
    expect(hasDestructiveMigration(root, localMigrations(root))).toBe(false);
  });

  it("up() の dropColumn を破壊的として検出する", () => {
    const root = fixture();
    writeFileSync(
      join(root, "database", "migrations", "2026_02_01_000000_drop_legacy.php"),
      "<?php\n    public function up()\n    {\n        Schema::table('users', fn ($t) => $t->dropColumn('legacy'));\n    }\n",
    );
    expect(hasDestructiveMigration(root, localMigrations(root))).toBe(true);
  });
});

describe("shq", () => {
  it("シングルクォートを安全にエスケープする", () => {
    expect(shq("a'b")).toBe(`'a'\\''b'`);
    expect(shq("/plain/path")).toBe("'/plain/path'");
  });
});

// ---------------------------------------------------------------------------
// docroot 競合（XServer DirectoryIndex: index.html > htm > shtml > cgi > php）
// ---------------------------------------------------------------------------

describe("docroot conflicts", () => {
  it("XServer 初期生成の index.html は backup 判定（マーカー確認）", async () => {
    const exec = new FakeExecutor([
      [/test -f .*index\.html'/, { stdout: "yes" }],
      ["Copyright XSERVER Inc.", { stdout: "xserver-default" }],
      [/test -f .*index\.(htm|shtml|cgi)'/, { stdout: "no" }],
    ]);
    const conflicts = await xserverDocrootConflicts(exec, cfg);
    expect(conflicts).toHaveLength(1);
    expect(conflicts[0]).toMatchObject({
      file: "index.html",
      action: "backup",
    });
  });

  it("マーカーの無い index.html はユーザー作成の可能性として block", async () => {
    const exec = new FakeExecutor([
      [/test -f .*index\.html'/, { stdout: "yes" }],
      ["Copyright XSERVER Inc.", { stdout: "other" }],
      [/test -f .*index\.(htm|shtml|cgi)'/, { stdout: "no" }],
    ]);
    const conflicts = await xserverDocrootConflicts(exec, cfg);
    expect(conflicts[0]?.action).toBe("block");
    expect(conflicts[0]?.reason).toContain("自動削除しません");
  });

  it("index.cgi / index.htm / index.shtml は常に block（自動判定しない）", async () => {
    const exec = new FakeExecutor([
      [/test -f .*index\.html'/, { stdout: "no" }],
      [/test -f .*index\.htm'/, { stdout: "no" }],
      [/test -f .*index\.shtml'/, { stdout: "no" }],
      [/test -f .*index\.cgi'/, { stdout: "yes" }],
    ]);
    const conflicts = await xserverDocrootConflicts(exec, cfg);
    expect(conflicts).toHaveLength(1);
    expect(conflicts[0]).toMatchObject({ file: "index.cgi", action: "block" });
  });

  it("競合ファイルが無ければ空配列", async () => {
    const exec = new FakeExecutor([[/test -f/, { stdout: "no" }]]);
    expect(await xserverDocrootConflicts(exec, cfg)).toEqual([]);
  });

  it("退避は mv（削除しない）で backups/ へ。default_page.png も同時に退避", async () => {
    const exec = new FakeExecutor([
      ["conflict-backup-ok", { stdout: "conflict-backup-ok" }],
    ]);
    const r = await backupDocrootConflicts(
      exec,
      cfg,
      [{ file: "index.html", action: "backup", reason: "test" }],
      "20260808120000",
    );
    expect(r.ok).toBe(true);
    const cmd = exec.history.find((h) => h.includes("mv "))!;
    expect(cmd).toContain(
      "mv '/home/xsample/xsample.xsrv.jp/public_html/index.html'",
    );
    expect(cmd).toContain("/backups/docroot-index.html.20260808120000");
    expect(cmd).toContain("default_page.png");
    expect(cmd).not.toMatch(/\brm\b/);
  });

  it("backup 対象が無ければ何も実行しない", async () => {
    const exec = new FakeExecutor([]);
    const r = await backupDocrootConflicts(
      exec,
      cfg,
      [{ file: "index.cgi", action: "block", reason: "test" }],
      "20260808120000",
    );
    expect(r.ok).toBe(true);
    expect(exec.history).toEqual([]);
  });

  /**
   * B-41 — **docroot の既存 index.php を上書きしない。**
   *
   * `publishDocroot()` は `tar -xf` のあと `cp` で `index.php` を**置き換える**。
   * ファイルの backup はコード全体で 1 行も無い（backup は DB だけ）。
   * つまり **既存サイトの入口が黙って消える**。
   *
   * ただし**自分が前回置いたスタブは上書きしてよい**ので、
   * 見分けはスタブ自身の目印で行う（`index.html` を XServer 初期ページの
   * マーカーで見分けているのと同じやり方）。
   */
  it("他人の index.php は上書きせずに止める", async () => {
    const exec = new FakeExecutor([
      [/test -f .*index\.(html|htm|shtml|cgi)'/, { stdout: "no" }],
      [/test -f .*index\.php'/, { stdout: "yes" }],
      [ENTRY_STUB_MARKER, { stdout: "other" }],
    ]);
    const conflicts = await xserverDocrootConflicts(exec, cfg);
    expect(conflicts).toHaveLength(1);
    expect(conflicts[0]).toMatchObject({ file: "index.php", action: "block" });
    expect(conflicts[0]?.reason).toContain("上書き");
  });

  it("前回の release が置いたスタブなら競合にしない（自分のものは上書きしてよい）", async () => {
    const exec = new FakeExecutor([
      [/test -f .*index\.(html|htm|shtml|cgi)'/, { stdout: "no" }],
      [/test -f .*index\.php'/, { stdout: "yes" }],
      [ENTRY_STUB_MARKER, { stdout: "agentcms-stub" }],
    ]);
    expect(await xserverDocrootConflicts(exec, cfg)).toEqual([]);
  });

  /**
   * 目印とスタブ本文がずれると、**自分が置いたものを他人だと読んで**
   * 2 回目のデプロイが止まる。だから同じ 1 つの定数から出す。
   */
  it("目印は実際にスタブ本文へ入っている", () => {
    expect(entryStubPhp("/home/x/app")).toContain(ENTRY_STUB_MARKER);
  });
});

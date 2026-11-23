import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";

import { loadDeployConfig } from "../src/deploy/config.js";
import type { ExecResult, RemoteExecutor } from "../src/deploy/executor.js";
import {
  extractNames,
  findStatusFor,
  redactSecrets,
  resolveApiKey,
  XserverApiHttp,
  type ApiResult,
  type XserverApi,
} from "../src/deploy/providers/xserver-api.js";
import {
  detectSetupActions,
  dbSuffix,
  generateAppKey,
  generateDbPassword,
  isPhpVersionOk,
  splitDomain,
} from "../src/deploy/providers/xserver-setup.js";
import { xserverDoctor } from "../src/deploy/providers/xserver.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

const cfg: DeployTargetConfig = {
  provider: "xserver",
  target: "staging",
  ssh: { host: "xsample", port: 10022, user: "xsample" },
  remote: {
    appDir: "/home/xsample/agentcms/staging",
    docroot: "/home/xsample/example.com/public_html/agcms",
    phpBin: "auto",
  },
  app: { url: "https://agcms.example.com", healthPath: "/healthz" },
  keepReleases: 3,
};

/** Control Plane の Fake（実 API へ接続する前に Adapter を検証する） */
class FakeApi implements XserverApi {
  public calls: Array<{ method: string; args: unknown[] }> = [];

  constructor(
    public state: {
      subdomains?: string[];
      ssl?: Array<{ domain: string; status: string }>;
      dbs?: string[];
      users?: string[];
      grants?: string[];
      crons?: string[];
      phpVersion?: string;
      failAll?: boolean;
    } = {},
  ) {}

  // 実測知見（ADR-0009）: servername は初期ドメイン形式
  readonly servername = "xsample.xsrv.jp";

  private track(method: string, ...args: unknown[]): void {
    this.calls.push({ method, args });
  }

  private result<T>(data: T): Promise<ApiResult<T>> {
    if (this.state.failAll)
      return Promise.resolve({ ok: false, status: 503, error: "api down" });
    return Promise.resolve({ ok: true, status: 200, data });
  }

  sshStatus(): Promise<ApiResult> {
    this.track("sshStatus");
    return this.result({ ssh_enabled: true });
  }

  subdomainList(): Promise<ApiResult<{ subdomains?: unknown[] }>> {
    this.track("subdomainList");
    return this.result({
      subdomains: (this.state.subdomains ?? []).map((fqdn) => ({ fqdn })),
    });
  }

  subdomainCreate(body: {
    domain: string;
    subdomain: string;
    document_root?: string;
  }): Promise<ApiResult> {
    this.track("subdomainCreate", body);
    return this.result({});
  }

  sslList(): Promise<ApiResult<{ ssl?: unknown[] }>> {
    this.track("sslList");
    return this.result({ ssl: this.state.ssl ?? [] });
  }

  sslCreate(domain: string): Promise<ApiResult> {
    this.track("sslCreate", domain);
    // 発行後は pending → active になったことにする
    this.state.ssl = [{ domain, status: "active" }];
    return this.result({});
  }

  dbList(): Promise<ApiResult<{ databases?: unknown[] }>> {
    this.track("dbList");
    return this.result({
      databases: (this.state.dbs ?? []).map((db_name) => ({ db_name })),
    });
  }

  dbCreate(body: { name_suffix: string }): Promise<ApiResult> {
    this.track("dbCreate", body);
    return this.result({});
  }

  dbUserList(): Promise<ApiResult<{ users?: unknown[] }>> {
    this.track("dbUserList");
    return this.result({
      users: (this.state.users ?? []).map((db_user) => ({ db_user })),
    });
  }

  dbUserCreate(body: {
    name_suffix: string;
    password: string;
  }): Promise<ApiResult> {
    this.track("dbUserCreate", { name_suffix: body.name_suffix });
    return this.result({});
  }

  dbUserUpdate(dbUser: string, _password: string): Promise<ApiResult> {
    this.track("dbUserUpdate", dbUser); // パスワードは記録しない
    return this.result({});
  }

  dbGrantList(dbUser: string): Promise<ApiResult<{ grants?: unknown[] }>> {
    this.track("dbGrantList", dbUser);
    return this.result({
      grants: (this.state.grants ?? []).map((db_name) => ({ db_name })),
    });
  }

  dbGrantCreate(dbUser: string, dbName: string): Promise<ApiResult> {
    this.track("dbGrantCreate", dbUser, dbName);
    return this.result({});
  }

  cronList(): Promise<ApiResult<{ crons?: unknown[] }>> {
    this.track("cronList");
    return this.result({
      crons: (this.state.crons ?? []).map((command) => ({ command })),
    });
  }

  cronCreate(body: { command: string }): Promise<ApiResult> {
    this.track("cronCreate", body);
    return this.result({});
  }

  phpVersionGet(domain: string): Promise<ApiResult> {
    this.track("phpVersionGet", domain);
    // state.phpVersion 未指定 = API から取得できないケースを表す
    return this.result(
      this.state.phpVersion ? { version: this.state.phpVersion } : {},
    );
  }

  phpVersionSet(domain: string, version: string): Promise<ApiResult> {
    this.track("phpVersionSet", domain, version);
    return this.result({});
  }
}

class FakeExec implements RemoteExecutor {
  public history: string[] = [];

  constructor(
    private rules: Array<[string | RegExp, Partial<ExecResult>]> = [],
  ) {}

  label(): string {
    return "fake";
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
    return { code: 0, stdout: "", stderr: "" };
  }

  async upload(): Promise<ExecResult> {
    return { code: 0, stdout: "", stderr: "" };
  }
}

// ---------------------------------------------------------------------------

describe("api key handling", () => {
  it("環境変数からのみ解決する（未設定は null = 手動 fallback）", () => {
    expect(resolveApiKey({} as NodeJS.ProcessEnv)).toBeNull();
    expect(
      resolveApiKey({
        XSERVER_API_KEY: " xs_abc123456789 ",
      } as NodeJS.ProcessEnv),
    ).toBe("xs_abc123456789");
  });

  it("エラーメッセージ中のキーらしき文字列を redact する", () => {
    expect(redactSecrets("error: Bearer xs_AbC123456789xyz invalid")).toBe(
      "error: Bearer xs_*** invalid",
    );
  });

  it("設定ファイルに API キーを書くと validation で拒否される", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-api-config-"));
    mkdirSync(join(dir, ".agentcms", "deploy"), { recursive: true });
    writeFileSync(
      join(dir, ".agentcms", "deploy", "staging.json"),
      JSON.stringify({ ...cfg, api: { apiKey: "xs_secret" } }),
    );
    const { error } = loadDeployConfig(dir, "staging");
    expect(error?.why).toContain("XSERVER_API_KEY");
    rmSync(dir, { recursive: true, force: true });
  });

  it("api.servername / dbSuffix は設定できる", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-api-config-"));
    mkdirSync(join(dir, ".agentcms", "deploy"), { recursive: true });
    writeFileSync(
      join(dir, ".agentcms", "deploy", "staging.json"),
      JSON.stringify({ ...cfg, api: { servername: "sv1", dbSuffix: "agcms" } }),
    );
    const { config, error } = loadDeployConfig(dir, "staging");
    expect(error).toBeUndefined();
    expect(config?.api?.dbSuffix).toBe("agcms");
    rmSync(dir, { recursive: true, force: true });
  });
});

describe("XserverApiHttp", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("Bearer 認証で正しい URL を叩き、エラー本文から xs_ を redact する", async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response('{"message":"bad key xs_leaked1234567"}', { status: 401 }),
    );
    vi.stubGlobal("fetch", fetchMock);
    const api = new XserverApiHttp("xsample", "xs_testkey1234567");
    const r = await api.sslList();
    expect(r.ok).toBe(false);
    expect(r.status).toBe(401);
    expect(r.error).not.toContain("xs_leaked1234567");
    expect(r.error).toContain("xs_***");
    const [url, init] = fetchMock.mock.calls[0] as unknown as [
      string,
      RequestInit,
    ];
    expect(url).toBe("https://api.xserver.ne.jp/v1/server/xsample/ssl");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer xs_testkey1234567",
    );
  });
});

describe("tolerant response parsing", () => {
  it("器のキー名が変わっても代表キーで名前を抽出する", () => {
    expect(
      extractNames({ items: [{ db_name: "a" }, { db_name: "b" }] }, [
        "db_name",
      ]),
    ).toEqual(["a", "b"]);
    expect(
      extractNames({ data: { list: [{ fqdn: "x.example.com" }] } }, ["fqdn"]),
    ).toEqual(["x.example.com"]);
    expect(extractNames(undefined, ["fqdn"])).toEqual([]);
  });

  it("ドメインに対応する status を見つける", () => {
    const data = {
      ssl: [
        { domain: "a.example.com", status: "active" },
        { common_name: "b.example.com", status: "pending" },
      ],
    };
    expect(findStatusFor(data, "b.example.com")).toBe("pending");
    expect(findStatusFor(data, "c.example.com")).toBeNull();
  });
});

describe("setup helpers", () => {
  it("splitDomain がサブドメインを分解する", () => {
    expect(splitDomain("https://agcms.example.com")).toEqual({
      fqdn: "agcms.example.com",
      parent: "example.com",
      sub: "agcms",
    });
    expect(splitDomain("https://example.com").sub).toBeNull();
  });

  /**
   * 🔴 **2 段の公開接尾辞は、ラベル数だけでは見分けられない**（B-41 ③）。
   *
   * `midori-zoen.co.jp` は `agcms.example.com` と**同じ 3 ラベル**だが、
   * サブドメインではない。ラベル数で切ると **親を `co.jp` と読み、
   * 他人のドメインの下へサブドメインを作りに行く**。
   *
   * ▷ **この試験が無かったから見つからなかった。** 例は `.com` の 2 つだけで、
   * **日本の案件なら普通に来る `.co.jp` が 1 つも無かった** — 今日の
   * 「**前提の例が無い**」と同じ形である。
   */
  it("2 段の JP はサブドメインではない（実案件の実例）", () => {
    expect(splitDomain("https://midori-zoen.co.jp")).toEqual({
      fqdn: "midori-zoen.co.jp",
      parent: "midori-zoen.co.jp",
      sub: null,
    });
  });

  it("2 段の JP の下のサブドメインは分解できる", () => {
    expect(splitDomain("https://agcms.midori-zoen.co.jp")).toEqual({
      fqdn: "agcms.midori-zoen.co.jp",
      parent: "midori-zoen.co.jp",
      sub: "agcms",
    });
  });

  /** **属性型 JP は全部同じ形**（`co` だけ直して終わりにしない） */
  it("属性型 JP を一通り分解できる", () => {
    for (const suffix of ["or.jp", "ne.jp", "ac.jp", "go.jp", "gr.jp", "lg.jp"]) {
      expect(splitDomain(`https://example.${suffix}`).sub).toBeNull();
      expect(splitDomain(`https://agcms.example.${suffix}`)).toEqual({
        fqdn: `agcms.example.${suffix}`,
        parent: `example.${suffix}`,
        sub: "agcms",
      });
    }
  });

  /** **1 段の `.jp` は今までどおり**（2 段の表に引きずられない） */
  it("1 段の JP は今までどおり分解する", () => {
    expect(splitDomain("https://example.jp").sub).toBeNull();
    expect(splitDomain("https://agcms.example.jp")).toEqual({
      fqdn: "agcms.example.jp",
      parent: "example.jp",
      sub: "agcms",
    });
  });

  /** 他国の 2 段も同じ */
  it("他国の 2 段も分解できる", () => {
    expect(splitDomain("https://example.co.uk").sub).toBeNull();
    expect(splitDomain("https://shop.example.com.au").parent).toBe(
      "example.com.au",
    );
  });

  /** **深い階層でも、親は契約しているドメイン** */
  it("階層が深くても親は契約しているドメイン", () => {
    expect(splitDomain("https://a.b.example.co.jp")).toEqual({
      fqdn: "a.b.example.co.jp",
      parent: "example.co.jp",
      sub: "a.b",
    });
  });

  /**
   * **表に無い接尾辞は取り違える。だから明示で上書きできる。**
   *
   * `MULTI_LABEL_SUFFIXES` は手書きなので必ず抜ける。
   * 抜けた場合の逃げ道が在ることを固定する。
   */
  it("親ドメインを明示すれば推測しない", () => {
    // 表に無い 2 段（推測では co.zz を親と読む）
    expect(splitDomain("https://example.co.zz").parent).toBe("co.zz");

    // 明示すれば正しくなる
    expect(splitDomain("https://example.co.zz", "example.co.zz")).toEqual({
      fqdn: "example.co.zz",
      parent: "example.co.zz",
      sub: null,
    });
    expect(splitDomain("https://agcms.example.co.zz", "example.co.zz")).toEqual({
      fqdn: "agcms.example.co.zz",
      parent: "example.co.zz",
      sub: "agcms",
    });
  });

  /** **DB の suffix も同じ判定を通る**（サブドメインが無ければ既定へ落ちる） */
  it("2 段の JP では dbSuffix が既定へ落ちる", () => {
    expect(
      dbSuffix({ ...cfg, app: { ...cfg.app, url: "https://midori-zoen.co.jp" } }),
    ).toBe("app");
  });

  it("dbSuffix は 設定 > サブドメインラベル の順で決まる", () => {
    expect(dbSuffix(cfg)).toBe("agcms");
    expect(dbSuffix({ ...cfg, api: { dbSuffix: "custom" } })).toBe("custom");
  });

  it("isPhpVersionOk は 8.3 以上のみ許可", () => {
    expect(isPhpVersionOk("8.3.20")).toBe(true);
    expect(isPhpVersionOk("8.5.5")).toBe(true);
    expect(isPhpVersionOk("8.2.9")).toBe(false);
    expect(isPhpVersionOk("7.4.33")).toBe(false);
    expect(isPhpVersionOk(null)).toBe(false);
  });

  it("APP_KEY / DB パスワード生成が要件を満たす", () => {
    expect(generateAppKey()).toMatch(/^base64:[A-Za-z0-9+/=]{40,}$/);
    const pw = generateDbPassword();
    expect(pw.length).toBeGreaterThanOrEqual(8);
    expect(pw.length).toBeLessThanOrEqual(16); // API 実測制約（16 文字超は 422）
    expect(pw).not.toMatch(/['"\\$`\s]/); // シェル安全
  });
});

describe("detectSetupActions", () => {
  const phpBin = "/usr/bin/php8.5";

  it("何も無い状態では全項目が pending になる", async () => {
    const api = new FakeApi({ phpVersion: "8.2.0" });
    const exec = new FakeExec([[/DB_DATABASE/, { stdout: "0" }]]);
    const { actions, errors } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    expect(errors).toEqual([]);
    const pending = actions.filter((a) => !a.satisfied).map((a) => a.key);
    expect(pending).toEqual([
      "subdomain",
      "ssl",
      "db",
      "db_user",
      "db_grant",
      "shared_env",
      "cron",
      "php",
    ]);
  });

  it("全て整っていれば pending なし（冪等）", async () => {
    const api = new FakeApi({
      subdomains: ["agcms.example.com"],
      ssl: [{ domain: "agcms.example.com", status: "active" }],
      dbs: ["xsample_agcms"],
      users: ["xsample_agcms"],
      grants: ["xsample_agcms"],
      crons: [
        "/usr/bin/php8.5 /home/xsample/agentcms/staging/current/artisan schedule:run >/dev/null 2>&1",
      ],
      phpVersion: "8.5.5",
    });
    const exec = new FakeExec([
      [/DB_DATABASE/, { stdout: "1" }],
      [/MYSQL_PWD/, { stdout: "dbconn-ok" }],
    ]);
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    expect(actions.filter((a) => !a.satisfied)).toEqual([]);
  });

  it("認証失敗を検出したらパスワードを API で再設定し .env を更新・再検証する", async () => {
    const api = new FakeApi({
      subdomains: ["agcms.example.com"],
      ssl: [{ domain: "agcms.example.com", status: "active" }],
      dbs: ["xsample_agcms"],
      users: ["xsample_agcms"],
      grants: ["xsample_agcms"],
      phpVersion: "8.5.5",
    });
    const exec = new FakeExec([
      [/DB_DATABASE/, { stdout: "1" }],
      [/sed -i/, { stdout: "dbconn-ok" }], // 再設定後の検証は成功
      [/MYSQL_PWD/, { stdout: "dbconn-fail" }], // 検出時は認証失敗
    ]);
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    const repair = actions.find((a) => a.key === "db_password")!;
    expect(repair.satisfied).toBe(false);
    expect(repair.current).toContain("接続失敗");
    const r = await repair.run();
    expect(r.ok).toBe(true);
    expect(api.calls.some((c) => c.method === "dbUserUpdate")).toBe(true);
    // 再設定コマンドは chmod 600 を維持し、パスワードを echo しない
    const cmd = exec.history.find((h) => h.includes("sed -i"))!;
    expect(cmd).toContain("chmod 600");
    expect(cmd).not.toContain("echo $pw");
  });

  it("subdomain 作成は docroot を指定する", async () => {
    const api = new FakeApi({});
    const exec = new FakeExec();
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    const sub = actions.find((a) => a.key === "subdomain")!;
    await sub.run();
    const call = api.calls.find((c) => c.method === "subdomainCreate")!;
    expect(call.args[0]).toEqual({
      domain: "example.com",
      subdomain: "agcms",
      document_root: cfg.remote.docroot,
    });
  });

  it("shared/.env 新規作成は APP_KEY / DB_* を書き chmod 600 する（表示はしない）", async () => {
    const api = new FakeApi({});
    const exec = new FakeExec([
      [/test -f .*shared\/\.env && echo/, { stdout: "no" }],
      [/env-created/, { stdout: "env-created" }],
    ]);
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    // ユーザー作成 → env 作成の順で実行
    await actions.find((a) => a.key === "db_user")!.run();
    const envAction = actions.find((a) => a.key === "shared_env")!;
    const r = await envAction.run();
    expect(r.ok).toBe(true);
    const cmd = exec.history.find((h) => h.includes("AGENTCMS_ENV_EOS"))!;
    expect(cmd).toContain("APP_KEY=base64:");
    expect(cmd).toContain("APP_ENV=staging");
    expect(cmd).toContain("APP_DEBUG=false");
    expect(cmd).toContain("DB_DATABASE=xsample_agcms");
    expect(cmd).toContain("chmod 600");
    // describe（confirm 表示）にパスワードが含まれない
    expect(envAction.describe).not.toMatch(/[A-Za-z0-9_-]{24,}/);
  });

  it("PHP バージョンが API から取得できない場合は盲目的に切り替えない", async () => {
    const api = new FakeApi({}); // phpVersion 未指定 = 取得不可
    const exec = new FakeExec();
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    const php = actions.find((a) => a.key === "php")!;
    expect(php.satisfied).toBe(true); // 切替対象にしない
    expect(php.current).toContain("取得不可");
    // fqdn → 親ドメインの順で問い合わせている
    const calls = api.calls
      .filter((c) => c.method === "phpVersionGet")
      .map((c) => c.args[0]);
    expect(calls).toEqual(["agcms.example.com", "example.com"]);
  });

  it("DB 名の接頭辞は servername（FQDN）ではなくサーバー ID を使う", async () => {
    const api = new FakeApi({});
    const exec = new FakeExec();
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    expect(actions.find((a) => a.key === "db")?.describe).toContain(
      "xsample_agcms",
    );
    expect(actions.find((a) => a.key === "db")?.describe).not.toContain(
      ".xsrv.jp_",
    );
  });

  it("既存 DB ユーザーのパスワードは取得できないため手動更新を案内する", async () => {
    const api = new FakeApi({ users: ["xsample_agcms"] });
    const exec = new FakeExec([[/DB_DATABASE/, { stdout: "0" }]]);
    const { actions } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    const r = await actions.find((a) => a.key === "shared_env")!.run();
    expect(r.ok).toBe(false);
    expect(r.detail).toContain("手動更新");
  });

  it("API 全滅時は errors に集約される（例外にしない = fallback 可能）", async () => {
    const api = new FakeApi({ failAll: true });
    const exec = new FakeExec();
    const { errors } = await detectSetupActions({
      cfg,
      api,
      exec,
      phpBin,
      log: () => {},
    });
    expect(errors.length).toBeGreaterThan(0);
  });
});

describe("doctor と Control Plane の統合", () => {
  const doctorExec = () =>
    new FakeExec([
      ["echo agentcms-ssh-ok", { stdout: "agentcms-ssh-ok" }],
      ["'/usr/bin/php8.5' -v", { stdout: "PHP 8.5.5 (cli)" }],
      [
        / -m$/,
        {
          stdout:
            "ctype\ncurl\ndom\nfileinfo\ngd\nmbstring\nopenssl\npdo_mysql\ntokenizer\nxml\nzip",
        },
      ],
      ["composer --version", { stdout: "Composer version 1.10.10" }],
      [/test -f .*shared\/\.env/, { stdout: "no" }],
      [/mkdir -p .*test -w/, { stdout: "yes" }],
      [/public_html.*test -w/, { stdout: "yes" }],
      ["symlink-test", { stdout: "yes" }],
      [/curl .*http_code/, { stdout: "200" }],
      ["df -h", { stdout: "1.1T" }],
      ["readlink", { stdout: "" }],
    ]);

  it("API あり: SSL active + Cron 登録済みが PASS になる", async () => {
    const api = new FakeApi({
      ssl: [{ domain: "agcms.example.com", status: "active" }],
      crons: [
        "/usr/bin/php8.5 /home/xsample/agentcms/staging/current/artisan schedule:run >/dev/null 2>&1",
      ],
    });
    const { capabilities } = await xserverDoctor(doctorExec(), cfg, api);
    expect(capabilities.checks.find((c) => c.key === "api_ssl")?.status).toBe(
      "PASS",
    );
    expect(capabilities.checks.find((c) => c.key === "cron")?.status).toBe(
      "PASS",
    );
  });

  it("API あり: SSL 未設定は deploy setup を案内する FAIL", async () => {
    const api = new FakeApi({});
    const { capabilities } = await xserverDoctor(doctorExec(), cfg, api);
    const ssl = capabilities.checks.find((c) => c.key === "api_ssl");
    expect(ssl?.status).toBe("FAIL");
    expect(ssl?.fix).toContain("deploy setup");
  });

  it("API なし: WARN で手動 fallback を案内する（FAIL にしない）", async () => {
    const { capabilities } = await xserverDoctor(doctorExec(), cfg, null);
    const apiCheck = capabilities.checks.find((c) => c.key === "api");
    expect(apiCheck?.status).toBe("WARN");
    expect(apiCheck?.fix).toContain("XSERVER_API_KEY");
    expect(capabilities.checks.find((c) => c.key === "cron")?.status).toBe(
      "WARN",
    );
  });
});

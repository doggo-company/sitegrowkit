import { execFileSync, spawnSync } from "node:child_process";
import {
  existsSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import {
  buildProjectContext,
  renderForAudience,
} from "../src/agent-context.js";
import { composeProjectName, projectSlug } from "../src/compose-identity.js";
import {
  ensureAdminCredential,
  generatePassword,
  readEnvValue,
  setEnvValue,
} from "../src/credentials.js";
import { choosePorts } from "../src/ports.js";
import { create, shouldCopyTemplatePath } from "../src/commands/create.js";
import { needsFrontendBuild, parseLoginPath } from "../src/commands/dev.js";
import { buildPlan } from "../src/importer/plan.js";
import type { ContentSchema } from "../src/importer/plan-types.js";
import { analyzeTemplateDir } from "../src/inspect/analyze.js";
import {
  kitVersion,
  TEMPLATE_SCHEMA_VERSION,
  AGENT_CONTEXT_VERSION,
} from "../src/version.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");

/**
 * Plan を組むための schema。
 * `works.category` は import 済みの作業ツリーに左右されないよう Phase 4 の値へ固定する
 * （plan.test.ts と同じ扱い）。
 */
function fixtureSchema(id: string): ContentSchema {
  const schema = JSON.parse(
    readFileSync(
      join(repoRoot, "template/content/schemas", `${id}.json`),
      "utf8",
    ),
  ) as ContentSchema;

  if (id === "works") {
    const category = schema.fields.find((f) => f.key === "category");

    if (category) category.choices = ["web", "graphic", "branding"];
  }

  return schema;
}

/* ---------------------------------------------------------------- *
 * Version SSoT（Decision 2）
 * ---------------------------------------------------------------- */
describe("version single source of truth", () => {
  it("VERSION と cli package.json の version が一致する", () => {
    const versionFile = readFileSync(join(repoRoot, "VERSION"), "utf8").trim();
    const pkg = JSON.parse(
      readFileSync(join(repoRoot, "packages/cli/package.json"), "utf8"),
    ) as { version: string };
    expect(pkg.version).toBe(versionFile);
    expect(kitVersion()).toBe(versionFile);
  });

  it("index.ts に version がハードコードされていない", () => {
    const src = readFileSync(
      join(repoRoot, "packages/cli/src/index.ts"),
      "utf8",
    );
    expect(src).toContain(".version(kitVersion())");
    expect(src).not.toMatch(/\.version\(["']\d/);
  });
});

/* ---------------------------------------------------------------- *
 * Agent Context 変換と template 同期（Decision 3）
 * ---------------------------------------------------------------- */
describe("agent context rendering", () => {
  const sample = [
    "line",
    "<!-- kit-only:start -->",
    "kit line `packages/cli/`",
    "<!-- kit-only:end -->",
    "<!-- project-only:start",
    "project line",
    "project-only:end -->",
    "path `template/core/src/`",
  ].join("\n");

  it("kit audience は kit-only を残し project-only を除く", () => {
    const out = renderForAudience(sample, "kit");
    expect(out).toContain("kit line");
    expect(out).not.toContain("project line");
    expect(out).toContain("template/core/src/");
    expect(out).not.toContain("kit-only");
  });

  it("project audience は project-only を残し template/ prefix を除く", () => {
    const out = renderForAudience(sample, "project");
    expect(out).not.toContain("kit line");
    expect(out).toContain("project line");
    expect(out).toContain("`core/src/`");
    expect(out).not.toContain("project-only");
  });

  it("marker 除去でテーブルが分断されない", () => {
    const table = [
      "| a | b |",
      "<!-- kit-only:start -->",
      "| kit | row |",
      "<!-- kit-only:end -->",
      "| c | d |",
    ].join("\n");
    const out = renderForAudience(table, "project");
    expect(out).toContain("| a | b |\n| c | d |");
  });

  it("template/.agentcms が canonical から生成された状態と一致する（ドリフト禁止）", () => {
    const files = buildProjectContext(join(repoRoot, ".agentcms"));
    expect(files.length).toBeGreaterThanOrEqual(8);
    for (const f of files) {
      const p = join(repoRoot, "template/.agentcms", f.rel);
      expect(existsSync(p), `${f.rel} が template に存在しない`).toBe(true);
      expect(readFileSync(p, "utf8"), `${f.rel} がドリフトしている`).toBe(
        f.content,
      );
    }
  });

  it("project 向け context に kit 内部参照が漏れない", () => {
    const files = buildProjectContext(join(repoRoot, ".agentcms"));
    for (const f of files) {
      expect(f.content, `${f.rel} に packages/cli が漏れている`).not.toContain(
        "packages/cli",
      );
      expect(f.content, `${f.rel} に marker が残っている`).not.toContain(
        "kit-only",
      );
    }
  });
});

/* ---------------------------------------------------------------- *
 * Admin credential（Decision 4）
 * ---------------------------------------------------------------- */
describe("admin credential", () => {
  it("password は十分に長くランダム（project ごとに異なる）", () => {
    const seen = new Set<string>();
    for (let i = 0; i < 100; i++) {
      const p = generatePassword();
      expect(p).toHaveLength(24);
      expect(p).toMatch(/^[A-Za-z2-9]+$/);
      expect(p).not.toBe("password");
      seen.add(p);
    }
    expect(seen.size).toBe(100);
  });

  it("env の読み書きが往復する", () => {
    let env = "APP_NAME=x\nADMIN_PASSWORD=\n";
    env = setEnvValue(env, "ADMIN_PASSWORD", "abc123");
    env = setEnvValue(env, "NEW_KEY", "v");
    expect(readEnvValue(env, "ADMIN_PASSWORD")).toBe("abc123");
    expect(readEnvValue(env, "NEW_KEY")).toBe("v");
    expect(env.match(/^ADMIN_PASSWORD=/gm)).toHaveLength(1);
  });

  it("ensureAdminCredential は生成・保存し、再実行では変更しない", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-cred-"));
    try {
      writeFileSync(join(dir, ".env"), "APP_NAME=t\n", "utf8");
      const first = ensureAdminCredential(dir);
      expect(first.created).toBe(true);
      const credPath = join(dir, ".agentcms/local/admin-credentials.json");
      const cred = JSON.parse(readFileSync(credPath, "utf8")) as {
        email: string;
        password: string;
      };
      expect(cred.password).toHaveLength(24);
      if (process.platform !== "win32") {
        expect(statSync(credPath).mode & 0o777).toBe(0o600);
      }
      const envPassword = readEnvValue(
        readFileSync(join(dir, ".env"), "utf8"),
        "ADMIN_PASSWORD",
      );
      expect(envPassword).toBe(cred.password);
      // gitignore が保護している
      expect(readFileSync(join(dir, ".gitignore"), "utf8")).toContain(
        ".agentcms/local/",
      );
      // 冪等
      const second = ensureAdminCredential(dir);
      expect(second.created).toBe(false);
      expect(
        (JSON.parse(readFileSync(credPath, "utf8")) as { password: string })
          .password,
      ).toBe(cred.password);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

/* ---------------------------------------------------------------- *
 * Compose identity / ports（Decision 9）
 * ---------------------------------------------------------------- */
describe("compose identity", () => {
  it("slug は compose 安全な文字のみ", () => {
    expect(projectSlug("My Site!")).toBe("my-site");
    expect(projectSlug("日本語のみ")).toBe("site");
  });

  it("project 名は固定 agentcms にならず、呼び出しごとに一意", () => {
    const a = composeProjectName("my-site");
    const b = composeProjectName("my-site");
    expect(a).toMatch(/^agentcms-my-site-[0-9a-f]{6}$/);
    expect(a).not.toBe(b);
    expect(a).not.toBe("agentcms");
  });
});

describe("port selection", () => {
  it("空いていれば既定ポートを使う", async () => {
    const sel = await choosePorts(async () => true);
    expect(sel.assignments["APP_PORT"]).toBe(8180);
    expect(sel.changed).toHaveLength(0);
  });

  it("使用中なら次の空きポートを選び .env 記録用に報告する", async () => {
    const busy = new Set([8180, 8181]);
    const sel = await choosePorts(async (p) => !busy.has(p));
    expect(sel.assignments["APP_PORT"]).toBe(8182);
    expect(sel.changed.some((c) => c.includes("8180 → 8182"))).toBe(true);
  });

  it("同一実行内でポートが重複しない", async () => {
    const sel = await choosePorts(
      async () => true,
      [
        { key: "A", name: "a", preferred: 9000 },
        { key: "B", name: "b", preferred: 9000 },
      ],
    );
    expect(new Set(Object.values(sel.assignments)).size).toBe(2);
  });
});

/* ---------------------------------------------------------------- *
 * create — Golden Path 生成物（Decision 3/4/5/6/9）
 * ---------------------------------------------------------------- */
describe("template copy filter", () => {
  it("生成物・ローカル状態・秘密をコピーしない", () => {
    for (const rel of [
      "vendor/autoload.php",
      "node_modules/x/index.js",
      ".env",
      ".git/HEAD",
      ".lighthouseci/report.json",
      "storage/logs/laravel.log",
      "public/build/manifest.json",
      ".agentcms/local/admin-credentials.json",
      ".agentcms/cache/figma/raw.json",
      ".agentcms/deploy/staging.json",
      "test-results/foo.txt",
      "tests/e2e/.auth/admin.json",
      // project override は **その project の状態**（#24）。
      // kit のリポジトリには開発機用の override が置かれていることがあり、
      // 追跡から外しただけでは repo 上の create で複製されてしまう
      "content/schemas/overrides",
      "content/schemas/overrides/works.json",
      // kit の例・検証資産（PHASE 9.7 / STAGE 20B・OG-A）。
      // 詳細は tests/default-post-type.test.ts
      "content/schemas/news.json",
      "content/schemas/works.json",
      "modules/News/Models/News.php",
    ]) {
      expect(shouldCopyTemplatePath(rel), `${rel} はコピー対象外のはず`).toBe(
        false,
      );
    }
  });

  it("プロジェクトに必要なファイルはコピーする", () => {
    for (const rel of [
      "artisan",
      ".env.example",
      "docker-compose.yml",
      "core/src/Contracts/x.php",
      // 新しく生成するサイトが持つ唯一の投稿タイプ（STAGE 20B）
      "content/schemas/post.json",
      ".agentcms/architecture.md",
      ".agentcms/deploy/staging.example.json",
      "storage/logs",
    ]) {
      expect(shouldCopyTemplatePath(rel), `${rel} はコピーするはず`).toBe(true);
    }
  });
});

/* ---------------------------------------------------------------- *
 * 同梱サンプルの外部フォント依存（Phase 9.3B / STAGE 26）
 * ---------------------------------------------------------------- */

/**
 * 同梱サンプルが Google Fonts を hotlink していた結果、公開ページの
 * console error として 16 件の 404（fonts.gstatic.com の woff2 subset）が出た。
 *
 * ここで分けたいのは **2 つの別物**である。
 *
 * | | 期待 |
 * | --- | --- |
 * | kit が配るサンプル（`template/`） | 外部 CDN のフォントを読まない |
 * | importer の機能（`examples/html-template` の契約 fixture） | 利用者が指定した外部フォントを **保持し**、CSP origin を導出する |
 *
 * どちらか片方だけを見ていると、
 * 「サンプルを直すついでに importer の機能まで削る」逆流が起きる。
 * どちらもネットワークに依存せず、ファイルの中身だけで決まるように検査する。
 */
describe("同梱サンプルは外部フォントを読まない", () => {
  const TEMPLATE = resolve(__dirname, "../../../template");
  const read = (rel: string): string =>
    readFileSync(join(TEMPLATE, rel), "utf8");

  const EXTERNAL_FONT_HOSTS = ["fonts.googleapis.com", "fonts.gstatic.com"];

  it("配布 layout に外部フォントの参照が無い", () => {
    const layout = read("resources/views/layouts/imported.blade.php");

    for (const host of EXTERNAL_FONT_HOSTS) {
      expect(layout, `${host} を参照している`).not.toContain(host);
    }

    // productization（設定由来の識別情報・サンプル表記）は残っている
    expect(layout).toContain("$site['site_name']");
  });

  it("配布 .env.example に不要な外部フォント origin を残さない", () => {
    const env = read(".env.example");

    for (const host of EXTERNAL_FONT_HOSTS) {
      expect(env, `${host} を許可したままにしている`).not.toContain(host);
    }

    // 同梱サンプルは外部の script を読まない。追記の仕組み（空の行）そのものは残す
    expect(env).toMatch(/^AGENTCMS_CSP_SCRIPT_SRC=$/m);
    expect(env).not.toContain("cdn.jsdelivr.net");
  });

  it("同梱サンプルは GSAP（自由なソフトウェアでないライセンス）を読まない", () => {
    for (const file of [
      "resources/views/public/home.blade.php",
      "resources/views/layouts/imported.blade.php",
      "public/imported/js/main.js",
    ]) {
      expect(read(file), file).not.toMatch(/gsap/i);
    }
    // 登場の動きは CSS で持つ（JS が無くても最後は見える）
    expect(read("public/imported/css/style.css")).toContain("@keyframes hero-rise");
  });

  it("配布 CSS は端末にある sans を使い、Noto Sans JP を要求しない", () => {
    const css = read("public/imported/css/style.css");
    const body = /\bbody\s*\{[^}]*\}/.exec(css)?.[0] ?? "";

    expect(body, "body 宣言を読み取れていない").not.toBe("");
    expect(body).not.toContain("Noto Sans JP");

    // 日本語が読める端末内蔵フォントを順に指定している
    for (const family of [
      "Helvetica Neue",
      "Hiragino Kaku Gothic ProN",
      "Hiragino Sans",
      "BIZ UDPGothic",
      "Meiryo",
      "sans-serif",
    ]) {
      expect(body, `${family} が font-family に無い`).toContain(family);
    }
  });

  it("importer の外部フォント保持と CSP 導出は残っている（逆流防止）", () => {
    // 契約 fixture は **利用者のテンプレートの代理**。ここから外してはいけない
    const fixture = resolve(__dirname, "../../../examples/html-template");
    const index = readFileSync(join(fixture, "index.html"), "utf8");
    const fixtureCss = readFileSync(join(fixture, "css/style.css"), "utf8");

    expect(index).toContain("https://fonts.googleapis.com/css2");
    expect(fixtureCss).toContain("Noto Sans JP");

    // 検出 → CSP origin の導出まで、実際に importer を通して確かめる
    const manifest = analyzeTemplateDir(fixture, "fixture");
    const font = manifest.external.find((e) =>
      e.url.includes("fonts.googleapis.com"),
    );

    expect(font?.category).toBe("google-fonts");

    const plan = buildPlan(manifest, {
      works: fixtureSchema("works"),
      news: fixtureSchema("news"),
      contact: fixtureSchema("contact"),
    });

    expect(plan.env_additions["AGENTCMS_CSP_STYLE_SRC"]).toBe(
      "https://fonts.googleapis.com",
    );
    expect(plan.env_additions["AGENTCMS_CSP_FONT_SRC"]).toBe(
      "https://fonts.gstatic.com",
    );
  });
});

/**
 * Phase 9.3B / STAGE 3C CORRECTION（F-3C-1 C）。
 *
 * `agentcms create` のあとは **template/ 自体が利用者プロジェクトの root** になる。
 * 親ディレクトリの `.agentcms/`（kit canonical）や `packages/cli/` は存在しないので、
 * 利用者へコピーされる PHP テストがそれらを参照していると生成プロジェクトで壊れる。
 *
 * この検査は **kit 側でのみ**行い、利用者プロジェクトへ kit 内部パスを持ち込まない。
 */
describe("generated project portability", () => {
  const phpTests = (dir: string): string[] => {
    const out: string[] = [];

    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);

      if (entry.isDirectory()) {
        out.push(...phpTests(full));
      } else if (entry.name.endsWith(".php")) {
        out.push(full);
      }
    }

    return out;
  };

  it("template/tests の PHP テストが kit 親ディレクトリを参照しない", () => {
    const forbidden = [
      "base_path('../",
      'base_path("../',
      "../packages/cli/",
      "../.agentcms/",
    ];

    const offenders: string[] = [];

    for (const file of phpTests(join(repoRoot, "template/tests"))) {
      const source = readFileSync(file, "utf8");

      for (const needle of forbidden) {
        if (source.includes(needle)) {
          offenders.push(`${file.replace(repoRoot + "/", "")}: ${needle}`);
        }
      }
    }

    expect(offenders, offenders.join("\n")).toEqual([]);
  });

  it("生成プロジェクト向け context の registry が consumers を持つ", () => {
    const context = buildProjectContext(join(repoRoot, ".agentcms"));
    const registry = context.find(
      (f) => f.rel === "registry/taxonomies.json",
    )?.content;

    expect(registry).toBeTruthy();

    const parsed = JSON.parse(registry ?? "{}") as {
      taxonomies: { key: string; definition: string; consumers: string[] }[];
    };

    /*
     * ここは **template 自身の索引**であって、生成 site の索引ではない
     * （PHASE 9.7 / STAGE 20F）。template には `news_category` の宣言が実在するので、
     * 索引にも載っていなければ `agentcms:taxonomy-check` が落ちる。
     * 配らないものを落とすのは `create` のコピー時であり、その検査は
     * `distribution-boundary.test.ts` が持つ。
     */
    expect(parsed.taxonomies[0]?.consumers).toEqual(["news"]);
    // 生成プロジェクトから見たパスへ書き換わっている（kit の template/ prefix が残らない）
    expect(parsed.taxonomies[0]?.definition).toBe(
      "content/taxonomies/news_category.json",
    );

    for (const file of context) {
      expect(file.content).not.toContain("packages/cli/");
      expect(file.content).not.toContain("template/content/");
      expect(file.content).not.toContain("template/core/");
    }
  });
});

describe("create (integration)", () => {
  let workDir: string;
  let projectDir: string;

  beforeAll(async () => {
    workDir = mkdtempSync(join(tmpdir(), "agentcms-create-"));
    projectDir = join(workDir, "test-site");
    const code = await create(projectDir);
    expect(code).toBe(0);
  }, 120_000);

  afterAll(() => {
    rmSync(workDir, { recursive: true, force: true });
  });

  it("AI Agent Context 一式が生成される", () => {
    for (const rel of [
      ".agentcms/architecture.md",
      ".agentcms/commands.md",
      ".agentcms/rules/boundaries.md",
      ".agentcms/context/map.md",
      ".agentcms/registry/content-types.json",
      "CLAUDE.md",
      "AGENTS.md",
    ]) {
      expect(existsSync(join(projectDir, rel)), `${rel} が無い`).toBe(true);
    }
    const claude = readFileSync(join(projectDir, "CLAUDE.md"), "utf8");
    expect(claude).toContain(".agentcms");
    expect(claude).not.toContain("packages/cli");
  });

  it("project metadata に kit version が記録される", () => {
    const meta = JSON.parse(
      readFileSync(join(projectDir, ".agentcms/project.json"), "utf8"),
    ) as Record<string, unknown>;
    expect(meta["kit_version"]).toBe(kitVersion());
    expect(meta["template_schema_version"]).toBe(TEMPLATE_SCHEMA_VERSION);
    expect(meta["agent_context_version"]).toBe(AGENT_CONTEXT_VERSION);
    expect(String(meta["compose_project"])).toMatch(/^agentcms-test-site-/);
  });

  it("credential が生成され、共有 default ではない", () => {
    const cred = JSON.parse(
      readFileSync(
        join(projectDir, ".agentcms/local/admin-credentials.json"),
        "utf8",
      ),
    ) as { password: string };
    expect(cred.password).not.toBe("password");
    expect(cred.password).toHaveLength(24);
    const env = readFileSync(join(projectDir, ".env"), "utf8");
    expect(readEnvValue(env, "ADMIN_PASSWORD")).toBe(cred.password);
    expect(readEnvValue(env, "COMPOSE_PROJECT_NAME")).toMatch(
      /^agentcms-test-site-/,
    );
  });

  it("git 初期化済みで credential は Git 管理対象外", () => {
    expect(existsSync(join(projectDir, ".git"))).toBe(true);
    const tracked = execFileSync("git", ["ls-files"], {
      cwd: projectDir,
      encoding: "utf8",
    });
    expect(tracked).not.toContain(".agentcms/local");
    expect(tracked).not.toContain(".env\n");
    expect(tracked).toContain("artisan");
  });

  it("HOST_UID / HOST_GID が .env に記録される（POSIX — WSL2 permission 対策）", () => {
    const env = readFileSync(join(projectDir, ".env"), "utf8");
    if (
      typeof process.getuid === "function" &&
      typeof process.getgid === "function"
    ) {
      expect(readEnvValue(env, "HOST_UID")).toBe(String(process.getuid()));
      expect(readEnvValue(env, "HOST_GID")).toBe(String(process.getgid()));
    }
  });

  it("pnpm pin と build 承認が生成 project に含まれる", () => {
    const pkg = JSON.parse(
      readFileSync(join(projectDir, "package.json"), "utf8"),
    ) as { packageManager?: string };
    expect(pkg.packageManager).toMatch(/^pnpm@10\./);
    expect(
      readFileSync(join(projectDir, "pnpm-workspace.yaml"), "utf8"),
    ).toContain("onlyBuiltDependencies:");
  });

  it("作者環境の生成物・ローカル状態が混入しない", () => {
    for (const rel of [
      "vendor",
      "node_modules",
      ".lighthouseci",
      "public/build/manifest.json",
      ".agentcms/cache",
    ]) {
      expect(existsSync(join(projectDir, rel)), `${rel} が混入`).toBe(false);
    }
  });

  it("既存ディレクトリへは上書きしない", async () => {
    const code = await create(projectDir);
    expect(code).toBe(1);
  });

  it("2 つ目の project は identity / credential が異なる", async () => {
    const second = join(workDir, "second-site");
    const code = await create(second);
    expect(code).toBe(0);
    const env1 = readFileSync(join(projectDir, ".env"), "utf8");
    const env2 = readFileSync(join(second, ".env"), "utf8");
    expect(readEnvValue(env2, "COMPOSE_PROJECT_NAME")).not.toBe(
      readEnvValue(env1, "COMPOSE_PROJECT_NAME"),
    );
    expect(readEnvValue(env2, "ADMIN_PASSWORD")).not.toBe(
      readEnvValue(env1, "ADMIN_PASSWORD"),
    );
  }, 120_000);
});

/* ---------------------------------------------------------------- *
 * dev のロジック（Baseline A: login 500 / URL 案内の一般化）
 * ---------------------------------------------------------------- */
describe("dev logic", () => {
  it("Vite manifest が無ければ frontend build が必要", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-build-"));
    try {
      expect(needsFrontendBuild(dir)).toBe(true);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("route:list --json から login パスを取り出す", () => {
    const json = JSON.stringify([
      { method: "POST", uri: "login", name: "login.store" },
      { method: "GET|HEAD", uri: "login", name: "login" },
    ]);
    expect(parseLoginPath(json)).toBe("login");
    expect(parseLoginPath("broken")).toBe(null);
  });
});

/* ---------------------------------------------------------------- *
 * secret scan — git 不在でも実行でき、誤誘導しない
 * （Baseline A: 「git 不在」を「秘密の漏えい」と誤認させない。
 *   Phase 8.5-C.1 / O-12 で git 依存そのものを撤廃したため、
 *   期待する結果は「git init の案内」から「Git 無しで実行完了」へ変わった）
 * ---------------------------------------------------------------- */
describe("secret scan (git-less)", () => {
  it("Git リポジトリでなくても実行でき、鍵ローテーションへ誤誘導しない", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-scan-"));
    try {
      const r = spawnSync(
        process.execPath,
        [join(repoRoot, "packages/cli/dist/secret-scan.js")],
        {
          cwd: dir,
          encoding: "utf8",
          env: { ...process.env, GIT_DIR: join(dir, "nowhere") },
        },
      );
      expect(r.status).toBe(0);
      expect(r.stdout).toContain("Git 不使用");
      // 秘密が検出されたわけではないので、ローテーションを促さない
      expect(r.stderr).not.toContain("ローテーション");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

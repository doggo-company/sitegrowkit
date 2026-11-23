import {
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import { buildProjectContext } from "../src/agent-context.js";
import { filesystemLocationCheck } from "../src/commands/doctor.js";
import {
  composeUserArgs,
  containerWriteProbeScript,
  CONTAINER_WRITABLE_PATHS,
  hostUserIds,
  isDrvfsPath,
} from "../src/runtime-user.js";
import {
  findPlaceholders,
  PLACEHOLDER_PATTERNS,
} from "../src/scan-patterns.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");
const read = (rel: string): string => readFileSync(join(repoRoot, rel), "utf8");

/* ---------------------------------------------------------------- *
 * Windows RC1 Blocker 1 — pnpm 決定論
 * ---------------------------------------------------------------- */
describe("pnpm determinism (RC1 Blocker 1)", () => {
  it("template は pnpm 10 系を packageManager で pin している", () => {
    const pkg = JSON.parse(read("template/package.json")) as {
      packageManager?: string;
    };
    expect(pkg.packageManager).toMatch(/^pnpm@10\.\d+\.\d+$/);
  });

  it("build 承認は onlyBuiltDependencies で確定している（手動 approve-builds 不要）", () => {
    const ws = read("template/pnpm-workspace.yaml");
    expect(ws).toContain("onlyBuiltDependencies:");
    expect(ws).toContain("- unrs-resolver");
    expect(ws).toContain("- vue-demi");
  });

  it("pnpm-workspace.yaml に未解決 placeholder が無い", () => {
    expect(findPlaceholders(read("template/pnpm-workspace.yaml"))).toEqual([]);
  });

  it("placeholder scan は『set this to true or false』系を検出する", () => {
    expect(
      findPlaceholders("unrs-resolver: set this to true or false"),
    ).not.toEqual([]);
    expect(findPlaceholders("value: REPLACE_ME")).not.toEqual([]);
    expect(findPlaceholders("普通の設定: true")).toEqual([]);
    expect(PLACEHOLDER_PATTERNS.length).toBeGreaterThanOrEqual(3);
  });

  it("dev は ERR_PNPM_IGNORED_BUILDS を network error と誤診しない", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    expect(src).toContain("ERR_PNPM_IGNORED_BUILDS");
    // 実際の対処（build 承認の追加先）を案内していること。
    // Phase 8.5-A で pnpm は node コンテナ実行になり、host 側の
    // `corepack enable pnpm` 案内は該当しなくなったため文言から外している
    // （version pin は node イメージの corepack が package.json から解決する）。
    expect(src).toContain("onlyBuiltDependencies");
  });
});

/* ---------------------------------------------------------------- *
 * Windows RC1 Blocker 2 — runtime user mapping / 書き込み検査
 * ---------------------------------------------------------------- */
describe("runtime user mapping (RC1 Blocker 2)", () => {
  it("Dockerfile が HOST_UID/HOST_GID で PHP-FPM 実行ユーザーを合わせる", () => {
    const df = read("template/docker/php/Dockerfile");
    expect(df).toContain("ARG HOST_UID");
    expect(df).toContain("ARG HOST_GID");
    expect(df).toContain("zz-agentcms-user.conf");
    // 禁止された回避策を使っていない
    expect(df).not.toMatch(/chmod\s+-R\s+777|a\+rwX/);
  });

  it("docker-compose が build args として HOST_UID/HOST_GID を渡す", () => {
    const compose = read("template/docker-compose.yml");
    expect(compose).toContain("HOST_UID");
    expect(compose).toContain("HOST_GID");
    expect(compose).not.toMatch(/^name: agentcms$/m);
  });

  it("composeUserArgs は .env の HOST_UID/HOST_GID を --user へ写す", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-user-"));
    try {
      writeFileSync(join(dir, ".env"), "HOST_UID=1234\nHOST_GID=5678\n");
      expect(composeUserArgs(dir)).toEqual(["--user", "1234:5678"]);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("composeUserArgs は .env が無ければ現在の uid/gid へフォールバックする", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-user-"));
    try {
      const ids = hostUserIds();
      if (ids) {
        expect(composeUserArgs(dir)).toEqual([
          "--user",
          `${ids.uid}:${ids.gid}`,
        ]);
      } else {
        expect(composeUserArgs(dir)).toEqual([]);
      }
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("container 書き込み検査は要求された全パスを検査する", () => {
    const script = containerWriteProbeScript();
    for (const p of [
      "storage",
      "storage/logs",
      "storage/framework",
      "storage/framework/cache",
      "storage/framework/sessions",
      "storage/framework/views",
      "storage/app",
      "bootstrap/cache",
    ]) {
      expect(CONTAINER_WRITABLE_PATHS).toContain(p);
      expect(script).toContain(`'${p}/.agentcms-probe'`);
    }
  });

  it("dev / verify / doctor が container 実行を host user へ mapping している", () => {
    for (const rel of [
      "packages/cli/src/commands/dev.ts",
      "packages/cli/src/commands/verify.ts",
      "packages/cli/src/commands/doctor.ts",
    ]) {
      expect(read(rel), `${rel} が composeUserArgs を使用していない`).toContain(
        "composeUserArgs",
      );
    }
  });
});

/* ---------------------------------------------------------------- *
 * Windows RC1 Blocker 3 — host PHP NOT REQUIRED
 * ---------------------------------------------------------------- */
describe("host PHP not required (RC1 Blocker 3)", () => {
  it("vite build は host php 不在時に wayfinder plugin をスキップする", () => {
    const vite = read("template/vite.config.ts");
    expect(vite).toContain("AGENTCMS_SKIP_WAYFINDER");
    expect(vite).toContain("hostPhpAvailable");
    expect(vite).toMatch(/useWayfinderPlugin\s*\?/);
  });

  it("dev が wayfinder 生成を container 内で実行する", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    expect(src).toContain("wayfinder:generate");
    expect(src).toContain("AGENTCMS_SKIP_WAYFINDER");
  });

  it("verify が wayfinder artifacts を runtime 側で保証し build を host php 非依存にする", () => {
    const src = read("packages/cli/src/commands/verify.ts");
    expect(src).toContain("wayfinder:generate");
    expect(src).toContain("AGENTCMS_SKIP_WAYFINDER");
  });
});

/* ---------------------------------------------------------------- *
 * /mnt/c (DrvFS) 検出
 * ---------------------------------------------------------------- */
describe("DrvFS path classification", () => {
  it("Windows mounted filesystem を判定する", () => {
    expect(isDrvfsPath("/mnt/c/Users/taro/projects/my-site")).toBe(true);
    expect(isDrvfsPath("/mnt/d/work")).toBe(true);
    expect(isDrvfsPath("/home/taro/projects/my-site")).toBe(false);
    expect(isDrvfsPath("/Users/taro/www/my-site")).toBe(false);
    expect(isDrvfsPath("/mnt/wsl/something")).toBe(false); // 1 文字ドライブのみ
  });

  it("doctor / create が DrvFS 警告を実装している", () => {
    expect(read("packages/cli/src/commands/doctor.ts")).toContain(
      "isDrvfsPath",
    );
    expect(read("packages/cli/src/commands/create.ts")).toContain(
      "isDrvfsPath",
    );
  });

  it("doctor は project 外（cwd）でも DrvFS を警告し、Linux filesystem では警告しない", () => {
    // WSL2 想定
    expect(filesystemLocationCheck("/mnt/c/Users/taro", true).status).toBe(
      "warn",
    );
    expect(filesystemLocationCheck("/mnt/d/work", true).status).toBe("warn");
    expect(filesystemLocationCheck("/home/taro/projects", true).status).toBe(
      "pass",
    );
    expect(filesystemLocationCheck("/mnt/wsl/docker", true).status).toBe(
      "pass",
    );
    // 非 WSL（macOS 等）では常に警告しない
    expect(filesystemLocationCheck("/mnt/c/anything", false).status).toBe(
      "pass",
    );
  });
});

/* ---------------------------------------------------------------- *
 * Registry path casing（Windows RC2 acceptance feedback）
 * ---------------------------------------------------------------- */
describe("registry path casing", () => {
  it("content-types.json の module/schema path が実在し、case-sensitive でも解決できる", () => {
    const registry = JSON.parse(
      read(".agentcms/registry/content-types.json"),
    ) as { types: { id: string; module: string; schema: string }[] };
    expect(registry.types.length).toBeGreaterThanOrEqual(5);

    // 実ディレクトリ一覧（readdir は実際の case を返す）と厳密比較する —
    // macOS の case-insensitive FS 上でも casing 不一致を検出できる
    const moduleDirs = new Set(readdirSync(join(repoRoot, "template/modules")));
    const schemaFiles = new Set(
      readdirSync(join(repoRoot, "template/content/schemas")),
    );
    for (const t of registry.types) {
      const moduleName = t.module.split("/").pop() ?? "";
      expect(
        moduleDirs.has(moduleName),
        `module "${t.module}" の casing が実ディレクトリと不一致（実在: ${[...moduleDirs].join(", ")}）`,
      ).toBe(true);
      const schemaName = t.schema.split("/").pop() ?? "";
      expect(
        schemaFiles.has(schemaName),
        `schema "${t.schema}" が実ファイルと不一致`,
      ).toBe(true);
    }
  });

  it("project 向けに生成された registry も正しい casing を保持する", () => {
    const files = buildProjectContext(join(repoRoot, ".agentcms"));
    const reg = files.find((f) => f.rel === "registry/content-types.json");
    expect(reg).toBeDefined();
    const registry = JSON.parse(reg!.content) as {
      types: { module: string }[];
    };
    const moduleDirs = new Set(readdirSync(join(repoRoot, "template/modules")));
    for (const t of registry.types) {
      // project audience では `modules/<Name>` 形式（template/ prefix 除去済み）
      expect(t.module).toMatch(/^modules\//);
      expect(moduleDirs.has(t.module.split("/").pop() ?? "")).toBe(true);
    }
  });
});

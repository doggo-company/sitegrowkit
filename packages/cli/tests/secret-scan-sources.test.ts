import { execFileSync } from "node:child_process";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import {
  EXCLUDED_DIRS,
  collectScanSources,
  isExpectedLocalFile,
} from "../src/scan-sources.js";

const here = dirname(fileURLToPath(import.meta.url));
const scanScript = resolve(here, "../dist/secret-scan.js");

/* ---------------------------------------------------------------- *
 * Phase 8.5-C.1 / O-12 — secret scan を Git 非依存にする
 * ---------------------------------------------------------------- */

/** git 無しを模した PATH で secret-scan を実行する */
function runScan(cwd: string, withGit: boolean): { code: number; out: string } {
  try {
    const out = execFileSync(process.execPath, [scanScript], {
      cwd,
      encoding: "utf8",
      env: {
        ...process.env,
        PATH: withGit ? process.env.PATH : "/nonexistent",
      },
    });
    return { code: 0, out };
  } catch (error) {
    const e = error as { status?: number; stdout?: string; stderr?: string };
    return { code: e.status ?? 1, out: `${e.stdout ?? ""}${e.stderr ?? ""}` };
  }
}

function makeProject(): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-scan-"));
  writeFileSync(join(dir, "artisan"), "#!/usr/bin/env php\n");
  writeFileSync(join(dir, "composer.json"), "{}\n");
  writeFileSync(join(dir, ".env"), "APP_KEY=base64:aaaaaaaaaaaaaaaaaaaaaaaa\n");
  writeFileSync(join(dir, ".env.example"), "APP_KEY=\n");
  // 実プロジェクト同様、ローカル秘密は Git 管理外にする
  writeFileSync(join(dir, ".gitignore"), ".env\n");
  mkdirSync(join(dir, "app"), { recursive: true });
  writeFileSync(join(dir, "app/Clean.php"), "<?php\n// no secrets here\n");
  return dir;
}

describe("走査対象の列挙（git mode / filesystem mode）", () => {
  let project: string;

  beforeAll(() => {
    project = makeProject();
  });

  afterAll(() => {
    rmSync(project, { recursive: true, force: true });
  });

  it("git が使えなければ filesystem を走査する", () => {
    const saved = process.env.PATH;
    process.env.PATH = "/nonexistent";
    try {
      const sources = collectScanSources(project);
      expect(sources.mode).toBe("filesystem");
      expect(sources.files).toContain("app/Clean.php");
    } finally {
      process.env.PATH = saved;
    }
  });

  it("存在して当然のローカルファイルは走査対象にしない（git mode と同じ範囲）", () => {
    expect(isExpectedLocalFile(".env")).toBe(true);
    expect(isExpectedLocalFile(".env.production")).toBe(true);
    expect(isExpectedLocalFile(".env.example")).toBe(false);
    expect(isExpectedLocalFile("auth.json")).toBe(true);
    expect(isExpectedLocalFile("app/Clean.php")).toBe(false);
  });

  it("依存・生成物・ローカル秘密のディレクトリを明示的に除外する", () => {
    for (const required of [
      "node_modules",
      "vendor",
      "storage",
      "public/build",
      ".agentcms/local",
      ".agentcms/cache",
      ".git",
    ]) {
      expect(EXCLUDED_DIRS).toContain(required);
    }
  });

  it("除外ディレクトリの中身は走査しない", () => {
    const dir = makeProject();
    try {
      mkdirSync(join(dir, "vendor/some/pkg"), { recursive: true });
      writeFileSync(
        join(dir, "vendor/some/pkg/fixture.php"),
        "<?php $k = 'AKIAIOSFODNN7EXAMPLE';\n",
      );
      const saved = process.env.PATH;
      process.env.PATH = "/nonexistent";
      try {
        const files = collectScanSources(dir).files;
        expect(files.some((f) => f.startsWith("vendor/"))).toBe(false);
      } finally {
        process.env.PATH = saved;
      }
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

describe("secret scan は Git が無くても実行できる", () => {
  it("Git 不在でも PASS する（従来は FAIL していた）", () => {
    const dir = makeProject();
    try {
      const result = runScan(dir, false);
      expect(result.code).toBe(0);
      expect(result.out).toContain("Git 不使用");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("Git 不在でも秘密情報を検出する（検出力を落とさない）", () => {
    const dir = makeProject();
    try {
      writeFileSync(
        join(dir, "app/Leak.php"),
        "<?php\n$key = 'AKIAIOSFODNN7EXAMPLE';\n",
      );
      const result = runScan(dir, false);
      expect(result.code).toBe(1);
      expect(result.out).toContain("AWS Access Key");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("git 未追加のファイルは filesystem mode でのみ検出できる（coverage equal or better）", () => {
    const dir = makeProject();
    try {
      execFileSync("git", ["init", "-q"], { cwd: dir });
      execFileSync("git", ["add", "-A"], { cwd: dir });
      // git add のあとに作られた（= untracked な）ファイルへ秘密を置く
      writeFileSync(
        join(dir, "app/Untracked.php"),
        "<?php\n$key = 'AKIAIOSFODNN7EXAMPLE';\n",
      );

      // git mode: tracked しか見ないため検出できない
      expect(runScan(dir, true).code).toBe(0);
      // filesystem mode: 実在するファイルを見るので検出する
      const withoutGit = runScan(dir, false);
      expect(withoutGit.code).toBe(1);
      expect(withoutGit.out).toContain("AWS Access Key");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("プロジェクト内の秘密ファイル（.pem）は Git 無しでも違反になる", () => {
    const dir = makeProject();
    try {
      writeFileSync(join(dir, "deploy.pem"), "-----BEGIN PRIVATE KEY-----\n");
      const result = runScan(dir, false);
      expect(result.code).toBe(1);
      expect(result.out).toContain("deploy.pem");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

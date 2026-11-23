import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");
const read = (rel: string): string => readFileSync(join(repoRoot, rel), "utf8");

/* ---------------------------------------------------------------- *
 * Phase 8 RC2 finding — Composer archive extraction instability.
 *
 * macOS Docker Desktop の bind mount 上で、unzip（busybox applet）による
 * 子プロセス展開が "Install of <pkg> failed" を高頻度で起こす。
 * 実測: busybox unzip 1/5 失敗 / COMPOSER_MAX_PARALLEL_PROCESSES=1 は 5/5 失敗 /
 * PHP zip extension（in-process）は 11/11 成功 / container ローカル FS では
 * unzip でも 3/3 成功（= bind mount 起因）。
 * 採用 fix: template イメージから unzip/7z を除去し ZipArchive に固定する。
 * ---------------------------------------------------------------- */

const DOCKERFILE = "template/docker/php/Dockerfile";

describe("composer extraction stability (RC2 finding)", () => {
  it("template イメージは unzip/7z を除去して ZipArchive 展開に固定している", () => {
    const df = read(DOCKERFILE);
    expect(df).toContain("rm -f /usr/bin/unzip");
    expect(df).toContain("/usr/bin/7z");
  });

  it("除去の理由（bind mount race / 実測データ）が Dockerfile に記録されている", () => {
    const df = read(DOCKERFILE);
    expect(df).toContain("PHP zip extension");
    expect(df).toContain("bind mount");
  });

  it("ZipArchive fallback の前提となる zip PHP extension をインストールしている", () => {
    const df = read(DOCKERFILE);
    expect(df).toMatch(/install-php-extensions[^\n]*\bzip\b/);
  });

  it("fix は container image 内で完結し、dev は COMPOSER_* 環境変数を注入しない", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    // 検証済みで不採用となった serial 化 env を利用者環境へ持ち込まない
    expect(src).not.toContain("COMPOSER_MAX_PARALLEL");
    // host（ユーザーの global composer 設定）を変更するコードが無いこと
    expect(src).not.toContain("COMPOSER_HOME");
    expect(src).not.toMatch(/process\.env\.COMPOSER/);
  });

  it("defense in depth: partial vendor detection / self-healing は維持されている", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    expect(src).toContain("composerState(root)");
    expect(src).toContain("cleanIncompleteVendor(root)");
  });
});

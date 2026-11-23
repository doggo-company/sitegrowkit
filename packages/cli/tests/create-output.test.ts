import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { homedir, tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";

import { create } from "../src/commands/create.js";

const here = dirname(fileURLToPath(import.meta.url));
const templateSourceDir = resolve(here, "../../..", "template");

/**
 * F-3 regression: create の出力は利用者に必要な情報
 * （project created / destination / next steps / credential file 案内）に限定し、
 * CLI パッケージの internal install path（実 username を含み得る）を露出しない。
 */
describe("create output hygiene (F-3)", () => {
  let workDir: string;
  let projectDir: string;
  let output: string;

  beforeAll(async () => {
    workDir = mkdtempSync(join(tmpdir(), "agentcms-create-out-"));
    projectDir = join(workDir, "demo-site");

    const lines: string[] = [];
    const logSpy = vi
      .spyOn(console, "log")
      .mockImplementation((...args: unknown[]) => {
        lines.push(args.join(" "));
      });
    const errSpy = vi
      .spyOn(console, "error")
      .mockImplementation((...args: unknown[]) => {
        lines.push(args.join(" "));
      });
    const code = await create(projectDir);
    logSpy.mockRestore();
    errSpy.mockRestore();
    output = lines.join("\n");
    expect(code).toBe(0);
  }, 120_000);

  afterAll(() => {
    rmSync(workDir, { recursive: true, force: true });
  });

  it("internal / global template source の絶対パスを出力しない", () => {
    expect(output).not.toContain("テンプレート:");
    expect(output).not.toContain(templateSourceDir);
  });

  it("personal username を internal path 経由で露出しない", () => {
    // 出力に home 配下のパスが現れてよいのは、利用者が自分で指定した
    // project destination だけ（このテストでは tmpdir 配下なので home は現れない想定）
    for (const line of output.split("\n")) {
      if (line.includes(homedir())) {
        expect(line).toContain(projectDir);
      }
    }
  });

  it("project destination / next steps / credential 案内は維持する", () => {
    expect(output).toContain("プロジェクトを作成しました");
    expect(output).toContain(`cd ${projectDir}`);
    expect(output).toContain("agentcms dev");
    expect(output).toContain("admin-credentials.json");
    expect(output).toContain("値は表示しません");
  });

  it("Instrument Sans の OFL license が生成 project の public/ に含まれる(D-3)", () => {
    const ofl = readFileSync(
      join(projectDir, "public/licenses/instrument-sans/OFL.txt"),
      "utf8",
    );
    expect(
      ofl.startsWith("Copyright 2022 The Instrument Sans Project Authors"),
    ).toBe(true);
    expect(ofl).toContain("SIL OPEN FONT LICENSE Version 1.1");
  });

  it("credential 値を出力しない", () => {
    const cred = JSON.parse(
      readFileSync(
        join(projectDir, ".agentcms/local/admin-credentials.json"),
        "utf8",
      ),
    ) as { password: string };
    expect(cred.password.length).toBeGreaterThan(8);
    expect(output).not.toContain(cred.password);
  });
});

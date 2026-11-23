import { spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, rmSync, symlinkSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

import { storageLinkProbeScript } from "../src/storage-link.js";

/**
 * `public/storage` が通っているかの検査（WP 一周 6 周目 2026-09-03）。
 *
 * 張られていないと**アップロードした画像が全部 403** になる。
 * それでいて画面は何も言わない — `agentcms dev` が
 * `storage:link` の結果だけ見ていなかったためである。
 *
 * ここは script そのものを **本物の sh で走らせて**確かめる。
 * 文字列の中身を突き合わせるだけでは、`[ -e ]` の綴りを間違えても緑になる。
 */
describe("storage link probe", () => {
  const run = (cwd: string): { code: number; out: string } => {
    const r = spawnSync("sh", ["-c", storageLinkProbeScript()], {
      cwd,
      encoding: "utf8",
    });

    return { code: r.status ?? 1, out: (r.stdout ?? "") + (r.stderr ?? "") };
  };

  const project = (): string => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-link-"));

    mkdirSync(join(dir, "storage/app/public"), { recursive: true });
    mkdirSync(join(dir, "public"), { recursive: true });

    return dir;
  };

  it("link が張られていれば通る", () => {
    const dir = project();

    try {
      symlinkSync(join(dir, "storage/app/public"), join(dir, "public/storage"));

      expect(run(dir).code).toBe(0);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("link が無ければ落ちて、理由を言う", () => {
    const dir = project();

    try {
      const result = run(dir);

      expect(result.code).not.toBe(0);
      expect(result.out).toContain("STORAGE LINK BROKEN");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  /**
   * 🔴 **「在る」だけでは足りない。**
   * 別の場所を指した symlink は、`[ -e ]` では通ってしまう。
   */
  it("別の場所を指した link は落とす", () => {
    const dir = project();

    try {
      mkdirSync(join(dir, "yoso"), { recursive: true });
      symlinkSync(join(dir, "yoso"), join(dir, "public/storage"));

      expect(run(dir).code).not.toBe(0);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  /** 印を残さない（毎回のはずが、増えていくのは困る） */
  it("検査の印を残さない", () => {
    const dir = project();

    try {
      symlinkSync(join(dir, "storage/app/public"), join(dir, "public/storage"));

      run(dir);

      const listing = spawnSync("ls", ["-a", join(dir, "storage/app/public")], {
        encoding: "utf8",
      });

      expect(listing.stdout).not.toContain(".agentcms-link-probe");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

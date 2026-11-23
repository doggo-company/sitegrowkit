import { execFileSync } from "node:child_process";
import { existsSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import { create } from "../src/commands/create.js";
import { BUNDLED_SAMPLES_PATH } from "../src/importer/bundled-samples.js";

/**
 * **利用者の手元には、空のディレクトリは届かない。**
 *
 * 🔴 `git archive` も `npm pack` も **空ディレクトリを運ばない**。
 * 開発機の `template/.agentcms/manifest/` は過去の実行の名残で在るので
 * copy で足りているように見えるが、**配布物にはその入れ物ごと無い** —
 * 利用者の最初の 1 コマンドが `ENOENT` で落ちる。
 *
 * ▷ **実際に起きた**（2026-08-28・v0.9.3 の packaging）。単体試験は
 *   1046 本すべて緑で、**packaging の smoke test まで来て初めて落ちた**。
 *
 * ▷ **なぜ単体試験が見逃したか。** 試験は `locateTemplate()` 経由で
 *   **開発機の `template/` を読む**ので、入れ物が在る世界しか通らない。
 *   **試験は、自分で作った世界しか通らない。**
 *
 * ## だから、利用者の template を作ってから渡す
 *
 * `git archive` を通す — **tracked なものだけが残る**ので、
 * これが利用者の手元とほぼ同じ形になる。**共有の `template/` は触らない**
 * （試験ファイルは並列に走るので、退かすと他の試験を巻き込む）。
 */
const repoRoot = resolve(import.meta.dirname, "../../..");

describe("配布物には空ディレクトリが無い（利用者と同じ template で create する）", () => {
  let sandbox: string;
  let shippedTemplate: string;

  beforeAll(() => {
    sandbox = mkdtempSync(join(tmpdir(), "agentcms-packaged-world-"));
    execFileSync(
      "bash",
      ["-c", `git archive HEAD template | tar -x -C ${JSON.stringify(sandbox)}`],
      { cwd: repoRoot },
    );
    shippedTemplate = join(sandbox, "template");
  });

  afterAll(() => {
    rmSync(sandbox, { recursive: true, force: true });
  });

  it("配布される template は、その入れ物を持っていない（前提の確認）", () => {
    /*
     * **これが偽になったら、この試験は何も測っていない。**
     * 入れ物が配られるようになったなら、下の試験は入れ物が在る世界を
     * 見ているだけで、欠陥を通す。
     */
    expect(existsSync(join(shippedTemplate, "artisan"))).toBe(true);
    expect(existsSync(join(shippedTemplate, ".agentcms", "manifest"))).toBe(
      false,
    );
  });

  it("🔴 入れ物が無くても、同梱の見本の一覧を書ける", async () => {
    const project = join(sandbox, "buyer-site");

    await expect(create(project, undefined, shippedTemplate)).resolves.toBe(0);
    expect(existsSync(join(project, BUNDLED_SAMPLES_PATH))).toBe(true);
  }, 120_000);
});

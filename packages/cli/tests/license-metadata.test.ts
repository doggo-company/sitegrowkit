import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");

/* ---------------------------------------------------------------- *
 * first-party パッケージのライセンス表記（2026-11-23 GPL 公開）
 *
 * 本キットは GPL-3.0-or-later で公開する。first-party のマニフェストが
 * LICENSE と食い違う表記（proprietary・MIT など）を残していてはならない。
 * third-party（vendor/ node_modules/）は対象外。
 * ---------------------------------------------------------------- */

const FIRST_PARTY_LICENSE = "GPL-3.0-or-later";

const FIRST_PARTY_MANIFESTS = [
  "template/composer.json",
  "template/core/composer.json",
];

/** 配布物は git tracked ファイルから生成されるため、tracked のみを対象にする */
function trackedManifests(): string[] {
  return execFileSync("git", ["ls-files", "template"], {
    cwd: repoRoot,
    encoding: "utf8",
  })
    .split("\n")
    .filter((p) => /(^|\/)(composer|package)\.json$/.test(p));
}

describe("first-party package license (GPL-3.0-or-later)", () => {
  it.each(FIRST_PARTY_MANIFESTS)(
    "%s は license = GPL-3.0-or-later を宣言する",
    (rel) => {
      const manifest = JSON.parse(
        readFileSync(join(repoRoot, rel), "utf8"),
      ) as { license?: string };
      expect(manifest.license).toBe(FIRST_PARTY_LICENSE);
    },
  );

  it("composer.lock の agentcms/* パッケージが GPL-3.0-or-later である", () => {
    const lock = JSON.parse(
      readFileSync(join(repoRoot, "template/composer.lock"), "utf8"),
    ) as { packages: { name: string; license?: string[] }[] };
    const firstParty = lock.packages.filter((p) =>
      p.name.startsWith("agentcms/"),
    );
    expect(firstParty.length).toBeGreaterThan(0);
    for (const pkg of firstParty) {
      expect(pkg.license, pkg.name).toEqual([FIRST_PARTY_LICENSE]);
    }
  });

  it("template 配下の first-party マニフェストが LICENSE と違う表記をしていない", () => {
    const manifests = trackedManifests();
    expect(manifests.length).toBeGreaterThan(0);
    for (const rel of manifests) {
      const manifest = JSON.parse(
        readFileSync(join(repoRoot, rel), "utf8"),
      ) as { license?: string };
      if (manifest.license !== undefined) {
        expect(manifest.license, rel).toBe(FIRST_PARTY_LICENSE);
      }
    }
  });
});

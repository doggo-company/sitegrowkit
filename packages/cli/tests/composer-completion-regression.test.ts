import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

import { phpDepsCheck } from "../src/commands/doctor.js";
import {
  cleanIncompleteVendor,
  composerState,
  migrateFailureDiagnosis,
} from "../src/php-deps.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");
const read = (rel: string): string => readFileSync(join(repoRoot, rel), "utf8");

/* ---------------------------------------------------------------- *
 * Phase 8 Stage A RC1 Blocker —
 * partial Composer state was incorrectly treated as complete.
 *
 * composer install の途中失敗が partial vendor/ を残し、
 * dev/doctor が「vendor/ 存在」だけで complete と誤判定 →
 * composer install を恒久 skip → vendor/autoload.php 欠如 →
 * migrate 失敗を MySQL と誤診（undocumented recovery が必要だった）。
 * ---------------------------------------------------------------- */

const cleanups: string[] = [];
afterEach(() => {
  while (cleanups.length) {
    rmSync(cleanups.pop()!, { recursive: true, force: true });
  }
});

/** artisan + composer.json を持つ fake CMS プロジェクトを tmp に作る */
function makeProject(): string {
  const root = mkdtempSync(join(tmpdir(), "agentcms-vendor-test-"));
  cleanups.push(root);
  writeFileSync(join(root, "artisan"), "<?php // fake artisan\n");
  writeFileSync(join(root, "composer.json"), "{}\n");
  return root;
}

function addPartialVendor(root: string): void {
  mkdirSync(join(root, "vendor", "composer"), { recursive: true });
  writeFileSync(join(root, "vendor", "composer", "installed.json"), "{}\n");
  mkdirSync(join(root, "vendor", "hamcrest"), { recursive: true });
}

function addCompleteVendor(root: string): void {
  addPartialVendor(root);
  writeFileSync(join(root, "vendor", "autoload.php"), "<?php // autoload\n");
}

describe("composer completion detection (Fix 1)", () => {
  it("Case 1: vendor/ が無ければ absent（dev は composer install を実行する）", () => {
    const root = makeProject();
    expect(composerState(root)).toBe("absent");
  });

  it("Case 2: vendor/autoload.php があれば complete（composer install を skip してよい）", () => {
    const root = makeProject();
    addCompleteVendor(root);
    expect(composerState(root)).toBe("complete");
  });

  it("Case 3: vendor/ はあるが autoload.php が無ければ incomplete（complete と扱わない）", () => {
    const root = makeProject();
    addPartialVendor(root);
    expect(composerState(root)).toBe("incomplete");
  });

  it("dev は vendor/ の存在ではなく composerState で composer install を判断する", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    expect(src).toContain("composerState(root)");
    expect(src).toContain("cleanIncompleteVendor(root)");
    // 旧・欠陥判定（vendor ディレクトリ存在 gate）が残っていないこと
    expect(src).not.toMatch(/existsSync\(join\(root, "vendor"\)\)/);
  });

  it("dev は composer install 成功後にも completion condition を再検証する", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    expect(src).toContain(
      "composer install は終了しましたが vendor/autoload.php が生成されていません",
    );
  });
});

describe("self-healing vendor cleanup (Fix 2)", () => {
  it("Case 3: incomplete vendor/ を検出して安全に削除できる（self-heal 前段）", () => {
    const root = makeProject();
    addPartialVendor(root);
    const r = cleanIncompleteVendor(root);
    expect(r.removed).toBe(true);
    expect(existsSync(join(root, "vendor"))).toBe(false);
    expect(composerState(root)).toBe("absent"); // → dev が composer install を再実行する状態
  });

  it("complete な vendor/ は削除しない", () => {
    const root = makeProject();
    addCompleteVendor(root);
    const r = cleanIncompleteVendor(root);
    expect(r.removed).toBe(false);
    expect(existsSync(join(root, "vendor", "autoload.php"))).toBe(true);
  });

  it("safety: 相対パスの root では削除しない", () => {
    const root = makeProject();
    addPartialVendor(root);
    const rel = relative(process.cwd(), root);
    const r = cleanIncompleteVendor(rel);
    expect(r.removed).toBe(false);
    expect(existsSync(join(root, "vendor"))).toBe(true);
  });

  it("safety: CMS プロジェクト marker（artisan + composer.json）が無い場所では削除しない", () => {
    const root = mkdtempSync(join(tmpdir(), "agentcms-not-a-project-"));
    cleanups.push(root);
    mkdirSync(join(root, "vendor"), { recursive: true });
    const r = cleanIncompleteVendor(root);
    expect(r.removed).toBe(false);
    expect(existsSync(join(root, "vendor"))).toBe(true);
  });

  it("safety: vendor/ が symlink の場合はリンク先を巻き込まない（削除しない）", () => {
    const root = makeProject();
    const target = mkdtempSync(join(tmpdir(), "agentcms-symlink-target-"));
    cleanups.push(target);
    writeFileSync(join(target, "keep.txt"), "must survive\n");
    symlinkSync(target, join(root, "vendor"));
    const r = cleanIncompleteVendor(root);
    expect(r.removed).toBe(false);
    expect(existsSync(join(target, "keep.txt"))).toBe(true);
    expect(existsSync(join(root, "vendor"))).toBe(true);
  });

  it("safety: 削除対象は root 直下の vendor/ のみ（隣接ディレクトリに触れない）", () => {
    const root = makeProject();
    addPartialVendor(root);
    mkdirSync(join(root, "vendors-backup"), { recursive: true });
    writeFileSync(join(root, "vendors-backup", "keep.txt"), "must survive\n");
    const r = cleanIncompleteVendor(root);
    expect(r.removed).toBe(true);
    expect(existsSync(join(root, "vendors-backup", "keep.txt"))).toBe(true);
  });
});

describe("doctor PHP deps check (Fix 3)", () => {
  it("Case 5: partial vendor を false PASS しない（WHAT/WHY/FIX 付きで警告する）", () => {
    const root = makeProject();
    addPartialVendor(root);
    const r = phpDepsCheck(root);
    expect(r.status).not.toBe("pass");
    expect(r.status).toBe("warn");
    expect(r.failure?.what).toContain("不完全");
    expect(r.failure?.what).toContain("vendor/autoload.php");
    expect(r.failure?.why).toContain("途中で失敗");
    expect(r.failure?.fix).toContain("agentcms dev");
  });

  it("complete vendor は PASS", () => {
    const root = makeProject();
    addCompleteVendor(root);
    expect(phpDepsCheck(root).status).toBe("pass");
  });

  it("vendor 無しは従来どおり警告（dev が自動インストール）", () => {
    const root = makeProject();
    const r = phpDepsCheck(root);
    expect(r.status).toBe("warn");
    expect(r.failure?.fix).toContain("agentcms dev");
  });
});

describe("diagnosis ordering (Fix 4)", () => {
  it("Case 6: autoload 欠如時の migrate 失敗を MySQL failure と誤診しない", () => {
    const root = makeProject();
    addPartialVendor(root);
    const d = migrateFailureDiagnosis(root);
    expect(d.why).toContain("vendor/autoload.php");
    expect(d.why).toContain("MySQL の問題ではありません");
    expect(d.what).not.toContain("MySQL");
    expect(d.why).not.toContain("MySQL コンテナが未初期化");
    expect(d.fix).toContain("agentcms dev");
  });

  it("PHP 依存が complete のときのみ database 診断を出す", () => {
    const root = makeProject();
    addCompleteVendor(root);
    const d = migrateFailureDiagnosis(root);
    expect(d.why).toContain("MySQL");
    expect(d.fix).toContain("docker compose logs mysql");
  });

  it("dev は artisan 系（APP_KEY/migrate）の前に bootstrap artifacts を検証し、migrate 失敗診断を diagnosis helper に委譲する", () => {
    const src = read("packages/cli/src/commands/dev.ts");
    expect(src).toContain("migrateFailureDiagnosis(root)");
    // 旧・固定 MySQL 診断が dev に直書きされていないこと
    expect(src).not.toContain(
      "MySQL コンテナが未初期化か、接続設定が .env と一致していません",
    );
  });
});

describe("customer docs (Fix 5)", () => {
  it("TROUBLESHOOTING は autoload 欠落の症状と自動回復を説明している", () => {
    const doc = read("docs/TROUBLESHOOTING.md");
    expect(doc).toContain("vendor/autoload.php");
    expect(doc).toContain("自動で検出");
  });

  it("Golden Path は手動 `rm -rf vendor` を要求しない", () => {
    const doc = read("docs/TROUBLESHOOTING.md");
    expect(doc).not.toContain("rm -rf vendor");
  });
});

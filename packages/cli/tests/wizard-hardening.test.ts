import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { homedir, tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import { doctor } from "../src/commands/doctor.js";
import type { EngineEvent } from "../src/engine.js";
import { CANCEL_CLEANUP_ARGS, siteSettingArgs } from "../src/wizard/runner.js";
import { startWizardServer } from "../src/wizard/server.js";
import {
  readSetupState,
  setupStatePath,
  writeSetupState,
} from "../src/wizard/state.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");
const assetsDir = resolve(here, "../src/wizard/assets");

/* ---------------------------------------------------------------- *
 * Phase 8.5-C — Wizard Hardening
 * ---------------------------------------------------------------- */

describe("C1 / O-10 — サイト名の反映", () => {
  it("CLI / template の既定は Studio Sample のまま（Wizard だけが上書きする）", () => {
    const seeder = readFileSync(
      join(repoRoot, "template/database/seeders/DemoContentSeeder.php"),
      "utf8",
    );
    expect(seeder).toContain("'site_name' => 'Studio Sample'");
    // O-11 / C.2: 既存値は再 seed で上書きしない（key が無い / null のときだけ投入）。
    // 空文字を falsey として扱う実装（!$value）は禁止
    expect(seeder).toMatch(/array_key_exists\(\$key, \$current\)/);
    expect(seeder).toMatch(/\$current\[\$key\]\s*===\s*null/);
    expect(seeder).not.toMatch(/if\s*\(\s*!\s*\$value\s*\)/);
  });

  it("更新は管理画面と同じ経路（schema 検証 → SettingsStore）を通る", () => {
    const command = readFileSync(
      join(repoRoot, "template/app/Console/Commands/SetSiteSettingCommand.php"),
      "utf8",
    );
    // Phase 9.1B: 検証と保存は Application Service（UpdateSettings）へ集約された。
    // 管理画面・CLI・AI が同じ 1 クラスを通る（F-A1 の解消）
    expect(command).toContain("UpdateSettings");
    // schema 検証も保存も Command 側で再実装しない
    expect(command).not.toContain("SchemaRules::for");
    expect(command).not.toContain("DB::table");
  });

  it("サイト名は独立した引数として渡す（shell も文字列連結も使わない）", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-args-"));
    try {
      const value = 'サンプル "制作所" & <script>alert(1)</script>';
      const args = siteSettingArgs(dir, "site_name", value);
      expect(args.at(-1)).toBe(value);
      expect(args.at(-2)).toBe("site_name");
      expect(args).toContain("agentcms:site-setting");
      // 値が他の引数へ混入していないこと
      expect(args.filter((a) => a.includes("alert(1)"))).toHaveLength(1);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("公開サイトのテンプレートはサイト名をエスケープして出力する", () => {
    for (const view of [
      "template/resources/views/layouts/public.blade.php",
      "template/resources/views/layouts/imported.blade.php",
    ]) {
      const blade = readFileSync(join(repoRoot, view), "utf8");
      // {!! ... !!}（raw 出力）で site_name を出していないこと
      expect(blade).not.toMatch(/\{!!\s*\$site\['site_name'\]/);
      expect(blade).toContain("{{ $site['site_name'] }}");
    }
  });

  it("進捗にサイト情報の設定 stage がある（失敗を READY に紛れ込ませない）", async () => {
    const home = mkdtempSync(join(tmpdir(), "agentcms-stage-"));
    const server = await startWizardServer({ assetsDir, home });
    try {
      const stages = server.runner.snapshot().stages.map((s) => s.id);
      expect(stages).toContain("site");
      // D8: サイト情報の設定のあとに最終確認（verify 相当）が入る
      expect(stages.at(-1)).toBe("check");
      expect(stages.indexOf("site")).toBeLessThan(stages.indexOf("check"));
    } finally {
      await server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });
});

describe("C2 / F-B3 — 中止の後片付け", () => {
  it("後片付けは compose down（データ volume は削除しない）", () => {
    expect([...CANCEL_CLEANUP_ARGS]).toEqual([
      "compose",
      "down",
      "--remove-orphans",
    ]);
    expect(CANCEL_CLEANUP_ARGS).not.toContain("-v");
    expect(CANCEL_CLEANUP_ARGS).not.toContain("--volumes");
    expect(CANCEL_CLEANUP_ARGS).not.toContain("--rmi");
  });

  it("中止しても project ファイルは削除しない", async () => {
    const home = mkdtempSync(join(tmpdir(), "agentcms-cancel-"));
    const server = await startWizardServer({ assetsDir, home });
    try {
      // 進行中でないときの cancel は何もしない（誤操作で state を壊さない）
      server.runner.cancel();
      expect(server.runner.snapshot().phase).toBe("WELCOME");
    } finally {
      await server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });
});

describe("C3 / F-B2 — doctor の詳細ログ完全性", () => {
  it("NOT REQUIRED の判定も詳細ログに残る", async () => {
    const home = mkdtempSync(join(tmpdir(), "agentcms-details-"));
    const server = await startWizardServer({ assetsDir, home });
    try {
      await server.runner.runEnvironmentCheck();
      const logs = server.runner.detailLogs().join("\n");
      expect(logs).toContain("NOT REQUIRED");
      // 画面に出さない任意ツールの判定も追跡できること
      expect(logs).toMatch(/PHP \(host\)|Composer \(host\)/);
    } finally {
      await server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });

  it("doctor の全 check が event として発行される（表示有無に関わらず）", async () => {
    const events: EngineEvent[] = [];
    await doctor(
      { onEvent: (e) => events.push(e) },
      { scope: "environment", baseDir: homedir() },
    );
    const ids = events
      .filter(
        (e): e is Extract<EngineEvent, { type: "check" }> => e.type === "check",
      )
      .map((e) => e.id);
    expect(ids).toContain("php.host");
    expect(ids).toContain("composer.host");
    expect(ids).toContain("pnpm.installed");
  });
});

describe("セキュリティの回帰固定", () => {
  it("子プロセスは常に shell を介さず起動する", () => {
    const runSource = readFileSync(
      join(repoRoot, "packages/cli/src/run.ts"),
      "utf8",
    );
    expect(runSource).not.toMatch(/shell:\s*true/);
    // spawn / spawnSync の両方で明示的に false を指定していること
    expect(runSource.match(/shell:\s*false/g)?.length).toBeGreaterThanOrEqual(
      2,
    );
  });

  it("wizard は OS ダイアログを固定スクリプトで呼ぶ（入力を連結しない）", () => {
    const picker = readFileSync(
      join(repoRoot, "packages/cli/src/wizard/picker.ts"),
      "utf8",
    );
    // テンプレートリテラルによるスクリプト組み立てをしていないこと
    expect(picker).not.toMatch(/MACOS_SCRIPT\s*=\s*`/);
    expect(picker).not.toMatch(/\$\{[^}]*\}\s*"?\s*\)\s*;?\s*\/\/ script/);
    expect(picker).toContain("shell も使わない");
  });
});

describe("C5 — 再接続しても状態を見失わない", () => {
  it("SSE は接続直後に現在の snapshot を配信する（再接続で復旧できる）", async () => {
    const home = mkdtempSync(join(tmpdir(), "agentcms-sse-"));
    const server = await startWizardServer({ assetsDir, home });
    const token = new URL(server.url).searchParams.get("t") as string;
    const origin = `http://127.0.0.1:${server.port}`;

    const firstSnapshot = async (): Promise<Record<string, unknown>> => {
      const controller = new AbortController();
      const res = await fetch(`${origin}/api/events`, {
        headers: { Cookie: `agentcms_setup=${token}` },
        signal: controller.signal,
      });
      const reader = (res.body as ReadableStream<Uint8Array>).getReader();
      const { value } = await reader.read();
      controller.abort();
      const text = new TextDecoder().decode(value);
      return JSON.parse(text.replace(/^data: /, "").trim()) as Record<
        string,
        unknown
      >;
    };

    try {
      const first = await firstSnapshot();
      expect((first.snapshot as { phase: string }).phase).toBe("WELCOME");

      // 画面側の状態遷移のあと、切断 → 再接続でも同じ state に戻る
      server.runner.enterProject();
      const second = await firstSnapshot();
      expect((second.snapshot as { phase: string }).phase).toBe("PROJECT");
    } finally {
      await server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });
});

describe("C6 — setup state の衛生", () => {
  let home: string;

  beforeAll(() => {
    home = mkdtempSync(join(tmpdir(), "agentcms-hygiene-"));
    mkdirSync(dirname(setupStatePath(home)), { recursive: true });
  });

  afterAll(() => {
    rmSync(home, { recursive: true, force: true });
  });

  it("相対パスの state は採用しない", () => {
    writeFileSync(
      setupStatePath(home),
      JSON.stringify({
        version: 1,
        projectPath: "relative/path",
        projectName: "x",
        siteName: "x",
        stage: "created",
        createdAt: "2026-08-11T00:00:00.000Z",
        updatedAt: "2026-08-11T00:00:00.000Z",
      }),
      "utf8",
    );
    expect(readSetupState(home)).toBeNull();
  });

  it("壊れた JSON は例外にせず「状態なし」として扱う", () => {
    writeFileSync(setupStatePath(home), "{ not json", "utf8");
    expect(readSetupState(home)).toBeNull();
  });

  it("project が消えていれば再開を提案しない（stale 検出）", async () => {
    const gone = join(home, "deleted-project");
    writeSetupState(
      {
        version: 1,
        projectPath: gone,
        projectName: "deleted-project",
        siteName: "消えたサイト",
        stage: "created",
        createdAt: "2026-08-11T00:00:00.000Z",
        updatedAt: "2026-08-11T00:00:00.000Z",
      },
      home,
    );
    expect(existsSync(gone)).toBe(false);

    const server = await startWizardServer({ assetsDir, home });
    try {
      const snapshot = server.runner.snapshot();
      expect(snapshot.phase).toBe("WELCOME");
      expect(snapshot.canResume).toBe(false);
    } finally {
      await server.close();
    }
  });
});

import { execFileSync } from "node:child_process";
import {
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  renameSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, sep } from "node:path";

import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { shouldCopyTemplatePath } from "../src/commands/create.js";

/**
 * Phase 9.3B / #25 — `figma apply` の all-or-nothing（bounded fix）。
 *
 * これまで Figma 経路には復旧記録が無く、生成物を書いたあとで
 * route の追記や registry の保存が落ちると **生成物だけが残った**。
 * Template Import と同じ「全部やるか、何も残さないか」を持たせる。
 *
 * ここは実際に CLI を起動して確かめる。
 * 「戻しました」は関数単体ではどうとでも書けるので、
 * プロセスを跨いだ **最終的なディスクの状態**で見る。
 *
 * 失敗の作り方は **権限に頼らない**。0555 は実行ユーザー次第で素通りするので、
 * 「戻せない対象がある」「定義に無い field を指している」のように
 * 誰が実行しても同じ結果になる状態を使う。
 */

const CLI = resolve(__dirname, "../dist/index.js");
const FIXTURE = resolve(__dirname, "fixtures/figma");
const TEMPLATE = resolve(__dirname, "../../../template");
const SCHEMAS = join(TEMPLATE, "content/schemas");

let ws: string;
let project: string;
let fixtureDir: string;

function run(
  args: string[],
  opts: { expectFail?: boolean } = {},
): { code: number; out: string } {
  try {
    const out = execFileSync("node", [CLI, ...args], {
      cwd: project,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
      env: { ...process.env, AGENTCMS_FIGMA_FIXTURE: fixtureDir },
    });

    return { code: 0, out };
  } catch (e) {
    const err = e as { status?: number; stdout?: string; stderr?: string };

    if (!opts.expectFail) {
      throw new Error(
        `CLI failed: ${args.join(" ")}\n${err.stdout ?? ""}${err.stderr ?? ""}`,
      );
    }

    return {
      code: err.status ?? 1,
      out: `${err.stdout ?? ""}${err.stderr ?? ""}`,
    };
  }
}

/** プロジェクト内のファイル内容（byte 比較用） */
function snapshot(dir = project, base = project): Record<string, string> {
  const out: Record<string, string> = {};

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === ".git") continue;

    const path = join(dir, entry.name);

    // symlink は辿らない（辿ると外を読みに行く）
    if (entry.isSymbolicLink()) continue;

    if (entry.isDirectory()) {
      Object.assign(out, snapshot(path, base));
    } else if (entry.isFile()) {
      out[path.slice(base.length + 1)] = readFileSync(path).toString("base64");
    }
  }

  return out;
}

const journalOf = () =>
  join(project, ".agentcms", "manifest", "figma-apply-journal.json");

const recordOf = () =>
  join(project, ".agentcms", "manifest", "figma-apply.json");

const planOf = () => join(project, ".agentcms", "manifest", "figma-plan.json");

const GENERATED = "resources/views/figma/home.blade.php";

const generated = () => join(project, GENERATED);

/** 中断した記録を手で置く（phase は既定で files_written） */
function leaveJournal(
  files: { target: string; content: string | null }[],
  phase = "files_written",
): void {
  mkdirSync(join(project, ".agentcms", "manifest"), { recursive: true });
  writeFileSync(
    journalOf(),
    JSON.stringify({
      version: 1,
      phase,
      started_at: "2026-01-01T00:00:00Z",
      // Plan が変わっていても、前回の途中結果の上には重ねない
      plan_sha256: "別の plan",
      files,
      terms: [],
    }),
  );
}

/** connect → inspect → plan まで進めた状態を作る */
function prepare(): void {
  expect(
    run([
      "figma",
      "connect",
      "https://www.figma.com/design/FKEYFIXTURE0001/Northwind?node-id=0-1",
    ]).code,
  ).toBe(0);
  expect(run(["figma", "inspect"]).code).toBe(0);
  expect(run(["figma", "plan"]).code).toBe(0);
}

beforeEach(() => {
  expect(
    existsSync(CLI),
    "dist が必要です（pnpm -C packages/cli build を先に実行）",
  ).toBe(true);

  ws = mkdtempSync(join(tmpdir(), "agentcms-figma-rollback-"));
  fixtureDir = join(ws, "fixture");
  project = join(ws, "project");

  cpSync(FIXTURE, fixtureDir, { recursive: true });
  mkdirSync(join(project, "content", "schemas"), { recursive: true });
  // 配布されるのは base だけ（overrides/ は開発機の状態）
  cpSync(SCHEMAS, join(project, "content", "schemas"), {
    recursive: true,
    filter: (src) => !src.includes(`${sep}overrides`),
  });
  writeFileSync(join(project, "artisan"), "");
  writeFileSync(join(project, "docker-compose.yml"), "services: {}\n");
  mkdirSync(join(project, ".agentcms"), { recursive: true });
  mkdirSync(join(project, "routes"), { recursive: true });
  writeFileSync(join(project, "routes", "web.php"), "<?php\n");
});

afterEach(() => {
  if (ws) rmSync(ws, { recursive: true, force: true });
});

describe("後段で落ちても生成物だけを残さない", () => {
  it("routes/web.php が無いと、1 バイトも変えずに終わる", () => {
    prepare();

    // route の追記は生成物を書いたあとに走る。ここで落ちても残してはいけない
    rmSync(join(project, "routes", "web.php"));

    const before = snapshot();
    const res = run(["figma", "apply"], { expectFail: true });

    expect(res.code).toBe(1);
    expect(res.out).toContain("元へ戻しました");
    expect(snapshot()).toStrictEqual(before);

    // 生成物・override・apply 記録・復旧記録のどれも残らない
    // （registry は plan の時点で既にあるので、byte 一致の方で見ている）
    expect(existsSync(generated())).toBe(false);
    expect(existsSync(join(project, "content/schemas/overrides"))).toBe(false);
    expect(existsSync(recordOf())).toBe(false);
    expect(existsSync(journalOf())).toBe(false);
  });

  it("route 追記より後で落ちても、1 バイトも変えずに終わる", () => {
    prepare();

    // schema override の書き込みは **生成物と route 追記のあと**に走る。
    // 定義に無い field を指した Plan は、誰が実行しても同じところで落ちる
    const plan = JSON.parse(readFileSync(planOf(), "utf8")) as {
      schema_overrides: { field: string }[];
    };

    // 直前の件数検査が存在を保証している
    expect(plan.schema_overrides.length).toBeGreaterThan(0);
    plan.schema_overrides[0]!.field = "no_such_field";
    writeFileSync(planOf(), JSON.stringify(plan, null, 2));

    const before = snapshot();
    const res = run(["figma", "apply"], { expectFail: true });

    expect(res.code).toBe(1);
    // route 追記まで進んでいる＝「route 不在だけを特別扱い」していない
    expect(res.out).toContain("routes/web.php へ require を追記しました");
    expect(res.out).toContain("元へ戻しました");
    expect(snapshot()).toStrictEqual(before);
    expect(existsSync(generated())).toBe(false);
    expect(existsSync(journalOf())).toBe(false);
  });

  it("戻せない対象が混ざっていたら、そもそも始めない", () => {
    prepare();

    // routes/web.php は追記対象。通常のファイルでないと退避も復元もできない。
    // 「記録は諦めて適用だけする」が一番危ないので、始める前に止める
    rmSync(join(project, "routes", "web.php"));
    mkdirSync(join(project, "routes", "web.php"));

    const before = snapshot();
    const res = run(["figma", "apply"], { expectFail: true });

    expect(res.code).toBe(1);
    expect(res.out).toContain("変更を始められません");
    expect(snapshot()).toStrictEqual(before);
    expect(existsSync(generated())).toBe(false);
    expect(existsSync(journalOf())).toBe(false);
  });

  it("復旧記録を置けない場所なら、そもそも始めない", () => {
    prepare();

    // 記録の置き場所が symlink 経由になっている。
    // 辿った先はプロジェクトの外かもしれず、**戻す根拠を安全に残せない**。
    // 「記録は諦めて適用だけする」は選ばない
    const manifest = join(project, ".agentcms", "manifest");

    renameSync(manifest, join(project, ".agentcms", "manifest-real"));
    symlinkSync(join(project, ".agentcms", "manifest-real"), manifest);

    const before = snapshot();
    const res = run(["figma", "apply"], { expectFail: true });

    expect(res.code).toBe(1);
    expect(res.out).toContain("変更を始められません");
    expect(res.out).toContain("symlink");
    expect(snapshot()).toStrictEqual(before);
    expect(existsSync(generated())).toBe(false);
  });

  it("読めない記録が残っていたら、上書きせずに止める", () => {
    prepare();

    // 記録が壊れている＝何を戻すべきかの根拠が無い。
    // 「読めないから無かったことにする」と、前回の途中結果の上に重ねてしまう
    mkdirSync(journalOf(), { recursive: true });

    const before = snapshot();
    const res = run(["figma", "apply"], { expectFail: true });

    expect(res.code).toBe(1);
    expect(res.out).toContain("前回の復旧記録を読み取れません");
    expect(snapshot()).toStrictEqual(before);
    expect(existsSync(generated())).toBe(false);
  });

  it("戻せない対象があれば、記録を残して対象名を示す", () => {
    prepare();

    // 復元先の途中が symlink だと、辿った先の実体を消しに行くので復元できない。
    // 「戻せなかった」を **黙って成功にしない**ことを見る
    mkdirSync(join(project, "resources", "views"), { recursive: true });
    mkdirSync(join(project, "resources", "views", "figma-real"));
    symlinkSync(
      join(project, "resources", "views", "figma-real"),
      join(project, "resources", "views", "figma"),
    );
    leaveJournal([{ target: GENERATED, content: null }]);

    const res = run(["figma", "apply"], { expectFail: true });

    expect(res.code).toBe(1);
    expect(res.out).toContain("戻せませんでした");
    // どれが戻せなかったのかを名指しする
    expect(res.out).toContain(GENERATED);
    // 手当ての根拠が消えていない
    expect(existsSync(journalOf())).toBe(true);
  });
});

describe("前回の中断を片づけてから進む", () => {
  it("書きかけの生成物が残っていても、conflict ではなく復旧になる", () => {
    prepare();

    // **前回書きかけたファイルが実際に残っている**状態。
    // 復旧を conflict 判定より後ろでやると、これが「apply 外で作られた
    // ファイル」に見えて STOP し、復旧に辿り着けない
    mkdirSync(join(project, "resources", "views", "figma"), {
      recursive: true,
    });
    writeFileSync(generated(), "書きかけ\n");
    leaveJournal([{ target: GENERATED, content: null }]);

    const res = run(["figma", "apply"]);

    expect(res.code).toBe(0);
    expect(res.out).not.toContain("conflict を検出");
    expect(res.out).toContain("もう一度");
    // 書きかけは消え、記録も残らない
    expect(existsSync(generated())).toBe(false);
    expect(existsSync(journalOf())).toBe(false);
    // **新しい apply を重ねない**（適用記録もまだ無い）
    expect(existsSync(recordOf())).toBe(false);
  });

  it("dry-run は残存記録があっても 1 バイトも変えず、残っていることは知らせる", () => {
    prepare();
    leaveJournal([{ target: "routes/web.php", content: null }]);

    const before = snapshot();
    const res = run(["figma", "apply", "--dry-run"]);

    expect(res.code).toBe(0);
    expect(res.out).toContain("前回の apply が phase [files_written]");
    expect(res.out).toContain("dry-run");
    expect(snapshot()).toStrictEqual(before);
  });

  it("phase [recorded] の消し忘れは片づけて、そのまま続行する", () => {
    prepare();

    // recorded は「適用まで終わっていた」印。戻す対象ではない
    leaveJournal([{ target: GENERATED, content: null }], "recorded");

    const res = run(["figma", "apply"]);

    expect(res.code).toBe(0);
    expect(res.out).toContain("前回の復旧記録を片づけました");
    // 再実行を促さずに、その場で適用まで進む
    expect(res.out).not.toContain("もう一度");
    expect(existsSync(generated())).toBe(true);
    expect(existsSync(recordOf())).toBe(true);
    expect(existsSync(journalOf())).toBe(false);
  });
});

describe("正常時は記録を残さない", () => {
  it("成功しても再 apply（no-op）でも復旧記録は消えている", () => {
    prepare();

    expect(run(["figma", "apply"]).code).toBe(0);
    expect(existsSync(generated())).toBe(true);
    expect(existsSync(recordOf())).toBe(true);
    expect(existsSync(journalOf())).toBe(false);

    // 同じ Plan の再 apply（no-op）でも残さない
    const again = run(["figma", "apply"]);

    expect(again.code).toBe(0);
    expect(again.out).toContain("no-op");
    expect(existsSync(journalOf())).toBe(false);
  });
});

describe("復旧記録をコミットさせない", () => {
  // 復旧記録は **変更前のファイルの中身**を base64 で持つ。
  // .env を書き換える経路（Template Import）を通れば秘密がそのまま入るので、
  // 配布する .gitignore の側で止める
  it("生成 project の .gitignore が 2 つの journal を無視し、provenance は無視しない", () => {
    const repo = join(ws, "repo");

    // 生成 project は template の .gitignore をそのまま受け取る
    expect(shouldCopyTemplatePath(".gitignore")).toBe(true);

    mkdirSync(join(repo, ".agentcms", "manifest"), { recursive: true });
    cpSync(join(TEMPLATE, ".gitignore"), join(repo, ".gitignore"));
    execFileSync("git", ["init", "-q"], { cwd: repo });

    const ignored = (path: string): boolean => {
      writeFileSync(join(repo, path), "{}\n");

      try {
        execFileSync("git", ["check-ignore", "-q", path], { cwd: repo });

        return true;
      } catch {
        return false;
      }
    };

    expect(ignored(".agentcms/manifest/figma-apply-journal.json")).toBe(true);
    expect(ignored(".agentcms/manifest/template-apply-journal.json")).toBe(
      true,
    );
    // manifest ごと無視すると、apply の provenance まで消える
    expect(ignored(".agentcms/manifest/figma-apply.json")).toBe(false);
    expect(ignored(".agentcms/manifest/template.json")).toBe(false);
  });
});

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, sep } from "node:path";

import { afterEach, beforeEach, describe, expect, it } from "vitest";

/**
 * R03 / B-42 — 取り込んだトップを**画面から編集できるページ**として起こす実経路を、
 * 実際の CLI（inspect → plan → apply）を起動して固定する。
 *
 * helper（`buildImportedPageBlocks` / `buildImportedLayout`）は前の版でも在ったが、
 * **試験からしか呼ばれていなかった**。ここでは dist の CLI をプロセスとして動かし、
 * `docker` を差し替えて CMS（`agentcms:import-page` / `settings.get` / `site-setting`）の
 * 応答を作る。こちらから見える契約（stdin JSON → stdout の Result JSON）は本物と同じ。
 *
 * 固定するのは 6 つ:
 *   1. **dry-run は 1 バイトも書かず、CMS も呼ばない**（見せるだけ）
 *   2. **作る → トップページに指定する**（`/` がページを出す）
 *   3. **再 apply は既に在るページを触らない**（管理画面の編集を上書きしない）
 *   4. **途中失敗の下書きを、再実行で成功にしない**
 *   5. **作られたか分からないときは、そう言って止まる**
 *   6. **layout モード・別のトップが指定済み、では勝手に変えない**
 */

const CLI = resolve(__dirname, "../dist/index.js");
const GREEN_LEAF = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

interface Workspace {
  root: string;
  project: string;
  bin: string;
  /** fake docker が import-page の stdin を書き出す先 */
  payloadFile: string;
  /** fake docker が site-setting の引数を書き出す先 */
  settingFile: string;
  /** fake docker が imported-usage の stdin を書き出す先 */
  usageFile: string;
}

const OK_TERMS = JSON.stringify({ status: "success", data: { terms: [] } });
const OK_TYPES = JSON.stringify({
  status: "success",
  created: [],
  skipped: [],
});
const OK_IMPORT_TERMS = JSON.stringify({ status: "success", created: [] });
const SETTINGS_EMPTY = JSON.stringify({
  status: "success",
  // 設定をまだ保存していない PHP の実応答（空配列は [] に符号化される）。
  data: { values: [] },
});
/** 使用状況: 何も使われていない（両方の見本に出る部品・枠を全部列挙） */
const usageOf = (components: Record<string, string[]>, layouts: Record<string, string[]>) =>
  JSON.stringify({ status: "success", components, layouts });
const ALL_TYPES = [
  "imported_home_home",
  "imported_home_fullbleed_band",
  "imported_home_concept",
  "imported_home_contact",
  "imported_home_about",
  "imported_home_services",
];
const USAGE_NONE = usageOf(
  Object.fromEntries(ALL_TYPES.map((t) => [t, []])),
  { "layouts.preserved.home": [] },
);
const settingsWith = (id: number | string) =>
  JSON.stringify({
    status: "success",
    data: { values: { front_page_id: id } },
  });
const pageResponse = (
  action: "created" | "kept",
  id: number,
  published: boolean,
) =>
  JSON.stringify({
    status: "success",
    action,
    id,
    slug: "home",
    blocks: 4,
    published,
  });

/** トップ 1 枚だけの standalone 原本（nav / 節 / 帯 / footer / script を持つ） */
const SINGLE_PAGE = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>みどり造園</title><style>.hero{color:red}</style></head><body>
<div class="bg-pattern"></div>
<nav id="navbar"><img src="images/logo.png" alt="logo"><ul><li><a href="#home">ホーム</a></li><li><a href="#concept">こだわり</a></li><li><a href="#contact">お問い合わせ</a></li></ul></nav>
<section id="home"><h1>庭をつくる</h1><p>庭づくりの会社です。</p></section>
<div class="fullbleed-band"></div>
<section id="concept"><h2>CONCEPT</h2><p>植物が好き。</p><a href="#contact">詳しく見る</a></section>
<section id="contact"><h2>CONTACT</h2><p>お気軽にご相談ください。</p></section>
<footer class="footer"><small>&copy; みどり造園</small></footer>
<script>document.body.classList.add("ready")</script>
</body></html>`;

/** 別のテンプレート（節の id が全部違う。枠の style も違う） */
const OTHER_PAGE = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>別の会社</title><style>.about{color:blue}</style></head><body>
<nav id="navbar"><img src="images/logo.png" alt="logo"><a href="#about">会社</a></nav>
<section id="about"><h1>別の会社</h1><p>別の本文。</p></section>
<section id="services"><h2>SERVICES</h2><p>提供内容。</p></section>
<footer class="footer"><small>&copy; 別の会社</small></footer>
<script>document.body.classList.add("ready")</script>
</body></html>`;

/** 資産まで別物のテンプレート（画像を差し替え・CSS ファイルを足す・inline script を変える） */
const OTHER_ASSETS_PAGE = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>別の会社</title><link rel="stylesheet" href="css/site.css"></head><body>
<nav id="navbar"><img src="images/hero.jpg" alt="hero"><a href="#about">会社</a></nav>
<section id="about"><h1>別の会社</h1><p>別の本文。</p></section>
<footer class="footer"><small>&copy; 別の会社</small></footer>
<script>document.body.classList.add("other")</script>
</body></html>`;

function setup(source: "single" | "green-leaf" = "single"): Workspace {
  const root = mkdtempSync(join(tmpdir(), "agentcms-import-page-"));
  const project = join(root, "project");
  const bin = join(root, "bin");
  const template = join(root, "site-template");

  if (source === "green-leaf") {
    cpSync(GREEN_LEAF, template, { recursive: true });
  } else {
    mkdirSync(join(template, "images"), { recursive: true });
    writeFileSync(join(template, "images", "logo.png"), "png");
    writeFileSync(join(template, "index.html"), SINGLE_PAGE);
  }

  mkdirSync(join(project, "content", "schemas"), { recursive: true });
  cpSync(SCHEMAS, join(project, "content", "schemas"), {
    recursive: true,
    filter: (src) => !src.includes(`${sep}overrides`),
  });
  writeFileSync(join(project, "artisan"), "");
  mkdirSync(join(project, "routes"), { recursive: true });
  writeFileSync(join(project, "routes", "web.php"), "<?php\n");
  writeFileSync(join(project, "docker-compose.yml"), "services: {}\n");
  writeFileSync(join(project, ".env"), "APP_NAME=apply-test\n");
  writeFileSync(join(project, ".env.example"), "APP_NAME=apply-test\n");
  mkdirSync(join(project, ".agentcms"), { recursive: true });
  execFileSync("git", ["init", "-q"], { cwd: project });

  const payloadFile = join(root, "import-page.stdin");
  const settingFile = join(root, "site-setting.args");
  const usageFile = join(root, "imported-usage.stdin");

  mkdirSync(bin, { recursive: true });
  writeFileSync(
    join(bin, "docker"),
    `#!/bin/sh
case "$*" in
  *agentcms:import-page*)
    # stdin の JSON を残す（呼ばれたこと・何を渡したかの証拠）
    cat >> "$PAGE_PAYLOAD_FILE"
    printf '\\n' >> "$PAGE_PAYLOAD_FILE"
    printf '%s\\n' "$FAKE_IMPORT_PAGE"
    exit "\${FAKE_IMPORT_PAGE_CODE:-0}"
    ;;
  *agentcms:imported-usage*)
    cat >> "$USAGE_PAYLOAD_FILE"
    printf '\\n' >> "$USAGE_PAYLOAD_FILE"
    printf '%s\\n' "$FAKE_USAGE"
    exit "\${FAKE_USAGE_CODE:-0}"
    ;;
  *agentcms:operation*settings.get*)
    printf '%s\\n' "$FAKE_SETTINGS"
    exit "\${FAKE_SETTINGS_CODE:-0}"
    ;;
  *agentcms:site-setting*)
    printf '%s\\n' "$*" >> "$SETTING_ARGS_FILE"
    printf '%s\\n' "\${FAKE_SETTING_OUT:-サイト設定 [front_page_id] を更新しました}"
    exit "\${FAKE_SETTING_CODE:-0}"
    ;;
  *agentcms:create-content-type*)
    printf '%s\\n' "$FAKE_CREATE_TYPES"
    exit 0
    ;;
  *agentcms:import-terms*)
    printf '%s\\n' "$FAKE_IMPORT"
    exit 0
    ;;
  *)
    printf '%s\\n' "$FAKE_TERM_LIST"
    exit 0
    ;;
esac
`,
    { mode: 0o755 },
  );

  return { root, project, bin, payloadFile, settingFile, usageFile };
}

function cli(
  ws: Workspace,
  args: string[],
  env: Record<string, string> = {},
): { code: number; out: string } {
  try {
    const out = execFileSync("node", [CLI, ...args], {
      cwd: ws.project,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
      env: {
        ...process.env,
        PATH: `${ws.bin}:${process.env.PATH ?? ""}`,
        PAGE_PAYLOAD_FILE: ws.payloadFile,
        SETTING_ARGS_FILE: ws.settingFile,
        USAGE_PAYLOAD_FILE: ws.usageFile,
        FAKE_USAGE: USAGE_NONE,
        FAKE_TERM_LIST: OK_TERMS,
        FAKE_IMPORT: OK_IMPORT_TERMS,
        FAKE_CREATE_TYPES: OK_TYPES,
        FAKE_IMPORT_PAGE: pageResponse("created", 42, true),
        FAKE_SETTINGS: SETTINGS_EMPTY,
        ...env,
      },
    });

    return { code: 0, out };
  } catch (e) {
    const err = e as { status?: number; stdout?: string; stderr?: string };

    return {
      code: err.status ?? 1,
      out: `${err.stdout ?? ""}${err.stderr ?? ""}`,
    };
  }
}

function planned(ws: Workspace): void {
  expect(cli(ws, ["template", "inspect", "../site-template"]).code).toBe(0);
  expect(cli(ws, ["template", "plan"]).code).toBe(0);
}

/** import-page へ渡した stdin（呼ばれた回数ぶん） */
function payloads(ws: Workspace): { page: Record<string, unknown> }[] {
  if (!existsSync(ws.payloadFile)) return [];

  return readFileSync(ws.payloadFile, "utf8")
    .split("\n")
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => JSON.parse(l) as { page: Record<string, unknown> });
}

function settingCalls(ws: Workspace): string[] {
  if (!existsSync(ws.settingFile)) return [];

  return readFileSync(ws.settingFile, "utf8").split("\n").filter(Boolean);
}

function usagePayloads(ws: Workspace): { components: string[]; layouts: string[] }[] {
  if (!existsSync(ws.usageFile)) return [];

  return readFileSync(ws.usageFile, "utf8")
    .split("\n")
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => JSON.parse(l) as { components: string[]; layouts: string[] });
}

function record(ws: Workspace): {
  files: { target: string }[];
  pages?: {
    slug: string;
    action: string;
    id: number | null;
    published: boolean;
    front_set: boolean;
  }[];
} {
  return JSON.parse(
    readFileSync(
      join(ws.project, ".agentcms", "manifest", "template-apply.json"),
      "utf8",
    ),
  ) as ReturnType<typeof record>;
}

function snapshot(dir: string, base = dir): Record<string, string> {
  const out: Record<string, string> = {};

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === ".git") continue;

    const path = join(dir, entry.name);

    if (entry.isDirectory()) Object.assign(out, snapshot(path, base));
    else if (entry.isFile())
      out[path.slice(base.length + 1)] = readFileSync(path).toString("base64");
  }

  return out;
}

const journalOf = (ws: Workspace) =>
  join(ws.project, ".agentcms", "manifest", "template-apply-journal.json");

let ws: Workspace;

beforeEach(() => {
  expect(
    existsSync(CLI),
    "dist が必要です（pnpm -C packages/cli build を先に実行）",
  ).toBe(true);
  ws = setup();
});

afterEach(() => {
  if (ws?.root) rmSync(ws.root, { recursive: true, force: true });
});

describe("dry-run は見せるだけ", () => {
  it("ページの予定を出し、ファイルも CMS も触らない", () => {
    planned(ws);

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply", "--dry-run"]);

    expect(result.code, result.out).toBe(0);
    expect(result.out).toContain("would create page: home");
    expect(result.out).toContain("ブロック 4 件");
    expect(result.out).toContain("トップページに指定");
    expect(result.out).toContain(
      "would write [new]: resources/views/layouts/preserved/home.blade.php",
    );

    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(payloads(ws)).toEqual([]);
    expect(settingCalls(ws)).toEqual([]);
  });
});

describe("作って、トップページに指定する", () => {
  it("import-page へ原本の順のブロックを渡し、front_page_id を立て、記録に残す", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"]);

    expect(result.code, result.out).toBe(0);

    // ---- CMS へ渡したもの（`ImportPageCommand` が受ける形そのもの）----
    const sent = payloads(ws);
    expect(sent).toHaveLength(1);

    const page = sent[0]!.page;
    expect(Object.keys(page).sort()).toEqual([
      "blocks",
      "layout",
      "publish",
      "slug",
      "title",
    ]);
    expect(page.layout).toBe("layouts.preserved.home");
    expect(page.slug).toBe("home");
    expect(page.publish).toBe(true);

    const blocks = page.blocks as {
      type: string;
      props: Record<string, string>;
    }[];
    expect(blocks.map((b) => b.type)).toEqual([
      "imported_home_home",
      "imported_home_fullbleed_band",
      "imported_home_concept",
      "imported_home_contact",
    ]);
    expect(blocks[0]!.props).toEqual({
      heading_1: "庭をつくる",
      body_1: "庭づくりの会社です。",
    });

    // ---- トップページの指定（口は agentcms:site-setting の 1 つ）----
    expect(settingCalls(ws)).toHaveLength(1);
    expect(settingCalls(ws)[0]).toContain(
      "agentcms:site-setting front_page_id 42",
    );
    expect(result.out).toContain(
      "トップページに ページ [home] (id 42) を指定",
    );

    // ---- 部品と枠のファイルが書かれ、静的なトップの Blade も残る ----
    for (const target of [
      "resources/views/layouts/preserved/home.blade.php",
      "content/components/imported_home_home.json",
      "resources/views/components/document/imported-home-home.blade.php",
      "resources/views/public/home.blade.php",
    ]) {
      expect(existsSync(join(ws.project, target)), target).toBe(true);
    }

    // 部品の view は原本の id を保つ（ナビのアンカーが死なない）
    expect(
      readFileSync(
        join(
          ws.project,
          "resources/views/components/document/imported-home-concept.blade.php",
        ),
        "utf8",
      ),
    ).toContain('<section id="concept">');

    // ---- 記録 ----
    expect(record(ws).pages).toEqual([
      expect.objectContaining({
        slug: "home",
        action: "created",
        id: 42,
        published: true,
        front: true,
        front_set: true,
      }),
    ]);
    expect(existsSync(journalOf(ws))).toBe(false);

    // 編集の入口を案内する
    expect(result.out).toContain("管理画面 > ページ");
  });

  it("別のページが既にトップなら、変えずに言う", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_SETTINGS: settingsWith(7),
    });

    expect(result.code, result.out).toBe(0);
    expect(settingCalls(ws)).toEqual([]);
    expect(result.out).toContain(
      "別のページ (id 7) が指定されているため変更していません",
    );
    expect(record(ws).pages?.[0]).toMatchObject({
      action: "created",
      front_set: false,
    });
  });

  it("トップページの設定を読めないときは「未設定」と取り違えず、失敗で終える", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_SETTINGS: "not json",
    });

    expect(result.code).toBe(1);
    expect(settingCalls(ws)).toEqual([]);
    expect(result.out).toContain(
      "トップページの設定を確認できないため変更していません",
    );
    // ファイルと記録は残る（ページは在る。無いのはトップの指定だけ）
    expect(
      existsSync(
        join(ws.project, "resources/views/layouts/preserved/home.blade.php"),
      ),
    ).toBe(true);
    expect(record(ws).pages?.[0]).toMatchObject({ action: "created", id: 42 });
  });
});

describe("再 apply は既に在るページを触らない", () => {
  it("kept を受け取ったら上書きせず、トップも指定済みなら何もしない", () => {
    planned(ws);
    expect(cli(ws, ["template", "apply"]).code).toBe(0);

    const again = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
      FAKE_SETTINGS: settingsWith(42),
    });

    expect(again.code, again.out).toBe(0);
    expect(again.out).toContain("既に在るので触っていません");
    expect(again.out).toContain("no-op");
    // 2 回目も import-page は呼ぶ（在るかどうかは CMS が判断する）が、site-setting は呼ばない
    expect(payloads(ws)).toHaveLength(2);
    expect(settingCalls(ws)).toHaveLength(1);
    expect(record(ws).pages?.[0]).toMatchObject({
      action: "kept",
      id: 42,
      front_set: false,
    });
  });

  it("自分が作った記録があり、トップが外れていれば立て直す", () => {
    planned(ws);
    expect(cli(ws, ["template", "apply"]).code).toBe(0);

    // 記録が残らなかった中断（import-page の直後に落ちた）を想定: 設定は空
    const again = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
      FAKE_SETTINGS: SETTINGS_EMPTY,
    });

    expect(again.code, again.out).toBe(0);
    expect(settingCalls(ws)).toHaveLength(2);
    expect(record(ws).pages?.[0]).toMatchObject({
      action: "kept",
      front_set: true,
    });
  });

  it("記録の無い同名ページは、こちらのものと決めつけてトップにしない", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("kept", 9, true),
    });

    expect(result.code, result.out).toBe(0);
    expect(settingCalls(ws)).toEqual([]);
    expect(result.out).toContain(
      "確かめられないため指定していません",
    );
    expect(result.out).toContain("agentcms:site-setting front_page_id 9");
  });
});

describe("途中失敗の下書きを成功にしない", () => {
  const refused = JSON.stringify({
    status: "validation_error",
    action: "none",
    message:
      "2 個目のブロック [imported_home_fullbleed_band] を置けませんでした（ページ [home] は下書きのまま残しました）",
    reason: "入力内容を確認してください",
    errors: { type: ["登録されていない部品です"] },
  });

  it("断られたら失敗で終え、理由と後始末を言い、記録には failed と残す", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: refused,
      FAKE_IMPORT_PAGE_CODE: "1",
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("2 個目のブロック");
    expect(result.out).toContain("登録されていない部品です");
    expect(result.out).toContain("半端なページは残っていません");
    expect(result.out).not.toContain("ゴミ箱へ入れてから");
    expect(settingCalls(ws)).toEqual([]);

    // ファイルと DB は適用済みなので戻さない。記録は保存し、復旧記録は消す
    expect(
      existsSync(
        join(ws.project, "resources/views/layouts/preserved/home.blade.php"),
      ),
    ).toBe(true);
    expect(existsSync(journalOf(ws))).toBe(false);
    expect(record(ws).pages?.[0]).toMatchObject({
      slug: "home",
      action: "failed",
      id: null,
    });
  });

  it("自分のものと確かめられない未公開ページが在れば失敗し、何度実行しても劣化しない", () => {
    planned(ws);
    expect(
      cli(ws, ["template", "apply"], { FAKE_IMPORT_PAGE: refused, FAKE_IMPORT_PAGE_CODE: "1" }).code,
    ).toBe(1);

    // 🔴 Codex 再現: 2 回目で記録が kept に書き換わり、3 回目は「警告だけの成功」に劣化していた
    for (const nth of [2, 3, 4]) {
      const again = cli(ws, ["template", "apply"], {
        FAKE_IMPORT_PAGE: pageResponse("kept", 42, false),
      });

      expect(again.code, `${nth} 回目: ${again.out}`).toBe(1);
      expect(again.out).toContain("同じ URL のページ（ゴミ箱の中を含む）が既に在り、公開されていません");
      expect(again.out).not.toContain("適用完了");
      expect(again.out).not.toContain("no-op");
      expect(settingCalls(ws)).toEqual([]);
      expect(record(ws).pages?.[0]).toMatchObject({ action: "kept", published: false, ours: false, front_set: false });
    }
  });

  it("前回の結果が unknown のまま未公開で在るページは、そう言って失敗する", () => {
    planned(ws);
    expect(cli(ws, ["template", "apply"], { FAKE_IMPORT_PAGE: "not json at all" }).code).toBe(1);

    const again = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, false),
    });

    expect(again.code).toBe(1);
    expect(again.out).toContain("作られたか確かめられなかったページが未公開のまま在ります");
    expect(record(ws).pages?.[0]).toMatchObject({ action: "kept", ours: false });
  });

  it("復旧記録から apply 記録を組み直しても、ページの結果は落とさない", () => {
    planned(ws);
    expect(
      cli(ws, ["template", "apply"], { FAKE_IMPORT_PAGE: refused, FAKE_IMPORT_PAGE_CODE: "1" }).code,
    ).toBe(1);
    expect(record(ws).pages?.[0]).toMatchObject({ action: "failed", ours: false });

    // db_committed で中断した記録を置く → 次の apply は記録を組み直して終わる（closeout）
    writeFileSync(
      journalOf(ws),
      JSON.stringify({
        version: 1,
        phase: "db_committed",
        started_at: "2026-01-01T00:00:00Z",
        plan_sha256: createHash("sha256")
          .update(readFileSync(join(ws.project, ".agentcms", "manifest", "template-plan.json"), "utf8"))
          .digest("hex"),
        files: [],
        terms: [],
      }),
    );

    const recovered = cli(ws, ["template", "apply"]);

    expect(recovered.code, recovered.out).toBe(0);
    expect(recovered.out).toContain("復旧");
    // 🔴 Codex 再現: closeout が pages 無しで記録を再生成し、failed が消えていた
    expect(record(ws).pages?.[0]).toMatchObject({ slug: "home", action: "failed", ours: false });
  });

  it("失敗のあとに同じ URL のページが公開されていても、自分のものと決めつけてトップにしない", () => {
    planned(ws);
    expect(
      cli(ws, ["template", "apply"], { FAKE_IMPORT_PAGE: refused, FAKE_IMPORT_PAGE_CODE: "1" }).code,
    ).toBe(1);

    // 作成は 1 トランザクションなので、失敗した回にこちらが作ったページは無い。
    // 公開されて在るなら別の経路のもの — トップの指定は運用者に委ねる
    const later = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
    });

    expect(later.code, later.out).toBe(0);
    expect(settingCalls(ws)).toEqual([]);
    expect(later.out).toContain("確かめられないため指定していません");
    expect(record(ws).pages?.[0]).toMatchObject({ action: "kept", published: true, ours: false, front_set: false });
  });

  it("作ると言ったのに公開されていなければ成功にしない", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("created", 42, false),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("公開されていません");
    expect(settingCalls(ws)).toEqual([]);
  });
});

describe("作られたか分からないときは、そう言って止まる", () => {
  it("応答が読めなければ unknown として記録し、失敗で終える", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: "not json at all",
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("作られたか確認できませんでした");
    expect(result.out).not.toContain("作成（");
    expect(record(ws).pages?.[0]).toMatchObject({
      action: "unknown",
      id: null,
    });
  });

  it("exit code と応答が食い違えば信じない", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT_PAGE: pageResponse("created", 42, true),
      FAKE_IMPORT_PAGE_CODE: "1",
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("確認できませんでした");
    expect(settingCalls(ws)).toEqual([]);
  });

  it("トップの指定に失敗したら、/ が静的なままだと言って失敗で終える", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_SETTING_CODE: "1",
      FAKE_SETTING_OUT: "公開されているページを選んでください",
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("公開されているページを選んでください");
    expect(result.out).toContain("静的なトップのまま");
    expect(record(ws).pages?.[0]).toMatchObject({
      action: "created",
      front_set: false,
    });
  });
});

describe("別テンプレートの再取り込みで、既存のページを壊さない", () => {
  /** 1 回目: いまの見本を取り込む（home を作って公開・トップに指定） */
  const firstImport = (): void => {
    planned(ws);
    expect(cli(ws, ["template", "apply"]).code).toBe(0);
    // 1 回目は前回の記録が無いので使用状況は聞かない
    expect(usagePayloads(ws)).toEqual([]);
  };

  /** 原本を別のテンプレートに差し替えて inspect → plan */
  const switchTemplate = (): void => {
    writeFileSync(join(ws.root, "site-template", "index.html"), OTHER_PAGE);
    planned(ws);
  };

  const inUse = usageOf(
    Object.fromEntries(
      ALL_TYPES.map((t) => [t, t.startsWith("imported_home_a") || t.startsWith("imported_home_s") ? [] : ["home"]]),
    ),
    { "layouts.preserved.home": ["home"] },
  );

  it("使われている部品と枠は消さず・書き換えず、記録に残し続ける", () => {
    firstImport();
    const beforeLayout = readFileSync(join(ws.project, "resources/views/layouts/preserved/home.blade.php"), "utf8");
    switchTemplate();

    const result = cli(ws, ["template", "apply"], {
      FAKE_USAGE: inUse,
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
      FAKE_SETTINGS: settingsWith(42),
    });

    expect(result.code, result.out).toBe(0);

    // 問うたのは前回と今回の部品・枠
    const asked = usagePayloads(ws);
    expect(asked).toHaveLength(1);
    expect(asked[0]!.components).toEqual(expect.arrayContaining(["imported_home_home", "imported_home_about"]));
    expect(asked[0]!.layouts).toEqual(["layouts.preserved.home"]);

    // 🔴 Codex 続き ②: 使われている部品は消えない。使われていないものだけ prune される
    expect(existsSync(join(ws.project, "content/components/imported_home_home.json"))).toBe(true);
    expect(existsSync(join(ws.project, "resources/views/components/document/imported-home-home.blade.php"))).toBe(true);
    expect(existsSync(join(ws.project, "content/components/imported_home_concept.json"))).toBe(true);
    expect(result.out).toContain("retain: content/components/imported_home_home.json");

    // 使われている枠は、別テンプレートの枠で書き換えない（kept のページの意匠を差し替えない）
    expect(readFileSync(join(ws.project, "resources/views/layouts/preserved/home.blade.php"), "utf8")).toBe(beforeLayout);
    expect(result.out).toContain("retain: resources/views/layouts/preserved/home.blade.php");

    // 新しいテンプレートの部品は書かれる
    expect(existsSync(join(ws.project, "content/components/imported_home_about.json"))).toBe(true);

    // 記録には残した部品・枠が前回の hash のまま載る（次回も同じ判断ができる）
    const targets = record(ws).files.map((f) => f.target);
    expect(targets).toContain("content/components/imported_home_home.json");
    expect(targets).toContain("resources/views/layouts/preserved/home.blade.php");
    expect(targets).toContain("content/components/imported_home_about.json");
  });

  it("使われていなければ、これまでどおり prune される", () => {
    firstImport();
    switchTemplate();

    const result = cli(ws, ["template", "apply"], {
      FAKE_USAGE: USAGE_NONE,
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
      FAKE_SETTINGS: settingsWith(42),
    });

    expect(result.code, result.out).toBe(0);
    expect(existsSync(join(ws.project, "content/components/imported_home_concept.json"))).toBe(false);
    expect(result.out).toContain("prune(delete): content/components/imported_home_concept.json");
  });

  it("使用状況を確認できなければ、1 バイトも書かずに止まる", () => {
    firstImport();
    switchTemplate();
    const before = snapshot(ws.project);

    const result = cli(ws, ["template", "apply"], { FAKE_USAGE: "not json" });

    expect(result.code).toBe(1);
    expect(result.out).toContain("使われているか確認できないため中止しました");
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(payloads(ws)).toHaveLength(1); // 1 回目の import-page だけ
  });

  it("dry-run でも残すものを言い、書かない", () => {
    firstImport();
    switchTemplate();
    const before = snapshot(ws.project);

    const result = cli(ws, ["template", "apply", "--dry-run"], { FAKE_USAGE: inUse });

    expect(result.code, result.out).toBe(0);
    expect(result.out).toContain("would retain [in use]: resources/views/layouts/preserved/home.blade.php");
    expect(snapshot(ws.project)).toStrictEqual(before);
  });

  it("🔴 使用中のページが在り、資産（CSS / 画像 / JS）が変わる再取り込みは全体を拒否する", () => {
    // 部品と枠だけ残しても、意匠が依存する資産が置き換われば既存ページは壊れる（Codex 続き (1)）
    firstImport();
    writeFileSync(join(ws.root, "site-template", "index.html"), OTHER_ASSETS_PAGE);
    mkdirSync(join(ws.root, "site-template", "css"), { recursive: true });
    writeFileSync(join(ws.root, "site-template", "css", "site.css"), ".about{color:blue}");
    writeFileSync(join(ws.root, "site-template", "images", "hero.jpg"), "jpg");
    rmSync(join(ws.root, "site-template", "images", "logo.png"));
    planned(ws);
    const before = snapshot(ws.project);

    const result = cli(ws, ["template", "apply"], {
      FAKE_USAGE: inUse,
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
      FAKE_SETTINGS: settingsWith(42),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("資産を置き換える再取り込みのため中止しました");
    expect(result.out).toContain("public/imported/images/logo.png（削除）");
    expect(result.out).toContain("public/imported/js/index-inline-1.js（変更）");
    // 1 バイトも書いていない: 旧公開素材（画像・JS・部品・枠）はそのまま
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(existsSync(join(ws.project, "public/imported/images/logo.png"))).toBe(true);
    expect(payloads(ws)).toHaveLength(1); // 1 回目の import-page だけ
  });

  it("使われていなければ、資産が変わる再取り込みも通る", () => {
    firstImport();
    writeFileSync(join(ws.root, "site-template", "index.html"), OTHER_ASSETS_PAGE);
    mkdirSync(join(ws.root, "site-template", "css"), { recursive: true });
    writeFileSync(join(ws.root, "site-template", "css", "site.css"), ".about{color:blue}");
    writeFileSync(join(ws.root, "site-template", "images", "hero.jpg"), "jpg");
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_USAGE: USAGE_NONE,
      FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
      FAKE_SETTINGS: settingsWith(42),
    });

    expect(result.code, result.out).toBe(0);
    expect(existsSync(join(ws.project, "public/imported/images/hero.jpg"))).toBe(true);
  });

  it("復旧記録から記録を組み直しても、残した部品・枠を記録から落とさない", () => {
    firstImport();
    switchTemplate();
    expect(
      cli(ws, ["template", "apply"], {
        FAKE_USAGE: inUse,
        FAKE_IMPORT_PAGE: pageResponse("kept", 42, true),
        FAKE_SETTINGS: settingsWith(42),
      }).code,
    ).toBe(0);
    const retainedBefore = record(ws).files.filter((f) =>
      f.target.includes("imported_home_home") || f.target.includes("layouts/preserved/home"),
    );
    expect(retainedBefore.length).toBeGreaterThan(0);

    writeFileSync(
      journalOf(ws),
      JSON.stringify({
        version: 1,
        phase: "db_committed",
        started_at: "2026-01-01T00:00:00Z",
        plan_sha256: createHash("sha256")
          .update(readFileSync(join(ws.project, ".agentcms", "manifest", "template-plan.json"), "utf8"))
          .digest("hex"),
        files: [],
        terms: [],
      }),
    );

    const recovered = cli(ws, ["template", "apply"]);

    expect(recovered.code, recovered.out).toBe(0);
    expect(recovered.out).toContain("復旧");
    // 🔴 Codex 続き (2): closeout が retained を落とすと、次の prune が「記録に無い」として見なくなる
    const after = record(ws).files;
    for (const f of retainedBefore) {
      expect(after).toContainEqual(f);
    }
  });
});

describe("トップ以外のページもページになり、元の URL は controller を通る（R03 続き (1)）", () => {
  const COMPANY_PAGE = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>会社概要</title><style>.hero{color:red}</style></head><body>
<div class="bg-pattern"></div>
<nav id="navbar"><img src="images/logo.png" alt="logo"><ul><li><a href="index.html">ホーム</a></li><li><a href="company.html">会社</a></li></ul></nav>
<main class="sub">
<section id="about"><h1>会社概要</h1><p>所在地。</p><a href="#top">上へ</a></section>
</main>
<footer class="footer"><small>&copy; みどり造園</small></footer>
<script>document.body.classList.add("ready")</script>
</body></html>`;

  it("2 枚とも import-page へ渡し、company は自分の枠と結び付き、route は Route::view ではない", () => {
    writeFileSync(join(ws.root, "site-template", "company.html"), COMPANY_PAGE);
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      // 2 回呼ばれる。どちらも created として返す（id は同じでも試験の主題ではない）
      FAKE_IMPORT_PAGE: pageResponse("created", 42, true),
    });

    expect(result.code, result.out).toBe(0);

    const sent = payloads(ws);
    expect(sent.map((p) => [p.page.slug, p.page.layout])).toEqual([
      ["home", "layouts.preserved.home"],
      ["company", "layouts.preserved.company"],
    ]);
    // company は main の中身が部品になり、リンクの行き先・文言も項目
    const company = sent[1]!.page.blocks as { type: string; props: Record<string, string> }[];
    expect(company.map((b) => b.type)).toEqual(["imported_company_about"]);
    expect(company[0]!.props["link_1_href"]).toBe("#top");
    expect(company[0]!.props["link_1_text"]).toBe("上へ");

    // front はトップだけ（site-setting は 1 回）
    expect(settingCalls(ws)).toHaveLength(1);

    // 元の URL はページを先に見る controller を通る（Route::view が編集結果を隠さない）
    const routes = readFileSync(join(ws.project, "routes", "imported.php"), "utf8");
    expect(routes).not.toContain("Route::view(");
    expect(routes).toContain("Route::get('/company', ImportedPageController::class)");
    expect(routes).toContain("->defaults('layout', 'layouts.preserved.company')");
    expect(existsSync(join(ws.project, "resources/views/layouts/preserved/company.blade.php"))).toBe(true);
    expect(readFileSync(join(ws.project, "resources/views/layouts/preserved/company.blade.php"), "utf8")).toContain(
      '<main class="sub">',
    );

    expect(record(ws).pages?.map((p) => p.slug)).toEqual(["home", "company"]);
  });
});

describe("ページを起こさない案件では、CMS を呼ばない", () => {
  it("layout モード（同梱見本）では理由を出し、import-page を呼ばない", () => {
    rmSync(ws.root, { recursive: true, force: true });
    ws = setup("green-leaf");
    planned(ws);

    const result = cli(ws, ["template", "apply"]);

    expect(result.code, result.out).toBe(0);
    expect(result.out).toContain("layout モード");
    expect(payloads(ws)).toEqual([]);
    expect(settingCalls(ws)).toEqual([]);
    expect(record(ws).pages).toBeUndefined();
    expect(
      existsSync(
        join(ws.project, "resources/views/layouts/preserved/home.blade.php"),
      ),
    ).toBe(false);
  });
});

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  chmodSync,
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
 * Phase 9.3B / #23 — `template apply` の壊れ方を実際に動かして固定する。
 *
 * ここは **実際の CLI を起動する**テスト。
 * 「戻しました」「作っていません」といった振る舞いは、関数単体では
 * どうとでも書けてしまうので、プロセスを跨いだ結果として確かめる。
 *
 * CMS は Docker の中にいるため、`docker` を差し替えて応答を作る。
 * こちらから見える契約（stdin JSON → stdout の Result JSON）は本物と同じ。
 */

const CLI = resolve(__dirname, "../dist/index.js");
const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

/** 分類項目の一覧応答（既存 0 件） */
const EMPTY_TERMS = JSON.stringify({
  status: "success",
  data: { terms: [] },
});

interface Workspace {
  root: string;
  project: string;
  bin: string;
}

function setup(): Workspace {
  const root = mkdtempSync(join(tmpdir(), "agentcms-apply-"));
  const project = join(root, "project");
  const bin = join(root, "bin");

  cpSync(FIXTURE, join(root, "site-template"), { recursive: true });
  mkdirSync(join(project, "content", "schemas"), { recursive: true });
  // **配布されるのは base だけ。** overrides/ は開発機の状態なので持ち込まない
  // （利用者の新規 project と同じ出発点にする）
  cpSync(SCHEMAS, join(project, "content", "schemas"), {
    recursive: true,
    filter: (src) => !src.includes(`${sep}overrides`),
  });

  // works.category を **分類**として宣言したプロジェクトにする。
  // これで取り込んだカテゴリが add_terms の提案になる（select のままだと選択肢の追加）
  const works = JSON.parse(
    readFileSync(join(project, "content", "schemas", "works.json"), "utf8"),
  ) as {
    fields: { key: string; required?: boolean }[];
    admin?: { list?: string[]; search?: string[] };
    taxonomies?: unknown;
  };
  works.fields = works.fields.filter((f) => f.key !== "category");
  // 管理画面の参照も外す（存在しない field を指したままにしない）
  if (works.admin?.list) {
    works.admin.list = works.admin.list.filter((k) => k !== "category");
  }
  if (works.admin?.search) {
    works.admin.search = works.admin.search.filter((k) => k !== "category");
  }
  works.taxonomies = [{ key: "category", multiple: false, required: false }];
  writeFileSync(
    join(project, "content", "schemas", "works.json"),
    `${JSON.stringify(works, null, 2)}\n`,
  );

  writeFileSync(join(project, "artisan"), "");
  // 本物のプロジェクトには在るファイル。生成物の配線（require 追記）先になる
  mkdirSync(join(project, "routes"), { recursive: true });
  writeFileSync(join(project, "routes", "web.php"), "<?php\n");
  writeFileSync(join(project, "docker-compose.yml"), "services: {}\n");
  writeFileSync(join(project, ".env"), "APP_NAME=apply-test\n");
  writeFileSync(join(project, ".env.example"), "APP_NAME=apply-test\n");
  mkdirSync(join(project, ".agentcms"), { recursive: true });
  execFileSync("git", ["init", "-q"], { cwd: project });

  // docker の差し替え。応答は環境変数で切り替える
  mkdirSync(bin, { recursive: true });
  writeFileSync(
    join(bin, "docker"),
    `#!/bin/sh
case "$*" in
  *agentcms:create-content-type*)
    # 投稿タイプの作成（R-13 Stage 2）。応答は cli() の環境変数が決める
    printf '%s\\n' "$FAKE_CREATE_TYPES"
    exit "\${FAKE_CREATE_TYPES_CODE:-0}"
    ;;
  *agentcms:import-terms*)
    # 応答を返す **前**に、そのときのプロジェクトの状態を外へ書き出す。
    # ここは writeFiles のあとに呼ばれるので、生成物があれば
    # 「ファイルを書いたあとで失敗した」ことの証拠になる
    if [ -n "$MARKER" ]; then
      if [ -f "$WATCH_FILE" ]; then echo written > "$MARKER"; else echo missing > "$MARKER"; fi
    fi
    # 分類項目を作る **その瞬間**に細工する（DB 成功後の失敗を作るため）
    if [ -n "$SABOTAGE_DIR" ]; then mkdir -p "$SABOTAGE_DIR"; fi
    printf '%s\\n' "$FAKE_IMPORT"
    exit "\${FAKE_IMPORT_CODE:-0}"
    ;;
  *)
    printf '%s\\n' "$FAKE_TERM_LIST"
    exit 0
    ;;
esac
`,
    { mode: 0o755 },
  );

  return { root, project, bin };
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
        FAKE_TERM_LIST: EMPTY_TERMS,
        FAKE_IMPORT: JSON.stringify({ status: "success", created: [] }),
        FAKE_CREATE_TYPES: JSON.stringify({
          status: "success",
          created: [],
          skipped: [],
        }),
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

/** inspect → plan まで進めて、提案された分類項目を返す */
function planned(
  ws: Workspace,
): { taxonomy: string; slug: string; name: string }[] {
  expect(cli(ws, ["template", "inspect", "../site-template"]).code).toBe(0);
  expect(cli(ws, ["template", "plan"]).code).toBe(0);

  const plan = JSON.parse(
    readFileSync(
      join(ws.project, ".agentcms", "manifest", "template-plan.json"),
      "utf8",
    ),
  ) as {
    version: number;
    taxonomy_changes: {
      taxonomy: string;
      proposed_slug: string | null;
      proposed_name: string;
      match: { kind: string };
    }[];
  };

  expect(plan.version).toBe(3);

  return plan.taxonomy_changes
    .filter((c) => c.match.kind === "absent" && c.proposed_slug !== null)
    .map((c) => ({
      taxonomy: c.taxonomy,
      slug: c.proposed_slug as string,
      name: c.proposed_name,
    }));
}

const journalOf = (ws: Workspace) =>
  join(ws.project, ".agentcms", "manifest", "template-apply-journal.json");

/** プロジェクト内のファイル内容の一覧（byte 比較用） */
function snapshot(dir: string, base = dir): Record<string, string> {
  const out: Record<string, string> = {};

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === ".git") continue;

    const path = join(dir, entry.name);

    if (entry.isDirectory()) {
      Object.assign(out, snapshot(path, base));
    } else if (entry.isFile()) {
      out[path.slice(base.length + 1)] = readFileSync(path).toString("base64");
    }
  }

  return out;
}

/** いまの Plan の hash（journal の plan_sha256 と同じ計算） */
function planHash(ws: Workspace): string {
  return createHash("sha256")
    .update(
      readFileSync(
        join(ws.project, ".agentcms", "manifest", "template-plan.json"),
        "utf8",
      ),
    )
    .digest("hex");
}

/** 中断した記録を置く（files は **実際の byte** を退避する） */
function putJournal(
  ws: Workspace,
  phase: string,
  terms: { taxonomy: string; slug: string; name: string }[],
  planSha256 = planHash(ws),
  targets = ["content/schemas/works.json"],
): void {
  mkdirSync(join(ws.project, ".agentcms", "manifest"), { recursive: true });
  writeFileSync(
    journalOf(ws),
    JSON.stringify({
      version: 1,
      phase,
      started_at: "2026-01-01T00:00:00Z",
      plan_sha256: planSha256,
      files: targets.map((target) => ({
        target,
        content: readFileSync(join(ws.project, target)).toString("base64"),
      })),
      terms,
    }),
  );
}

/** 既存の分類項目一覧を返す応答 */
const termsResponse = (
  terms: { taxonomy: string; slug: string; name: string }[],
  archived = false,
) =>
  JSON.stringify({
    status: "success",
    data: {
      terms: terms.map((t, i) => ({
        id: `t${i}`,
        name: t.name,
        slug: t.slug,
        archived,
      })),
    },
  });

let ws: Workspace;

beforeEach(() => {
  expect(
    existsSync(CLI),
    "dist が必要です（pnpm -C packages/cli build を先に実行）",
  ).toBe(true);
  ws = setup();
});

describe("分類項目の作成に失敗したときの後始末", () => {
  it("CMS が失敗を返したら（巻き戻り確定）ファイルを戻し、記録も残さない", () => {
    const terms = planned(ws);
    expect(terms.length).toBeGreaterThan(0);

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT: JSON.stringify({
        status: "validation_error",
        message: "slug が重複しています",
      }),
      FAKE_IMPORT_CODE: "1",
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("元へ戻しました");

    // ファイルは 1 バイトも変わっていない
    expect(snapshot(ws.project)).toStrictEqual(before);
    // 記録も残さない（残すと次回の復旧が「commit 済み」と誤読する）
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("作成できたか分からないときは、ファイルも記録も触らずに止まる", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], {
      // 応答が壊れている = commit されたかどうか分からない
      FAKE_IMPORT: "not json at all",
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("確認できませんでした");
    // **戻さない。** 戻すと「DB だけ残る」状態を自分で作る
    expect(result.out).not.toContain("元へ戻しました");

    // 書いたファイルはそのまま残り、記録も残る（次回が実状態で判断する）
    expect(
      existsSync(
        join(ws.project, "resources/views/layouts/imported.blade.php"),
      ),
    ).toBe(true);
    expect(existsSync(journalOf(ws))).toBe(true);

    const journal = JSON.parse(readFileSync(journalOf(ws), "utf8")) as {
      phase: string;
    };
    // 型の新設が無い実行でも phase は types_created まで進む（作る型が 0 件だっただけ）
    expect(journal.phase).toBe("types_created");
  });

  it("プロセスを起動できないときも「作っていない」と断言しない", () => {
    planned(ws);

    const result = cli(ws, ["template", "apply"], { PATH: "/nonexistent" });

    expect(result.code).toBe(1);
    expect(result.out).not.toContain("作成されていません");
  });
});

describe("ファイルの変更に失敗したときの後始末", () => {
  it("戻せない対象があるときは、変更を始めない", () => {
    planned(ws);

    // routes/web.php をディレクトリにする = 退避も復元もできない対象
    rmSync(join(ws.project, "routes", "web.php"), { force: true });
    mkdirSync(join(ws.project, "routes", "web.php"), { recursive: true });

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply"]);

    // 戻せないと分かった時点で、**1 つも変更せずに**止まる
    expect(result.code).toBe(1);
    expect(result.out).toContain("何も変更していません");
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("ファイルを書いたあとで失敗したら、書けたぶんも元へ戻す", () => {
    planned(ws);

    const layout = join(
      ws.project,
      "resources",
      "views",
      "layouts",
      "imported.blade.php",
    );
    // 記録先は **プロジェクトの外**。復元の対象にならないので証拠が残る
    const marker = join(ws.root, "marker.txt");

    expect(existsSync(layout)).toBe(false);

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply"], {
      MARKER: marker,
      WATCH_FILE: layout,
      // CMS が構造化された失敗を返す = DB は巻き戻り確定
      FAKE_IMPORT: JSON.stringify({
        status: "validation_error",
        message: "slug が重複しています",
      }),
      FAKE_IMPORT_CODE: "1",
    });

    // **失敗した時点でファイルは書かれていた**（これが部分適用の証拠）
    expect(readFileSync(marker, "utf8").trim()).toBe("written");

    expect(result.code).toBe(1);
    expect(result.out).toContain("元へ戻しました");

    // それでも最終状態は 1 バイトも変わっていない
    expect(existsSync(layout)).toBe(false);
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("生成の途中で書けなくなったときも byte 単位で元へ戻す", () => {
    planned(ws);

    const seeders = join(ws.project, "database", "seeders");
    mkdirSync(seeders, { recursive: true });
    chmodSync(seeders, 0o555);

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply"]);

    chmodSync(seeders, 0o755);

    expect(result.code).toBe(1);
    expect(result.out).toContain("元へ戻しました");
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("失敗せずに実行すれば同じファイルが生成される（上の順序の裏取り）", () => {
    const terms = planned(ws);

    expect(
      cli(ws, ["template", "apply"], {
        FAKE_IMPORT: JSON.stringify({
          status: "success",
          created: terms.map((t, i) => ({
            id: `t${i}`,
            taxonomy: t.taxonomy,
            name: t.name,
            slug: t.slug,
          })),
        }),
      }).code,
    ).toBe(0);
    expect(
      existsSync(
        join(ws.project, "resources", "views", "layouts", "imported.blade.php"),
      ),
    ).toBe(true);
  });
});

describe("前回の中断を片づけてから進む", () => {
  it("dry-run では復旧しない（1 バイトも書かない）", () => {
    planned(ws);

    // 中断した記録を置く
    const journal = {
      version: 1,
      phase: "files_written",
      started_at: "2026-01-01T00:00:00Z",
      plan_sha256: "other-plan",
      files: [{ target: "content/schemas/works.json", content: null }],
      terms: [],
    };
    mkdirSync(join(ws.project, ".agentcms", "manifest"), { recursive: true });
    writeFileSync(journalOf(ws), JSON.stringify(journal));

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply", "--dry-run"]);

    expect(result.code).toBe(1);
    expect(result.out).toContain("dry-run では復旧しません");
    // 記録も含めて 1 バイトも変わらない
    expect(snapshot(ws.project)).toStrictEqual(before);
  });

  it("復旧したら、その実行では続けずに再実行を促す", () => {
    planned(ws);

    // DB を呼ぶ前に落ちた記録（prepared）。作る予定だった項目は存在しない
    const terms = planned(ws);
    const journal = {
      version: 1,
      phase: "prepared",
      started_at: "2026-01-01T00:00:00Z",
      plan_sha256: "whatever",
      files: [{ target: "content/schemas/works.json", content: null }],
      terms,
    };
    writeFileSync(journalOf(ws), JSON.stringify(journal));

    const result = cli(ws, ["template", "apply"]);

    expect(result.code).toBe(0);
    expect(result.out).toContain("もう一度");
    // 復旧が終われば記録は残らない
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("別の Plan の中断は、いまの Plan の記録で締めない", () => {
    const terms = planned(ws);

    // 「作る予定だった項目が全部ある」= commit 済みに見える状態を作る
    const journal = {
      version: 1,
      phase: "files_written",
      started_at: "2026-01-01T00:00:00Z",
      plan_sha256: "a-different-plan",
      files: [],
      terms,
    };
    mkdirSync(join(ws.project, ".agentcms", "manifest"), { recursive: true });
    writeFileSync(journalOf(ws), JSON.stringify(journal));

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: JSON.stringify({
        status: "success",
        data: {
          terms: terms.map((t, i) => ({
            id: `t${i}`,
            name: t.name,
            slug: t.slug,
            archived: false,
          })),
        },
      }),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("別の Plan");
    // 判断できないので記録は残す
    expect(existsSync(journalOf(ws))).toBe(true);
  });
});

describe("成功したときの状態", () => {
  it("分類項目を作り、記録を残し、復旧記録は消す", () => {
    const terms = planned(ws);

    const result = cli(ws, ["template", "apply"], {
      FAKE_IMPORT: JSON.stringify({
        status: "success",
        created: terms.map((t, i) => ({
          id: `t${i}`,
          taxonomy: t.taxonomy,
          name: t.name,
          slug: t.slug,
        })),
      }),
    });

    expect(result.code).toBe(0);
    expect(existsSync(journalOf(ws))).toBe(false);
    expect(
      existsSync(
        join(ws.project, ".agentcms", "manifest", "template-apply.json"),
      ),
    ).toBe(true);

    // base schema は 1 バイトも変わっていない（override へ書く）
    const base = readFileSync(
      join(ws.project, "content", "schemas", "news.json"),
      "utf8",
    );
    expect(base).toBe(readFileSync(join(SCHEMAS, "news.json"), "utf8"));
  });

  it("同じ Plan の再適用では分類項目を作り直さない", () => {
    const terms = planned(ws);
    const created = terms.map((t, i) => ({
      id: `t${i}`,
      taxonomy: t.taxonomy,
      name: t.name,
      slug: t.slug,
    }));

    expect(
      cli(ws, ["template", "apply"], {
        FAKE_IMPORT: JSON.stringify({ status: "success", created }),
      }).code,
    ).toBe(0);

    // 2 回目: 既に作られている状態を返す。作成要求が飛べば失敗する応答にしておく
    const again = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: JSON.stringify({
        status: "success",
        data: {
          terms: created.map((c) => ({
            id: c.id,
            name: c.name,
            slug: c.slug,
            archived: false,
          })),
        },
      }),
      FAKE_IMPORT: JSON.stringify({
        status: "conflict",
        message: "作ってはいけない",
      }),
      FAKE_IMPORT_CODE: "1",
    });

    expect(again.code).toBe(0);
    expect(again.out).toContain("適用済み");
  });

  it("アーカイブ済みと衝突したら、何も変更せずに止まる", () => {
    const terms = planned(ws);
    const before = snapshot(ws.project);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: JSON.stringify({
        status: "success",
        data: {
          terms: [
            {
              id: "t0",
              name: terms[0]?.name ?? "x",
              slug: terms[0]?.slug ?? "x",
              archived: true,
            },
          ],
        },
      }),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("アーカイブ済み");
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(existsSync(journalOf(ws))).toBe(false);
  });
});

/**
 * **生成した物が配線されるまでを 1 つの実行で見る**（監査 指摘 34）。
 *
 * `routes/imported.php` を作ることと、`web.php` からそれを読ませることは、
 * 以前は **別々に動けた**（生成側が真偽値を返し、apply がそれを見る形）。
 * 真偽値だけを false にしてもファイルは作られるので試験は全緑のまま、
 * 利用者の手元でだけ「route を呼んでいるのに登録されていない」例外になる。
 *
 * ここは**利用者の形**（`works` / `news` を持たないプロジェクト）で動かす。
 * kit の形では works.html が module のページになるので、この経路自体を通らない。
 */
/**
 * **利用者のプロジェクトへ、投稿タイプごと持ち込む**（R-13 Stage 2）。
 *
 * Stage 1 は「受け皿が無いので取り込まない」だった。取り込めないと、
 * 利用者は実績もお知らせも**CMS で編集できない**。
 * Stage 2 は plan に「作る型」を書き、apply がそれを作ってから取り込む。
 *
 * 型の作成は**巻き戻さない**（migration を伴う不可逆な変更）。
 * だから見るのは「作れたか」だけでなく、**途中で止まったときに何と言うか**である。
 */
describe("受け皿の投稿タイプごと持ち込む", () => {
  let ws: Workspace;

  beforeEach(() => {
    ws = setup();

    // 配布境界の向こう側にする（生成プロジェクトが持つのは contact/media/post/site）
    for (const id of ["works", "news", "product"]) {
      rmSync(join(ws.project, "content", "schemas", `${id}.json`), {
        force: true,
      });
    }
  });

  afterEach(() => {
    rmSync(ws.root, { recursive: true, force: true });
  });

  it("plan に作る型が出て、apply がそれを作る", () => {
    planned(ws);

    const plan = JSON.parse(
      readFileSync(
        join(ws.project, ".agentcms", "manifest", "template-plan.json"),
        "utf8",
      ),
    ) as { content_types: { id: string; label: string; reason: string }[] };

    expect(plan.content_types.map((t) => t.id).sort()).toStrictEqual([
      "news",
      "works",
    ]);

    for (const type of plan.content_types) {
      // 承認する人は「ID を変えられない」ことを知ったうえで承認する
      expect(type.reason).toContain("変更できません");
    }

    const applied = cli(ws, ["template", "apply"], {
      FAKE_CREATE_TYPES: JSON.stringify({
        status: "success",
        created: ["works", "news"],
        skipped: [],
      }),
    });

    expect(applied.code, applied.out).toBe(0);
    expect(applied.out).toContain("content type: [works] を作成");
    expect(applied.out).toContain("content type: [news] を作成");
    expect(existsSync(journalOf(ws))).toBe(false); // 完了して記録は消える

    /*
     * **作った回は、もう一度 plan を回してもらう**。
     * 型が無い時点では「選択肢を足す」提案を出せないので、
     * 言わないと**取り込んだ値が選択肢に無いまま**残る。
     */
    expect(applied.out).toContain("agentcms template plan");
    expect(applied.out).toContain("works, news");
  });

  it("途中で止まったら、作った型は戻さず「中身は未投入」と言う", () => {
    planned(ws);

    const applied = cli(ws, ["template", "apply"], {
      FAKE_CREATE_TYPES: JSON.stringify({
        status: "conflict",
        message: "テーブルを作成できませんでした",
        created: ["works"],
        skipped: [],
      }),
      FAKE_CREATE_TYPES_CODE: "1",
    });

    expect(applied.code).toBe(1);
    expect(applied.out).toContain("作ったものは戻しません");
    expect(applied.out).toContain("作成済み: works（中身は未投入）");

    // **ファイルは戻さない。** 戻すと「型だけ在って置き場が無い」を自分で作る
    expect(
      existsSync(
        join(ws.project, "resources", "views", "layouts", "imported.blade.php"),
      ),
      "ファイルまで巻き戻している",
    ).toBe(true);
  });

  it("止まった状態から再実行すると、既に在る型を飛ばして続きから進む", () => {
    planned(ws);

    cli(ws, ["template", "apply"], {
      FAKE_CREATE_TYPES: JSON.stringify({
        status: "conflict",
        message: "テーブルを作成できませんでした",
        created: ["works"],
        skipped: [],
      }),
      FAKE_CREATE_TYPES_CODE: "1",
    });

    // 1 回目の再実行は「片づけ」。**ファイルは戻さず**、記録だけ捨てる
    const cleanup = cli(ws, ["template", "apply"], {
      FAKE_CREATE_TYPES: JSON.stringify({
        status: "success",
        created: [],
        skipped: ["works"],
      }),
    });

    expect(cleanup.out).toContain("作った投稿タイプは残し（中身は未投入）");
    expect(cleanup.out).not.toContain("元へ戻しました");

    const again = cli(ws, ["template", "apply"], {
      FAKE_CREATE_TYPES: JSON.stringify({
        status: "success",
        created: ["news"],
        skipped: ["works"],
      }),
    });

    expect(again.code, again.out).toBe(0);
    expect(again.out).toContain("content type: [works] は既に在ります");
    expect(again.out).toContain("content type: [news] を作成");
  });
});

describe("生成した route ファイルは、必ず web.php から読まれる", () => {
  let ws: Workspace;

  beforeEach(() => {
    ws = setup();

    /*
     * **標準 5 ページ規約の外にあるページ**を 1 枚足す（R-13 Stage 2 以降）。
     *
     * Stage 1 までは「受け皿の型が無い works.html」が汎用 route へ落ちたので、
     * それがこの検査の題材だった。Stage 2 では works は**型ごと持ち込む**ようになり、
     * 公開面は fallback が受ける（名前付き route を作らない）。
     * 汎用 route が出るのは、いまは**規約外のページ**だけである。
     */
    writeFileSync(
      join(ws.root, "site-template", "company.html"),
      readFileSync(join(ws.root, "site-template", "about.html"), "utf8"),
      "utf8",
    );
  });

  afterEach(() => {
    rmSync(ws.root, { recursive: true, force: true });
  });

  /** 分類項目の作成は、要求どおり全部できたことにする（ここの主題ではない） */
  const applyWithTerms = (): { code: number; out: string } => {
    const terms = planned(ws);

    return cli(ws, ["template", "apply"], {
      FAKE_IMPORT: JSON.stringify({
        status: "success",
        created: terms.map((t, i) => ({
          id: `t${i}`,
          taxonomy: t.taxonomy,
          name: t.name,
          slug: t.slug,
        })),
      }),
    });
  };

  it("汎用ページへ落ちた route を生成し、同じ実行で require を足す", () => {
    const applied = applyWithTerms();

    expect(applied.code, applied.out).toBe(0);

    // 型を作らなかった回に、再 plan を勧めない（要らない手順を増やさない）
    expect(applied.out).not.toContain("agentcms template plan");

    const imported = join(ws.project, "routes", "imported.php");

    expect(existsSync(imported), "汎用 route が生成されていない").toBe(true);
    expect(readFileSync(imported, "utf8")).toContain("->name('company')");

    expect(
      readFileSync(join(ws.project, "routes", "web.php"), "utf8"),
      "生成したのに web.php から読まれていない（片肺）",
    ).toContain("require __DIR__.'/imported.php';");
  });

  it("require を人が消しても、再適用で戻る（書き直しの有無に依らない）", () => {
    expect(applyWithTerms().code).toBe(0);

    const webPhp = join(ws.project, "routes", "web.php");

    writeFileSync(webPhp, "<?php\n", "utf8");
    expect(applyWithTerms().code).toBe(0);

    expect(readFileSync(webPhp, "utf8")).toContain(
      "require __DIR__.'/imported.php';",
    );
  });
});

describe("読み取り経路は書き込まない", () => {
  it("plan と dry-run はプロジェクトの中身を変えない", () => {
    expect(cli(ws, ["template", "inspect", "../site-template"]).code).toBe(0);

    const before = snapshot(ws.project);

    expect(cli(ws, ["template", "plan"]).code).toBe(0);

    // plan が書くのは .agentcms/manifest と docs だけ
    const afterPlan = snapshot(ws.project);
    for (const [path, content] of Object.entries(before)) {
      expect(afterPlan[path], path).toBe(content);
    }

    const beforeDryRun = snapshot(ws.project);
    expect(cli(ws, ["template", "apply", "--dry-run"]).code).toBe(0);
    expect(snapshot(ws.project)).toStrictEqual(beforeDryRun);
  });
});

/** テストが失敗しても一時ディレクトリを溜めない */
afterEach(() => {
  if (ws?.root) rmSync(ws.root, { recursive: true, force: true });
});

describe("中断した phase ごとの振る舞い", () => {
  it("files_written + 予定した項目が全部ある → 適用済みとして記録を締める", () => {
    const terms = planned(ws);
    putJournal(ws, "files_written", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: termsResponse(terms),
    });

    expect(result.code).toBe(0);
    expect(result.out).toContain("適用済みでした");
    expect(existsSync(journalOf(ws))).toBe(false);
    expect(
      existsSync(
        join(ws.project, ".agentcms", "manifest", "template-apply.json"),
      ),
    ).toBe(true);
  });

  it("files_written + 1 件も無い → ファイルを戻す", () => {
    const terms = planned(ws);
    putJournal(ws, "files_written", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: EMPTY_TERMS,
    });

    expect(result.code).toBe(0);
    expect(result.out).toContain("元へ戻しました");
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("files_written + 一部だけある → 自動で判断せず止める", () => {
    const terms = planned(ws);

    // 「一部だけ」を作るには 2 件以上の提案が要る
    expect(terms.length).toBeGreaterThanOrEqual(2);

    putJournal(ws, "files_written", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: termsResponse(terms.slice(0, 1)),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("件だけが存在します");
    // 判断できないので記録は残す
    expect(existsSync(journalOf(ws))).toBe(true);
  });

  it("files_written + アーカイブ済み → 止める", () => {
    const terms = planned(ws);
    putJournal(ws, "files_written", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: termsResponse(terms, true),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("アーカイブ済み");
    expect(existsSync(journalOf(ws))).toBe(true);
  });

  it("prepared + 項目が存在する → DB を呼ぶ前なので外部の作成として止める", () => {
    const terms = planned(ws);
    putJournal(ws, "prepared", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: termsResponse(terms),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("別の経路で作られた");
    expect(existsSync(journalOf(ws))).toBe(true);
  });

  it("prepared + 項目が無い → 実 byte へ戻す", () => {
    const terms = planned(ws);
    // schema を壊すと定義の読み取りで先に止まるので、定義以外のファイルで確かめる
    const target = ".env.example";
    const original = readFileSync(join(ws.project, target));

    putJournal(ws, "prepared", terms, planHash(ws), [target]);

    // 中断後に書き換えられた状態を作る
    writeFileSync(join(ws.project, target), "APP_NAME=broken\n");

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: EMPTY_TERMS,
    });

    expect(result.code).toBe(0);
    // **記録した byte そのもの**へ戻る
    expect(readFileSync(join(ws.project, target))).toStrictEqual(original);
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("db_committed → 記録を締めるだけ", () => {
    const terms = planned(ws);
    putJournal(ws, "db_committed", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: termsResponse(terms),
    });

    expect(result.code).toBe(0);
    expect(result.out).toContain("適用済みでした");
    expect(existsSync(journalOf(ws))).toBe(false);
  });

  it("recorded → 消し忘れなので捨てる", () => {
    const terms = planned(ws);
    putJournal(ws, "recorded", terms);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: EMPTY_TERMS,
    });

    expect(result.code).toBe(0);
    expect(existsSync(journalOf(ws))).toBe(false);
  });
});

describe("復元できない対象があるときは記録を残す", () => {
  it(".env を書き戻せないなら、戻せなかった対象を挙げて記録を保持する", () => {
    planned(ws);

    // .env を読み取り専用にすると、env 追記も復元も失敗する
    chmodSync(join(ws.project, ".env"), 0o444);

    const result = cli(ws, ["template", "apply"]);

    chmodSync(join(ws.project, ".env"), 0o644);

    expect(result.code).toBe(1);
    expect(result.out).toContain(".env");
    expect(result.out).toContain("戻せませんでした");
    // 人が判断できるよう記録は残す
    expect(existsSync(journalOf(ws))).toBe(true);
  });
});

describe("DB 成功後に記録を保存できなかったとき", () => {
  it("ファイルは戻さず、記録を残したまま止める", () => {
    const terms = planned(ws);
    const record = join(
      ws.project,
      ".agentcms",
      "manifest",
      "template-apply.json",
    );
    const layout = join(
      ws.project,
      "resources",
      "views",
      "layouts",
      "imported.blade.php",
    );

    const result = cli(ws, ["template", "apply"], {
      // 分類項目を作った直後に、適用記録の置き場をディレクトリにして書けなくする
      SABOTAGE_DIR: record,
      FAKE_IMPORT: JSON.stringify({
        status: "success",
        created: terms.map((t, i) => ({
          id: `t${i}`,
          taxonomy: t.taxonomy,
          name: t.name,
          slug: t.slug,
        })),
      }),
    });

    expect(result.code).toBe(1);
    expect(result.out).toContain("適用記録を保存できませんでした");
    // **戻さない。** 分類項目は作られているので、戻すと「DB だけ残る」状態になる
    expect(result.out).not.toContain("元へ戻しました");
    expect(existsSync(layout)).toBe(true);

    // 次回の apply が締め直せるよう、記録は db_committed のまま残す
    const journal = JSON.parse(readFileSync(journalOf(ws), "utf8")) as {
      phase: string;
    };
    expect(journal.phase).toBe("db_committed");
  });
});

describe("prune が失敗したとき", () => {
  it("旧生成物を消せなかったら、その実行の変更を元へ戻す", () => {
    planned(ws);

    // 前回 apply の記録を作る。中身は「消される予定の旧生成物」
    const stale = "resources/views/old-page.blade.php";
    const abs = join(ws.project, stale);
    mkdirSync(join(abs, ".."), { recursive: true });
    writeFileSync(abs, "old");

    mkdirSync(join(ws.project, ".agentcms", "manifest"), { recursive: true });
    writeFileSync(
      join(ws.project, ".agentcms", "manifest", "template-apply.json"),
      JSON.stringify({
        version: 1,
        manifest_fingerprint: "x",
        plan_sha256: "y",
        applied_at: "2026-01-01T00:00:00Z",
        files: [
          {
            target: stale,
            sha256: createHash("sha256").update("old").digest("hex"),
            source: "s",
          },
        ],
      }),
    );

    // 親ディレクトリを read-only にして削除を失敗させる
    chmodSync(join(ws.project, "resources", "views"), 0o555);

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply"]);

    chmodSync(join(ws.project, "resources", "views"), 0o755);

    expect(result.code).toBe(1);
    expect(result.out).toContain("元へ戻しました");
    expect(snapshot(ws.project)).toStrictEqual(before);
    // 旧生成物も消えていない
    expect(readFileSync(abs, "utf8")).toBe("old");
  });
});

describe("contact の server constant 適用が失敗したとき", () => {
  it("全 byte 元へ戻し、記録も残さない", () => {
    planned(ws);

    // Plan に contact の server constant を足す。
    // 適用先の ContactController はこの一時プロジェクトに存在しないので、
    // **ファイル生成のあと**に確実に失敗する
    const planPath = join(
      ws.project,
      ".agentcms",
      "manifest",
      "template-plan.json",
    );
    const plan = JSON.parse(readFileSync(planPath, "utf8")) as {
      cms_integrations: {
        id: string;
        server_constants: { key: string; value: string; note: string }[];
      }[];
    };
    const contact = plan.cms_integrations.find((i) => i.id === "contact-form");

    expect(contact, "contact-form 統合が Plan にありません").toBeTruthy();

    contact!.server_constants = [
      { key: "source", value: "imported", note: "テスト用" },
    ];
    writeFileSync(planPath, `${JSON.stringify(plan, null, 2)}\n`);

    const before = snapshot(ws.project);
    const result = cli(ws, ["template", "apply"]);

    expect(result.code).toBe(1);
    expect(result.out).toContain("元へ戻しました");
    expect(snapshot(ws.project)).toStrictEqual(before);
    expect(existsSync(journalOf(ws))).toBe(false);
  });
});

describe("分類項目を 1 件も作らなかった実行", () => {
  /** 既存項目をそのまま返す（作成 0 件になる） */
  const reuseAll = (
    terms: { taxonomy: string; slug: string; name: string }[],
  ) => termsResponse(terms);

  it("記録の保存に失敗しても、ファイルは残し phase は db_committed になる", () => {
    const terms = planned(ws);
    const record = join(
      ws.project,
      ".agentcms",
      "manifest",
      "template-apply.json",
    );
    const layout = join(
      ws.project,
      "resources",
      "views",
      "layouts",
      "imported.blade.php",
    );

    // 1 回目: 正常に適用して記録を作る（次の実行が「適用済み」と判断できる状態）
    expect(
      cli(ws, ["template", "apply"], {
        FAKE_IMPORT: JSON.stringify({
          status: "success",
          created: terms.map((t, i) => ({
            id: `t${i}`,
            taxonomy: t.taxonomy,
            name: t.name,
            slug: t.slug,
          })),
        }),
      }).code,
    ).toBe(0);

    // 2 回目: 既存項目があるので **作成 0 件**。そのうえで記録を書けなくする
    chmodSync(record, 0o444);

    const result = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: reuseAll(terms),
    });

    chmodSync(record, 0o644);

    expect(result.code).toBe(1);
    expect(result.out).toContain("適用記録を保存できませんでした");
    // ファイルは適用済みのまま残す
    expect(existsSync(layout)).toBe(true);

    // **db_committed** まで進んでいること。files_written のままだと、
    // 次回の復旧が「DB 未確定」と読み違えてファイルを巻き戻す
    const journal = JSON.parse(readFileSync(journalOf(ws), "utf8")) as {
      phase: string;
      terms: unknown[];
    };
    expect(journal.phase).toBe("db_committed");
    expect(journal.terms).toStrictEqual([]);
  });

  it("その状態から再実行すると、ファイルを保ったまま記録を締める", () => {
    const terms = planned(ws);
    const layout = join(
      ws.project,
      "resources",
      "views",
      "layouts",
      "imported.blade.php",
    );

    expect(
      cli(ws, ["template", "apply"], {
        FAKE_IMPORT: JSON.stringify({
          status: "success",
          created: terms.map((t, i) => ({
            id: `t${i}`,
            taxonomy: t.taxonomy,
            name: t.name,
            slug: t.slug,
          })),
        }),
      }).code,
    ).toBe(0);

    // 「作成 0 件で記録だけ落ちた」状態を再現する
    putJournal(ws, "db_committed", []);
    const applied = readFileSync(layout);

    const again = cli(ws, ["template", "apply"], {
      FAKE_TERM_LIST: reuseAll(terms),
    });

    expect(again.code).toBe(0);
    expect(again.out).toContain("適用済みでした");
    // **巻き戻さない。** 適用済みのファイルはそのまま
    expect(readFileSync(layout)).toStrictEqual(applied);
    expect(existsSync(journalOf(ws))).toBe(false);
  });
});

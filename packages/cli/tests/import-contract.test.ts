import { execFileSync } from "node:child_process";
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

import { beforeEach, describe, expect, it, vi } from "vitest";

const runMock = vi.hoisted(() => vi.fn());

vi.mock("../src/run.js", () => ({
  run: runMock,
  runCapture: vi.fn(),
}));

import {
  isSafeProjectTarget,
  readJournal,
  restoreFromJournal,
  rollbackJournal,
  SchemaWriteError,
  symlinkOffenders,
  writeJournal,
  writeJsonAtomically,
  writeSchemaChoicesOverride,
  writeSchemaOverride,
  type ApplyJournal,
} from "../src/importer/apply.js";
import {
  canonicalSlugCandidate,
  classifyValueTarget,
  matchTerm,
  validatePlanChanges,
} from "../src/importer/plan-contract.js";
import type { ContentSchema } from "../src/importer/plan-types.js";
import { preflightTerms } from "../src/commands/template-apply.js";
import {
  loadProjectSchemas,
  SchemaContractError,
} from "../src/importer/project-schemas.js";
import {
  createTerms,
  fetchTaxonomyCatalog,
} from "../src/importer/term-bridge.js";

const REPO = resolve(__dirname, "..", "..", "..");

function tempDir(): string {
  return mkdtempSync(join(tmpdir(), "agentcms-import-"));
}

function project(files: Record<string, string>): string {
  const root = tempDir();

  for (const [path, content] of Object.entries(files)) {
    const abs = join(root, path);
    mkdirSync(join(abs, ".."), { recursive: true });
    writeFileSync(abs, content);
  }

  return root;
}

/**
 * Phase 9.3B / #23 — import の contract。
 *
 * 「値の行き先は schema が決める」「読めないものを無いことにしない」
 * 「変更は必ず戻せる形で始める」の 3 つを固定する。
 */
describe("値の行き先は schema contract が決める", () => {
  const select: ContentSchema = {
    id: "works",
    fields: [{ key: "category", type: "select", choices: ["web"] }],
  };
  const taxonomy: ContentSchema = {
    id: "works",
    fields: [{ key: "title", type: "text" }],
    taxonomies: [{ key: "category", multiple: true, required: false }],
  };

  it("同じ key でも select なら選択肢、宣言があれば分類になる", () => {
    // 名前が同じ「category」でも、contract が違えば行き先が変わる
    expect(classifyValueTarget(select, "category").kind).toBe("select");
    expect(classifyValueTarget(taxonomy, "category").kind).toBe("taxonomy");
  });

  it("field も宣言も無い key は推測しない", () => {
    expect(classifyValueTarget(select, "genre").kind).toBe("unknown");
    // select 以外の型も選択肢を持たない
    expect(classifyValueTarget(taxonomy, "title").kind).toBe("unknown");
    // schema そのものが無い場合も推測しない
    expect(classifyValueTarget(undefined, "category").kind).toBe("unknown");
  });

  it("field と分類の両方で宣言されていたら人間の判断へ渡す", () => {
    const both: ContentSchema = {
      id: "works",
      fields: [{ key: "category", type: "select", choices: [] }],
      taxonomies: [{ key: "category", multiple: false, required: false }],
    };

    expect(classifyValueTarget(both, "category").kind).toBe("unknown");
  });

  it("CMS 側の長さ上限（120）を超える slug は提案しない", () => {
    // ここで通すと「CONFIDENT な提案」として承認され、apply で必ず失敗する
    expect(canonicalSlugCandidate("a".repeat(120))).toBe("a".repeat(120));
    expect(canonicalSlugCandidate("a".repeat(121))).toBeNull();
  });

  it("canonical slug を一意に決められない値は null（ランダムに作らない）", () => {
    expect(canonicalSlugCandidate("Press Release")).toBe("press-release");
    expect(canonicalSlugCandidate("EC")).toBe("ec");
    expect(canonicalSlugCandidate("お知らせ")).toBeNull();
    expect(canonicalSlugCandidate("news!")).toBeNull();
    expect(canonicalSlugCandidate("  ")).toBeNull();
  });
});

describe("実プロジェクトの contract を読む", () => {
  it("kit の schema から news_category を分類として読み取れる", () => {
    // fixture ではなく **配布している実物**を読む
    const schemas = loadProjectSchemas(join(REPO, "template"));

    expect(classifyValueTarget(schemas["news"], "news_category").kind).toBe(
      "taxonomy",
    );
    // works.category は select のまま（この Stage で taxonomy 化しない）
    expect(classifyValueTarget(schemas["works"], "category").kind).toBe(
      "select",
    );
  });

  it("override を重ねた結果を contract とする", () => {
    const root = project({
      "content/schemas/works.json": JSON.stringify({
        id: "works",
        fields: [{ key: "category", type: "select", choices: ["web"] }],
      }),
      "content/schemas/overrides/works.json": JSON.stringify({
        id: "works",
        fields: { category: { choices: ["web", "EC"] } },
        taxonomies: [{ key: "genre", multiple: true, required: false }],
      }),
    });

    const schemas = loadProjectSchemas(root);
    const target = classifyValueTarget(schemas["works"], "category");

    // override 済みの選択肢が「いま効いている contract」
    expect(target.kind === "select" && target.choices).toStrictEqual([
      "web",
      "EC",
    ]);
    // override で宣言された分類も読める
    expect(classifyValueTarget(schemas["works"], "genre").kind).toBe(
      "taxonomy",
    );
  });

  /**
   * **override は「誰が管理しているか」を奪わない**（R-13 9 件目）。
   *
   * `mergeOverride` は新しいオブジェクトを組み立て直すので、
   * **書き忘れた属性は消える**。`managedBy` が消えると
   * 「CMS が作った型」が「kit の module 型」に見え、plan が module 前提の枝へ落ちる
   * （実測: 型を作ったあとの再 plan で公開面の生成物が入れ替わり、
   *  `/news` と `/works/{slug}` が 404 になった）。
   */
  it("override を重ねても managedBy は残る", () => {
    const root = project({
      "content/schemas/works.json": JSON.stringify({
        id: "works",
        managedBy: "cms",
        fields: [{ key: "category", type: "select", choices: ["web"] }],
      }),
      "content/schemas/overrides/works.json": JSON.stringify({
        id: "works",
        fields: { category: { choices: ["web", "EC"] } },
      }),
    });

    expect(loadProjectSchemas(root)["works"]?.managedBy).toBe("cms");
  });

  // **CMS と共有する表**を読む。片側だけ緩めたら、どちらかのテストが落ちる
  const contract = JSON.parse(
    readFileSync(
      join(REPO, "template", "tests", "fixtures", "schema-contract-cases.json"),
      "utf8",
    ),
  ) as {
    rejected: { label: string; files: Record<string, string> }[];
    accepted: { label: string; files: Record<string, string> }[];
  };

  it.each(contract.rejected.map((c) => [c.label, c.files] as const))(
    "壊れた定義 [%s] は無視せず失敗させる",
    (_label, files) => {
      const prefixed = Object.fromEntries(
        Object.entries(files).map(([name, body]) => [
          `content/schemas/${name}`,
          body,
        ]),
      );

      expect(() => loadProjectSchemas(project(prefixed))).toThrow(
        SchemaContractError,
      );
    },
  );

  it.each(contract.accepted.map((c) => [c.label, c.files] as const))(
    "正しい定義 [%s] は受け付ける",
    (_label, files) => {
      const prefixed = Object.fromEntries(
        Object.entries(files).map(([name, body]) => [
          `content/schemas/${name}`,
          body,
        ]),
      );

      expect(() => loadProjectSchemas(project(prefixed))).not.toThrow();
    },
  );
});

describe("既存項目の照合は fail closed", () => {
  beforeEach(() => runMock.mockReset());

  const ok = (payload: unknown) => ({
    code: 0,
    stdout: JSON.stringify(payload),
    stderr: "",
  });

  it("CMS を実行できなければ「無い」ではなく「未確認」", async () => {
    runMock.mockResolvedValue({ code: 1, stdout: "", stderr: "boom" });

    const catalog = await fetchTaxonomyCatalog(["news_category"], {
      root: "/tmp",
    });

    expect(catalog.failures["news_category"]).toBeDefined();
    expect(matchTerm(catalog, "news_category", "press").kind).toBe(
      "undetermined",
    );
  });

  it("exit code が 0 でなければ success 風の応答でも成功にしない", async () => {
    runMock.mockResolvedValue({
      code: 2,
      stdout: JSON.stringify({ status: "success", data: { terms: [] } }),
      stderr: "",
    });

    const catalog = await fetchTaxonomyCatalog(["news_category"], {
      root: "/tmp",
    });

    expect(catalog.terms["news_category"]).toBeUndefined();
    expect(catalog.failures["news_category"]).toBeDefined();
  });

  it("読めない項目が 1 件でもあれば分類ごと未確認にする", async () => {
    runMock.mockResolvedValue(
      ok({
        status: "success",
        data: {
          terms: [
            { id: "1", name: "press", slug: "press", archived: false },
            { id: "2", name: "broken", slug: "broken" }, // archived が無い
          ],
        },
      }),
    );

    const catalog = await fetchTaxonomyCatalog(["news_category"], {
      root: "/tmp",
    });

    // 「読めた分だけ」で判断すると、実在する項目をもう一度作ってしまう
    expect(catalog.terms["news_category"]).toBeUndefined();
    expect(matchTerm(catalog, "news_category", "press").kind).toBe(
      "undetermined",
    );
  });

  it("active / archived / absent を取り違えない", async () => {
    runMock.mockResolvedValue(
      ok({
        status: "success",
        data: {
          terms: [
            { id: "t1", name: "press", slug: "press", archived: false },
            { id: "t2", name: "old", slug: "old", archived: true },
          ],
        },
      }),
    );

    const catalog = await fetchTaxonomyCatalog(["news_category"], {
      root: "/tmp",
    });

    expect(matchTerm(catalog, "news_category", "press")).toStrictEqual({
      kind: "active",
      term_id: "t1",
      slug: "press",
    });
    expect(matchTerm(catalog, "news_category", "old").kind).toBe("archived");
    expect(matchTerm(catalog, "news_category", "new").kind).toBe("absent");
    // slug を決められない値は照合そのものができない
    expect(matchTerm(catalog, "news_category", null).kind).toBe("undetermined");
  });

  it("作成応答が要求と一致しなければ成功にしない", async () => {
    const want = [
      { taxonomyKey: "news_category", name: "Press", slug: "press" },
    ];

    // 件数が足りない
    runMock.mockResolvedValue(ok({ status: "success", created: [] }));
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe(
      "commit_unknown",
    );

    // 別の slug が返る
    runMock.mockResolvedValue(
      ok({
        status: "success",
        created: [
          { id: "x", taxonomy: "news_category", name: "Press", slug: "other" },
        ],
      }),
    );
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe(
      "commit_unknown",
    );

    // 一致していれば成功
    runMock.mockResolvedValue(
      ok({
        status: "success",
        created: [
          { id: "x", taxonomy: "news_category", name: "Press", slug: "press" },
        ],
      }),
    );
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe("ok");
  });

  it("構造化された失敗と「分からない」を区別する", async () => {
    // ここが同じ扱いになると、commit 済みかもしれないのにファイルだけ戻して
    // 「DB だけ残る」状態を自分で作ってしまう
    const want = [
      { taxonomyKey: "news_category", name: "Press", slug: "press" },
    ];

    // CMS が構造化された失敗を返した = 1 トランザクションが巻き戻っている
    runMock.mockResolvedValue({
      code: 1,
      stdout: JSON.stringify({
        status: "validation_error",
        message: "slug が重複しています",
      }),
      stderr: "",
    });
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe(
      "definite_rollback",
    );

    // 応答が壊れている = commit されたか分からない
    runMock.mockResolvedValue({ code: 0, stdout: "not json", stderr: "" });
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe(
      "commit_unknown",
    );

    // プロセスが起動しない = 同上
    runMock.mockRejectedValue(new Error("docker not found"));
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe(
      "commit_unknown",
    );

    // exit code と応答が食い違う = 同上
    runMock.mockResolvedValue({
      code: 137,
      stdout: JSON.stringify({ status: "success", created: [] }),
      stderr: "",
    });
    expect((await createTerms(want, { root: "/tmp" })).kind).toBe(
      "commit_unknown",
    );
  });

  it("actor を送らない（監査の呼び出し元を入力で名乗らせない）", async () => {
    runMock.mockResolvedValue(
      ok({
        status: "success",
        created: [
          { id: "x", taxonomy: "news_category", name: "Press", slug: "press" },
        ],
      }),
    );

    await createTerms(
      [{ taxonomyKey: "news_category", name: "Press", slug: "press" }],
      {
        root: "/tmp",
      },
    );

    const payload = JSON.parse(
      (runMock.mock.calls[0]?.[2] as { input: string }).input,
    ) as Record<string, unknown>;

    expect(Object.keys(payload)).toStrictEqual(["terms"]);
  });
});

describe("復旧記録は改変されても危険な書き込みをしない", () => {
  it("project の外を指す対象を受け付けない", () => {
    const root = tempDir();

    expect(isSafeProjectTarget(root, "content/a.json")).toBe(true);
    expect(isSafeProjectTarget(root, "../outside.txt")).toBe(false);
    expect(isSafeProjectTarget(root, "a/../../outside.txt")).toBe(false);
    expect(isSafeProjectTarget(root, "/etc/passwd")).toBe(false);
    expect(isSafeProjectTarget(root, "")).toBe(false);
  });

  it("対象そのものと途中のディレクトリの symlink を検出する", () => {
    const root = tempDir();
    const outside = tempDir();
    mkdirSync(join(root, "content"), { recursive: true });
    writeFileSync(join(outside, "secret.txt"), "secret");
    symlinkSync(
      join(outside, "secret.txt"),
      join(root, "content", "linked.json"),
    );
    symlinkSync(outside, join(root, "linkdir"));

    expect(symlinkOffenders(root, ["content/linked.json"])).toStrictEqual([
      "content/linked.json",
    ]);
    expect(symlinkOffenders(root, ["linkdir/secret.txt"])).toStrictEqual([
      "linkdir/secret.txt",
    ]);
    expect(symlinkOffenders(root, ["content/plain.json"])).toStrictEqual([]);
  });

  it("symlink が混ざっていたら記録の作成そのものを止める", () => {
    const root = tempDir();
    const outside = tempDir();
    writeFileSync(join(outside, "secret.txt"), "secret");
    symlinkSync(join(outside, "secret.txt"), join(root, "linked.json"));

    expect(() =>
      writeJournal(
        join(root, ".agentcms", "journal.json"),
        root,
        ["linked.json"],
        [],
        "hash",
        "2026-01-01T00:00:00Z",
      ),
    ).toThrow(/symlink/);
  });

  it("壊れた記録・脱出する対象・重複は読まない", () => {
    const root = tempDir();
    const path = join(root, "journal.json");
    const base: ApplyJournal = {
      version: 1,
      phase: "prepared",
      started_at: "2026-01-01T00:00:00Z",
      plan_sha256: "hash",
      files: [{ target: "a.txt", content: null }],
      terms: [],
    };

    writeFileSync(path, JSON.stringify(base));
    expect(readJournal(path, root)).not.toBeNull();

    for (const broken of [
      { ...base, version: 2 },
      { ...base, phase: "whatever" },
      { ...base, files: [{ target: "../escape.txt", content: null }] },
      { ...base, files: [{ target: "/etc/passwd", content: null }] },
      {
        ...base,
        files: [
          { target: "a.txt", content: null },
          { target: "a.txt", content: null },
        ],
      },
      { ...base, files: [{ target: "a.txt", content: "not base64!!" }] },
      { ...base, terms: [{ taxonomy: "t", slug: "s" }] },
    ]) {
      writeFileSync(path, JSON.stringify(broken));
      expect(readJournal(path, root)).toBeNull();
    }
  });

  it("記録した状態へ byte 単位で戻す", () => {
    const root = tempDir();
    writeFileSync(join(root, "kept.txt"), "before");

    const journalFile = join(root, ".agentcms", "journal.json");
    const journal = writeJournal(
      journalFile,
      root,
      ["kept.txt", "created.txt"],
      [],
      "hash",
      "2026-01-01T00:00:00Z",
    );

    // apply が変更した想定
    writeFileSync(join(root, "kept.txt"), "after");
    writeFileSync(join(root, "created.txt"), "new");

    const restored = restoreFromJournal(root, journal);

    expect(restored.failed).toStrictEqual([]);
    expect(readJournal(journalFile, root)?.phase).toBe("prepared");
  });
});

describe("Plan の検証は apply が読む値を全部見る", () => {
  const schemas = {
    works: {
      id: "works",
      fields: [{ key: "category", type: "select", choices: ["web"] }],
    } as ContentSchema,
    news: {
      id: "news",
      fields: [{ key: "title", type: "text" }],
      taxonomies: [{ key: "news_category", multiple: true, required: false }],
    } as ContentSchema,
  };

  const validPlan = {
    version: 3,
    schema_changes: [
      {
        schema: "works",
        field: "category",
        change: "add_choices",
        scope: "project",
        values: ["EC"],
        confidence: "CONFIDENT",
        reason: "テンプレートの表記を保つ",
      },
    ],
    taxonomy_changes: [
      {
        change: "add_terms",
        scope: "project",
        schema: "news",
        taxonomy: "news_category",
        source_value: "Press",
        occurrences: 3,
        proposed_name: "Press",
        proposed_slug: "press",
        match: { kind: "absent" },
        confidence: "CONFIDENT",
        reason: "既存に無いため",
      },
    ],
  };

  it("正しい v3 Plan は通る", () => {
    expect(validatePlanChanges(validPlan, { schemas })).toStrictEqual([]);
  });

  it.each([[1], [2]])("v%i Plan は解釈せず拒否する", (version) => {
    const issues = validatePlanChanges(
      { ...validPlan, version },
      { schemas },
    );

    expect(issues).toHaveLength(1);
    expect(issues[0]?.message).toContain("古い形式");
  });

  it.each([
    ["未知の変更種別", { change: "delete_choices" }],
    ["scope の改変", { scope: "core" }],
    ["登録されていない schema", { schema: "../../etc/passwd" }],
    ["存在しない schema", { schema: "unknown_schema" }],
    ["select ではない field", { field: "title" }],
    ["values が空", { values: [] }],
    ["confidence が不正", { confidence: "SURE" }],
  ])("schema_changes の改変 [%s] を拒否する", (_label, patch) => {
    const plan = {
      ...validPlan,
      schema_changes: [{ ...validPlan.schema_changes[0], ...patch }],
    };

    expect(validatePlanChanges(plan, { schemas }).length).toBeGreaterThan(0);
  });

  it.each([
    ["宣言の無い分類", { taxonomy: "no_such" }],
    ["別 schema の分類", { schema: "works" }],
    ["canonical でない slug", { proposed_slug: "Press Release" }],
    ["occurrences が 0", { occurrences: 0 }],
    ["source_value が空", { source_value: "" }],
    ["match が未知", { match: { kind: "maybe" } }],
    [
      "active なのに term_id が無い",
      { match: { kind: "active", slug: "press" } },
    ],
    [
      "match.slug が提案と食い違う",
      { match: { kind: "active", term_id: "x", slug: "other" } },
    ],
    ["undetermined に理由が無い", { match: { kind: "undetermined" } }],
    [
      "slug が無いのに新規作成",
      { proposed_slug: null, match: { kind: "absent" } },
    ],
  ])("taxonomy_changes の改変 [%s] を拒否する", (_label, patch) => {
    const plan = {
      ...validPlan,
      taxonomy_changes: [{ ...validPlan.taxonomy_changes[0], ...patch }],
    };

    expect(validatePlanChanges(plan, { schemas }).length).toBeGreaterThan(0);
  });
});

describe("同じ slug になる値をまとめない", () => {
  it("由来が違っても canonical slug が衝突したら全部を人の判断へ回す", () => {
    const schemas = {
      news: {
        id: "news",
        fields: [{ key: "title", type: "text" }],
        taxonomies: [{ key: "news_category", multiple: true, required: false }],
      } as ContentSchema,
    };

    // `foo bar` / `foo-bar` / `foo_bar` はすべて `foo-bar` になる。
    // 作れるのは 1 つだけなので、どれを採るかは人が決める
    const proposals = ["foo bar", "foo-bar", "foo_bar"].map((value) => ({
      change: "add_terms" as const,
      scope: "project" as const,
      schema: "news",
      taxonomy: "news_category",
      source_value: value,
      occurrences: 1,
      proposed_name: value,
      proposed_slug: "foo-bar",
      match: { kind: "absent" as const },
      confidence: "CONFIDENT" as const,
      reason: "既存に無いため",
    }));

    const issues = validatePlanChanges(
      { version: 3, taxonomy_changes: proposals },
      { schemas },
    );

    // 同じ分類に同じ slug の提案が並ぶ Plan は成立しない
    expect(issues.some((i) => i.message.includes("重複"))).toBe(true);
  });
});

describe("確度と照合結果の整合", () => {
  const schemas = {
    news: {
      id: "news",
      fields: [{ key: "title", type: "text" }],
      taxonomies: [{ key: "news_category", multiple: true, required: false }],
    } as ContentSchema,
  };

  const proposal = {
    change: "add_terms" as const,
    scope: "project" as const,
    schema: "news",
    taxonomy: "news_category",
    source_value: "Press",
    occurrences: 1,
    proposed_name: "Press",
    proposed_slug: "press",
    match: { kind: "absent" as const },
    confidence: "CONFIDENT" as const,
    reason: "既存に無いため",
  };

  const check = (patch: Record<string, unknown>) =>
    validatePlanChanges(
      { version: 3, taxonomy_changes: [{ ...proposal, ...patch }] },
      { schemas },
    );

  it("新規作成の提案が REVIEW なら拒否する（人の判断待ちを自動適用しない）", () => {
    expect(check({ confidence: "REVIEW" }).length).toBeGreaterThan(0);
    expect(check({ confidence: "UNKNOWN" }).length).toBeGreaterThan(0);
  });

  it("作成しない提案を CONFIDENT にはできない", () => {
    for (const match of [
      { kind: "active", term_id: "t1", slug: "press" },
      { kind: "archived", term_id: "t1", slug: "press" },
      { kind: "undetermined", reason: "確認できません" },
    ]) {
      expect(check({ match, confidence: "CONFIDENT" }).length).toBeGreaterThan(
        0,
      );
      expect(check({ match, confidence: "REVIEW" })).toStrictEqual([]);
    }
  });

  it("名前は取り込んだ値と同じでなければならない", () => {
    // 別名で作らせる改変を許さない
    expect(check({ proposed_name: "別の名前" }).length).toBeGreaterThan(0);
  });

  it.each([
    ["120 文字", "a".repeat(120), 0],
    ["121 文字", "a".repeat(121), 1],
  ])("名前の長さ境界 [%s]", (_label, name, expected) => {
    const issues = check({ source_value: name, proposed_name: name });

    expect(issues.length >= expected).toBe(true);
    expect(issues.length === 0).toBe(expected === 0);
  });

  it("未知のキーがある変更宣言を拒否する", () => {
    expect(check({ apply_now: true }).length).toBeGreaterThan(0);
  });
});

describe("apply 直前の再確認（preflight）", () => {
  beforeEach(() => runMock.mockReset());

  const catalog = (terms: unknown[]) =>
    runMock.mockResolvedValue({
      code: 0,
      stdout: JSON.stringify({ status: "success", data: { terms } }),
      stderr: "",
    });

  const proposal = (patch: Record<string, unknown> = {}) => ({
    change: "add_terms" as const,
    scope: "project" as const,
    schema: "news",
    taxonomy: "news_category",
    source_value: "Press",
    occurrences: 1,
    proposed_name: "Press",
    proposed_slug: "press",
    match: { kind: "absent" } as never,
    confidence: "CONFIDENT" as const,
    reason: "既存に無いため",
    ...patch,
  });

  it("新規作成の提案は、直前も absent のときだけ作る", async () => {
    catalog([]);

    const result = await preflightTerms([proposal()], "/tmp", false);

    expect(result.stop).toBeNull();
    expect(result.create).toStrictEqual([
      { taxonomyKey: "news_category", name: "Press", slug: "press" },
    ]);
  });

  it("アーカイブ済みとの衝突は「作らないから成功」にしない", async () => {
    catalog([{ id: "t1", name: "Press", slug: "press", archived: true }]);

    // Plan の時点で archived だった場合も、直前に archived になった場合も止まる
    for (const p of [
      proposal({
        match: { kind: "archived", term_id: "t1", slug: "press" },
        confidence: "REVIEW",
      }),
      proposal(),
    ]) {
      const result = await preflightTerms([p], "/tmp", false);

      expect(result.stop).not.toBeNull();
      expect(result.create).toStrictEqual([]);
    }
  });

  it("確認できない提案も成功にしない", async () => {
    runMock.mockResolvedValue({ code: 1, stdout: "", stderr: "down" });

    const result = await preflightTerms(
      [
        proposal({
          match: { kind: "undetermined", reason: "確認できません" },
          confidence: "REVIEW",
        }),
      ],
      "/tmp",
      false,
    );

    expect(result.stop).not.toBeNull();
    expect(result.create).toStrictEqual([]);
  });

  it("既存利用の提案は作らないが、入れ替わっていたら止める", async () => {
    const reuse = proposal({
      match: { kind: "active", term_id: "t1", slug: "press" },
      confidence: "REVIEW",
    });

    // 同じものが残っている → 作らずに続行
    catalog([{ id: "t1", name: "Press", slug: "press", archived: false }]);
    const ok = await preflightTerms([reuse], "/tmp", false);
    expect(ok.stop).toBeNull();
    expect(ok.create).toStrictEqual([]);

    // 別の項目に入れ替わっている → 止める
    catalog([{ id: "t2", name: "Press", slug: "press", archived: false }]);
    expect((await preflightTerms([reuse], "/tmp", false)).stop).not.toBeNull();

    // archive された → 止める
    catalog([{ id: "t1", name: "Press", slug: "press", archived: true }]);
    expect((await preflightTerms([reuse], "/tmp", false)).stop).not.toBeNull();
  });

  it("同じ Plan の再適用だけ absent → active を「適用済み」として通す", async () => {
    catalog([{ id: "t1", name: "Press", slug: "press", archived: false }]);

    // 適用記録が無い（＝別経路で作られた）なら止める
    expect(
      (await preflightTerms([proposal()], "/tmp", false)).stop,
    ).not.toBeNull();

    // 同じ Plan を適用済みなら作らずに通す
    const again = await preflightTerms([proposal()], "/tmp", true);
    expect(again.stop).toBeNull();
    expect(again.create).toStrictEqual([]);
  });
});

describe("override の書き込みは壊れた既存内容を直さない", () => {
  const works = {
    id: "works",
    fields: [{ key: "category", type: "select", choices: ["web"] }],
  } as ContentSchema;

  const withOverride = (content: string) =>
    project({
      "content/schemas/works.json": JSON.stringify(works),
      "content/schemas/overrides/works.json": content,
    });

  it.each([
    [
      "choices に数値が混ざる",
      '{"id":"works","fields":{"category":{"choices":[1,"web"]}}}',
    ],
    [
      "choices が配列でない",
      '{"id":"works","fields":{"category":{"choices":"web"}}}',
    ],
    ["fields がオブジェクトでない", '{"id":"works","fields":[]}'],
    [
      "field patch がオブジェクトでない",
      '{"id":"works","fields":{"category":"x"}}',
    ],
    ["id が対象と違う", '{"id":"news","fields":{}}'],
    ["JSON が壊れている", "{"],
  ])("壊れた override [%s] は直さずに失敗させる", (_label, content) => {
    const root = withOverride(content);
    const before = readFileSync(
      join(root, "content/schemas/overrides/works.json"),
    );
    const baseBefore = readFileSync(join(root, "content/schemas/works.json"));

    expect(() =>
      writeSchemaChoicesOverride(root, works, "category", ["EC"]),
    ).toThrow(SchemaWriteError);

    // 失敗しても **1 バイトも変えない**（黙って直すと元の値が消える）
    expect(
      readFileSync(join(root, "content/schemas/overrides/works.json")),
    ).toStrictEqual(before);
    expect(
      readFileSync(join(root, "content/schemas/works.json")),
    ).toStrictEqual(baseBefore);
  });

  it("選択肢を持たない field へは書かない", () => {
    const root = project({
      "content/schemas/works.json": JSON.stringify(works),
    });

    // 呼び出し元の検証を通り抜けても、書く場所で止まる
    expect(() =>
      writeSchemaChoicesOverride(root, works, "title", ["EC"]),
    ).toThrow(SchemaWriteError);
    expect(() =>
      writeSchemaChoicesOverride(root, undefined, "category", ["EC"]),
    ).toThrow(SchemaWriteError);
    expect(() =>
      writeSchemaChoicesOverride(root, works, "category", []),
    ).toThrow(SchemaWriteError);
  });
});

describe("JSON は途中で壊れない形で書く", () => {
  it("復旧記録は 0600 で保存し、緩い権限の既存ファイルも締め直す", () => {
    const root = tempDir();
    const path = join(root, ".agentcms", "journal.json");
    mkdirSync(join(root, ".agentcms"), { recursive: true });
    writeFileSync(path, "{}", { mode: 0o644 });

    writeJournal(path, root, [], [], "hash", "2026-01-01T00:00:00Z");

    // 変更前のファイル（.env を含み得る）が入るので他人に読ませない
    expect(statSync(path).mode & 0o777).toBe(0o600);
  });

  it("書き込みに失敗しても元の内容が残る", () => {
    const root = tempDir();
    const path = join(root, "data.json");
    writeFileSync(path, '{"keep":true}\n');

    // JSON にできない値を渡して失敗させる
    const circular: Record<string, unknown> = {};
    circular.self = circular;

    expect(() => writeJsonAtomically(path, circular)).toThrow();
    expect(readFileSync(path, "utf8")).toBe('{"keep":true}\n');
    // 一時ファイルも残さない
    expect(readdirSync(root)).toStrictEqual(["data.json"]);
  });

  it("記録のパスに symlink が含まれていたら書かない", () => {
    const root = tempDir();
    const outside = tempDir();
    symlinkSync(outside, join(root, "linked"));

    expect(() =>
      writeJournal(
        join(root, "linked", "journal.json"),
        root,
        [],
        [],
        "hash",
        "2026-01-01T00:00:00Z",
      ),
    ).toThrow(/symlink/);
  });
});

describe("改変された復旧記録の分類項目を信用しない", () => {
  const root = tempDir();
  const path = join(root, "journal.json");
  const base = {
    version: 1,
    phase: "files_written",
    started_at: "2026-01-01T00:00:00Z",
    plan_sha256: "hash",
    files: [],
  };

  it.each([
    ["分類 key が不正", [{ taxonomy: "../etc", slug: "press", name: "Press" }]],
    [
      "slug が canonical でない",
      [{ taxonomy: "t", slug: "Press!", name: "Press" }],
    ],
    [
      "slug が長すぎる",
      [{ taxonomy: "t", slug: "a".repeat(121), name: "Press" }],
    ],
    ["name が空", [{ taxonomy: "t", slug: "press", name: "  " }]],
    [
      "name が長すぎる",
      [{ taxonomy: "t", slug: "press", name: "a".repeat(121) }],
    ],
    [
      "同じ分類に同じ slug",
      [
        { taxonomy: "t", slug: "press", name: "Press" },
        { taxonomy: "t", slug: "press", name: "Press" },
      ],
    ],
    ["未知のキー", [{ taxonomy: "t", slug: "press", name: "Press", extra: 1 }]],
  ])("[%s] の記録は読まない", (_label, terms) => {
    writeFileSync(path, JSON.stringify({ ...base, terms }));

    expect(readJournal(path, root)).toBeNull();
  });

  it("正しい記録は読める", () => {
    writeFileSync(
      path,
      JSON.stringify({
        ...base,
        terms: [{ taxonomy: "news_category", slug: "press", name: "Press" }],
      }),
    );

    expect(readJournal(path, root)?.terms).toHaveLength(1);
  });
});

describe("既存項目の再利用は名前ではなく slug と ID で決まる（IM-13）", () => {
  beforeEach(() => runMock.mockReset());

  const reuse = {
    change: "add_terms" as const,
    scope: "project" as const,
    schema: "news",
    taxonomy: "news_category",
    // 取り込んだ表記は大文字
    source_value: "PRESS",
    occurrences: 2,
    proposed_name: "PRESS",
    proposed_slug: "press",
    match: { kind: "active", term_id: "t1", slug: "press" } as never,
    confidence: "REVIEW" as const,
    reason: "既存があるため",
  };

  it("取り込んだ表記と既存の名前が違っても、slug が同じなら再利用する", async () => {
    // 既存の名前は "Press"。ここで名前一致を要求すると正常な再利用が止まる
    runMock.mockResolvedValue({
      code: 0,
      stdout: JSON.stringify({
        status: "success",
        data: {
          terms: [{ id: "t1", name: "Press", slug: "press", archived: false }],
        },
      }),
      stderr: "",
    });

    const result = await preflightTerms([reuse], "/tmp", false);

    expect(result.stop).toBeNull();
    expect(result.create).toStrictEqual([]);
    expect(result.notes.join("")).toContain("既存を利用");
  });
});

describe("override の書き込み先はプロジェクト内に固定する", () => {
  const works = {
    id: "works",
    fields: [
      { key: "category", type: "select", choices: ["web"] },
      { key: "body", type: "richtext" },
    ],
  } as ContentSchema;

  it("存在しない field や許可外の属性へは書かない", () => {
    const root = project({
      "content/schemas/works.json": JSON.stringify(works),
    });

    expect(() =>
      writeSchemaOverride(root, works, "nope", { required: false }),
    ).toThrow(SchemaWriteError);
    expect(() =>
      writeSchemaOverride(root, works, "body", { type: "text" }),
    ).toThrow(SchemaWriteError);
    expect(() =>
      writeSchemaOverride(root, works, "body", { required: "false" }),
    ).toThrow(SchemaWriteError);
    expect(() =>
      writeSchemaOverride(root, undefined, "body", { required: false }),
    ).toThrow(SchemaWriteError);
  });

  it("パスを組み立てられない id では書かない", () => {
    const root = project({
      "content/schemas/works.json": JSON.stringify(works),
    });
    const hostile = { ...works, id: "../../etc/passwd" };

    expect(() =>
      writeSchemaOverride(root, hostile, "body", { required: false }),
    ).toThrow(SchemaWriteError);
  });

  it("書き込み先が symlink 経由なら書かない", () => {
    const outside = tempDir();
    const root = project({
      "content/schemas/works.json": JSON.stringify(works),
    });
    symlinkSync(outside, join(root, "content", "schemas", "overrides"));

    expect(() =>
      writeSchemaOverride(root, works, "body", { required: false }),
    ).toThrow(/symlink/);
    expect(readdirSync(outside)).toStrictEqual([]);
  });

  it("許可された属性は今までどおり書ける", () => {
    const root = project({
      "content/schemas/works.json": JSON.stringify(works),
    });
    const first = writeSchemaOverride(root, works, "body", { required: false });

    expect(first.changed).toBe(true);
    // 同値の再適用は no-op
    expect(
      writeSchemaOverride(root, works, "body", { required: false }).changed,
    ).toBe(false);
  });
});

describe("巻き戻したら復旧記録も残さない", () => {
  const setup = () => {
    const root = tempDir();
    mkdirSync(join(root, ".agentcms"), { recursive: true });
    writeFileSync(join(root, "kept.txt"), "before");

    const journalFile = join(root, ".agentcms", "journal.json");
    const journal = writeJournal(
      journalFile,
      root,
      ["kept.txt", "created.txt"],
      [{ taxonomy: "news_category", slug: "press", name: "Press" }],
      "hash",
      "2026-01-01T00:00:00Z",
    );

    // apply がファイルを変更した状態を作る
    writeFileSync(join(root, "kept.txt"), "after");
    writeFileSync(join(root, "created.txt"), "new");

    return { root, journalFile, journal };
  };

  it("戻し切れたら記録を消す（次回の復旧に誤読させない）", () => {
    // 記録が残ると、あとから同じ項目が作られたときに
    // 「前回は commit 済みだった」と読み違える
    const { root, journalFile, journal } = setup();
    const outcome = rollbackJournal(root, journalFile, journal);

    expect(outcome.failed).toStrictEqual([]);
    expect(outcome.cleared).toBe(true);
    expect(existsSync(journalFile)).toBe(false);

    // ファイルは変更前の byte に戻っている
    expect(readFileSync(join(root, "kept.txt"), "utf8")).toBe("before");
    expect(existsSync(join(root, "created.txt"))).toBe(false);
  });

  it("戻せなかったものがあれば記録を残す", () => {
    const { root, journalFile, journal } = setup();

    // 復元先を symlink にして戻せなくする
    rmSync(join(root, "kept.txt"));
    symlinkSync(join(tempDir(), "elsewhere.txt"), join(root, "kept.txt"));

    const outcome = rollbackJournal(root, journalFile, journal);

    expect(outcome.failed).toStrictEqual(["kept.txt"]);
    expect(outcome.cleared).toBe(false);
    // 人が判断できるよう記録は残す
    expect(existsSync(journalFile)).toBe(true);
  });

  it("記録を消せなければ成功にしない", () => {
    const { root, journalFile, journal } = setup();

    // 記録をディレクトリに差し替えて削除を失敗させる
    rmSync(journalFile);
    mkdirSync(join(journalFile, "blocker"), { recursive: true });

    const outcome = rollbackJournal(root, journalFile, journal);

    expect(outcome.failed).toStrictEqual([]);
    expect(outcome.cleared).toBe(false);
    expect(outcome.error).toBeDefined();
  });

  // commit されたか分からない経路の振る舞いは、実際に CLI を動かす
  // `import-apply-e2e.test.ts`（ファイルも記録も残ることを確認）で固定している
});

describe("配布する定義は import の結果で汚さない", () => {
  const distributed = join(REPO, "template", "content", "schemas");

  it("works.category は select のまま（この Stage で分類化しない）", () => {
    const schemas = loadProjectSchemas(join(REPO, "template"));
    const target = classifyValueTarget(schemas["works"], "category");

    expect(target.kind).toBe("select");
    expect(target.kind === "select" && target.choices.length).toBeGreaterThan(
      0,
    );

    // 分類としては宣言していない
    expect(schemas["works"]?.taxonomies ?? []).toStrictEqual([]);
  });

  /**
   * 配布されるのは **git が持っているファイル**（#24）。
   *
   * 開発機には base から外した値を有効に保つための override を置いているが、
   * それは追跡対象から外してある。作業ディレクトリを見に行くと、その開発用の
   * ファイルを「配布物」と誤認してしまうので、**tracked かどうか**で判定する。
   */
  const tracked = (path: string): boolean =>
    execFileSync("git", ["ls-files", "--error-unmatch", path], {
      cwd: REPO,
      stdio: ["ignore", "pipe", "ignore"],
    })
      .toString()
      .trim().length > 0;

  it("schema override を配布しない（開発機だけの状態を持ち出さない）", () => {
    let isTracked = true;

    try {
      isTracked = tracked("template/content/schemas/overrides/works.json");
    } catch {
      isTracked = false; // ls-files が失敗 = 追跡されていない
    }

    expect(isTracked, "override が配布物に含まれています").toBe(false);
  });

  it("配布 tree に overrides が 1 つも入らない", () => {
    // git archive の入力（= 利用者へ渡る一式）に override が現れないこと
    const listed = execFileSync(
      "git",
      ["ls-files", "template/content/schemas"],
      {
        cwd: REPO,
      },
    )
      .toString()
      .split("\n")
      .filter(Boolean);

    expect(listed.length).toBeGreaterThan(0);
    expect(listed.filter((f) => f.includes("/overrides/"))).toStrictEqual([]);
  });

  it("配布する works.json の choices は標準 3 値 + import 由来 5 値だけ", () => {
    // base は kit の定義そのもの。**これから import が足す値**は override 側へ書く
    // （既に base に載っている 5 値は、過去に base へ書き戻された結果であり、
    //   その完全除去は 9.3C の課題）。
    // casefold で重複する Branding / Web は **選択肢として区別できない**ので base から外した（F-T1）
    const works = JSON.parse(
      readFileSync(join(distributed, "works.json"), "utf8"),
    ) as { fields: { key: string; choices?: string[] }[] };
    const choices =
      works.fields.find((f) => f.key === "category")?.choices ?? [];

    expect(choices).toStrictEqual([
      "web",
      "graphic",
      "branding",
      "Corporate",
      "EC",
      "LP",
      "Package",
      "Service",
    ]);

    // 重複も、大文字小文字だけが違う組も無い
    expect(new Set(choices).size).toBe(choices.length);
    expect(new Set(choices.map((c) => c.toLowerCase())).size).toBe(
      choices.length,
    );
  });

  it("works.category は select / required のままで、分類宣言も無い", () => {
    const works = JSON.parse(
      readFileSync(join(distributed, "works.json"), "utf8"),
    ) as {
      fields: { key: string; type?: string; required?: boolean }[];
      taxonomies?: unknown[];
    };
    const category = works.fields.find((f) => f.key === "category");

    expect(category?.type).toBe("select");
    expect(category?.required).toBe(true);
    expect(works.taxonomies ?? []).toStrictEqual([]);
  });
});

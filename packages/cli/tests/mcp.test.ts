import { mkdtempSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import {
  AGENT_CLIENTS,
  SERVER_KEY,
  renderConfig,
  writeConfig,
} from "../src/mcp/agent-config.js";
import { parseResult } from "../src/mcp/bridge.js";
import {
  DESTRUCTIVE_TOOLS,
  TOOLS,
  TOOL_OPERATIONS,
  type ToolName,
} from "../src/mcp/tools.js";

/**
 * Phase 9.1B / STEP 11・13・14: MCP server の公開範囲と設定生成。
 *
 * ここで固定するのは「AI に何を渡すか」の境界。ツールが増減したら落ちる。
 */
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../../..");

describe("MCP tool surface", () => {
  it("公開ツールは allowlist と 1:1 で、危険な操作を含まない", () => {
    expect(Object.keys(TOOL_OPERATIONS).sort()).toEqual(
      [
        // 受信を読むツール（旧 contact_get / contact_list）は **意図的に無い**（B-43 / 9.12）。
        // 「消えた」のではなく、9.12 の決定がやっと実装に追いついたもの
        "content_create",
        "content_duplicate",
        "content_delete",
        "content_trash",
        // 掲載順（P1 ②）。**内容は 1 文字も変わらず、また動かせる**ので出す
        "content_reorder",
        "content_get",
        "content_list",
        "content_schema_get",
        "content_types_list",
        "content_update",
        "media_get",
        "media_list",
        "media_replace",
        "media_update",
        "settings_get",
        "settings_update",
        "page_list",
        "page_create",
        "page_duplicate",
        "page_get",
        "page_document_get",
        "page_document_versions",
        "page_document_patch",
        "page_publish",
        "page_unpublish",
        "page_trash",
        "taxonomy_list",
        "taxonomy_get",
        "term_list",
        "term_create",
        "term_update",
        "term_reorder",
        "term_delete",
      ].sort(),
    );
  });

  /**
   * B-43: **受信（問い合わせ本文・連絡先・生 IP）を読むツールを 1 つも出さない。**
   *
   * Phase 9.12 は `form.submission.*` を「本文と連絡先そのもの」として出さないと
   * 決めていたが、9.12 より前に作られた旧側の `contact_list` / `contact_get` が
   * 残っており、**同じ種類のデータについて新旧で決定が逆向き**になっていた。
   * 名前でも operation ID でも見る — 片方だけ戻ると、また逆向きに戻るため。
   */
  it("受信を読むツールは 1 つも公開しない（B-43 / 9.12）", () => {
    for (const [name, operation] of Object.entries(TOOL_OPERATIONS)) {
      expect(name.includes("contact")).toBe(false);
      expect(name.includes("submission")).toBe(false);
      expect(operation.startsWith("contact.")).toBe(false);
      expect(operation.startsWith("form.submission.")).toBe(false);
    }

    for (const tool of TOOLS) {
      expect(String(tool.name).includes("contact")).toBe(false);
      expect(String(tool.name).includes("submission")).toBe(false);
    }
  });

  it("shell / SQL / ファイル読み取り / secret 系のツールは存在しない", () => {
    const forbidden = [
      "shell",
      "exec",
      "artisan",
      "sql",
      "query",
      "file_read",
      "read_file",
      "env",
      "secret",
      "credential",
    ];

    for (const name of Object.keys(TOOL_OPERATIONS)) {
      for (const bad of forbidden) {
        expect(name.includes(bad)).toBe(false);
      }
    }
  });

  it("取り消せない操作は destructive として申告される", () => {
    expect([...DESTRUCTIVE_TOOLS].sort()).toEqual(
      [
        "content_delete",
        // 9.8 以降は取り消せる（ゴミ箱へ移す）が、利用者にとっては「消す」導線なので
        // 確認は外さない。分類を緩めるのは安全側の既定と逆である
        "content_trash",
        "page_publish",
        "page_unpublish",
        "page_trash",
        "term_delete",
      ].sort(),
    );

    for (const tool of TOOLS) {
      // 破壊的なツールを readOnly と申告しない
      expect(tool.readOnly && DESTRUCTIVE_TOOLS.includes(tool.name)).toBe(
        false,
      );
    }
  });

  it("ページの完全削除ツールは存在しない", () => {
    const names = Object.keys(TOOL_OPERATIONS);

    for (const forbidden of ["page_delete", "page_purge", "page_destroy"]) {
      expect(names).not.toContain(forbidden);
    }
  });

  it("page_create は下書きを作るだけで、公開は別ツール", () => {
    const create = TOOLS.find((t) => t.name === "page_create");

    expect(Object.keys(create?.inputSchema.shape ?? {})).toEqual([
      "title",
      "slug",
    ]);
    // 公開状態を作成時に指定させない
    expect(Object.keys(create?.inputSchema.shape ?? {})).not.toContain(
      "status",
    );
    expect(DESTRUCTIVE_TOOLS).not.toContain("page_create");
    expect(create?.description).toContain("下書き");
  });

  /**
   * Phase 9.3B / #17（F-P1・MB-2 / MB-3）:
   * ページの管理方式は入力 contract に存在しない。
   *
   * 既定の `z.object` は未知キーを**黙って strip する**ため、`managedBy` を送っても
   * 「何も言われずに無視された」ように見えてしまう。strict にして明示的に拒否し、
   * Application Service（CreatePage）と同じ結論になるようにする。
   */
  it("page_create は managedBy や未知キーを黙って捨てず拒否する", () => {
    const create = TOOLS.find((t) => t.name === "page_create");
    const valid = { title: "会社案内", slug: "company" };

    // 正常な contract は従来どおり通る
    expect(create?.inputSchema.safeParse(valid).success).toBe(true);

    for (const extra of [
      { managedBy: "blade" },
      { managedBy: "document" },
      { managed_by: "blade" },
      { status: "published" },
      { whatever: 1 },
    ]) {
      const parsed = create?.inputSchema.safeParse({ ...valid, ...extra });

      expect(parsed?.success, `${JSON.stringify(extra)} が受理された`).toBe(
        false,
      );
    }

    // 公開 shape は title / slug のままで、キーを増やしていない
    expect(Object.keys(create?.inputSchema.shape ?? {})).toEqual([
      "title",
      "slug",
    ]);
  });

  /**
   * Phase 9.3B / #14・#15: 分類ツールは **正確に 7 件**（SPEC §P.3）。
   *
   * **数は書かない。**
   *
   * ここには「22（既存）+ 7 + 1 + 1 = 31」と、`original` が 22 件であることを
   * 数える行が並んでいた。だが `toHaveLength(22)` は**集合を見ていない** —
   * 既存の 1 つが別名に変わっても 22 のままである。
   * コメントは「増えたものを名指しで外してから残りを確かめる」と書いており、
   * **コードより強いことを主張していた**。
   *
   * 公開範囲そのものは、このファイルの先頭が**名前の完全な一覧**で固定し、
   * この試験の末尾が **`TOOLS` と `TOOL_OPERATIONS` の集合一致**を見ている。
   * 数の 3 行は、その 2 つが既に言っていることを弱く言い直していただけだった。
   */
  it("分類 7 件とゴミ箱・版の一覧だけを足している", () => {
    const names = Object.keys(TOOL_OPERATIONS);

    const taxonomyTools = names.filter(
      (n) => n.startsWith("taxonomy_") || n.startsWith("term_"),
    );

    expect(taxonomyTools.sort()).toEqual(
      [
        "taxonomy_list",
        "taxonomy_get",
        "term_list",
        "term_create",
        "term_update",
        "term_reorder",
        "term_delete",
      ].sort(),
    );

    const added = ["content_trash", "page_document_versions"];

    for (const name of added) {
      expect(names).toContain(name);
    }

    // operation ID / tool 名に重複が無い
    const ids = Object.values(TOOL_OPERATIONS);
    expect(new Set(ids).size).toBe(ids.length);
    expect(new Set(TOOLS.map((t) => t.name)).size).toBe(TOOLS.length);

    // TOOL_OPERATIONS と TOOLS が完全に同じ集合
    expect(TOOLS.map((t) => t.name).sort()).toEqual(names.sort());
  });

  /**
   * 版（`lock_version`）は **省略できるが、書くなら正しく**（Phase 9.8 / STAGE 21C）。
   *
   * schema で弾けるものは入口で弾く。サーバー側も同じ範囲で断るので、
   * どちらか片方だけの守りにはなっていない。
   */
  it("lock_version は省略可能で、安全に扱える整数だけを受ける", () => {
    const update = TOOLS.find((t) => t.name === "content_update");
    const shape = update?.inputSchema.shape as
      | Record<string, { safeParse: (v: unknown) => { success: boolean } }>
      | undefined;
    const lock = shape?.lock_version;

    expect(lock).toBeDefined();

    // 省略できる（移行期間中）
    expect(update?.inputSchema.safeParse({ type: "post", id: 1, data: {} }).success).toBe(true);

    for (const ok of [0, 1, Number.MAX_SAFE_INTEGER]) {
      expect(lock?.safeParse(ok).success, `${ok} を弾いている`).toBe(true);
    }

    for (const bad of [
      -1,
      1.5,
      null,
      "3",
      Number.MAX_SAFE_INTEGER + 1,
    ]) {
      expect(lock?.safeParse(bad).success, `${String(bad)} が通っている`).toBe(
        false,
      );
    }
  });

  it("ゴミ箱への移動と互換 delete も同じ版の規則を使う", () => {
    for (const name of ["content_trash", "content_delete"]) {
      const tool = TOOLS.find((t) => t.name === name);
      const shape = tool?.inputSchema.shape as
        | Record<string, { safeParse: (v: unknown) => { success: boolean } }>
        | undefined;

      expect(shape?.lock_version, `${name} に lock_version が無い`).toBeDefined();

      for (const bad of [null, -1, 1.5, "0", Number.MAX_SAFE_INTEGER + 1]) {
        expect(
          shape?.lock_version?.safeParse(bad).success,
          `${name}: ${String(bad)} が通っている`,
        ).toBe(false);
      }

      for (const ok of [0, 1, Number.MAX_SAFE_INTEGER]) {
        expect(
          shape?.lock_version?.safeParse(ok).success,
          `${name}: ${ok} を弾いている`,
        ).toBe(true);
      }

      // 省略はできる（移行期間中）
      expect(
        tool?.inputSchema.safeParse({ type: "post", id: 1 }).success,
        `${name}: lock_version が必須になっている`,
      ).toBe(true);

      expect(tool?.destructive, `${name} は確認を要する`).toBe(true);
    }
  });

  it("分類そのものの作成・更新・削除ツールは公開しない（Phase 9.4 へ延期）", () => {
    const names = Object.keys(TOOL_OPERATIONS);
    const ids = Object.values(TOOL_OPERATIONS);

    for (const forbidden of [
      "taxonomy_create",
      "taxonomy_update",
      "taxonomy_delete",
      "taxonomy_sync",
      "term_assign",
      "term_unassign",
      "term_archive",
      "term_unarchive",
    ]) {
      expect(names).not.toContain(forbidden);
    }

    for (const forbidden of [
      "taxonomy.create",
      "taxonomy.update",
      "taxonomy.delete",
      "taxonomy.sync",
    ]) {
      expect(ids).not.toContain(forbidden);
    }
  });

  it("分類 tool の operation ID と入力契約が Application Service と一致する", () => {
    const expected: Record<string, { operation: string; keys: string[] }> = {
      taxonomy_list: { operation: "taxonomy.list", keys: [] },
      taxonomy_get: { operation: "taxonomy.get", keys: ["key"] },
      term_list: {
        operation: "term.list",
        keys: ["taxonomyKey", "includeArchived"],
      },
      term_create: {
        operation: "term.create",
        keys: ["taxonomyKey", "name", "slug", "description", "parentId"],
      },
      term_update: {
        operation: "term.update",
        keys: ["termId", "name", "slug", "description", "parentId", "archived"],
      },
      term_reorder: {
        operation: "term.reorder",
        keys: ["taxonomyKey", "parentId", "orderedTermIds"],
      },
      term_delete: { operation: "term.delete", keys: ["termId"] },
    };

    for (const [name, spec] of Object.entries(expected)) {
      const tool = TOOLS.find((t) => t.name === name);

      expect(tool, `${name} が存在しない`).toBeTruthy();
      expect(TOOL_OPERATIONS[name as ToolName]).toBe(spec.operation);
      expect(Object.keys(tool?.inputSchema.shape ?? {}).sort()).toEqual(
        spec.keys.sort(),
      );
      expect(tool?.description?.length ?? 0).toBeGreaterThan(20);
    }
  });

  it("分類 tool の required / optional が正しい", () => {
    const required: Record<string, Record<string, unknown>> = {
      taxonomy_get: { key: "news_category" },
      term_list: { taxonomyKey: "news_category" },
      term_create: { taxonomyKey: "news_category", name: "お知らせ" },
      term_update: { termId: "01ABC" },
      term_reorder: { taxonomyKey: "news_category", orderedTermIds: ["01ABC"] },
      term_delete: { termId: "01ABC" },
    };

    for (const [name, minimal] of Object.entries(required)) {
      const tool = TOOLS.find((t) => t.name === name);

      // 必須項目だけで通る
      expect(
        tool?.inputSchema.safeParse(minimal).success,
        `${name} minimal`,
      ).toBe(true);

      // 必須項目を 1 つ落とすと通らない
      for (const key of Object.keys(minimal)) {
        const missing = { ...minimal };
        delete missing[key];
        expect(
          tool?.inputSchema.safeParse(missing).success,
          `${name} は ${key} を必須にしていない`,
        ).toBe(false);
      }
    }

    // description / parentId は明示 null を受ける（未指定と区別する）
    const create = TOOLS.find((t) => t.name === "term_create");
    expect(
      create?.inputSchema.safeParse({
        taxonomyKey: "k",
        name: "n",
        description: null,
        parentId: null,
      }).success,
    ).toBe(true);

    // archived は boolean のみ（"true" を暗黙変換しない）
    const update = TOOLS.find((t) => t.name === "term_update");
    expect(
      update?.inputSchema.safeParse({ termId: "01ABC", archived: true })
        .success,
    ).toBe(true);
    expect(
      update?.inputSchema.safeParse({ termId: "01ABC", archived: "true" })
        .success,
    ).toBe(false);

    // orderedTermIds は文字列配列のみ
    const reorder = TOOLS.find((t) => t.name === "term_reorder");
    expect(
      reorder?.inputSchema.safeParse({ taxonomyKey: "k", orderedTermIds: [1] })
        .success,
    ).toBe(false);
  });

  /**
   * `OperationCatalog` に在るが、**MCP へは出していない**操作。
   *
   * これは「出さないと決めた」記録ではなく、**今そうなっている**という記録である。
   * どれも後から入った仕組み（backup / form / menu / redirect）の読み取り系で、
   * MCP の口をそこまで広げていないだけである。
   *
   * 出すことにしたら、ここから消して `TOOL_OPERATIONS` へ足す。
   * catalog へ足しただけでここに現れなければ、上の検査が落ちる — それが狙いで、
   * **「AI へ出すか」を決めないまま操作が増えるのを止める**。
   */
  const NOT_EXPOSED_TO_AI = [
    "backup.list",
    "backup.verify",
    "form.get",
    "form.list",
    "menu.get",
    "menu.list",
    "redirect.list",
    "redirect.simulate",
  ];

  /**
   * 3 者一致の実測。**TS 側の一覧と PHP 側の allowlist を突き合わせる。**
   *
   * kit 側でだけ実行する（利用者プロジェクトに packages/cli は無い）。
   * 片方だけを書き換えたら落ちる。
   */
  it("すべての tool の operation ID が OperationCatalog に存在する", () => {
    const catalog = readFileSync(
      join(repoRoot, "template/app/Support/OperationCatalog.php"),
      "utf8",
    );

    const registered = [
      ...catalog.matchAll(
        /^\s*'([a-z][a-z.]*)' => OperationClassification::/gm,
      ),
    ].map((m) => m[1]);

    /*
     * **数を書かない。**
     *
     * かつてここは「catalog は 30 件」と数で固定していた。catalog が育つたびに
     * その数だけがずれ、**直し方が「数字を書き換える」になる** — そうすると
     * 「増えた 1 件を MCP へ出すのか出さないのか」という問いが消える。
     *
     * 代わりに、**出していないものを名前で挙げる**。catalog が育てば、
     * その名前がここに無いので落ちる。落ちた人は数字ではなく
     * **「この操作を AI へ出すか」を決めてから**この一覧に足すことになる。
     */
    expect([...registered].sort()).toEqual(
      [...Object.values(TOOL_OPERATIONS), ...NOT_EXPOSED_TO_AI].sort(),
    );

    // destructive 申告が PHP 側の分類と一致する（**出している操作についてだけ**）
    const exposed = new Set<string>(Object.values(TOOL_OPERATIONS));
    const destructive = [
      ...catalog.matchAll(
        /^\s*'([a-z][a-z.]*)' => OperationClassification::(Destructive|ExternalEffect),/gm,
      ),
    ]
      .flatMap((m) => (m[1] === undefined ? [] : [m[1]]))
      .filter((id) => exposed.has(id));

    expect(
      [...DESTRUCTIVE_TOOLS].map((name) => TOOL_OPERATIONS[name]).sort(),
    ).toEqual([...destructive].sort());
  });

  /**
   * **取り込みと取り消しは AI へ出さない**（Phase 9.14 / SPEC §E.3）。
   *
   * `wxr.apply` / `wxr.rollback` は監査に残る操作だが、`OperationCatalog` には
   * 足さないと決めてある。十年分の他人のサイトを入れる / 消すのは、
   * **人が明示的に走らせる操作**である。
   *
   * template 側にも同じ意味の検査があるが、**catalog を読む側からも見ておく** —
   * 足した人がまず触るのはこのファイルだからである。
   */
  /**
   * **復元は AI へ出さない**（Phase 9.6 / SPEC §4.12・Owner 決定 2026-08-19）。
   *
   * 復元は**人が編集している draft を上書きする**。そしてエージェントには
   * **誰かが編集中かを知る手段が無い** — 取り消しも復元も `expectedVersion` を
   * 持たないからである（§4.10 の前提注記）。「AI が押せる復元」は
   * **人の編集を黙って巻き戻せる**ことを意味する。
   *
   * `wxr.rollback` を出さないのと同じ系譜の判断で、**決めた記録が実在する**。
   */
  it("ページの復元は AI へ出さない", () => {
    const catalog = readFileSync(
      join(repoRoot, "template/app/Support/OperationCatalog.php"),
      "utf8",
    );

    expect(catalog).not.toMatch(/'page\.document\.restore'/);
    expect(Object.values(TOOL_OPERATIONS)).not.toContain(
      "page.document.restore",
    );
    expect(Object.keys(TOOL_OPERATIONS)).not.toContain("page_document_restore");
  });

  it("取り込み系の操作は AI へ出さない", () => {
    const catalog = readFileSync(
      join(repoRoot, "template/app/Support/OperationCatalog.php"),
      "utf8",
    );

    expect(catalog).not.toMatch(/'wxr\./);
    expect(Object.values(TOOL_OPERATIONS).filter((id) => id.startsWith("wxr."))).toEqual([]);
  });

  it("term_delete は destructive として申告される", () => {
    expect(DESTRUCTIVE_TOOLS).toContain("term_delete");
    expect(TOOLS.find((t) => t.name === "term_delete")?.readOnly).toBe(false);

    // 読み取り専用の分類ツールは destructive ではない
    for (const readOnly of ["taxonomy_list", "taxonomy_get", "term_list"]) {
      expect(TOOLS.find((t) => t.name === readOnly)?.readOnly).toBe(true);
      expect(DESTRUCTIVE_TOOLS).not.toContain(readOnly);
    }
  });

  /**
   * Phase 9.3B / STAGE 3C: 分類での絞り込みは **公開契約にしていない**。
   *
   * この検査は Kit 側（CLI）の責務。利用者の生成プロジェクトに `packages/cli/` は
   * 無いため、template 側の PHP テストからは読まない。
   */
  it("content_list の入力は type / q / page / perPage だけ（filter を足していない）", () => {
    const tool = TOOLS.find((t) => t.name === "content_list");
    const keys = Object.keys(tool?.inputSchema.shape ?? {});

    expect(keys).toEqual(["type", "q", "page", "perPage"]);

    for (const forbidden of [
      "taxonomyFilter",
      "taxonomyFilters",
      "taxonomies",
      "terms",
      "filterByTerm",
    ]) {
      expect(keys).not.toContain(forbidden);
    }

    // 未知キーを渡しても契約が増えたことにはならない（schema 側で受理しない）
    expect(
      tool?.inputSchema.strict().safeParse({ type: "news", terms: ["01ABC"] })
        .success,
    ).toBe(false);
  });

  it("content_create / content_update は taxonomies を任意入力として受け取る", () => {
    for (const name of ["content_create", "content_update"] as const) {
      const tool = TOOLS.find((t) => t.name === name);
      const shape = tool?.inputSchema.shape ?? {};

      expect(Object.keys(shape)).toContain("taxonomies");
      // 省略可能であること（省略 = 既存の割り当てを変更しない）
      expect(
        tool?.inputSchema.safeParse({ type: "x", id: 1, data: {} }).success,
      ).toBe(true);
      // 分類キー → 文字列配列。scalar を 1 件配列へ暗黙変換しない
      expect(
        tool?.inputSchema.safeParse({
          type: "x",
          id: 1,
          data: {},
          taxonomies: { some_key: ["01ABC"] },
        }).success,
      ).toBe(true);
      expect(
        tool?.inputSchema.safeParse({
          type: "x",
          id: 1,
          data: {},
          taxonomies: { some_key: "01ABC" },
        }).success,
      ).toBe(false);
    }
  });

  it("media_replace はサーバー上のファイルパスを受け取らない", () => {
    const tool = TOOLS.find((t) => t.name === "media_replace");
    const keys = Object.keys(tool?.inputSchema.shape ?? {});

    expect(keys).toEqual(["id", "filename", "contentBase64"]);
    expect(keys).not.toContain("path");
  });

  it("component 操作は page_document_patch へ統合されている（tool を増やさない）", () => {
    const names = Object.keys(TOOL_OPERATIONS);

    for (const separate of [
      "component_insert",
      "component_update",
      "component_move",
      "component_delete",
    ]) {
      expect(names).not.toContain(separate);
    }

    expect(names).toContain("page_document_patch");
  });

  it("ツール定義に Content Type 名を焼き込まない", () => {
    const serialized = JSON.stringify(
      TOOLS.map((t) => ({ name: t.name, description: t.description })),
    );

    expect(serialized).not.toContain("news");
    expect(serialized).not.toContain("works");
  });
});

describe("bridge result parsing", () => {
  it("Result JSON の行だけを拾う", () => {
    const out = 'noise line\n{"status":"success","data":{"a":1}}\n';

    expect(parseResult(out)?.status).toBe("success");
  });

  it("JSON が無ければ null（勝手に成功扱いしない）", () => {
    expect(parseResult("Warning: something\n")).toBeNull();
    expect(parseResult("")).toBeNull();
  });

  it("status を持たない JSON は Result として扱わない", () => {
    expect(parseResult('{"foo":"bar"}')).toBeNull();
  });
});

describe("agent config generation", () => {
  it("生成できるのは形式を確認済みの 3 クライアントのみ", () => {
    expect(AGENT_CLIENTS.map((c) => c.id)).toEqual([
      "claude-code",
      "cursor",
      "codex",
    ]);

    for (const client of AGENT_CLIENTS) {
      expect(client.source).toMatch(/^https:\/\//);
    }
  });

  it("Claude Code / Cursor は mcpServers 形式、Codex は mcp_servers の TOML", () => {
    const root = "/tmp/example-site";
    const claude = JSON.parse(renderConfig(AGENT_CLIENTS[0]!, root)) as Record<
      string,
      Record<string, Record<string, unknown>>
    >;

    expect(claude.mcpServers?.[SERVER_KEY]?.type).toBe("stdio");
    expect(claude.mcpServers?.[SERVER_KEY]?.args).toContain("mcp");
    expect(claude.mcpServers?.[SERVER_KEY]?.args).toContain(root);

    const cursor = JSON.parse(renderConfig(AGENT_CLIENTS[1]!, root)) as {
      mcpServers: Record<string, Record<string, unknown>>;
    };
    // Cursor の公式例に type は無い
    expect(cursor.mcpServers[SERVER_KEY]).not.toHaveProperty("type");

    const codex = renderConfig(AGENT_CLIENTS[2]!, root);
    expect(codex).toContain(`[mcp_servers.${SERVER_KEY}]`);
    expect(codex).toContain('command = "');
  });

  it("起動コマンドは実行中の Node（同梱ランタイム）を指す", () => {
    const config = JSON.parse(renderConfig(AGENT_CLIENTS[0]!, "/tmp/x")) as {
      mcpServers: Record<string, { command: string }>;
    };

    expect(config.mcpServers[SERVER_KEY]?.command).toBe(process.execPath);
  });

  it("書き出し先はプロジェクトフォルダ内", () => {
    const root = mkdtempSync(join(tmpdir(), "agentcms-mcp-"));
    const written = writeConfig(AGENT_CLIENTS[0]!, root);

    expect(written).toBe(join(root, ".mcp.json"));
    expect(readFileSync(written, "utf8")).toContain(SERVER_KEY);
  });
});

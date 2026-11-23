/**
 * **利用者のプロジェクトで figma import が壊れないこと**（B-35）。
 *
 * `figma/cms.ts` の `MODULE_HINTS` は `works` / `news` を
 * 「kit 標準 module」として持っているが、**配布境界はその 2 つを配らない**。
 * 型が在るかを見ずに `existing-module` と決めると、生成物が
 * `Modules\Works\Models\Work` を書き、利用者の手元では静的解析も実行も落ちる。
 *
 * ▷ R-13（import 側）と**同じ家族**である。違うのは入口が Figma だという点だけ。
 *   だから出口の検査も**同じ座**を使う（`tests/support/output-guard.ts`）。
 *
 * figma の試験としては**初めて配布境界の向こう側を通る**。
 */
import { execFileSync } from "node:child_process";
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
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import { distributesContentType } from "../src/distribution.js";
import {
  declaredRouteNames,
  emittedRouteNames,
  undistributedModuleMarkers,
  unresolvedRoutes,
} from "./support/output-guard.js";

const CLI = resolve(__dirname, "../dist/index.js");
const FIXTURE = resolve(__dirname, "fixtures/figma");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

let ws: string;
let project: string;
let fixtureDir: string;

function run(args: string[]): string {
  return execFileSync("node", [CLI, ...args], {
    cwd: project,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    env: { ...process.env, AGENTCMS_FIGMA_FIXTURE: fixtureDir },
  });
}

/** 生成プロジェクトの中身（相対パス → 中身） */
function filesUnder(dir: string, base = project): Record<string, string> {
  const out: Record<string, string> = {};

  if (!existsSync(dir)) return out;

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === ".git") continue;

    const path = join(dir, entry.name);

    if (entry.isDirectory()) Object.assign(out, filesUnder(path, base));
    else if (entry.isFile()) {
      try {
        out[path.slice(base.length + 1)] = readFileSync(path, "utf8");
      } catch {
        continue; // binary
      }
    }
  }

  return out;
}

/** 一時 workspace に、`keep(id)` が真の投稿タイプだけを持つプロジェクトを作る */
function setupProject(root: string, keep: (id: string) => boolean): string {
  const dir = join(root, "project");

  mkdirSync(join(dir, "content", "schemas"), { recursive: true });
  cpSync(SCHEMAS, join(dir, "content", "schemas"), {
    recursive: true,
    filter: (src) => {
      if (src.includes(`${sep}overrides`)) return false;
      if (!src.endsWith(".json")) return true;

      return keep(src.slice(src.lastIndexOf(sep) + 1, -".json".length));
    },
  });

  writeFileSync(join(dir, "artisan"), "");
  writeFileSync(join(dir, "docker-compose.yml"), "services: {}\n");
  writeFileSync(join(dir, ".env"), "APP_NAME=figma-buyer\n");
  mkdirSync(join(dir, "routes"), { recursive: true });
  writeFileSync(join(dir, "routes", "web.php"), "<?php\n");
  mkdirSync(join(dir, "resources", "css"), { recursive: true });
  writeFileSync(
    join(dir, "resources", "css", "public.css"),
    ":root {\n    --color-accent: #0f4c46;\n}\n",
  );
  mkdirSync(join(dir, ".agentcms"), { recursive: true });
  writeFileSync(join(dir, ".gitignore"), ".agentcms/cache/\n");
  execFileSync("git", ["init", "-q"], { cwd: dir });

  return dir;
}

beforeAll(() => {
  expect(
    existsSync(CLI),
    "dist が必要です（pnpm -C packages/cli build を先に実行）",
  ).toBe(true);

  ws = mkdtempSync(join(tmpdir(), "agentcms-figma-buyer-"));
  fixtureDir = join(ws, "fixture");
  cpSync(FIXTURE, fixtureDir, { recursive: true });

  /*
   * **配布境界に聞いて、配るものだけを置く。**
   * ここで 4 つを手で書くと、境界が変わった日に置いていかれる。
   */
  project = setupProject(ws, distributesContentType);

  run([
    "figma",
    "connect",
    "https://www.figma.com/design/FKEYFIXTURE0001/Northwind?node-id=0-1",
  ]);
  run(["figma", "inspect"]);
  run(["figma", "plan"]);
});

afterAll(() => {
  rmSync(ws, { recursive: true, force: true });
});

function plan(): {
  cms: { candidate_id: string; action: string; module: string | null }[];
  uncertainties: { area: string; message: string }[];
} {
  return JSON.parse(
    readFileSync(
      join(project, ".agentcms", "manifest", "figma-plan.json"),
      "utf8",
    ),
  ) as ReturnType<typeof plan>;
}

describe("受け皿の投稿タイプが無い figma import", () => {
  it("そもそも kit の型を持っていない（この試験の前提）", () => {
    const ids = readdirSync(join(project, "content", "schemas"))
      .filter((f) => f.endsWith(".json"))
      .map((f) => f.replace(/\.json$/, ""));

    expect(ids).not.toContain("works");
    expect(ids).not.toContain("news");
  });

  it("既存 module へ載せず、見た目のまま static として取り込む", () => {
    const cms = plan().cms;

    expect(
      cms.length,
      "CMS 候補が 1 つも無いと何も検査していない",
    ).toBeGreaterThan(0);
    expect(cms.filter((c) => c.action === "existing-module")).toStrictEqual([]);
  });

  it("黙って落とさず、型を作れば載せ替えられることを言う", () => {
    const messages = plan()
      .uncertainties.filter((u) => u.area === "cms")
      .map((u) => u.message);

    const notice = messages.find((m) => m.includes("受け皿の module"));

    expect(notice, `cms の不確実性: ${messages.join(" / ")}`).toBeDefined();
    expect(notice).toContain("[works]"); // 何が足りないか
    expect(notice).toContain("見た目のまま静的に取り込む"); // 何をしたか
    // **CMS で作った同名の型が在っても静的**（生成器が書けるのは kit の module のクラスだけ）
    expect(notice).toContain("kit の module のクラス");
  });
});

/**
 * **CMS が作った型が在る世界**（監査 指摘 45）。
 *
 * figma の生成器は kit の module のクラス名（`Modules\Works\Models\Work` — **単数**）を
 * 直接書き出す。CMS が作った型のクラスは `…\Models\Works`（Studly を 2 度）で**別物**なので、
 * 「型が在る」だけで `existing-module` に倒すと**存在しないクラスを書く**。
 *
 * この世界は R-13 Stage 2（利用者も投稿タイプを持てる）から**今日起こり得るようになった**。
 * kit の schemas を丸ごと写すだけの workspace では `managedBy: "cms"` を持つのは `post` だけで、
 * figma の候補はそこを指さない — **守ろうとした場面そのものを一度も通っていなかった。**
 */
describe("CMS が作った型は、kit の module として扱わない", () => {
  let cmsWs: string;
  let generated: Record<string, string>;
  let planJson: {
    cms: { candidate_id: string; action: string; module: string | null }[];
  };

  beforeAll(() => {
    cmsWs = mkdtempSync(join(tmpdir(), "agentcms-figma-cms-"));

    const cmsProject = setupProject(cmsWs, () => true);

    // kit の形に、**CMS が作った works** を重ねる
    const schemaPath = join(cmsProject, "content", "schemas", "works.json");
    const schema = JSON.parse(readFileSync(schemaPath, "utf8")) as {
      managedBy?: string;
    };

    schema.managedBy = "cms";
    writeFileSync(schemaPath, `${JSON.stringify(schema, null, 2)}\n`, "utf8");

    const saved = project;

    project = cmsProject;

    try {
      run([
        "figma",
        "connect",
        "https://www.figma.com/design/FKEYFIXTURE0001/Northwind?node-id=0-1",
      ]);
      run(["figma", "inspect"]);
      run(["figma", "plan"]);
      run(["figma", "apply"]);

      planJson = JSON.parse(
        readFileSync(
          join(cmsProject, ".agentcms", "manifest", "figma-plan.json"),
          "utf8",
        ),
      ) as typeof planJson;
      generated = filesUnder(cmsProject, cmsProject);
    } finally {
      project = saved;
    }
  });

  afterAll(() => {
    rmSync(cmsWs, { recursive: true, force: true });
  });

  it("既存 module には載せず、静的に取り込む", () => {
    const works = planJson.cms.find((c) => c.candidate_id.includes("works"));

    expect(
      works,
      "works の候補が出ていない＝この試験は何も見ていない",
    ).toBeDefined();
    expect(works?.action).toBe("static");
  });

  it("kit の module のクラスを書き出さない", () => {
    const body = Object.values(generated).join("\n");

    expect(body).not.toContain("Modules\\Works\\Models\\Work");
  });
});

describe("生成物が、利用者の世界に無いものを呼ばない", () => {
  let generated: Record<string, string>;

  beforeAll(() => {
    run(["figma", "apply"]);
    generated = filesUnder(project);
  });

  it.each(
    undistributedModuleMarkers(["news", "product", "works"]).map((m) => [m]),
  )("%s が出ない（配らない module を名指ししている）", (needle) => {
    const hits = Object.entries(generated)
      .filter(([, body]) => body.includes(needle!))
      .map(([rel]) => rel);

    expect(hits).toStrictEqual([]);
  });

  /**
   * **0 件を名乗る前に、その針が本当に刺さるのかを確かめる**。
   *
   * 上の 6 本は「出ない」を見ている。針が的外れなら**何をしても緑**なので、
   * **型が在る世界（kit の形）では同じ針が刺さる**ことを別の workspace で示す。
   */
  it("型が在る世界では、同じ針が刺さる（見張っている証拠）", () => {
    const kitWs = mkdtempSync(join(tmpdir(), "agentcms-figma-kit-"));

    try {
      const kitProject = setupProject(kitWs, () => true);
      const saved = project;

      project = kitProject;

      try {
        run([
          "figma",
          "connect",
          "https://www.figma.com/design/FKEYFIXTURE0001/Northwind?node-id=0-1",
        ]);
        run(["figma", "inspect"]);
        run(["figma", "plan"]);
        run(["figma", "apply"]);

        const body = Object.values(filesUnder(kitProject, kitProject)).join(
          "\n",
        );

        /*
         * **針そのもの**（上の 6 本が使うのと同じ導出）で刺す。
         * ここで文字列を手書きすると、導出がずれても canary は緑のままになる
         * （実際 1 度そうなった — 導出を壊しても 11 本とも通った）。
         */
        const hits = undistributedModuleMarkers(["works"]).filter((m) =>
          body.includes(m),
        );

        expect(
          hits,
          "kit の形でも 1 つも刺さらない = 針が的外れ（上の 0 件は無意味）",
        ).not.toStrictEqual([]);
      } finally {
        project = saved;
      }
    } finally {
      rmSync(kitWs, { recursive: true, force: true });
    }
  });

  /** 針が鳴ることを、同じ導出で確かめる（監査 指摘 36） */
  it("route の針が鳴る（見張っている証拠）", () => {
    const all = Object.entries(generated)
      .map(([rel, body]) => `${rel}\n${body}`)
      .join("\n");

    expect(emittedRouteNames(all).length).toBeGreaterThan(0);
    expect(unresolvedRoutes(all, [], new Set()).length).toBeGreaterThan(0);
  });

  it("呼んだ route が 1 つ残らず解決できる", () => {
    const all = Object.entries(generated)
      .map(([rel, body]) => `${rel}\n${body}`)
      .join("\n");

    // figma の route は `->name('figma.')->group(...)` の中に居る
    const declared = declaredRouteNames(
      generated["routes/figma.php"] ?? "",
      "figma.",
    );

    expect(declared.size, "route が 1 本も生成されていない").toBeGreaterThan(0);

    const unresolved = unresolvedRoutes(
      all,
      ["home", "about", "service", "contact.show", "contact.store"],
      declared,
    );

    expect(
      unresolved,
      `生成物が、どこにも無い route を呼んでいます: ${unresolved.join(", ")}`,
    ).toStrictEqual([]);
  });
});

/**
 * Metadata First / Raw Cache / diff / config / plan のテスト。
 * SPEC §100: 2 (token config rejection) / 3 (metadata fetch) / 4 (metadata cache hit)
 * / 5 (node-targeted fetch) / 6 (Raw Cache) / 9 (stale detection は pipeline e2e)
 * / 14 (existing component matching) / 16–17 (token) / 22 (uncertainty)
 */
import {
  cpSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import { FixtureFigmaApi } from "../src/figma/api-fixture.js";
import { loadFigmaConfig, saveFigmaConfig } from "../src/figma/config.js";
import { diffManifests } from "../src/figma/diff.js";
import { fetchDesignData } from "../src/figma/fetch.js";
import { buildDesignManifest } from "../src/figma/normalize.js";
import { buildFigmaPlan } from "../src/figma/plan.js";

const FIXTURE = resolve(__dirname, "fixtures/figma");
const TEMPLATE = resolve(__dirname, "../../../template");

let ws: string;

beforeAll(() => {
  ws = mkdtempSync(join(tmpdir(), "agentcms-figma-"));
});

afterAll(() => {
  rmSync(ws, { recursive: true, force: true });
});

function makeAgentcmsDir(name: string): string {
  const dir = join(ws, name, ".agentcms");
  mkdirSync(dir, { recursive: true });
  return dir;
}

const CONFIG = {
  version: 1 as const,
  file_key: "FKEYFIXTURE0001",
  file_name: "northwind",
  file_type: "design" as const,
  entry_nodes: [],
};

describe("figma config", () => {
  it("connect の roundtrip（token は含まれない）", () => {
    const dir = makeAgentcmsDir("cfg");
    saveFigmaConfig(dir, CONFIG);
    const { config } = loadFigmaConfig(dir);
    expect(config?.file_key).toBe("FKEYFIXTURE0001");
    expect(
      readFileSync(join(dir, "figma", "project.json"), "utf8"),
    ).not.toContain("token");
  });

  it("token を含む config を拒否する（SPEC §100 #2）", () => {
    const dir = makeAgentcmsDir("cfg-bad");
    mkdirSync(join(dir, "figma"), { recursive: true });
    writeFileSync(
      join(dir, "figma", "project.json"),
      JSON.stringify({
        ...CONFIG,
        token: "figd_SHOULD_NOT_BE_HERE_12345",
      }),
    );
    const { config, error } = loadFigmaConfig(dir);
    expect(config).toBeUndefined();
    expect(error?.why).toContain("Token");
    expect(error?.fix).toContain("revoke");
  });
});

describe("Metadata First + Raw Cache", () => {
  it("初回は meta + file のみ（Variables は NOT REQUESTED）、2 回目は meta のみ（cache hit — SPEC §100 #3/#4/#6）", async () => {
    const dir = makeAgentcmsDir("cache");
    const api = new FixtureFigmaApi(FIXTURE);
    const first = await fetchDesignData(api, dir, CONFIG);
    expect("data" in first && first.data.cacheHit).toBe(false);
    const callsAfterFirst = api.calls.map((c) => c.endpoint);
    // Standard Mode 既定: variables call は発生しない（Advanced Capability）
    expect(callsAfterFirst).toEqual(["file_meta", "file"]);

    const second = await fetchDesignData(api, dir, CONFIG);
    expect("data" in second && second.data.cacheHit).toBe(true);
    const newCalls = api.calls.slice(2).map((c) => c.endpoint);
    // version 未変更 → Tier 1 (file/nodes) call なし
    expect(newCalls).toEqual(["file_meta"]);
  });

  it("--variables 要求時のみ variables call が発生し、403 結果も version 単位で cache される", async () => {
    const dir = makeAgentcmsDir("cache-vars");
    const api = new FixtureFigmaApi(FIXTURE);
    const first = await fetchDesignData(api, dir, CONFIG, { variables: true });
    expect("data" in first && first.data.input.variablesRequested).toBe(true);
    expect(api.calls.map((c) => c.endpoint)).toEqual([
      "file_meta",
      "file",
      "variables",
    ]);
    const second = await fetchDesignData(api, dir, CONFIG, { variables: true });
    expect("data" in second).toBe(true);
    // 403 も cache 済み → 2 回目の variables call なし
    expect(api.calls.slice(3).map((c) => c.endpoint)).toEqual(["file_meta"]);
  });

  it("entry_nodes 指定時は node-targeted fetch（1 call に集約 — SPEC §100 #5）", async () => {
    const dir = makeAgentcmsDir("nodes");
    const api = new FixtureFigmaApi(FIXTURE);
    const result = await fetchDesignData(api, dir, {
      ...CONFIG,
      entry_nodes: ["1:2", "1:3"],
    });
    expect("data" in result).toBe(true);
    if ("data" in result) {
      expect(result.data.input.roots.map((r) => r.id)).toEqual(["1:2", "1:3"]);
    }
    expect(api.calls.filter((c) => c.endpoint === "file_nodes").length).toBe(1);
    expect(api.calls.filter((c) => c.endpoint === "file").length).toBe(0);
  });

  it("存在しない node は {what,why,fix} で失敗する", async () => {
    const dir = makeAgentcmsDir("missing");
    const api = new FixtureFigmaApi(FIXTURE);
    const result = await fetchDesignData(api, dir, {
      ...CONFIG,
      entry_nodes: ["999:999"],
    });
    expect("error" in result && result.error.why).toContain("999:999");
  });
});

describe("semantic diff（SPEC §67–68）", () => {
  it("テキスト変更 / component 追加を意味単位で検出する", async () => {
    const dir = makeAgentcmsDir("diff");
    const api = new FixtureFigmaApi(FIXTURE);
    const base = await fetchDesignData(api, dir, CONFIG);
    if (!("data" in base)) throw new Error("fetch failed");
    const before = buildDesignManifest(base.data.input);

    // fixture を複製して WORKS A → WORKS Z、version を変更
    const mutated = join(ws, "fixture-mutated");
    cpSync(FIXTURE, mutated, { recursive: true });
    const filePath = join(mutated, "file.json");
    writeFileSync(
      filePath,
      readFileSync(filePath, "utf8")
        .replaceAll("WORKS A", "WORKS Z")
        .replace('"version": "2001"', '"version": "2002"'),
    );
    const metaPath = join(mutated, "meta.json");
    writeFileSync(
      metaPath,
      readFileSync(metaPath, "utf8").replace(
        '"version": "2001"',
        '"version": "2002"',
      ),
    );
    const api2 = new FixtureFigmaApi(mutated);
    const dir2 = makeAgentcmsDir("diff2");
    const after = await fetchDesignData(api2, dir2, CONFIG);
    if (!("data" in after)) throw new Error("fetch failed");
    const next = buildDesignManifest(after.data.input);

    const diff = diffManifests(before, next);
    expect(diff.changed).toBe(true);
    expect(diff.from_version).toBe("2001");
    expect(diff.to_version).toBe("2002");
    const screenChange = diff.entries.find(
      (e) => e.area === "screen" && e.id === "home",
    );
    expect(screenChange?.kind).toBe("CHANGED");
    expect(screenChange?.detail).toContain("text");
    // layout は変えていないので component 構造変更は works-card の text 差分のみ
    expect(diff.entries.every((e) => e.kind !== "REMOVED")).toBe(true);
  });

  it("同一入力なら changed=false", async () => {
    const dir = makeAgentcmsDir("diff-same");
    const api = new FixtureFigmaApi(FIXTURE);
    const r = await fetchDesignData(api, dir, CONFIG);
    if (!("data" in r)) throw new Error("fetch failed");
    const a = buildDesignManifest(r.data.input);
    const b = buildDesignManifest(r.data.input);
    expect(diffManifests(a, b).changed).toBe(false);
  });
});

describe("figma plan（Existing Component First / token 照合）", () => {
  it("既存 template プロジェクトの token と照合: MATCH / CONFLICT / NEW_CANDIDATE（SPEC §41）", async () => {
    const dir = makeAgentcmsDir("plan");
    const api = new FixtureFigmaApi(FIXTURE);
    const r = await fetchDesignData(api, dir, CONFIG);
    if (!("data" in r)) throw new Error("fetch failed");
    const manifest = buildDesignManifest(r.data.input);
    const plan = buildFigmaPlan(manifest, TEMPLATE);

    const accent = plan.tokens.find((t) => t.name === "color-accent");
    expect(accent?.status).toBe("MATCH"); // #0f4c46 は既存 --color-accent と一致
    const text = plan.tokens.find((t) => t.name === "color-text");
    expect(text?.status).toBe("CONFLICT"); // #222222 ≠ 既存 #1c1d20
    expect(text?.existing_value).toBe("#1c1d20");
    const sun = plan.tokens.find((t) => t.name === "brand-sun");
    expect(sun?.status).toBe("NEW_CANDIDATE");

    // CONFLICT は uncertainty として人へ提示される（SPEC §100 #22）
    expect(plan.uncertainties.some((u) => u.area === "tokens")).toBe(true);
    // FONT MISSING → REVIEW（SPEC §54）
    const clash = plan.fonts.find((f) => f.family === "Clash Display");
    expect(clash?.availability).toBe("MISSING");
    expect(clash?.action).toBe("review");
  });

  it("既存 component と同名なら reuse-existing を提案（SPEC §35 / §100 #14）", async () => {
    const project = join(ws, "reuse-project");
    mkdirSync(join(project, "resources", "views", "components"), {
      recursive: true,
    });
    writeFileSync(
      join(project, "resources", "views", "components", "works-card.blade.php"),
      "<article></article>\n",
    );
    const dir = makeAgentcmsDir("plan-reuse");
    const api = new FixtureFigmaApi(FIXTURE);
    const r = await fetchDesignData(api, dir, CONFIG);
    if (!("data" in r)) throw new Error("fetch failed");
    const manifest = buildDesignManifest(r.data.input);
    const plan = buildFigmaPlan(manifest, project);

    const card = plan.components.find((c) => c.component_id === "works-card");
    expect(card?.action).toBe("reuse-existing");
    expect(card?.existing_target).toBe(
      "resources/views/components/works-card.blade.php",
    );
    const header = plan.components.find((c) => c.component_id === "header");
    expect(header?.action).toBe("layout");
    // CMS: works は existing-module + Design Fixture seed（SPEC §45）
    const works = plan.cms.find((c) => c.candidate_id === "works-repeat");
    expect(works?.action).toBe("existing-module");
    expect(works?.seed_items).toBe(3);
    // Figma に本文が無い → schema override（SPEC §63 / §100 #27）
    expect(
      plan.schema_overrides.some(
        (o) => o.schema === "works" && o.field === "body",
      ),
    ).toBe(true);
  });
});

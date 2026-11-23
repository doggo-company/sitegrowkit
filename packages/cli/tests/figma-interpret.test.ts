/**
 * Gap 3 — AI Interpretation Provenance の synthetic 回帰テスト。
 * generated → interpreted → verified の状態遷移で、
 * 「承認済み AI interpretation」と「勝手な変更」を区別できることを検証する。
 * hash 保護は弱めない（記録後の再変更・stale 記録は broken）。
 */
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import {
  type ApplyRecord,
  evaluateGeneratedFiles,
  hashContent,
  recordInterpretation,
} from "../src/importer/apply.js";

let root = "";

function writeProject(files: Record<string, string>): ApplyRecord {
  for (const [target, content] of Object.entries(files)) {
    writeFileSync(join(root, target), content, "utf8");
  }
  return {
    version: 1,
    manifest_fingerprint: "fp-manifest",
    plan_sha256: "sha-plan",
    applied_at: "2026-01-01T00:00:00.000Z",
    files: Object.entries(files).map(([target, content]) => ({
      target,
      sha256: hashContent(content),
      source: "test",
    })),
  };
}

beforeEach(() => {
  root = mkdtempSync(join(tmpdir(), "agentcms-interpret-"));
});
afterEach(() => {
  rmSync(root, { recursive: true, force: true });
});

describe("figma interpret — provenance の状態遷移", () => {
  it("generated のまま → 全ファイル matched", () => {
    const record = writeProject({ "a.txt": "gen-a", "b.txt": "gen-b" });
    const r = evaluateGeneratedFiles(root, record);
    expect(r.matched).toEqual(["a.txt", "b.txt"]);
    expect(r.interpreted).toEqual([]);
    expect(r.broken).toEqual([]);
  });

  it("未承認の変更は broken（勝手な変更として検出）", () => {
    const record = writeProject({ "a.txt": "gen-a" });
    writeFileSync(join(root, "a.txt"), "modified", "utf8");
    const r = evaluateGeneratedFiles(root, record);
    expect(r.broken).toHaveLength(1);
    expect(r.broken[0]?.why).toContain("承認されていない変更");
  });

  it("interpret record 後は interpreted として PASS 相当になる", () => {
    const record = writeProject({ "a.txt": "gen-a", "b.txt": "gen-b" });
    writeFileSync(join(root, "a.txt"), "ai-interpreted", "utf8");
    const {
      record: next,
      interpreted,
      unchanged,
    } = recordInterpretation(root, record);
    expect(interpreted).toEqual(["a.txt"]);
    expect(unchanged).toEqual(["b.txt"]);
    expect(next.interpretation?.status).toBe("interpreted");
    // origin（generated hash）が証跡として残る
    expect(next.interpretation?.files[0]?.generated_sha256).toBe(
      hashContent("gen-a"),
    );
    const r = evaluateGeneratedFiles(root, next);
    expect(r.matched).toEqual(["b.txt"]);
    expect(r.interpreted).toEqual(["a.txt"]);
    expect(r.broken).toEqual([]);
  });

  it("記録後にさらに変更されたら broken（hash 保護は弱まらない）", () => {
    const record = writeProject({ "a.txt": "gen-a" });
    writeFileSync(join(root, "a.txt"), "ai-interpreted", "utf8");
    const { record: next } = recordInterpretation(root, record);
    writeFileSync(join(root, "a.txt"), "tampered-after-record", "utf8");
    const r = evaluateGeneratedFiles(root, next);
    expect(r.broken).toHaveLength(1);
    expect(r.broken[0]?.why).toContain("記録後にさらに変更");
  });

  it("stale な interpretation 記録（Manifest / Plan が変わった）は broken", () => {
    const record = writeProject({ "a.txt": "gen-a" });
    writeFileSync(join(root, "a.txt"), "ai-interpreted", "utf8");
    const { record: next } = recordInterpretation(root, record);
    const stale: ApplyRecord = {
      ...next,
      manifest_fingerprint: "fp-manifest-NEW",
    };
    const r = evaluateGeneratedFiles(root, stale);
    expect(r.broken).toHaveLength(1);
    expect(r.broken[0]?.why).toContain("stale");
  });

  it("欠落ファイルは記録できない（missing として報告）", () => {
    const record = writeProject({ "a.txt": "gen-a" });
    rmSync(join(root, "a.txt"));
    const { missing } = recordInterpretation(root, record);
    expect(missing).toEqual(["a.txt"]);
  });
});

/**
 * Gap 7 — Verify Runtime Capability の unit テスト。
 * Docker 起動時は Docker、未起動時は互換 host runtime、どちらも不可なら WHAT/WHY/FIX。
 */
import { describe, expect, it } from "vitest";

import {
  composeProjectMatches,
  minimumPhpVersion,
  phpSatisfies,
  resolveVerifyRuntime,
} from "../src/verify-runtime.js";

describe("verify runtime capability", () => {
  it("Docker 起動時は Docker を選ぶ（host が使えても Docker 優先）", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: true,
      hostPhpVersion: "8.5.0",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("docker");
  });

  it("Docker 未起動でも互換 host PHP + vendor があれば host 実行できる", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: false,
      hostPhpVersion: "8.5.8",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("host");
    if (r.kind === "host") {
      // 使用 PHP version と依存互換の明示が必須
      expect(r.phpVersion).toBe("8.5.8");
      expect(r.note).toContain("8.5.8");
      expect(r.note).toContain("^8.4");
    }
  });

  it("host PHP が要求 version を満たさなければ unavailable（WHAT/WHY/FIX）", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: false,
      hostPhpVersion: "8.3.1",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("unavailable");
    if (r.kind === "unavailable") {
      expect(r.why).toContain("8.3.1");
      expect(r.fix).toContain("agentcms dev");
    }
  });

  it("php が無い / vendor が無い場合も unavailable として理由を並べる", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: false,
      hostPhpVersion: null,
      vendorPresent: false,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("unavailable");
    if (r.kind === "unavailable") {
      expect(r.why).toContain("php がありません");
      expect(r.why).toContain("composer install");
    }
  });

  it("version 制約の解釈", () => {
    expect(minimumPhpVersion("^8.4")).toEqual({ major: 8, minor: 4 });
    expect(minimumPhpVersion(">=8.3")).toEqual({ major: 8, minor: 3 });
    expect(minimumPhpVersion(null)).toBeNull();
    expect(phpSatisfies("8.5.8", "^8.4")).toBe(true);
    expect(phpSatisfies("8.4.0", "^8.4")).toBe(true);
    expect(phpSatisfies("8.3.9", "^8.4")).toBe(false);
    expect(phpSatisfies("9.0.0", "^8.4")).toBe(true);
    // 制約を読めない場合は host 実行を妨げない（ゲート自体が検証する）
    expect(phpSatisfies("8.2.0", "invalid")).toBe(true);
  });
});

describe("runtime project isolation（Phase 6.1 実測 — compose 固定 project 名の誤帰属防止）", () => {
  it("A: 現在 project の Docker が起動 → docker を選ぶ", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: true,
      unrelatedDockerRunning: false,
      hostPhpVersion: "8.5.0",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("docker");
  });

  it("B: 別 project の Docker のみ起動 + 互換 host → 別 project を使わず host へ fallback", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: false,
      unrelatedDockerRunning: true,
      hostPhpVersion: "8.5.0",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("host");
    if (r.kind === "host") {
      expect(r.note).toContain("別プロジェクト");
    }
  });

  it("C: Docker 停止 + 互換 host → host（既存 fallback 設計は不変）", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: false,
      hostPhpVersion: "8.5.0",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("host");
  });

  it("D: 別 project の Docker 起動 + 非互換 host → 別 project Docker を使わず FAIL", () => {
    const r = resolveVerifyRuntime({
      dockerRunning: false,
      unrelatedDockerRunning: true,
      hostPhpVersion: "8.3.0",
      vendorPresent: true,
      requiredPhp: "^8.4",
    });
    expect(r.kind).toBe("unavailable");
    if (r.kind === "unavailable") {
      expect(r.why).toContain("別プロジェクト");
      expect(r.why).toContain("8.3.0");
      expect(r.fix).toContain("agentcms dev");
    }
  });

  it("composeProjectMatches — working_dir label による identity 判定", () => {
    expect(composeProjectMatches("/work/site", ["/work/site"])).toBe(true);
    expect(composeProjectMatches("/work/site/", ["/work/site"])).toBe(true);
    // macOS の /private prefix 差を吸収
    expect(composeProjectMatches("/private/work/site", ["/work/site"])).toBe(
      true,
    );
    expect(composeProjectMatches("/work/site", ["/other/project"])).toBe(false);
    expect(composeProjectMatches("/work/site", [null, ""])).toBe(false);
    expect(composeProjectMatches("/work/site", [])).toBe(false);
  });
});

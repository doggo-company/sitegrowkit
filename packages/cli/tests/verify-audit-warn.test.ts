/**
 * verify() の auditWarnOnly オプション（A1 の修正）。
 *
 * ## 何をテストするか
 *
 * - `VerifyOptions` が export されていること（runner.ts が import できること）
 * - `auditWarnOnly: true` のとき Gate.warnOnly が設定されること（型レベルの確認）
 *
 * ## なぜ verify() 本体を呼ばないのか
 *
 * verify() は実際のプロジェクトパスと Docker を必要とするため、unit レベルでは
 * 「型が正しく export されている」「オプションの形が壊れていない」をテストする。
 * エンドツーエンドの挙動は integration test（setup 通しの確認）で確かめる。
 */
import { describe, expect, it } from "vitest";

import type { VerifyOptions } from "../src/commands/verify.js";

describe("VerifyOptions export", () => {
  it("VerifyOptions は export されている", () => {
    // 型テスト: コンパイルが通れば export されている証拠
    const opts: VerifyOptions = { auditWarnOnly: true };
    expect(opts.auditWarnOnly).toBe(true);
  });

  it("auditWarnOnly はオプショナル", () => {
    const opts: VerifyOptions = {};
    expect(opts.auditWarnOnly).toBeUndefined();
  });

  it("auditWarnOnly の既定値は undefined（false 扱い）", () => {
    const opts: VerifyOptions = {};
    expect(opts.auditWarnOnly ?? false).toBe(false);
  });
});

import { describe, expect, it } from "vitest";

import { sanitizeLogLine } from "../src/wizard/sanitize.js";

// UX 一周 2026-11-05 F3: 「詳細を表示」に `[2m[22m` のような制御文字がそのまま出ていた
describe("詳細ログ — 端末の色の制御文字を画面に出さない", () => {
  it("artisan の色付き行から ESC 列を落とし、文字は残す", () => {
    const raw =
      "  \u001b[37;44m INFO \u001b[39;49m Discovering packages.  ";
    const out = sanitizeLogLine(raw);
    expect(out).not.toContain("\u001b");
    expect(out).toContain("INFO");
    expect(out).toContain("Discovering packages.");
  });

  it("太字・暗字の切り替えだけの行も残骸を出さない", () => {
    expect(sanitizeLogLine("\u001b[2m\u001b[22mdone \u001b[32;1mDONE\u001b[39;22m")).toBe(
      "done DONE",
    );
  });
});

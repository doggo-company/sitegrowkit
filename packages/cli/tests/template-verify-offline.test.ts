/**
 * `template verify --offline` が**本当に繋ぎに行かない**こと（B-20）。
 *
 * ## なぜ「出力に SKIP と出た」では足りないか
 *
 * SKIP の文言は、**繋ぎに行って失敗しても**似た形で出る。
 * 見たいのは文言ではなく **1 回も外へ出ていないこと**なので、`fetch` そのものを見張る。
 *
 * ## なぜこの門が要るのか
 *
 * HTTP 検査の宛先は `APP_URL`（既定 `http://localhost:8180`）で、**そこに居るものを何でも見る**。
 * 2026-08-20 に実際、:8180 に居た**別のプロジェクト**の `/works` 404 でこの門が赤くなった。
 * commit の前に回す門が「たまたま何が動いているか」で色を変えると、
 * **いずれ「また赤い」と読み飛ばされる**（B-2 の教訓）。
 */
import { afterEach, describe, expect, it, vi } from "vitest";

import { templateVerify } from "../src/commands/template-verify.js";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("--offline は外へ出ない", () => {
  it("fetch を 1 回も呼ばない", async () => {
    /*
     * **実際に動いているものが在っても**繋がないことを見る。
     * mock は「呼ばれたら分かる」ためだけに置く（呼ばれない前提なので応答は使われない）。
     */
    const fetchSpy = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(new Response("", { status: 200 }));

    // 出力そのものは検査の対象ではない（門の中身は template verify 自身が持つ）
    vi.spyOn(console, "log").mockImplementation(() => undefined);

    await templateVerify({ offline: true });

    expect(
      fetchSpy,
      "--offline なのに外へ出た（別プロジェクトの応答で色が変わる）",
    ).not.toHaveBeenCalled();
  });
});

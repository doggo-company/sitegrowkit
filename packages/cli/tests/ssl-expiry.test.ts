import { describe, expect, it } from "vitest";

import {
  certificateExpiryCheck,
  SSL_WARN_DAYS,
  type ExpiryProbe,
} from "../src/deploy/tls.js";

/**
 * SSL 有効期限の検査（P1 ⑪）。
 *
 * ## ここで一番大事なこと
 *
 * **測れなかったことを、通ったことにしない。**
 * 到達できない理由はいくらでもある（basic 認証の内側・IP 制限・DNS 未反映）。
 * そのとき「期限は大丈夫」とは言えないので、**PASS を返してはいけない**。
 *
 * 判定は純粋関数に切り出してあるので、**ネットワーク無しで確かめられる**。
 */
describe("SSL 有効期限の判定", () => {
  const now = new Date("2026-08-20T00:00:00Z");
  const after = (days: number): ExpiryProbe => ({
    ok: true,
    notAfter: new Date(now.getTime() + days * 86_400_000),
  });

  it("余裕があれば通る", () => {
    const check = certificateExpiryCheck("example.test", after(90), now);

    expect(check.status).toBe("PASS");
    expect(check.detected).toContain("残り 90 日");
  });

  it("期限が近ければ知らせる", () => {
    const check = certificateExpiryCheck("example.test", after(10), now);

    expect(check.status).toBe("WARN");
    expect(check.what).toContain("残り 10 日");
  });

  it("切れていれば止める", () => {
    const check = certificateExpiryCheck("example.test", after(-1), now);

    expect(check.status).toBe("FAIL");
    expect(check.what).toContain("既に切れています");
    // FAIL は「何が・なぜ・どう直すか」を必ず持つ（doctor の契約）
    expect(check.why).toBeTruthy();
    expect(check.fix).toBeTruthy();
  });

  /**
   * **黙って PASS にしない。**
   *
   * ここが PASS を返すようになると、**測れていないのに「大丈夫」と表示される**。
   * deploy 前検査は「確かめた」ことの記録なので、確かめていないものを混ぜない。
   */
  it("測れなかったら PASS にしない", () => {
    const check = certificateExpiryCheck(
      "example.test",
      { ok: false, reason: "5000ms で応答がありません" },
      now,
    );

    expect(check.status).not.toBe("PASS");
    expect(check.status).toBe("WARN");
    expect(check.what).toContain("読めませんでした");
    // 理由をそのまま伝える（何が起きたか分からないまま WARN にしない）
    expect(check.what).toContain("5000ms で応答がありません");
  });

  it("境界: ちょうど閾値なら知らせる側", () => {
    expect(
      certificateExpiryCheck("example.test", after(SSL_WARN_DAYS - 1), now)
        .status,
    ).toBe("WARN");
    expect(
      certificateExpiryCheck("example.test", after(SSL_WARN_DAYS), now).status,
    ).toBe("PASS");
  });

  it("閾値は呼ぶ側で変えられる", () => {
    expect(
      certificateExpiryCheck("example.test", after(45), now, 60).status,
    ).toBe("WARN");
  });
});

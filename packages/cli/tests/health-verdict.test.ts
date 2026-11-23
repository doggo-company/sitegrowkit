/**
 * B-11 — health が 503 のとき、**一番情報の要るときに一番情報が無い**状態だった。
 *
 * 200 のときしか body を読まないので、落ちている理由（`db` / `storage`）が
 * 画面に出ず、「エンドポイント未配置または JSON 以外の応答です」になっていた。
 * **落ちた理由は、落ちたときにこそ読めなければならない。**
 */
import { describe, expect, it } from "vitest";

import { healthVerdict } from "../src/deploy/health.js";

describe("health の判定と理由", () => {
  it("200 で ok なら通る", () => {
    const v = healthVerdict({
      status: 200,
      body: JSON.stringify({ ok: true, db: true, storage: true }),
    });

    expect(v.healthy).toBe(true);
  });

  /** **これが B-11 の本体。** 503 でも body は読む */
  it("503 でも db / storage を読んで理由に出す", () => {
    const v = healthVerdict({
      status: 503,
      body: JSON.stringify({ ok: false, db: false, storage: true }),
    });

    expect(v.healthy).toBe(false);
    expect(v.why).toContain("db=false");
    expect(v.why).toContain("storage=true");
  });

  it("JSON で返らないなら、そう言う（HTML の 500 など）", () => {
    const v = healthVerdict({ status: 500, body: "<html>Whoops</html>" });

    expect(v.healthy).toBe(false);
    expect(v.why).toContain("JSON 以外");
  });

  it("繋がらないなら、そう言う", () => {
    const v = healthVerdict(null);

    expect(v.healthy).toBe(false);
    expect(v.why).toContain("接続");
  });

  /**
   * **200 でないものを通さない。**
   *
   * body が `ok: true` と言っていても、status が 503 なら**外から見て落ちている**。
   * 自己申告だけで通すと、切り戻しの引き金が引かれない。
   */
  it("body が ok と言っても、200 でなければ通さない", () => {
    const v = healthVerdict({
      status: 503,
      body: JSON.stringify({ ok: true, db: true, storage: true }),
    });

    expect(v.healthy).toBe(false);
  });
});

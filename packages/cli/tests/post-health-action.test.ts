/**
 * B-12 — 「health が落ちたら旧 release へ戻す」経路を試験で固定する。
 *
 * コードを読む限り繋がってはいたが、**一度も試験で通していなかった**。
 * ここが繋がっていないと、**壊れた release が current のまま残る** —
 * deploy でいちばん起きてはいけないことである。
 */
import { describe, expect, it } from "vitest";

import { postHealthAction } from "../src/deploy/health.js";
import type { CapabilityCheck } from "../src/deploy/types.js";

const pass = (key: string): CapabilityCheck => ({
  key,
  name: key,
  status: "PASS",
});
const fail = (key: string): CapabilityCheck => ({
  key,
  name: key,
  status: "FAIL",
  what: "-",
  why: "-",
  fix: "-",
});
const warn = (key: string): CapabilityCheck => ({
  key,
  name: key,
  status: "WARN",
  what: "-",
  why: "-",
  fix: "-",
});

describe("health のあと何をするか", () => {
  it("全部 PASS なら進む", () => {
    expect(postHealthAction([pass("top"), pass("health")], "r-001")).toEqual({
      kind: "continue",
    });
  });

  it("FAIL が 1 つでもあれば、旧 release へ戻す", () => {
    expect(postHealthAction([pass("top"), fail("health")], "r-001")).toEqual({
      kind: "rollback",
      to: "r-001",
    });
  });

  /**
   * **WARN は引き金にならない。**
   *
   * 警告で切り戻すと、**動いている release を動いているのに戻す**ことになる。
   * 「直すべき」と「知っておくべき」は別である。
   */
  it("WARN だけなら戻さない", () => {
    expect(postHealthAction([pass("top"), warn("ssl")], "r-001")).toEqual({
      kind: "continue",
    });
  });

  /**
   * **戻す先が無いときに「戻した」と言わない。**
   *
   * 初回デプロイでは旧 release が無い。ここを rollback と同じ枝に流すと、
   * **存在しない release へ current を向ける**ことになる。
   */
  it("戻す先が無ければ、戻さずに止まる", () => {
    expect(postHealthAction([fail("health")], null)).toEqual({ kind: "stop" });
  });

  it("検査が 1 つも無いのは「通った」ではない", () => {
    // 空を PASS と読むと、**測っていないのに緑**になる
    expect(postHealthAction([], "r-001")).toEqual({ kind: "stop" });
  });
});

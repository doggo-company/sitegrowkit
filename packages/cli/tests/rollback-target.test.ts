import { describe, expect, it } from "vitest";

import { pickRollbackTarget } from "../src/deploy/release.js";

/**
 * **切り戻す先を間違えない**（B-41 ⑤）。
 *
 * 直す前は `releases.find((r) => r !== curId)` ——「**current 以外で一番新しいもの**」。
 * **1 回目は当たるが、2 回目に外す。**
 *
 * ▷ **rollback は事故のときにしか使わない。**
 * だから**動かないことに気づけるのは事故の最中だけ**で、そこで外すと**二重事故**になる。
 *
 * ▷ **両方向を見る。**
 * - **戻りすぎない**（さっき捨てた壊れたものへ行かない）
 * - **戻れなくならない**（正常な release まで対象外にしない）
 */
// 新しい順（`ls -1 | sort -r` の並び）
const R = ["20260828_0300", "20260828_0200", "20260828_0100"];

describe("戻りすぎない（新しい方向へ行かない）", () => {
  /** 🔴 **これが起きていた** */
  it("2 回目の rollback で、さっき捨てたものへ戻らない", () => {
    // 1 回目: 壊れた 0300 から 0200 へ
    expect(pickRollbackTarget(R, "20260828_0300")).toBe("20260828_0200");

    // 2 回目: 0200 から **さらに古い** 0100 へ（0300 へは戻らない）
    expect(pickRollbackTarget(R, "20260828_0200")).toBe("20260828_0100");
  });

  it("何度戻しても、行き先は必ず古くなる", () => {
    let cur: string | null = "20260828_0300";
    const visited: string[] = [];

    for (let i = 0; i < 3; i++) {
      cur = pickRollbackTarget(R, cur);
      if (cur === null) break;
      visited.push(cur);
    }

    expect(visited).toStrictEqual(["20260828_0200", "20260828_0100"]);
    // 3 回目は行き先が無い（一番古いところで止まる）
    expect(pickRollbackTarget(R, "20260828_0100")).toBeNull();
  });
});

describe("戻れなくならない", () => {
  /** **current が一覧に無い**（壊れた symlink・手で書き換えた）でも、動くものへ戻す */
  it("current が一覧に無ければ、一番新しいものへ戻す", () => {
    expect(pickRollbackTarget(R, "20260827_9999")).toBe("20260828_0300");
  });

  it("current が読めなくても、一番新しいものへ戻す", () => {
    expect(pickRollbackTarget(R, null)).toBe("20260828_0300");
  });

  /** release が 2 つしか無くても戻れる（**「1 つ古い」が在る限り戻す**） */
  it("release が 2 つでも戻れる", () => {
    const two = ["20260828_0200", "20260828_0100"];

    expect(pickRollbackTarget(two, "20260828_0200")).toBe("20260828_0100");
  });
});

describe("戻り先が本当に無いとき", () => {
  it("release が 1 つだけなら null", () => {
    expect(pickRollbackTarget(["20260828_0100"], "20260828_0100")).toBeNull();
  });

  it("release が 0 件なら null", () => {
    expect(pickRollbackTarget([], "20260828_0100")).toBeNull();
    expect(pickRollbackTarget([], null)).toBeNull();
  });

  /** 空行が混ざっても数に入れない（`ls` の出力を split した形） */
  it("空行は数に入れない", () => {
    expect(pickRollbackTarget(["", "20260828_0100", ""], "20260828_0100")).toBeNull();
  });
});

import { describe, expect, it } from "vitest";

import { parseFailedTests } from "../src/commands/verify.js";

/**
 * verify の Pest ゲートが「どのテストが落ちたか」を言えること。
 *
 * exit code だけでは読む人がログを遡ることになる。読む先は pest の画面出力ではなく
 * **PHPUnit 自身が書く JUnit** — 見た目が変わっても形が変わらない側である。
 */
describe("parseFailedTests", () => {
  const xml = (body: string) =>
    `<?xml version="1.0" encoding="UTF-8"?>\n<testsuites><testsuite name="s">${body}</testsuite></testsuites>`;

  it("通ったものは拾わない（自己終了する testcase）", () => {
    expect(
      parseFailedTests(
        xml('<testcase name="ok" classname="Tests.Feature.FooTest" time="0.1"/>'),
      ),
    ).toEqual([]);
  });

  it("落ちたものを型 :: 名前で拾う", () => {
    expect(
      parseFailedTests(
        xml(
          '<testcase name="壊れている" classname="Tests.Feature.FooTest"><failure type="x">boom</failure></testcase>',
        ),
      ),
    ).toEqual(["Tests\\Feature\\FooTest::壊れている"]);
  });

  it("error も落ちたものとして拾う（failure だけ見ない）", () => {
    expect(
      parseFailedTests(
        xml(
          '<testcase name="例外" classname="Tests.Feature.BarTest"><error type="x">boom</error></testcase>',
        ),
      ),
    ).toEqual(["Tests\\Feature\\BarTest::例外"]);
  });

  it("通ったものと落ちたものが混ざっていても落ちたものだけ", () => {
    const body =
      '<testcase name="ok" classname="Tests.Feature.FooTest"/>' +
      '<testcase name="ng" classname="Tests.Feature.FooTest"><failure/></testcase>' +
      '<testcase name="ok2" classname="Tests.Feature.FooTest"/>';

    expect(parseFailedTests(xml(body))).toEqual([
      "Tests\\Feature\\FooTest::ng",
    ]);
  });

  it("classname が無ければ名前だけ返す", () => {
    expect(
      parseFailedTests(xml('<testcase name="のみ"><failure/></testcase>')),
    ).toEqual(["のみ"]);
  });

  it("多すぎるときは頭を出して残りを数える（画面を埋めない）", () => {
    const body = Array.from(
      { length: 12 },
      (_, i) =>
        `<testcase name="t${i}" classname="Tests.Feature.FooTest"><failure/></testcase>`,
    ).join("");

    const names = parseFailedTests(xml(body), 3);

    expect(names).toHaveLength(4);
    expect(names[3]).toBe("… 他 9 件");
  });

  it("空でも壊れない", () => {
    expect(parseFailedTests("")).toEqual([]);
  });
});

import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterAll, describe, expect, it } from "vitest";

import { analyzeTemplateDir } from "../src/inspect/analyze.js";
import { buildPlan } from "../src/importer/plan.js";
import type { ContentSchema } from "../src/importer/plan-types.js";

/**
 * 「押せるのに『準備中』」を、**書き換えずに人へ渡す**（R-16 ③）。
 *
 * 静的テンプレートの送信ボタンはモックなので「準備中」と書いてあることがある。
 * 原文はそのまま残す（Preserve First。作り話をしない）が、取り込んだ後は
 * **フォームが実際に動く**ので、その文言は利用者に嘘をつく。
 *
 * ▷ 直さない。**言う。** 出典（ページ・原文・当たった語）を添えて plan の REVIEW に出す。
 */
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");
const roots: string[] = [];

function loadSchema(id: string): ContentSchema {
  return JSON.parse(
    readFileSync(resolve(SCHEMAS, `${id}.json`), "utf8"),
  ) as ContentSchema;
}

/** 送信ボタンだけを差し替えた合成テンプレート（実案件は使わない） */
function siteWithSubmit(submitHtml: string): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-notready-"));

  roots.push(dir);
  mkdirSync(dir, { recursive: true });
  writeFileSync(
    join(dir, "index.html"),
    `<!doctype html><html lang="ja"><head><title>合成サイト</title></head>
<body><main><h1>お問い合わせ</h1>
<form class="contact-form" action="#" method="post">
  <label for="name">お名前</label><input type="text" id="name" name="name" required>
  <label for="email">メール</label><input type="email" id="email" name="email" required>
  <label for="message">ご相談内容</label><textarea id="message" name="message" required></textarea>
  ${submitHtml}
</form></main></body></html>`,
    "utf8",
  );

  return dir;
}

function submitNotes(dir: string): string[] {
  const plan = buildPlan(analyzeTemplateDir(dir), {
    contact: loadSchema("contact"),
    site: loadSchema("site"),
  });

  return plan.uncertainties
    .filter((u) => u.subject.startsWith("contact form submit"))
    .map((u) => u.note);
}

afterAll(() => {
  for (const r of roots) rmSync(r, { recursive: true, force: true });
});

describe("送信ボタンの文言を読む", () => {
  it("button の中身を原文のまま manifest へ入れる", () => {
    const m = analyzeTemplateDir(
      siteWithSubmit('<button type="submit">送信（準備中）</button>'),
    );

    expect(m.forms[0]?.submit_label).toBe("送信（準備中）");
  });

  it("input[type=submit] は value を読む", () => {
    const m = analyzeTemplateDir(
      siteWithSubmit('<input type="submit" value="Coming Soon">'),
    );

    expect(m.forms[0]?.submit_label).toBe("Coming Soon");
  });

  it("ボタンが無ければ null（空文字にしない）", () => {
    const m = analyzeTemplateDir(siteWithSubmit(""));

    expect(m.forms[0]?.submit_label).toBeNull();
  });
});

describe("動かないと名乗る文言を、書き換えずに伝える", () => {
  it("原文と当たった語を出典として添える", () => {
    const notes = submitNotes(
      siteWithSubmit('<button type="submit">送信（準備中）</button>'),
    );

    expect(notes).toHaveLength(1);
    expect(notes[0]).toContain("送信（準備中）"); // 原文
    expect(notes[0]).toContain("検出語: 準備中"); // なぜ出たか
    expect(notes[0]).toContain("書き換えは行っていません");
  });

  it("大小の違いや語順の違う言い回しでも当たる", () => {
    expect(
      submitNotes(siteWithSubmit('<input type="submit" value="COMING SOON">')),
    ).toHaveLength(1);

    expect(
      submitNotes(
        siteWithSubmit(
          '<button type="submit">準備中につき送信できません</button>',
        ),
      ),
    ).toHaveLength(1);
  });

  /**
   * **0 件を名乗る前に、見張っていることを確かめる**。
   * 上の 1 件が出る仕掛けが生きていることは確認済みなので、ここは「出ない」を見てよい。
   */
  it("ふつうの「送信」には何も言わない", () => {
    expect(
      submitNotes(siteWithSubmit('<button type="submit">送信する</button>')),
    ).toStrictEqual([]);
  });
});

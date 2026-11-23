/**
 * B-2 — 「常に赤い門」を畳む（手直しの記録と、記録に無い変更の区別）。
 *
 * 生成物は kit の改訂で**正しく変わっていく**（SEO タグ・パンくず・ブランド中立化）。
 * 「apply 時のまま」を赤で見張り続けると、**常に赤い検査**になって誰も見なくなる。
 * だから見るのは **「記録に無い変更が無いこと」** に変える。
 */
import { describe, expect, it } from "vitest";

import {
  classifyGenerated,
  seededCategories,
  type TemplateEdits,
} from "../src/importer/edits.js";

const record = [
  { target: "a.blade.php", sha256: "aaa" },
  { target: "b.blade.php", sha256: "bbb" },
  { target: "logo.png", sha256: "ccc" },
];

const empty: TemplateEdits = { edited: [], removed: [] };

/** 現物の状態を差し替えられるようにする（ファイルを置かずに測る） */
const actual =
  (map: Record<string, string | null>) =>
  (target: string): string | null =>
    map[target] ?? null;

describe("生成ファイルの判定", () => {
  it("apply 時のままなら承認済みの記録は要らない", () => {
    const r = classifyGenerated(
      record,
      empty,
      actual({ "a.blade.php": "aaa", "b.blade.php": "bbb", "logo.png": "ccc" }),
    );

    expect(r.unknown).toEqual([]);
    expect(r.approved).toEqual([]);
    expect(r.missing).toEqual([]);
  });

  it("記録に無い変更は赤のまま", () => {
    const r = classifyGenerated(
      record,
      empty,
      actual({ "a.blade.php": "zzz", "b.blade.php": "bbb", "logo.png": "ccc" }),
    );

    expect(r.unknown).toEqual([{ target: "a.blade.php", sha256: "zzz" }]);
  });

  it("承認した内容と同じなら通る（手で直したと記録してある）", () => {
    const edits: TemplateEdits = {
      edited: [
        {
          target: "a.blade.php",
          sha256: "zzz",
          reason: "パンくずを component へ",
        },
      ],
      removed: [],
    };

    const r = classifyGenerated(
      record,
      edits,
      actual({ "a.blade.php": "zzz", "b.blade.php": "bbb", "logo.png": "ccc" }),
    );

    expect(r.unknown).toEqual([]);
    expect(r.approved).toEqual(["a.blade.php"]);
  });

  /**
   * **検査の歯を残す。**
   *
   * 「手を入れた」だけを記録すると、**その後どれだけ変えても緑**になる。
   * 承認したのは**その内容**であって、そのファイルではない。
   */
  it("承認したあとにさらに変えたら、また赤になる", () => {
    const edits: TemplateEdits = {
      edited: [
        { target: "a.blade.php", sha256: "zzz", reason: "承認済みの内容" },
      ],
      removed: [],
    };

    const r = classifyGenerated(
      record,
      edits,
      actual({ "a.blade.php": "yyy", "b.blade.php": "bbb", "logo.png": "ccc" }),
    );

    expect(r.unknown).toEqual([{ target: "a.blade.php", sha256: "yyy" }]);
    expect(r.approved).toEqual([]);
  });

  it("記録に無い欠落は赤", () => {
    const r = classifyGenerated(
      record,
      empty,
      actual({ "a.blade.php": "aaa", "b.blade.php": "bbb", "logo.png": null }),
    );

    expect(r.missing).toEqual(["logo.png"]);
    expect(r.unknown).toEqual([]);
  });

  it("配らないと決めたものは、欠けていて通る", () => {
    const edits: TemplateEdits = {
      edited: [],
      removed: [
        { target: "logo.png", reason: "ブランド中立化（配らないと決めた）" },
      ],
    };

    const r = classifyGenerated(
      record,
      edits,
      actual({ "a.blade.php": "aaa", "b.blade.php": "bbb", "logo.png": null }),
    );

    expect(r.missing).toEqual([]);
    expect(r.removed).toEqual(["logo.png"]);
  });

  /**
   * **「無い」と「別物になった」を混ぜない。**
   *
   * `removed` に書いたファイルが**在る**なら、それは配らないと決めたはずのものが
   * 配られているということで、記録と現物が食い違っている。
   */
  it("配らないと決めたものが在れば赤", () => {
    const edits: TemplateEdits = {
      edited: [],
      removed: [{ target: "logo.png", reason: "配らないと決めた" }],
    };

    const r = classifyGenerated(
      record,
      edits,
      actual({ "a.blade.php": "aaa", "b.blade.php": "bbb", "logo.png": "ccc" }),
    );

    expect(r.contradictions).toEqual(["logo.png"]);
  });
});

/**
 * F-43 — **空振りを緑と読まない**。
 *
 * seeder から 1 件も読めなかったのに「不足なし」と言うと、
 * **網が 1 枚も無い状態で緑**になる。
 */
describe("seeder の category 読み取り", () => {
  it("単引用符で書かれた値を読む", () => {
    expect(
      seededCategories("['title' => 'A', 'category' => 'branding'],"),
    ).toEqual(["branding"]);
  });

  it("二重引用符でも読む（書き方を変えただけで網が外れない）", () => {
    expect(seededCategories('["category" => "web"],')).toEqual(["web"]);
  });

  it("読めなければ空を返す（呼ぶ側が「測れていない」と言えるように）", () => {
    expect(seededCategories("category は書かれていない")).toEqual([]);
  });
});

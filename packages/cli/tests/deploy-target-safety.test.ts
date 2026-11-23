/**
 * B-41 安全側 — **「どこで」押すか**と**「どこへ」押すか**の番人。
 *
 * ここで守るのは 2 つ。どちらも実サーバーへ接続せずに固定できる
 * （fs の fixture と純関数だけ。SSH も HTTP も要らない）。
 *
 * 1. **kit 自身の `template/` はデプロイ対象ではない**
 *    `requireProject()` は `artisan` + `docker-compose.yml` の在るところを
 *    プロジェクトと認める。**`template/` は両方持っている**ので、そこで
 *    `agentcms deploy staging` を打つと **kit のひな形そのものを実サーバーへ押す**。
 *
 * 2. **`-y` だけでは本番へ押せない**
 *    既定は staging のまま、staging 以外は **target 名をもう一度書かせる**。
 */
import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

import { deployRollback, deployStaging } from "../src/commands/deploy.js";
import { loadDeployConfig } from "../src/deploy/config.js";
import { approveTarget } from "../src/deploy/target.js";
import { kitTemplateRefusal } from "../src/kit-template.js";
import { findProjectRoot } from "../src/project.js";
import { setUiSink, type UiEvent } from "../src/ui.js";

const repoRoot = resolve(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  "..",
  "..",
);

const dirs: string[] = [];
const tmp = (): string => {
  const d = mkdtempSync(join(tmpdir(), "agentcms-kit-template-"));
  dirs.push(d);
  return d;
};

afterEach(() => {
  for (const d of dirs.splice(0)) rmSync(d, { recursive: true, force: true });
});

/** kit の repo の形（`template/` の隣に `packages/cli/package.json`） */
function kitRepoTemplate(pkgName = "agent-cms-kit"): string {
  const root = tmp();
  mkdirSync(join(root, "packages", "cli"), { recursive: true });
  writeFileSync(
    join(root, "packages", "cli", "package.json"),
    JSON.stringify({ name: pkgName }),
  );
  const template = join(root, "template");
  mkdirSync(join(template, ".agentcms"), { recursive: true });
  return template;
}

/** 配布した npm パッケージの形（`template/` の隣に `dist/` と `package.json`） */
function installedKitTemplate(): string {
  const root = tmp();
  writeFileSync(
    join(root, "package.json"),
    JSON.stringify({ name: "agent-cms-kit" }),
  );
  mkdirSync(join(root, "dist"), { recursive: true });
  writeFileSync(join(root, "dist", "index.js"), "");
  const template = join(root, "template");
  mkdirSync(join(template, ".agentcms"), { recursive: true });
  return template;
}

describe("kit 自身の template/ をデプロイ対象と認めない", () => {
  /**
   * **これが本命である。** 想像した形ではなく、**この repo の実物**で測る。
   * `template/.agentcms/deploy/staging.json` は実サーバーを指しているので、
   * ここで止まらないと「ひな形を客のサーバーへ押す」が実際に起きる。
   */
  it("この repo の template/ を拒む（実測）", () => {
    const r = kitTemplateRefusal(join(repoRoot, "template"));
    expect(r).not.toBeNull();
    expect(r?.what).toContain("template");
  });

  /**
   * **`template/` の中に居なくても踏む。**
   * `findProjectRoot()` は「キットリポジトリ直下なら template/ を許容」する
   * （project.ts の実装。開発時の利便性のため）。つまり **repo のどこで打っても**
   * 解決先は `template/` になる。番人はその解決先に対して効かなければ意味が無い。
   */
  it("kit repo のどこで打っても解決先は template/ で、そこを拒む（実測）", () => {
    const root = findProjectRoot(repoRoot);
    expect(root).toBe(join(repoRoot, "template"));
    expect(kitTemplateRefusal(root!)).not.toBeNull();
  });

  it("kit repo の template/ を拒む", () => {
    expect(kitTemplateRefusal(kitRepoTemplate())).not.toBeNull();
  });

  it("配布パッケージに同梱された template/ も拒む", () => {
    expect(kitTemplateRefusal(installedKitTemplate())).not.toBeNull();
  });

  /**
   * **誤検知で利用者の案件を止めない**ほうが優先である。
   * `create` は生成時に必ず `.agentcms/project.json` を書く。
   * それが在るなら、たとえ名前が `template` でも**案件**として通す。
   */
  it("project.json が在れば通す（利用者の案件を止めない）", () => {
    const template = kitRepoTemplate();
    writeFileSync(
      join(template, ".agentcms", "project.json"),
      JSON.stringify({ name: "template" }),
    );
    expect(kitTemplateRefusal(template)).toBeNull();
  });

  it("kit の外なら、名前が template でも通す", () => {
    const root = tmp();
    const template = join(root, "template");
    mkdirSync(join(template, ".agentcms"), { recursive: true });
    expect(kitTemplateRefusal(template)).toBeNull();
  });

  it("隣に packages/cli が在っても、kit の package でなければ通す", () => {
    expect(
      kitTemplateRefusal(kitRepoTemplate("someone-elses-tool")),
    ).toBeNull();
  });

  it("名前が template でなければ通す", () => {
    const root = tmp();
    mkdirSync(join(root, "packages", "cli"), { recursive: true });
    writeFileSync(
      join(root, "packages", "cli", "package.json"),
      JSON.stringify({ name: "agent-cms-kit" }),
    );
    const site = join(root, "midori-zoen");
    mkdirSync(join(site, ".agentcms"), { recursive: true });
    expect(kitTemplateRefusal(site)).toBeNull();
  });
});

describe("staging 以外へは、明示しないと押せない", () => {
  it("staging の既定は対話で訊く", () => {
    expect(approveTarget("staging", {})).toEqual({ kind: "ask" });
  });

  it("staging は -y で通る（今までどおり）", () => {
    expect(approveTarget("staging", { yes: true })).toEqual({
      kind: "approved",
    });
  });

  /**
   * **`-y` だけでは本番へ押せない。** ここが「うっかり」の入口である
   * （`agentcms deploy staging -t production -y` が無言で通ってはいけない）。
   */
  it("production は -y だけでは拒む", () => {
    const r = approveTarget("production", { yes: true });
    expect(r.kind).toBe("refuse");
    if (r.kind === "refuse") expect(r.fix).toContain("--confirm-target");
  });

  it("production は --confirm-target 無しでは拒む", () => {
    expect(approveTarget("production", {}).kind).toBe("refuse");
  });

  it("--confirm-target が食い違えば拒む", () => {
    const r = approveTarget("production", { confirmTarget: "staging" });
    expect(r.kind).toBe("refuse");
    if (r.kind === "refuse") expect(r.why).toContain("staging");
  });

  it("--confirm-target が一致しても、-y が無ければまだ訊く", () => {
    expect(
      approveTarget("production", { confirmTarget: "production" }),
    ).toEqual({ kind: "ask" });
  });

  it("--confirm-target 一致 + -y で通す（2 つ揃って初めて）", () => {
    expect(
      approveTarget("production", { confirmTarget: "production", yes: true }),
    ).toEqual({ kind: "approved" });
  });
});

/**
 * **番人を関数の中に置いただけでは、何も守っていない。**
 * `deploy` が実際にそれを通るところまで固定する（SSH も HTTP も張らない —
 * どちらの拒否も「サーバーへ触る前」に決まるので、そこまで到達しない）。
 */
describe("deploy の入口で止まること（配線）", () => {
  const capture = (): UiEvent[] => {
    const events: UiEvent[] = [];
    setUiSink((e) => events.push(e));
    return events;
  };

  afterEach(() => {
    setUiSink(null);
  });

  it("kit の template/ の中では、1 行も進まずに止まる", async () => {
    const template = kitRepoTemplate();
    // requireProject() が「プロジェクト」と認める形にする（これが罠の本体）
    writeFileSync(join(template, "artisan"), "");
    writeFileSync(join(template, "docker-compose.yml"), "");
    // **設定は「正しい」ものを置く。** 壊れた設定だと、番人を外しても
    // 「設定が不正」で止まってしまい、**番人が効いたのか区別できない**
    mkdirSync(join(template, ".agentcms", "deploy"), { recursive: true });
    writeFileSync(
      join(template, ".agentcms", "deploy", "staging.json"),
      JSON.stringify({
        provider: "xserver",
        target: "staging",
        ssh: { host: "x.example.jp", port: 10022, user: "x" },
        remote: {
          appDir: "/home/x/agentcms/staging",
          docroot: "/home/x/example.jp/public_html",
          phpBin: "auto",
        },
        app: { url: "https://stg.example.jp", healthPath: "/healthz" },
      }),
    );

    const events = capture();
    const cwd = process.cwd();
    process.chdir(template);
    try {
      expect(await deployStaging("staging", { dryRun: true })).toBe(1);
    } finally {
      process.chdir(cwd);
    }

    // 見出しも artifact も出ない = 設定を読む前に止まっている
    expect(events).toHaveLength(1);
    expect(events[0]?.kind).toBe("fail");
    if (events[0]?.kind === "fail")
      expect(events[0].failure.what).toContain("案件ではありません");
  });

  /**
   * **断り文まで見る。** ここは kit の repo の中で走るので、承認の番人を外しても
   * `template/` の番人が同じ「1 件の fail」を出してしまう。
   * どちらが止めたのかを区別しないと、**変異させても緑のまま**になる。
   */
  it("production は -y だけでは 1 行も進まない", async () => {
    const events = capture();
    expect(await deployStaging("production", { yes: true })).toBe(1);
    expect(events).toHaveLength(1);
    if (events[0]?.kind === "fail")
      expect(events[0].failure.what).toContain("明示しないと押せません");
    else expect.unreachable("fail 以外が出ました");
  });

  it("rollback も同じ承認を通る", async () => {
    const events = capture();
    expect(await deployRollback("production", { yes: true })).toBe(1);
    expect(events).toHaveLength(1);
    if (events[0]?.kind === "fail")
      expect(events[0].failure.what).toContain("明示しないと押せません");
    else expect.unreachable("fail 以外が出ました");
  });
});

/**
 * CLI の面。ここが無いと **本番へ押す手段そのものが無い**（B-41 #4 の元の症状）。
 * 逆に `--confirm-target` を外すと `-y` で本番へ通ってしまう。
 */
describe("CLI が target を受け取る", () => {
  const src = readFileSync(
    new URL("../src/index.ts", import.meta.url),
    "utf8",
  );

  for (const cmd of ["staging", "rollback"] as const) {
    it(`deploy ${cmd} は -t と --confirm-target を持ち、target を渡す`, () => {
      const at = src.indexOf(`.command("${cmd}")`);
      expect(at).toBeGreaterThan(-1);
      const block = src.slice(at, src.indexOf(".description(", at));
      expect(block).toContain('"-t, --target <target>"');
      expect(block).toContain('"--confirm-target <target>"');
      expect(src.slice(at, src.indexOf("  );", at))).toContain("opts.target");
    });
  }
});

describe("設定ファイルの target は、ファイル名と一致していること", () => {
  const base = {
    provider: "xserver",
    ssh: { host: "x.example.jp", port: 10022, user: "x" },
    remote: {
      appDir: "/home/x/agentcms/production",
      docroot: "/home/x/example.jp/public_html",
      phpBin: "auto",
    },
    app: { url: "https://example.jp", healthPath: "/healthz" },
  };

  const write = (target: string, body: unknown): string => {
    const root = tmp();
    mkdirSync(join(root, ".agentcms", "deploy"), { recursive: true });
    writeFileSync(
      join(root, ".agentcms", "deploy", `${target}.json`),
      JSON.stringify(body),
    );
    return root;
  };

  /**
   * `ensureLayout()` はサーバー側 `.env` の **`APP_ENV === cfg.target`** を強制し、
   * health は target で staging / production を切り替える。
   * ファイル名と中身がずれると、**本番へ staging の物差しを当てる**ことになる。
   */
  it("production.json の中身が staging なら拒む", () => {
    const root = write("production", { ...base, target: "staging" });
    const { error } = loadDeployConfig(root, "production");
    expect(error?.why).toContain("target");
  });

  it("一致していれば読める", () => {
    const root = write("production", { ...base, target: "production" });
    const { config } = loadDeployConfig(root, "production");
    expect(config?.target).toBe("production");
  });
});

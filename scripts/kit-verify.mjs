/**
 * kit を触ったときに回す門（B-19）。**利用者の門（`agentcms verify`）とは別物である。**
 *
 * ## 使い方（repo root で）
 *
 * ```
 * node scripts/kit-verify.mjs
 * ```
 *
 * 引数は無い。**どの門が要るかは触った場所から機械が決める**（`kit-gates.mjs`）。
 * 選ばせないのが要点なので、`--kit` のような切り替えは足さないこと。
 *
 * ## 何を回すか
 *
 * - **kit 半分（常に）**: `packages/cli` の build → typecheck → lint → vitest（実測 ≈ 26 秒）
 * - **template 半分（`template/` を触ったときだけ）**: `agentcms verify`（11 門）
 *
 * ## 何を配るか
 *
 * **何も配らない。** このファイルは `scripts/` に在り、`scripts/` は成果物へ写されない
 * （`package-release.mjs` が写すのは `packages/cli/{package.json,dist}` と `template/` と一部 docs だけ）。
 * だから利用者の `agentcms verify` は 1 バイトも変わらない。
 */
import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import {
  basisLine,
  closingLines,
  FEEL_GATE,
  requiredGates,
  TEMPLATE_GATES,
} from "./kit-gates.mjs";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");

const run = (cmd, args, cwd) =>
  spawnSync(cmd, args, { cwd, stdio: "inherit", encoding: "utf8" }).status === 0;

/**
 * 触ったファイルを集める。
 *
 * **HEAD との差だけでは足りない。** これは commit の**前**に回す門なので、
 * これから commit する変更（未 commit・untracked）が見えないと**判断を外す**。
 */
function changedPaths() {
  const git = (args) =>
    spawnSync("git", args, { cwd: repoRoot, encoding: "utf8" }).stdout ?? "";

  return [
    ...git(["diff", "--name-only", "HEAD"]).split("\n"),
    ...git(["ls-files", "--others", "--exclude-standard"]).split("\n"),
  ].filter((p) => p.trim() !== "");
}

const changed = changedPaths();
const gates = requiredGates(changed);

// **1 行目に、何と比べて決めたかを書く**（判断を後から検証できるように）
console.log(basisLine(changed));
console.log(`  kit: ${gates.kit.why}`);
console.log(`  template: ${gates.template.why}`);
console.log(`  feel: ${gates.feel.why}`);
console.log("");

const cli = join(repoRoot, "packages/cli");
const halves = [];

let ok = true;

for (const [name, args] of [
  ["build", ["build"]],
  ["typecheck", ["typecheck"]],
  ["lint", ["lint"]],
  /*
   * 出力は緑のとき 1 行になる（`scripts/vitest-quiet.mjs`）。
   * **赤のときは素の出力がそのまま出る**ので、直すための情報は減らない。
   * 素で見たいときは `AGENTCMS_TEST_OUTPUT=full`。
   */
  ["vitest", ["exec", "node", "scripts/vitest-quiet.mjs"]],
]) {
  console.log(`▶ packages/cli: ${name}`);

  if (!run("pnpm", args, cli)) {
    ok = false;
    halves.push({ name: `kit (${name})`, ran: true, ok: false, how: "" });
    break;
  }
}

if (ok) halves.push({ name: "kit（packages/cli）", ran: true, ok: true, how: "" });

const cliEntry = join(repoRoot, "packages/cli/dist/index.js");

for (const gate of TEMPLATE_GATES) {
  const how = `node packages/cli/dist/index.js ${gate.args.join(" ")}`;

  if (!gates.template.required || !ok) {
    halves.push({ name: `template（${gate.name}）`, ran: false, ok: false, how });
    continue;
  }

  console.log(`▶ ${how}`);

  const passed = run("node", [cliEntry, ...gate.args], repoRoot);

  ok = ok && passed;
  halves.push({ name: `template（${gate.name}）`, ran: true, ok: passed, how });
}

/*
 * 触り心地の門（docs/feel-bar.md）。**線を超えたら赤 = その版は出さない。**
 * 相手（`agentcms dev` 済みのプロジェクト）が居なければ、回さずに緑と言わず赤にする。
 */
if (gates.feel.required) {
  if (!ok) {
    halves.push({ name: FEEL_GATE.name, ran: false, ok: false, how: FEEL_GATE.how });
  } else {
    const templateDir = join(repoRoot, "template");
    /*
     * 相手は **`agentcms create` で作ったプロジェクト**（利用者と同じ形）。kit の template/ そのものは
     * 見本サイトの作りが違い（メニューはコードで持つ・問い合わせはフォームの部品ではない）、
     * 門の 3 つの流れのうち 2 つを通せない（2026-11-05 実測）。だから既定を置かず、無ければ赤。
     */
    const project = process.env.AGENTCMS_FEEL_PROJECT
      ? resolve(process.env.AGENTCMS_FEEL_PROJECT)
      : "";
    let port = "";
    try {
      if (project === "") throw new Error("AGENTCMS_FEEL_PROJECT が無い");
      port = /^APP_PORT=(.*)$/m.exec(readFileSync(join(project, ".env"), "utf8"))?.[1]?.trim() ?? "";
    } catch {
      port = "";
    }
    const url = process.env.APP_URL ?? `http://localhost:${port || "8180"}`;
    let reachable = false;
    try {
      reachable = (await fetch(`${url}/login`, { signal: AbortSignal.timeout(5000) })).ok;
    } catch {
      reachable = false;
    }

    console.log(`▶ ${FEEL_GATE.name} — 相手: ${project || "（未指定）"}（${url}）`);
    if (project === "") {
      console.log(
        "✖ AGENTCMS_FEEL_PROJECT が指定されていません。この作業ツリーから `agentcms create` → `agentcms dev` した" +
          "プロジェクトの場所を指してください（docs/feel-bar.md）",
      );
      ok = false;
      halves.push({ name: FEEL_GATE.name, ran: true, ok: false, how: FEEL_GATE.how });
    } else if (!reachable) {
      console.log(
        `✖ ${url} に繋がりません。触り心地の門は実際に押して測るので、` +
          "`agentcms dev` 済みの場所を AGENTCMS_FEEL_PROJECT で指してから回し直してください（docs/feel-bar.md）",
      );
      ok = false;
      halves.push({ name: FEEL_GATE.name, ran: true, ok: false, how: FEEL_GATE.how });
    } else {
      const passed =
        spawnSync("pnpm", ["exec", "playwright", "test", "-c", "playwright.feel.config.ts"], {
          cwd: templateDir,
          stdio: "inherit",
          env: { ...process.env, AGENTCMS_FEEL_PROJECT: project },
        }).status === 0;
      ok = ok && passed;
      halves.push({ name: FEEL_GATE.name, ran: true, ok: passed, how: FEEL_GATE.how });
    }
  }
} else {
  halves.push({ name: FEEL_GATE.name, ran: false, ok: false, how: FEEL_GATE.how });
}

console.log("");
for (const line of closingLines(halves)) console.log(line);

process.exit(ok ? 0 : 1);

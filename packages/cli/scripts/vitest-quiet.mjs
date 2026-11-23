#!/usr/bin/env node
/**
 * vitest の出力を、緑のときだけ 1 行にする。
 *
 * ## なぜ要るか
 *
 * PHP 側は `laravel/pao` が既に 1 行 JSON を出している
 * （`{"tool":"pest","result":"passed","tests":3889,...}`）。**JS 側だけ素の出力のまま**で、
 * `packages/cli` の緑 1 回で **478 行**あった。flaky を疑って 2 回回せば倍になる。
 * 読む側（人でも AI でも）が払う量が、**通ったという 1 事実に対して大きすぎる。**
 *
 * ## 何をするか
 *
 * | 結果 | 出すもの |
 * | --- | --- |
 * | 緑 | **1 行の JSON**（pao と同じ形） |
 * | 赤 | **捕らえた出力を、1 文字も削らずそのまま** |
 *
 * 🔴 **短くするのは緑のときだけ。** 赤のときに減らすと、直すための情報が消える。
 * ここが崩れると「ログが短いせいで原因が分からない」になり、節約した分を何倍も失う。
 *
 * 🔴 **終了コードはそのまま通す。** 潰すと「落ちているのに緑に見える」が起きる。
 * これが一番危ないので、`exitCode` は捕らえた値をそのまま使う。
 *
 * ## 逃げ道
 *
 * `AGENTCMS_TEST_OUTPUT=full` を付けると**何もせず素の出力を流す**（live で見える）。
 * 進捗を見ながら回したいとき・このラッパー自身を疑うときに使う。
 *
 * ## 既存の呼び方は壊していない
 *
 * `vitest run` を直接打つ道はそのまま残っている。これは**別名の入口**であって、
 * `vitest` の差し替えではない。
 */
import { spawn } from "node:child_process";
import { existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const FULL = process.env.AGENTCMS_TEST_OUTPUT === "full";
const args = process.argv.slice(2);

/**
 * 素の出力から数字だけ拾う。
 *
 * **拾えなくても失敗にしない。** ここは見せ方であって判定ではないので、
 * 読めない行があっても `null` にして、終了コードの側を正とする。
 */
function summarize(text) {
  const strip = text.replace(/\[[0-9;]*m/g, "");
  const files = strip.match(/Test Files\s+(\d+) passed(?:\s*\|\s*(\d+) skipped)?/);
  const tests = strip.match(/Tests\s+(\d+) passed(?:\s*\|\s*(\d+) skipped)?/);
  const duration = strip.match(/Duration\s+([\d.]+)s/);

  return {
    files: files ? Number(files[1]) : null,
    tests: tests ? Number(tests[1]) : null,
    skipped: tests && tests[2] ? Number(tests[2]) : 0,
    duration_ms: duration ? Math.round(Number(duration[1]) * 1000) : null,
  };
}

/*
 * **binary は自分で解決する。** npm script 経由なら `node_modules/.bin` が PATH に
 * 入るが、`node scripts/vitest-quiet.mjs` と直接叩かれると入らない
 * （実測: `spawn vitest ENOENT`）。**呼び方によって動いたり動かなかったりする**のは、
 * 次に触る人が必ず踏む。見つからなければ PATH に任せる。
 */
const packageRoot = join(dirname(fileURLToPath(import.meta.url)), "..");
const localBin = join(packageRoot, "node_modules", ".bin", "vitest");
const bin = existsSync(localBin) ? localBin : "vitest";

const child = spawn(bin, ["run", ...args], {
  // 全部出す指定なら、捕らえずにそのまま流す（live の進捗が見える）
  stdio: FULL ? "inherit" : ["inherit", "pipe", "pipe"],
  shell: false,
});

let captured = "";

if (!FULL) {
  child.stdout.on("data", (b) => (captured += b));
  child.stderr.on("data", (b) => (captured += b));
}

child.on("error", (error) => {
  // vitest が起動すらしていない。**握りつぶさない**
  process.stderr.write(`${String(error.stack ?? error)}\n`);
  process.exit(1);
});

child.on("close", (code, signal) => {
  if (FULL) {
    process.exit(code ?? 1);
  }

  if (code === 0) {
    const s = summarize(captured);
    process.stdout.write(
      `${JSON.stringify({ tool: "vitest", result: "passed", ...s })}\n`,
    );
    process.exit(0);
  }

  // 🔴 赤のときは削らない。捕らえたものを全部返す
  process.stdout.write(captured);

  if (signal) {
    process.stderr.write(`\nvitest was terminated by signal ${signal}\n`);
  }

  process.exit(code ?? 1);
});

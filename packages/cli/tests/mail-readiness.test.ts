import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

import { describe, expect, it } from "vitest";

import { loadDeployConfig } from "../src/deploy/config.js";

import {
  inspectMailEnv,
  isProductionTarget,
} from "../src/deploy/providers/xserver-setup.js";

/**
 * **本番で「送ったつもりで届かない」を作らない**（B-41 ②）。
 *
 * `deploy setup` が作る `.env` は **`MAIL_MAILER=log`** だった。
 * `log` は**1 通も届かず、エラーも出ない** — 「問い合わせが来ない」としてしか現れず、
 * **来ないことは誰も気づけない**。
 *
 * ▷ **ひな形を直すだけでは足りない。** 書かれるのは setup の 1 回だけで、
 * **人が後から `log` へ戻せる**。だから **doctor が毎回見る**。
 */
describe("本番かどうかの判定", () => {
  it("staging と読めるものは本番ではない", () => {
    for (const t of ["staging", "stg", "test", "dev", "local", "preview"]) {
      expect(isProductionTarget(t)).toBe(false);
    }
  });

  /** **強いほう＝安全側へ倒す。** 見慣れない名前は本番として扱う */
  it("それ以外は本番として扱う", () => {
    for (const t of ["production", "prod", "prod-1", "honban", ""]) {
      expect(isProductionTarget(t)).toBe(true);
    }
  });
});

describe("メール送信の設定を見る", () => {
  const env = (mailer: string, from = "info@midori-zoen.co.jp"): string =>
    `APP_ENV=production\nMAIL_MAILER=${mailer}\nMAIL_FROM_ADDRESS=${from}\n`;

  // ── 本番 ──────────────────────────────────────────

  /** 🔴 **これが起きるところだった** */
  it("本番で log は FAIL（1 通も届かない）", () => {
    const c = inspectMailEnv("production", env("log"));

    expect(c.status).toBe("FAIL");
    expect(c.why).toContain("エラーも出ません");
    expect(c.fix).toContain("smtp");
  });

  it("本番で未設定も FAIL", () => {
    expect(inspectMailEnv("production", "APP_ENV=production\n").status).toBe(
      "FAIL",
    );
  });

  /** **差出人が無い / 例示用だと、届かない側へ倒れる** */
  it("本番で差出人が空なら FAIL", () => {
    const c = inspectMailEnv("production", env("smtp", ""));

    expect(c.status).toBe("FAIL");
    expect(c.what).toContain("空");
  });

  it("本番で差出人が example. なら FAIL", () => {
    expect(inspectMailEnv("production", env("smtp", "noreply@example.test")).status).toBe(
      "FAIL",
    );
    expect(inspectMailEnv("production", env("smtp", "staging@example.com")).status).toBe(
      "FAIL",
    );
  });

  it("本番で smtp と実アドレスが揃っていれば PASS", () => {
    const c = inspectMailEnv("production", env("smtp"));

    expect(c.status).toBe("PASS");
    expect(c.detected).toContain("smtp");
  });

  // ── staging ───────────────────────────────────────

  /** **staging の log は意図どおり。**ここを FAIL にすると、毎回赤くて読み飛ばされる */
  it("staging の log は PASS", () => {
    expect(inspectMailEnv("staging", env("log", "staging@example.test")).status).toBe(
      "PASS",
    );
  });

  // ── 読めないとき ──────────────────────────────────

  /** **読めなかったを「問題なし」にしない。**ただし setup 前は普通に起きるので WARN */
  it("読めなければ WARN（PASS にしない）", () => {
    const c = inspectMailEnv("production", null);

    expect(c.status).toBe("WARN");
    expect(c.fix).toContain("deploy setup");
  });

  /** **FAIL は「何が・なぜ・どう直すか」を必ず持つ**（`CapabilityCheck` の契約） */
  it("FAIL は 3 つとも埋まっている", () => {
    for (const c of [
      inspectMailEnv("production", env("log")),
      inspectMailEnv("production", env("smtp", "")),
    ]) {
      expect(c.what).toBeTruthy();
      expect(c.why).toBeTruthy();
      expect(c.fix).toBeTruthy();
    }
  });
});

/**
 * **本番のひな形が在る**（B-41 ①）。
 *
 * これまで `staging.example.json` しか無く、**本番を作る人にコピー元が無かった**。
 * しかも案内は `staging.example.json` 固定だったので、
 * 言われたとおりコピーすると **`"target": "staging"` のまま**になり、
 * **ファイル名と中身が食い違って `validate()` で落ちる** — 遠回りである。
 */
describe("本番のひな形", () => {
  const DEPLOY = resolve(__dirname, "../../../template/.agentcms/deploy");
  const read = (f: string): string => readFileSync(join(DEPLOY, f), "utf8");

  it("production.example.json が在る", () => {
    const json = JSON.parse(read("production.example.json")) as {
      target: string;
    };

    expect(json.target).toBe("production");
  });

  /** **staging をコピーしただけにしない**（target が中身と食い違う） */
  it("staging のひな形と target が違う", () => {
    const stg = JSON.parse(read("staging.example.json")) as { target: string };

    expect(stg.target).toBe("staging");
  });

  it("env.production.example が在り、log を使わない", () => {
    const env = read("env.production.example");

    expect(env).toContain("APP_ENV=production");
    expect(env).toContain("MAIL_MAILER=smtp");
    expect(env).not.toMatch(/^MAIL_MAILER=log/m);
  });

  /** **staging にしか無い逃げ道を、本番のひな形へ持ち込まない** */
  it("本番のひな形にメールの寄せ先を書かない", () => {
    // staging は全件を自分宛てへ寄せられる。本番にそれは無い
    expect(read("env.staging.example")).toContain("MAIL_STAGING_REDIRECT_TO");
    expect(read("env.production.example")).not.toContain(
      "MAIL_STAGING_REDIRECT_TO=",
    );
  });

  /** **案内するひな形は target で変わる**（本番の人へ staging を案内しない） */
  it("設定が無いときの案内が target で変わる", () => {
    const root = resolve(__dirname, "../../..", "no-such-project");

    expect(loadDeployConfig(root, "production").error?.fix).toContain(
      "production.example.json",
    );
    expect(loadDeployConfig(root, "staging").error?.fix).toContain(
      "staging.example.json",
    );
  });
});

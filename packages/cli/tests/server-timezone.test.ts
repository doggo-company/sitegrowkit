import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

import { describe, expect, it } from "vitest";

import { minimalEnv } from "../src/deploy/providers/xserver-setup.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * **サーバーの `.env` でも日本時間で動く**（直し 5 回目）。
 *
 * 手元の `.env.example` には `APP_TIMEZONE=Asia/Tokyo` があったが、
 * サーバーに置く `.env` を作る 3 か所（`env.staging.example` / `env.production.example` /
 * `deploy setup` が書く `shared/.env`）には無く、Laravel の既定 **UTC** で動いていた。
 * 編集画面で「10:00」に予約したページが、日本時間の 19:00 まで出ない
 * （公開面で見る試験は template/tests/Feature/Round5ServerTimezoneTest.php）。
 */
const timezoneOf = (envText: string): string | undefined =>
  /^APP_TIMEZONE=(.*)$/m.exec(envText)?.[1]?.trim();

const cfg = (target: string): DeployTargetConfig =>
  ({
    provider: "xserver",
    target,
    ssh: { host: "example.test", port: 10022, user: "u" },
    remote: {
      appDir: "/home/u/agentcms/x",
      docroot: "/home/u/x/public_html",
      phpBin: "auto",
    },
    app: { url: "https://example.test", healthPath: "/up" },
  }) as DeployTargetConfig;

describe("サーバーの .env の時間帯", () => {
  const DEPLOY = resolve(__dirname, "../../../template/.agentcms/deploy");

  for (const f of ["env.staging.example", "env.production.example"]) {
    it(`${f} は Asia/Tokyo`, () => {
      expect(timezoneOf(readFileSync(join(DEPLOY, f), "utf8"))).toBe(
        "Asia/Tokyo",
      );
    });
  }

  for (const target of ["staging", "production"]) {
    it(`deploy setup が書く ${target} の shared/.env は Asia/Tokyo`, () => {
      const env = minimalEnv(cfg(target), "base64:x", {
        dbName: "d",
        dbUser: "u",
        dbPassword: "p",
      });
      expect(timezoneOf(env)).toBe("Asia/Tokyo");
    });
  }
});

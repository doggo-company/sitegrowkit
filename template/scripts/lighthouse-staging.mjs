#!/usr/bin/env node
// Staging 環境の Lighthouse 実測（mobile + desktop）。
// ローカルの assert 基準は適用せず実測値を記録する（スコアを整えるための条件変更をしない）。
// staging は意図的に noindex のため SEO の is-crawlable は落ちる（既知・正常）。
import { spawnSync } from "node:child_process";

const base = process.env.LHCI_STAGING_URL;
if (!base) {
  console.error("LHCI_STAGING_URL を指定してください（例: LHCI_STAGING_URL=https://example.xsrv.jp pnpm lighthouse:staging）");
  process.exit(1);
}

const paths = ["/", "/about", "/service", "/works", "/news", "/contact"];
const urls = paths.map((p) => `--collect.url=${base.replace(/\/$/, "")}${p}`);

for (const [label, extra] of [
  ["mobile", []],
  ["desktop", ["--collect.settings.preset=desktop"]],
]) {
  console.log(`\n=== Lighthouse staging (${label}) ===`);
  const r = spawnSync(
    "npx",
    [
      "lhci", "collect", ...urls, "--collect.numberOfRuns=1", ...extra,
    ],
    { stdio: "inherit" },
  );
  if (r.status !== 0) process.exit(r.status ?? 1);
  spawnSync("npx", ["lhci", "upload", "--upload.target=filesystem", `--upload.outputDir=.lighthouseci/staging-${label}`], { stdio: "inherit" });
}
console.log("\n結果: .lighthouseci/staging-{mobile,desktop}/ の JSON から categories.*.score を記録してください");

#!/usr/bin/env node
/**
 * Secret leakage 検査（verify の 1 ゲート・単体実行可）。
 * 走査対象のファイル名と内容から高確度の秘密情報パターンを検出する。
 * 決定論的・依存なし。パターン定義は scan-patterns.ts（release packaging と共用）、
 * 対象ファイルの列挙は scan-sources.ts（Git の有無で mode が変わる）。
 */
import { readFileSync } from "node:fs";

import {
  ALLOWLIST,
  CONTENT_PATTERNS,
  FORBIDDEN_FILES,
} from "./scan-patterns.js";
import { collectScanSources } from "./scan-sources.js";

const TEXT_EXT =
  /\.(php|ts|js|vue|json|yml|yaml|md|env|blade\.php|css|html|xml|ini|conf)$/;

function main(): number {
  const sources = collectScanSources();
  const violations: string[] = [];

  for (const f of sources.files) {
    const base = f.split("/").pop() ?? f;
    if (FORBIDDEN_FILES.some((re) => re.test(base))) {
      violations.push(
        sources.mode === "git"
          ? `${f}: 秘密情報ファイルが Git 管理下にあります`
          : `${f}: 秘密情報ファイルがプロジェクト内にあります`,
      );
      continue;
    }
    if (!TEXT_EXT.test(f)) continue;
    let content: string;
    try {
      content = readFileSync(f, "utf8");
    } catch {
      continue;
    }
    for (const { name, re } of CONTENT_PATTERNS) {
      const m = content.match(re);
      if (m && !ALLOWLIST.some((a) => a.test(m[0]))) {
        violations.push(`${f}: ${name} らしき文字列を検出`);
      }
    }
  }

  if (violations.length > 0) {
    console.error("✖ Secret scan で問題を検出しました:");
    for (const v of violations) console.error(`  - ${v}`);
    console.error(
      sources.mode === "git"
        ? "修正: 該当ファイルを Git から除外（.gitignore + git rm --cached）し、漏えいした鍵は必ずローテーションしてください"
        : "修正: 該当ファイルをプロジェクト外へ移動し、漏えいした鍵は必ずローテーションしてください",
    );
    return 1;
  }
  console.log(
    sources.mode === "git"
      ? `✔ Secret scan: 問題なし（Git 管理下 ${sources.files.length} ファイル）`
      : `✔ Secret scan: 問題なし（プロジェクト内 ${sources.files.length} ファイル・Git 不使用）`,
  );
  return 0;
}

process.exit(main());

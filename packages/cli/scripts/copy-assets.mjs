#!/usr/bin/env node
import { cpSync, existsSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * tsc は .ts しか出力しないため、Setup Wizard の同梱アセット（HTML/CSS/JS）を
 * dist へコピーする。外部 CDN を使わない設計のため、これらは必ず配布物に含める。
 */

const pkgRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const from = join(pkgRoot, "src", "wizard", "assets");
const to = join(pkgRoot, "dist", "wizard", "assets");

if (!existsSync(from)) {
  console.error(`copy-assets: ${from} が見つかりません`);
  process.exit(1);
}

mkdirSync(dirname(to), { recursive: true });
cpSync(from, to, { recursive: true });

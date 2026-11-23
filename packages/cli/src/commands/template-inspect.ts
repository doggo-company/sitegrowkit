import { existsSync, mkdirSync, statSync, writeFileSync } from "node:fs";
import {
  basename,
  dirname,
  isAbsolute,
  join,
  relative,
  resolve,
  sep,
} from "node:path";

import { analyzeTemplateDir } from "../inspect/analyze.js";
import type { Manifest } from "../inspect/manifest.js";
import { findAgentcmsDir } from "../project.js";
import { failAndExit, heading, info, ok, warn } from "../ui.js";

interface Options {
  out?: string;
  entry?: string;
}

/**
 * HTML テンプレートの決定論的調査 → 機械可読 Manifest 出力。
 * AI はこの Manifest を読んでから計画を立てる（生ファイル全読み禁止）。
 * <path> はディレクトリまたは単一 HTML ファイル（Real World Source）を受け付ける。
 */
export async function templateInspect(
  dir: string,
  opts: Options,
): Promise<number> {
  const target = resolve(dir);
  heading(`agentcms template inspect — ${dir}`);

  if (!existsSync(target)) {
    failAndExit({
      what: `${dir} が見つかりません`,
      why: "指定されたテンプレートのパスが存在しません",
      fix: "HTML テンプレートのディレクトリ、または単一 HTML ファイルのパスを指定してください",
    });
  }

  // 単一 HTML ファイル指定: 親ディレクトリを source とし、対象ページ + 参照 asset のみ解析する
  // （作業ディレクトリに混在する無関係ファイルを巻き込まない）
  const isSingleFile =
    statSync(target).isFile() && /\.html?$/i.test(basename(target));
  if (statSync(target).isFile() && !isSingleFile) {
    failAndExit({
      what: `${dir} は HTML ファイルではありません`,
      why: "単一ファイル指定は .html / .htm のみ対応しています",
      fix: "HTML ファイルまたはテンプレートのディレクトリを指定してください",
    });
  }
  const source = isSingleFile ? dirname(target) : target;
  const onlyPage = isSingleFile ? basename(target) : undefined;

  const entry = opts.entry
    ? normalizeToSourceRel(opts.entry, source)
    : undefined;

  const manifest = ((): Manifest => {
    try {
      return analyzeTemplateDir(source, dir, { entry, onlyPage });
    } catch (e) {
      failAndExit({
        what: "inspect に失敗しました",
        why: e instanceof Error ? e.message : String(e),
        fix: "--entry には検出対象の HTML（source ルート相対）を指定してください",
      });
    }
  })();

  if (manifest.pages.length === 0) {
    failAndExit({
      what: "HTML ページが見つかりませんでした",
      why: `${dir} に実質空でない .html / .htm ファイルがありません`,
      fix: "テンプレートのルートディレクトリ（index.html がある階層）または HTML ファイルを指定してください",
    });
  }

  const outPath = opts.out ? resolve(opts.out) : defaultOutPath(source);
  mkdirSync(join(outPath, ".."), { recursive: true });
  const json = `${JSON.stringify(manifest, null, 2)}\n`;
  writeFileSync(outPath, json, "utf8");

  ok(`Manifest を出力しました: ${outPath}`);
  info(`ページ: ${manifest.pages.length}`);
  info(
    `entry (home): ${manifest.entry ?? "未決定（--entry で指定可能 — plan では REVIEW になります）"}`,
  );
  info(
    `アセット: css ${manifest.assets.css.length} / js ${manifest.assets.js.length} / images ${manifest.assets.images.length} / fonts ${manifest.assets.fonts.length}`,
  );
  info(`外部依存: ${manifest.external.length}`);
  info(`共通化候補: ${manifest.shared_candidates.length}`);
  info(`共通構造: ${manifest.common_structures.length}`);
  info(`フォーム: ${manifest.forms.length}`);
  info(`繰り返し構造: ${manifest.repeats.length}`);
  info(
    `Media 候補画像: ${manifest.image_classification.media_candidates.length} / static asset: ${manifest.image_classification.static_assets.length}`,
  );

  // Token Efficiency（spec §39–40）: AI へ渡す context の圧縮率を実測で示す
  const stats = manifest.source_stats;
  const manifestBytes = statSync(outPath).size;
  const manifestLines = json.split("\n").length;
  info(
    `source: ${stats.files} files / ${stats.text_lines} text lines / ${(stats.bytes / 1024).toFixed(1)} KB`,
  );
  info(
    `manifest: ${manifestLines} lines / ${(manifestBytes / 1024).toFixed(1)} KB（fingerprint: ${manifest.fingerprint.total.slice(0, 12)}…）`,
  );

  if (manifest.warnings.length > 0) {
    warn(`警告 ${manifest.warnings.length} 件:`);
    for (const w of manifest.warnings) {
      warn(`  [${w.kind}] ${w.file}: ${w.message}`);
    }
  }
  return 0;
}

function defaultOutPath(source: string): string {
  const agentcms = findAgentcmsDir();
  if (agentcms) return join(agentcms, "manifest", "template.json");
  return join(source, "agentcms.manifest.json");
}

/** --entry の指定（絶対 / 相対 / ファイル名）を source ルート相対の posix パスへ正規化する */
function normalizeToSourceRel(entry: string, source: string): string {
  const abs = isAbsolute(entry) ? entry : resolve(source, entry);
  const rel = relative(source, abs);
  return rel.split(sep).join("/");
}

/**
 * 配布物へ入れる顧客向けドキュメントの一覧（`docs/` からの相対パス）。
 *
 * ## なぜ別ファイルなのか
 *
 * この一覧は**配布物の中身そのもの**である。`package-release.mjs` の中に
 * 置いてあると、走らせて zip を作るまで中身を確かめられない
 * （本体は build も npm pack も smoke test もする）。**判断だけを試験できる形**に
 * しておかないと、抜けたことに次の版まで気づけない。
 *
 * 実際に抜けていた（2026-09-06 / 0.9.4-rc2）: `MIGRATE_FROM_WORDPRESS.md` が
 * 一覧に無く、zip にも `.tgz` にも入っていなかった。README_FIRST §7 のリンクは
 * 死に、`agentcms:wxr-plan` が止まったときの案内は
 * **配布物に存在しないファイルを名指ししていた**。
 *
 * 足したら `scripts/doc-links.mjs` の検査が、配布物の中のリンクが
 * 全部生きているかを機械で言う。
 */
export const CUSTOMER_DOCS = [
  "GETTING_STARTED.md",
  // 記事編集・URL変更など日常運用の案内も利用者へ届ける。
  "EDITOR_QUICKSTART.md",
  "WINDOWS_WSL2.md",
  "TROUBLESHOOTING.md",
  "MAINTENANCE.md",
  "AI_AGENTS.md",
  "PROJECT_STRUCTURE.md",
  "UPGRADING.md",
  "MIGRATE_FROM_WORDPRESS.md",
  "deployment/xserver.md",
];

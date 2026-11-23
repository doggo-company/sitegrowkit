#!/usr/bin/env node
import { Command } from "commander";
import { EngineError } from "./engine.js";
import { CommandAbortedError } from "./run.js";
import { fail } from "./ui.js";
import { create } from "./commands/create.js";
import { deployDoctor, deployPlan, deployRollback, deploySetup, deployStaging, verifyStaging, } from "./commands/deploy.js";
import { dev, stop, testCmd } from "./commands/dev.js";
import { doctor } from "./commands/doctor.js";
import { figmaApply } from "./commands/figma-apply.js";
import { figmaConnect } from "./commands/figma-connect.js";
import { figmaDiff } from "./commands/figma-diff.js";
import { figmaInspect } from "./commands/figma-inspect.js";
import { figmaInterpret } from "./commands/figma-interpret.js";
import { figmaPlan } from "./commands/figma-plan.js";
import { figmaVerify } from "./commands/figma-verify.js";
import { mcp } from "./commands/mcp.js";
import { setup } from "./commands/setup.js";
import { syncAgents } from "./commands/sync-agents.js";
import { syncTemplateContext } from "./commands/sync-template-context.js";
import { templateInspect } from "./commands/template-inspect.js";
import { templateApply } from "./commands/template-apply.js";
import { templatePlan } from "./commands/template-plan.js";
import { templateVerify } from "./commands/template-verify.js";
import { updateCheck } from "./commands/update.js";
import { verify } from "./commands/verify.js";
import { kitVersion } from "./version.js";
const program = new Command();
program
    .name("agentcms")
    .description("SiteGrowKit — AI エージェント時代の Web 制作スターターキット CLI")
    .version(kitVersion());
// Setup Wizard は利用者向けの主インターフェース（CLI はエンジン / 上級者向け）
program
    .command("setup")
    .description("セットアップ画面をブラウザで開く（ターミナル操作は不要）")
    .action(async () => process.exit(await setup()));
program
    .command("create")
    .alias("new")
    .argument("<dir>", "作成するプロジェクトディレクトリ")
    .description("テンプレートから新しい CMS プロジェクトを作成")
    .action(async (dir) => process.exit(await create(dir)));
// AI エージェント連携（MCP stdio server）。利用者は管理画面から設定を生成する
program
    .command("mcp")
    .description("AI エージェント連携サーバーを起動する（MCP / stdio）")
    .option("--root <dir>", "CMS プロジェクトのフォルダ")
    .action(async (options) => process.exit(await mcp(options.root)));
program
    .command("doctor")
    .description("環境診断（Docker / Node / ポート / プロジェクト状態）")
    .action(async () => process.exit(await doctor()));
program
    .command("dev")
    .description("開発環境を起動（Docker + 初期セットアップ）")
    .action(async () => process.exit(await dev()));
program
    .command("stop")
    .description("開発環境を停止")
    .action(async () => process.exit(await stop()));
program
    .command("test")
    .description("Pest テストを実行（引数は artisan test へ渡されます）")
    .allowUnknownOption(true)
    .argument("[args...]")
    .action(async (args) => process.exit(await testCmd(args)));
program
    .command("verify")
    .argument("[target]", "省略時はローカル品質ゲート。`staging` でデプロイ済み環境の外形検証")
    .description("品質ゲート一括実行（format / static analysis / tests / build / secret scan / audit）/ verify staging")
    .action(async (target) => {
    if (target === "staging")
        process.exit(await verifyStaging());
    if (target) {
        console.error(`不明な verify 対象です: ${target}（対応: staging）`);
        process.exit(1);
    }
    process.exit(await verify());
});
const deploy = program
    .command("deploy")
    .description("XServer デプロイ（doctor / plan / staging / rollback。既定の target は staging）");
deploy
    .command("doctor")
    .option("-t, --target <target>", "デプロイ先", "staging")
    .description("デプロイ先環境の診断（SSH / PHP / MySQL / 権限。破壊的変更なし）")
    .action(async (opts) => process.exit(await deployDoctor(opts.target)));
deploy
    .command("setup")
    .option("-t, --target <target>", "デプロイ先", "staging")
    .option("-y, --yes", "確認プロンプトを省略")
    .description("XServer API でサーバー前提条件を整える（subdomain / SSL / DB / Cron / PHP。要 XSERVER_API_KEY。作成のみ・冪等）")
    .action(async (opts) => process.exit(await deploySetup(opts.target, { yes: opts.yes })));
deploy
    .command("plan")
    .option("-t, --target <target>", "デプロイ先", "staging")
    .option("--offline", "リモートへ接続せずローカル情報のみで表示")
    .description("Deployment Plan の生成（実変更なし・秘密情報なし）")
    .action(async (opts) => process.exit(await deployPlan(opts.target, { offline: opts.offline })));
deploy
    .command("staging")
    .option("-t, --target <target>", "デプロイ先", "staging")
    .option("--dry-run", "artifact 構築までで停止（サーバーへの変更なし）")
    .option("-y, --yes", "確認プロンプトを省略")
    .option("--confirm-target <target>", "staging 以外へ押すときの再確認（target 名をもう一度書く。-y だけでは通らない）")
    .description("デプロイ（既定 staging。backup → migrate → 切替 → health check、失敗時は切り戻し）")
    .action(async (opts) => process.exit(await deployStaging(opts.target, opts)));
deploy
    .command("rollback")
    .option("-t, --target <target>", "デプロイ先", "staging")
    .option("-y, --yes", "確認プロンプトを省略")
    .option("--confirm-target <target>", "staging 以外へ押すときの再確認（target 名をもう一度書く。-y だけでは通らない）")
    .description("直前の release へ切り戻す（DB は自動では戻さない）")
    .action(async (opts) => process.exit(await deployRollback(opts.target, opts)));
const template = program
    .command("template")
    .description("HTML テンプレート関連");
template
    .command("inspect")
    .argument("<path>", "HTML テンプレートのディレクトリ、または単一 HTML ファイル")
    .option("-o, --out <file>", "Manifest の出力先")
    .option("-e, --entry <file>", "home (/) として扱うページ（省略時: 単一ファイル対象 > 空でない index.html）")
    .description("テンプレートを決定論的に調査し機械可読 Manifest を出力")
    .action(async (dir, opts) => process.exit(await templateInspect(dir, opts)));
template
    .command("plan")
    .option("-m, --manifest <file>", "Manifest ファイル（省略時は .agentcms/manifest/template.json）")
    .description("Manifest から変換計画を生成（json + 人間確認用 markdown）")
    .action(async (opts) => process.exit(await templatePlan(opts)));
template
    .command("apply")
    .option("-s, --source <dir>", "元テンプレートのパス（省略時は Plan の source）")
    .option("--dry-run", "書き込みせず適用内容のみ表示")
    .option("-f, --force", "conflict ファイルを backup へ退避した上で上書きする")
    .description("承認済み Plan をプロジェクトへ適用（conflict 時は STOP）")
    .action(async (opts) => process.exit(await templateApply(opts)));
template
    .command("verify")
    .option("--offline", "起動中の環境へ接続せず、記録との一致だけを検証する")
    .description("Template Import の検証（Manifest/Plan整合・fingerprint・生成物・schema・assets・HTTP）")
    .action(async (opts) => process.exit(await templateVerify({ offline: opts.offline })));
const figma = program
    .command("figma")
    .description("Figma Design Import（connect / inspect / diff / plan / apply / verify — Figma へは Read Only）");
figma
    .command("connect")
    .argument("<url>", "Figma file の URL（design / file / proto / board）")
    .option("-n, --nodes <ids>", "取得対象 node id（カンマ区切り。例: 0:1 または 1-2 形式）")
    .description("Figma URL を解析して project config を保存（token は保存しない・API call なし）")
    .action(async (url, opts) => process.exit(await figmaConnect(url, opts)));
figma
    .command("inspect")
    .option("-o, --out <file>", "Design Manifest の出力先")
    .option("--renders", "screen frame の render を Visual Baseline として保存（Tier 1 API call が増える）")
    .option("--variables", "Variables API を要求する（Advanced Capability。要 file_variables:read + Enterprise。既定は NOT REQUESTED）")
    .description("Metadata First → targeted fetch → Raw Cache → 決定論的 Design Manifest 生成")
    .action(async (opts) => process.exit(await figmaInspect(opts)));
figma
    .command("diff")
    .description("保存済み Manifest と現在の Figma の Semantic Diff（ADDED / CHANGED / REMOVED。適用はしない）")
    .action(async () => process.exit(await figmaDiff()));
figma
    .command("plan")
    .description("Design Manifest → 実装 Plan（confidence + 理由付き。json + 人間確認用 markdown）")
    .action(async () => process.exit(await figmaPlan()));
figma
    .command("apply")
    .option("--dry-run", "書き込みせず適用内容のみ表示")
    .option("-f, --force", "conflict ファイルを backup へ退避した上で上書きする")
    .description("承認済み Plan を Local プロジェクトへ適用（Figma へは書き込まない。conflict 時は STOP）")
    .action(async (opts) => process.exit(await figmaApply(opts)));
figma
    .command("interpret")
    .option("--status", "記録済み interpretation と現状 hash の突き合わせのみ表示")
    .description("承認済み AI interpretation を provenance へ記録（generated → interpreted。hash 保護は維持）")
    .action(async (opts) => process.exit(await figmaInterpret(opts)));
figma
    .command("verify")
    .description("Figma Import の検証（fingerprint / plan / provenance / registry / secret / gitignore / HTTP）")
    .action(async () => process.exit(await figmaVerify()));
const update = program
    .command("update")
    .description("配布物の更新確認（check のみ。ダウンロード・適用・自動更新はしない）");
update
    .command("check")
    .requiredOption("--manifest <file>", "配布物と一緒に受け取った release-manifest.json（ローカルファイル）")
    .requiredOption("--signature <file>", "その manifest の Ed25519 detached 署名（release-manifest.json.sig）")
    .requiredOption("--public-key <file>", "発行者の公開鍵 PEM。配布物とは別の経路で受け取ったものを指定する（配布物内の鍵は使わない）")
    .option("--archive <file>", "ダウンロードした .tgz / .zip。指定すると sha256 とサイズを manifest と実体照合する")
    .option("--project <dir>", "比較対象のプロジェクト（省略時は cwd から探す）")
    .option("--json", "機械可読な結果を stdout へ出す（秘密情報なし）")
    .option("--no-record", ".agentcms/local/update-check.json へ結果を記録しない")
    .description("ローカルの manifest / 署名 / 信頼鍵（/ アーカイブ）を突き合わせ、利用中の版との前後関係と互換性を表示する（ネット接続なし・適用なし）")
    .action(async (opts) => process.exit(await updateCheck(opts)));
program
    .command("sync-agents")
    .description(".agentcms/（SSoT）から CLAUDE.md / AGENTS.md を生成")
    .action(async () => process.exit(await syncAgents()));
program
    .command("sync-template-context")
    .option("--check", "生成せずドリフト検証のみ（差分があれば非 0 終了）")
    .description("kit 開発用: canonical .agentcms/ から template/.agentcms/ を決定論的に生成")
    .action(async (opts) => process.exit(await syncTemplateContext(opts)));
/**
 * engine 層は process.exit せず EngineError を throw する（Phase 8.5-A）。
 * CLI entrypoint だけが what/why/fix の表示と終了コードへの変換を担う。
 */
program.parseAsync(process.argv).catch((e) => {
    if (e instanceof EngineError) {
        fail(e.failure);
        process.exit(1);
    }
    if (e instanceof CommandAbortedError) {
        fail({
            what: "処理を中止しました",
            why: e.message,
            fix: "もう一度実行してください",
        });
        process.exit(130);
    }
    throw e;
});

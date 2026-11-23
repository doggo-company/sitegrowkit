import { cpSync, existsSync, mkdirSync, copyFileSync, readdirSync, readFileSync, renameSync, writeFileSync, } from "node:fs";
import { BUNDLED_SAMPLES_PATH, collectBundledSamples, } from "../importer/bundled-samples.js";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { writeAgentEntrypoints } from "../agent-context.js";
import { filterRegistryForProject, shouldCopyTemplatePath as shouldCopy, } from "../distribution.js";
import { ensureComposeIdentity } from "../compose-identity.js";
import { ensureAdminCredential, setEnvValue, CREDENTIALS_REL, } from "../credentials.js";
import { choosePorts } from "../ports.js";
import { emit, step } from "../engine.js";
import { commandExists, runCapture } from "../run.js";
import { ensureHostUserEnv, isDrvfsPath, isWsl } from "../runtime-user.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import { kitVersion, TEMPLATE_SCHEMA_VERSION, AGENT_CONTEXT_VERSION, } from "../version.js";
/**
 * template/ から新しい CMS プロジェクトを生成する（ネットワーク不要）。
 * Golden Path（Decision 5）: 生成・project identity・port 決定・管理者認証情報・
 * git 初期化・AI Agent Context 生成までを create が担う。実行系は dev が担う。
 */
/**
 * 配布境界は `../distribution.js` が単一情報源である（SPEC §C.5）。
 * ここから再輸出するのは、既存の呼び出し側（テストを含む）を壊さないため。
 */
export { shouldCopyTemplatePath } from "../distribution.js";
/**
 * @param templateDir **利用者の手元と同じ template を渡すための継ぎ目**（試験専用）。
 *
 * 省略すると開発機／インストール先の `template/` を探す（本番はこちら）。
 * ▷ **なぜ要るか。** 配布物は `git archive` / `npm pack` を通るので
 * **空ディレクトリを 1 つも持たない**。開発機の `template/` にはそれが在るため、
 * **試験は「入れ物が在る世界」しか通れない** — 実際にそれで
 * 利用者の最初の 1 コマンドが落ちる欠陥を通した（2026-08-28）。
 * 共有の `template/` を試験中に退かすと**他の試験と競合する**ので、
 * 渡せるようにしてある。
 */
export async function create(targetDir, ctx, templateDirOverride) {
    const target = resolve(targetDir);
    const name = basename(target);
    heading(`agentcms create — ${name} を作成します (SiteGrowKit v${kitVersion()})`);
    step(ctx, "create.started");
    if (existsSync(target)) {
        fail({
            what: `${target} は既に存在します`,
            why: "既存ディレクトリへの上書き生成は破壊的なため行いません",
            fix: "別の名前を指定するか、既存ディレクトリを確認して削除してから再実行してください",
        });
        return 1;
    }
    const templateDir = templateDirOverride ?? locateTemplate();
    if (!templateDir) {
        fail({
            what: "プロジェクトテンプレートが見つかりません",
            why: "CLI パッケージから template/ を解決できませんでした",
            fix: "SiteGrowKit を再インストールするか、Kit リポジトリ内で実行してください",
        });
        return 1;
    }
    // テンプレートの解決元（グローバルインストール先）は利用者に不要な内部絶対パスのため表示しない
    mkdirSync(dirname(target), { recursive: true });
    cpSync(templateDir, target, {
        recursive: true,
        filter: (src) => shouldCopy(src.slice(templateDir.length + 1)),
    });
    restoreDistDotfiles(target);
    filterProjectRegistries(target);
    /*
     * 🔴 **同梱している見本の一覧を、この時点で書き出す**（B-60）。
     *
     * kit の `template/` には**別案件の生成物**が入っている。
     * apply から見れば「記録に無い既存ファイル」なので、
     * **利用者は初回から conflict を見て `--force` を打つことになる。**
     *
     * ▷ **ここで数えるのが正しい。**apply の時点で kit を読みに行っても、
     *   **利用者の手元に kit が無い。**
     */
    /*
     * 🔴 **入れ物は、copy では出来ない。**
     *
     * `template/.agentcms/manifest/` は **tracked ファイルを 1 つも持たない**ので、
     * `git archive` も `npm pack` も**運ばない**。開発機には過去の実行の名残で
     * 在るため copy で足りているように見えるが、**利用者の手元には無い**。
     *
     * ▷ **利用者の最初の 1 コマンドが `ENOENT` で落ちる**（2026-08-28・
     *   v0.9.3 の packaging の smoke test で実測）。**書く前に作る。**
     */
    mkdirSync(dirname(join(target, BUNDLED_SAMPLES_PATH)), { recursive: true });
    writeFileSync(join(target, BUNDLED_SAMPLES_PATH), `${JSON.stringify(collectBundledSamples(templateDir), null, 2)}\n`);
    step(ctx, "create.files.completed");
    // 1. .env 生成（既存 .env はコピー対象外なので常に .env.example から）
    copyFileSync(join(target, ".env.example"), join(target, ".env"));
    // Windows mounted filesystem（DrvFS）警告 — Tier 1 Golden Path は WSL2 Linux filesystem
    if (isWsl() && isDrvfsPath(target)) {
        warn("この Project は Windows mounted filesystem（/mnt/*）上に作成されます");
        info("  理由: Docker bind mount の性能が大きく低下する可能性があります");
        info("  理由: Linux permission 0600 が保証されず、認証情報ファイルが広い権限になる可能性があります");
        info("  推奨: ~/projects/ など WSL2 の Linux filesystem 上で作成してください");
    }
    // 2. project identity（compose project / volume の分離 — Decision 9）+ host user 記録
    const identity = ensureComposeIdentity(target);
    info(`Docker identity: ${identity.name}`);
    ensureHostUserEnv(target);
    step(ctx, "create.identity.completed");
    // 3. ポート決定（使用中なら空きへ。以後 .env で安定 — Decision 9）
    let appPort = 8180;
    try {
        const ports = await choosePorts();
        let env = readFileSync(join(target, ".env"), "utf8");
        for (const [key, port] of Object.entries(ports.assignments)) {
            env = setEnvValue(env, key, String(port));
        }
        appPort = ports.assignments["APP_PORT"] ?? 8180;
        env = setEnvValue(env, "APP_URL", `http://localhost:${appPort}`);
        writeFileSync(join(target, ".env"), env, "utf8");
        for (const c of ports.changed) {
            warn(`ポートが使用中のため変更しました — ${c}（.env に記録済み）`);
        }
    }
    catch (e) {
        fail({
            what: "空きポートを確保できませんでした",
            why: e instanceof Error ? e.message : String(e),
            fix: "使用中のポートを解放するか、生成後に .env の *_PORT を手動設定してください",
        });
        return 1;
    }
    // 4. 管理者認証情報（project ごとに secure random — Decision 4。値は表示しない）
    const cred = ensureAdminCredential(target);
    if (cred.insecurePermissions) {
        warn("認証情報ファイルの permission を 0600 にできませんでした（Windows mounted filesystem の可能性）。WSL2 の Linux filesystem 上での作成を強く推奨します");
    }
    // 5. project metadata（どの Kit version から生成されたか — Decision 2）
    writeFileSync(join(target, ".agentcms/project.json"), `${JSON.stringify({
        name,
        compose_project: identity.name,
        kit_version: kitVersion(),
        template_schema_version: TEMPLATE_SCHEMA_VERSION,
        agent_context_version: AGENT_CONTEXT_VERSION,
        created_at: new Date().toISOString(),
    }, null, 2)}\n`, "utf8");
    // 6. AI Agent Context entrypoint（.agentcms/ は template に同梱済み — Decision 3）
    writeAgentEntrypoints(target, join(target, ".agentcms"));
    step(ctx, "create.context.completed");
    // 7. git 初期化（verify の secret scan の前提 — Decision 6。既存 .git は破壊しない）
    if (existsSync(join(target, ".git"))) {
        info("既存の .git を検出したため git init はスキップしました");
    }
    else if (commandExists("git")) {
        const init = runCapture("git", ["init"], target);
        if (init.code === 0) {
            runCapture("git", ["add", "-A"], target);
            ok("git リポジトリを初期化しました（最初のコミットはあなたのタイミングで）");
        }
        else {
            warn("git init に失敗しました。`agentcms verify` の前に手動で git init してください");
        }
    }
    else {
        warn("git が見つかりません。git init は省略しました（セットアップには不要です。バージョン管理を使う場合のみ導入してください）");
    }
    ok("プロジェクトを作成しました");
    emit(ctx, { type: "result", key: "projectPath", value: target });
    emit(ctx, {
        type: "result",
        key: "publicUrl",
        value: `http://localhost:${appPort}`,
    });
    step(ctx, "create.completed");
    console.log("");
    info(`公開サイト予定 URL: http://localhost:${appPort}（起動後）`);
    info(`管理者ログイン情報: ${CREDENTIALS_REL}（Git 対象外・値は表示しません）`);
    console.log("");
    info("次のステップ:");
    info(`  1. cd ${targetDir}`);
    info("  2. agentcms dev   # Docker 起動・依存インストール・build・migrate まで自動");
    info("     （環境に不安がある場合は先に agentcms doctor）");
    return 0;
}
/**
 * npm pack は一部の dotfile（.gitignore / .npmrc）を tarball から除外するため、
 * 配布 package では `.dist` 接尾辞で同梱される（scripts/package-release.mjs）。
 * 生成 project では本来の名前へ戻す（リポジトリからの生成時は対象が無く no-op）。
 */
export function restoreDistDotfiles(root) {
    const RESTORE = new Map([
        [".gitignore.dist", ".gitignore"],
        [".npmrc.dist", ".npmrc"],
    ]);
    const walk = (dir) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const p = join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(p);
            }
            else {
                const to = RESTORE.get(entry.name);
                if (to)
                    renameSync(p, join(dir, to));
            }
        }
    };
    walk(root);
}
/**
 * 生成した project の registry から、配らないものを落とす（SPEC §C.5）。
 *
 * template 自身の索引をそのまま渡すと、その site には無い型や分類が載ったままになる。
 * 判断は配布境界が持ち、ここはそれを当てるだけ。
 */
function filterProjectRegistries(root) {
    for (const rel of [
        "registry/content-types.json",
        "registry/taxonomies.json",
    ]) {
        const path = join(root, ".agentcms", rel);
        if (!existsSync(path))
            continue;
        const filtered = filterRegistryForProject(rel, readFileSync(path, "utf8"));
        if (filtered !== null)
            writeFileSync(path, filtered, "utf8");
    }
}
/** CLI の実行位置から template/ を探す（リポジトリ内 or npm パッケージ同梱） */
function locateTemplate() {
    const here = dirname(fileURLToPath(import.meta.url));
    const candidates = [
        resolve(here, "../../../../template"), // repo: packages/cli/dist/commands -> repo root
        resolve(here, "../../template"), // npm 配布: package root 直下に template/ を同梱
    ];
    return candidates.find((c) => existsSync(join(c, "artisan"))) ?? null;
}

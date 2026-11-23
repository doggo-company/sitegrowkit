import { existsSync, copyFileSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { ensureComposeIdentity } from "../compose-identity.js";
import { ensureAdminCredential, readEnvValue, CREDENTIALS_REL, } from "../credentials.js";
import { emit, failWith, step, throwIfAborted, } from "../engine.js";
import { pnpmCommand } from "../node-runtime.js";
import { dockerCredentialFailure } from "../docker-errors.js";
import { classifyComposeFailure, composeFailureMessage, listSiteGrowKitEnvironments, } from "../docker-failure.js";
import { cleanIncompleteVendor, composerState, migrateFailureDiagnosis, } from "../php-deps.js";
import { findProjectRoot, isProjectRoot } from "../project.js";
import { runCapture, runInherit, runInheritCapture } from "../run.js";
import { storageLinkProbeScript } from "../storage-link.js";
import { composeUserArgs, containerWriteProbeScript, ensureHostUserEnv, } from "../runtime-user.js";
import { heading, info, ok, warn } from "../ui.js";
/**
 * 開発環境の起動（Golden Path — Decision 5）。
 * Docker 起動・composer/pnpm 依存・APP_KEY・migrate・seed・frontend build・
 * health check まで dev が責務を持つ。冪等・再実行安全。
 * 部分的成功を SUCCESS として表示しない（health check が通って初めて成功）。
 *
 * Phase 8.5-A: GUI Setup Wizard から関数として呼べるようにした。
 * - process.exit しない（失敗は EngineError）
 * - project root は ctx.root で明示できる（cwd 非依存）
 * - 進捗は ctx.onEvent の StepId で受け取れる（stdout parsing に依存しない）
 * - pnpm は docker の node service で実行する（ホストの Node/pnpm 不要）
 */
export async function dev(ctx) {
    const root = requireProject(ctx?.root);
    heading("agentcms dev — 開発環境を起動します");
    step(ctx, "dev.started");
    const forward = {
        signal: ctx?.signal,
        onStdout: (line) => emit(ctx, { type: "log", stream: "stdout", line }),
        onStderr: (line) => emit(ctx, { type: "log", stream: "stderr", line }),
    };
    if (!existsSync(join(root, ".env"))) {
        copyFileSync(join(root, ".env.example"), join(root, ".env"));
        ok(".env を .env.example から作成しました");
    }
    // project identity / host user / 管理者認証情報を保証（旧 create の project でも成立させる）
    const identity = ensureComposeIdentity(root);
    if (identity.created) {
        ok(`Docker identity を生成しました: ${identity.name}（.env に記録）`);
    }
    ensureHostUserEnv(root);
    const cred = ensureAdminCredential(root);
    if (cred.created) {
        ok(`管理者認証情報を生成しました: ${CREDENTIALS_REL}（値は表示しません）`);
    }
    if (cred.insecurePermissions) {
        warn("認証情報ファイルを 0600 にできませんでした（Windows mounted filesystem の可能性）。WSL2 の Linux filesystem（~/ 配下）での利用を推奨します");
    }
    // container 内での composer / artisan 実行を host uid に合わせる（root-owned files 禁止）
    const execApp = (args) => [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(root),
        "app",
        ...args,
    ];
    throwIfAborted(ctx);
    step(ctx, "dev.docker.started");
    info("docker compose up -d --wait --build（初回はイメージ build のため数分かかります）");
    const upRun = await runInheritCapture("docker", ["compose", "up", "-d", "--wait", "--build"], root, forward);
    if (upRun.code !== 0) {
        // 原因ごとの言葉にする（UX 一周 18 周目 G-env: ネットワークの空き切れでも「Docker Desktop を確認」と出ていた）
        const cause = classifyComposeFailure(`${upRun.stdout}\n${upRun.stderr}`);
        const said = composeFailureMessage(cause, cause === "network_pool" ? listSiteGrowKitEnvironments() : [], readEnvValue(readFileSync(join(root, ".env"), "utf8"), "COMPOSE_PROJECT_NAME") ?? "");
        failWith({
            code: cause === "unknown" ? "docker.compose_up_failed" : `docker.compose_up_failed.${cause}`,
            what: "コンテナの起動に失敗しました",
            why: `docker compose up が非 0 で終了しました（原因: ${cause}・上のログ参照）`,
            fix: said.fix,
            title: said.title,
            message: said.message,
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        });
    }
    // bind mount への container 側書き込み検査（WSL2 uid 不一致は HTTP 500 になる前にここで止める）
    const writable = runCapture("docker", execApp(["sh", "-c", containerWriteProbeScript()]), root);
    if (writable.code !== 0) {
        failWith({
            code: "docker.container_not_writable",
            what: "コンテナからプロジェクトへ書き込めません",
            why: `container 実行ユーザーと host のファイル所有者（UID/GID）が一致していません: ${writable.stdout || writable.stderr}`,
            fix: "もう一度 `agentcms dev` を実行してください（HOST_UID/HOST_GID でイメージを再ビルドします）。解決しない場合は `docker compose build --no-cache app` 後に再実行。chmod 777 / sudo chown での回避はしないでください",
            title: "プロジェクトフォルダへ書き込めませんでした",
            message: "もう一度実行すると自動で修復を試みます（フォルダの権限を手で変更する必要はありません）。",
            recovery: [{ kind: "retry", label: "もう一度試す" }],
        });
    }
    step(ctx, "dev.docker.completed");
    throwIfAborted(ctx);
    step(ctx, "dev.dependencies.started");
    // completion condition は vendor/ の存在ではなく vendor/autoload.php（RC1 Blocker）
    const phpDeps = composerState(root);
    if (phpDeps !== "complete") {
        if (phpDeps === "incomplete") {
            warn("前回の composer install が不完全です（vendor/ はあるが vendor/autoload.php がありません）");
            info("不完全な vendor/ を削除して composer install をやり直します");
            const cleaned = cleanIncompleteVendor(root);
            if (!cleaned.removed) {
                failWith({
                    code: "composer.incomplete_vendor_cleanup_failed",
                    what: "不完全な vendor/ を自動削除できませんでした",
                    why: cleaned.reason ?? "安全条件を満たしませんでした",
                    fix: "vendor/ がプロジェクト直下の通常のディレクトリであることを確認し、手動で削除してから `agentcms dev` を再実行してください",
                });
            }
        }
        info("PHP 依存をインストールします（composer install）");
        const ci = await runInherit("docker", execApp(["composer", "install", "--no-interaction"]), root, undefined, forward);
        if (ci !== 0) {
            failWith({
                code: "composer.install_failed",
                what: "composer install に失敗しました",
                why: "依存解決またはネットワークエラーです（上のログ参照）",
                fix: "ネットワーク接続を確認し、もう一度 `agentcms dev` を実行してください（不完全な vendor/ は自動で検出して作り直します）",
                title: "必要なファイルの準備中に問題が発生しました",
                message: "インターネット接続を確認して、もう一度お試しください。途中まで進んだ状態から再開します。",
                recovery: [
                    { kind: "retry", label: "もう一度試す" },
                    { kind: "showDetails", label: "詳細を見る" },
                ],
            });
        }
        if (composerState(root) !== "complete") {
            failWith({
                code: "composer.install_incomplete",
                what: "composer install は終了しましたが vendor/autoload.php が生成されていません",
                why: "インストールが完全には完了していません（container と host の同期の問題の可能性）",
                fix: "もう一度 `agentcms dev` を実行してください（不完全な vendor/ を自動で作り直します）",
                title: "必要なファイルの準備が完了しませんでした",
                message: "もう一度お試しください（不足分だけを自動でやり直します）。",
                recovery: [{ kind: "retry", label: "もう一度試す" }],
            });
        }
    }
    if (!existsSync(join(root, "node_modules"))) {
        throwIfAborted(ctx);
        info("フロントエンド依存をインストールします（node コンテナ内 pnpm — ホストの Node/pnpm は不要です）");
        const install = pnpmCommand(root, ["install"]);
        const pi = await runInheritCapture(install.cmd, install.args, root, {
            signal: ctx?.signal,
            onStdout: forward.onStdout,
            onStderr: forward.onStderr,
        });
        if (pi.code !== 0) {
            const combined = `${pi.stdout}\n${pi.stderr}`;
            // 認証ヘルパーの失敗を「ネットを確認して再試行」のループへ入れない。
            const credentialFailure = dockerCredentialFailure(combined);
            if (credentialFailure)
                failWith(credentialFailure);
            // build 承認不足を network error と誤診しない（Windows RC1 実測の一般化）
            if (/ERR_PNPM_IGNORED_BUILDS|Ignored build scripts/i.test(combined)) {
                failWith({
                    code: "pnpm.ignored_builds",
                    what: "pnpm が build script の承認不足で失敗しました",
                    why: "build 承認設定（pnpm-workspace.yaml の onlyBuiltDependencies）に無いパッケージがあります",
                    fix: "エラーに表示されたパッケージ名を pnpm-workspace.yaml の onlyBuiltDependencies へ追加してから `agentcms dev` を再実行してください（pnpm approve-builds の手動実行は不要な設計です）",
                    title: "フロントエンドの準備が完了しませんでした",
                    message: "詳細ログに表示されたパッケージの設定が必要です。",
                    recovery: [{ kind: "showDetails", label: "詳細を見る" }],
                });
            }
            failWith({
                code: "pnpm.install_failed",
                what: "pnpm install に失敗しました",
                why: "依存解決またはネットワークエラーです（上のログ参照）",
                fix: "ネットワーク接続を確認し、もう一度 `agentcms dev` を実行してください",
                title: "サイトの見た目に必要なファイルを準備できませんでした",
                message: "インターネット接続を確認して、もう一度お試しください。途中まで進んだ状態から再開します。",
                recovery: [
                    { kind: "retry", label: "もう一度試す" },
                    { kind: "showDetails", label: "詳細を見る" },
                ],
            });
        }
    }
    step(ctx, "dev.dependencies.completed");
    // 診断順の保証（RC1 Fix 4）: artisan 系（APP_KEY / migrate / seed）へ進む前に
    // bootstrap artifacts を最終確認する。ここで不完全ならDB より先にそれを報告する
    if (composerState(root) !== "complete") {
        failWith(migrateFailureDiagnosis(root));
    }
    throwIfAborted(ctx);
    step(ctx, "dev.database.started");
    const envContent = readFileSync(join(root, ".env"), "utf8");
    if (/^APP_KEY=\s*$/m.test(envContent)) {
        info("APP_KEY を生成します");
        await runInherit("docker", execApp(["php", "artisan", "key:generate", "--force"]), root, undefined, forward);
    }
    info("データベースを migrate します");
    const mig = await runInherit("docker", execApp(["php", "artisan", "migrate", "--force"]), root, undefined, forward);
    if (mig !== 0) {
        // 診断順: PHP 依存（bootstrap artifacts）→ database（MySQL 誤診の防止 — RC1 Fix 4）
        failWith(migrateFailureDiagnosis(root));
    }
    // migration 成功後の**明示ステップ**として分類定義を同期する（SPEC §J.4 / SY-2）。
    // runtime（ServiceProvider / middleware / controller）からは決して実行しない
    info("分類の定義をデータベースへ同期します");
    const taxonomySynced = await runInherit("docker", execApp(["php", "artisan", "agentcms:taxonomy-sync"]), root, undefined, forward);
    if (taxonomySynced !== 0) {
        failWith({
            code: "artisan.taxonomy_sync_failed",
            what: "agentcms:taxonomy-sync に失敗しました",
            why: "分類（カテゴリなど）の定義をデータベースへ反映できませんでした（上のログ参照）",
            fix: "content/taxonomies/ の JSON を確認し、上のメッセージの指示に従ってから `agentcms dev` を実行し直してください",
            title: "分類の設定を反映できませんでした",
            message: "カテゴリなどの分類を準備できませんでした。安全のため、内容は変更していません。",
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        });
    }
    /*
     * 画像を公開できるようにする（`public/storage` → `storage/app/public`）。
     *
     * 🔴 **ここだけ結果を見ていなかった**（WP 一周 6 周目 2026-09-03）。
     * ほかの artisan は全部 exit code を見て、失敗したら題名付きで止まる。
     * 張られていないと**アップロードした画像が全部 403** になり、
     * 管理画面のメディア一覧に壊れた画像が並ぶ — **画面は何も言わない**。
     * 確認環境が実際にその状態だった。
     *
     * exit code だけでは足りない。`storage:link` は `public/storage` が既に在るとき
     * `ERROR  The [public/storage] link already exists.` と出しながら **exit 0** を返す。
     * だから**通っていることを別に確かめる**（`storageLinkProbeScript`）。
     */
    runCapture("docker", execApp(["php", "artisan", "storage:link"]), root);
    const linked = runCapture("docker", execApp(["sh", "-c", storageLinkProbeScript()]), root);
    if (linked.code !== 0) {
        failWith({
            code: "artisan.storage_link_failed",
            what: "public/storage が storage/app/public へ通っていません",
            why: `画像の公開先へのリンクを作れませんでした: ${linked.stdout || linked.stderr}`,
            fix: "public/storage を消してから `agentcms dev` を実行し直してください（別のフォルダやファイルが同じ名前で置かれていると張れません）",
            title: "画像を公開できる状態になっていません",
            message: "このままだと、アップロードした画像がサイトにも管理画面にも表示されません（403 になります）。",
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        });
    }
    info("シードデータを投入します（管理者ユーザー含む）");
    const seeded = await runInherit("docker", execApp(["php", "artisan", "db:seed", "--force"]), root, undefined, forward);
    if (seeded !== 0) {
        failWith({
            code: "artisan.seed_failed",
            what: "db:seed に失敗しました",
            why: "管理者ユーザー等の初期データを投入できませんでした（上のログ参照）",
            fix: "`.env` の ADMIN_EMAIL / ADMIN_PASSWORD を確認し、もう一度 `agentcms dev` を実行してください",
            title: "初期データの準備に失敗しました",
            message: "もう一度お試しください。",
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        });
    }
    step(ctx, "dev.database.completed");
    throwIfAborted(ctx);
    step(ctx, "dev.build.started");
    // wayfinder の TS artifacts は container 内 PHP で生成する
    // （Product Contract: host PHP は NOT REQUIRED — Windows RC1 Blocker 3 の一般化）
    info("wayfinder TS artifacts を生成します（container 内 PHP）");
    const wf = await runInherit("docker", execApp(["php", "artisan", "wayfinder:generate", "--with-form"]), root, undefined, forward);
    if (wf !== 0) {
        failWith({
            code: "artisan.wayfinder_failed",
            what: "wayfinder:generate に失敗しました",
            why: "管理画面の型付きルート（resources/js/{actions,routes,wayfinder}）を生成できませんでした（上のログ参照）",
            fix: "`docker compose logs app` を確認し、もう一度 `agentcms dev` を実行してください",
        });
    }
    // 管理画面（Inertia）は Vite build 生成物が無いと 500 になる — Baseline A 実測の一般化
    if (needsFrontendBuild(root)) {
        info("フロントエンドを build します（node コンテナ内 — 管理画面の表示に必要）");
        // artifacts は container 側で生成済みのため、build は host php に依存しない
        const build = pnpmCommand(root, ["run", "build"], {
            env: { AGENTCMS_SKIP_WAYFINDER: "1" },
        });
        const fb = await runInherit(build.cmd, build.args, root, undefined, forward);
        if (fb !== 0) {
            failWith({
                code: "pnpm.build_failed",
                what: "pnpm build に失敗しました",
                why: "管理画面の表示に必要なアセットを生成できませんでした（上のログ参照）",
                fix: "build ログのエラーを修正して、もう一度 `agentcms dev` を実行してください",
                title: "サイトの見た目を組み立てられませんでした",
                message: "もう一度お試しください。",
                recovery: [
                    { kind: "retry", label: "もう一度試す" },
                    { kind: "showDetails", label: "詳細を見る" },
                ],
            });
        }
    }
    step(ctx, "dev.build.completed");
    // Health check — 実 URL が応答して初めて成功と報告する
    const env = readFileSync(join(root, ".env"), "utf8");
    const appPort = Number(readEnvValue(env, "APP_PORT") ?? "8180");
    const mailpitPort = readEnvValue(env, "MAILPIT_UI_PORT") ?? "8026";
    const loginPath = detectLoginPath(root);
    const publicUrl = `http://localhost:${appPort}/`;
    const loginUrl = `http://localhost:${appPort}/${loginPath}`;
    step(ctx, "dev.health.started");
    info("Health check を実行します");
    const publicOk = await waitForHttp200(publicUrl);
    if (!publicOk) {
        failWith({
            code: "health.public_not_responding",
            what: "公開サイトが応答しません",
            why: `${publicUrl} が HTTP 200 を返しませんでした`,
            fix: "`docker compose logs app web` でエラーを確認してください。修正後に `agentcms dev` を再実行できます",
            title: "サイトが応答しませんでした",
            message: "もう一度お試しください。",
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        });
    }
    const loginOk = await waitForHttp200(loginUrl);
    if (!loginOk) {
        failWith({
            code: "health.login_not_responding",
            what: "管理画面ログインページが応答しません",
            why: `${loginUrl} が HTTP 200 を返しませんでした`,
            fix: "`docker compose logs app` と storage/logs/laravel.log を確認してください。修正後に `agentcms dev` を再実行できます",
            title: "管理画面が応答しませんでした",
            message: "もう一度お試しください。",
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        });
    }
    console.log("");
    ok("起動しました（health check 済み）");
    for (const line of readyLines({
        publicUrl,
        loginUrl,
        credentialsPath: CREDENTIALS_REL,
        mailpitUrl: `http://localhost:${mailpitPort}`,
    })) {
        info(line);
    }
    emit(ctx, { type: "result", key: "publicUrl", value: publicUrl });
    emit(ctx, { type: "result", key: "loginUrl", value: loginUrl });
    emit(ctx, { type: "result", key: "credentialsPath", value: CREDENTIALS_REL });
    emit(ctx, {
        type: "result",
        key: "mailpitUrl",
        value: `http://localhost:${mailpitPort}`,
    });
    step(ctx, "dev.ready");
    return 0;
}
/**
 * 起動できたあとの最後の出力（WP 一周 2026-09-03 / オーナー 13:06）。
 *
 * オーナーの原文 —「**一番困るのははじめインストールした後に何をすればいいか
 * わからないこと**」。
 *
 * これまでは URL の羅列で終わっていた。開けるようになったことは分かるが、
 * **開いて何をするか**がどこにも書いていない。
 * 最後に「次にやること」を 3 行だけ置き、画面側の「最初の 6 歩」へ繋ぐ。
 *
 * 3 行より増やさない。**増やすと読まれない。** 続きは画面が案内する。
 *
 * 文字を組み立てるだけの純粋な関数にしてあるのは、docker 無しで試験できるようにするため。
 */
export function readyLines(urls) {
    return [
        `公開サイト:        ${urls.publicUrl}`,
        `管理画面ログイン:   ${urls.loginUrl}`,
        `  認証情報: ${urls.credentialsPath} を参照（Git 対象外）`,
        `Mailpit UI:        ${urls.mailpitUrl}`,
        "アセット HMR:      `pnpm dev`（任意・管理画面の開発時のみ）",
        "",
        "次にやること:",
        `  1. 管理画面にログインする（${urls.loginUrl}）`,
        "  2. ダッシュボードの「最初の 6 歩」を上から順に進める",
        "  3. 公開する前に「公開前チェック」を 1 回開く",
    ];
}
/** Inertia 管理画面に必要な Vite build 生成物の有無 */
export function needsFrontendBuild(root) {
    return !existsSync(join(root, "public/build/manifest.json"));
}
/**
 * 実アプリの route 定義からログインパスを決定する（hardcode しない — Decision 8）。
 * route:list が使えない場合は Fortify 既定の "login" にフォールバックする。
 */
export function detectLoginPath(root) {
    const r = runCapture("docker", [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(root),
        "app",
        "php",
        "artisan",
        "route:list",
        "--json",
        "--name=login",
    ], root);
    if (r.code === 0) {
        const parsed = parseLoginPath(r.stdout);
        if (parsed)
            return parsed;
    }
    return "login";
}
/** route:list --json の出力から GET login route の uri を取り出す */
export function parseLoginPath(routeListJson) {
    try {
        const routes = JSON.parse(routeListJson);
        const login = routes.find((x) => x.name === "login" && (x.method ?? "").includes("GET") && x.uri);
        return login?.uri ?? null;
    }
    catch {
        return null;
    }
}
/** URL が HTTP 200 を返すまで待つ（起動直後のウォームアップを考慮してリトライする） */
async function waitForHttp200(url, tries = 10, intervalMs = 1000) {
    for (let i = 0; i < tries; i++) {
        try {
            const res = await fetch(url, { redirect: "follow" });
            if (res.status === 200)
                return true;
        }
        catch {
            // まだ起動中
        }
        await new Promise((r) => setTimeout(r, intervalMs));
    }
    return false;
}
export async function stop(ctx) {
    const root = requireProject(ctx?.root);
    heading("agentcms stop — コンテナを停止します");
    const code = await runInherit("docker", ["compose", "down"], root);
    if (code === 0)
        ok("停止しました（データは volume に保持されています）");
    return code;
}
export async function testCmd(args, ctx) {
    const root = requireProject(ctx?.root);
    return runInherit("docker", [
        "compose",
        "exec",
        ...composeUserArgs(root),
        "app",
        "php",
        "artisan",
        "test",
        ...args,
    ], root);
}
/**
 * project root を決める。
 * explicitRoot が渡されればそれを使い（GUI）、無ければ cwd から探索する（CLI 既定）。
 */
export function requireProject(explicitRoot) {
    // GUI から渡された path も検証する（不正・未作成の path をそのまま docker へ渡さない）
    if (explicitRoot !== undefined) {
        const root = resolve(explicitRoot);
        if (!isProjectRoot(root)) {
            failWith({
                code: "project.not_found",
                what: "CMS プロジェクトが見つかりません",
                why: `指定されたディレクトリに artisan + docker-compose.yml がありません: ${root}`,
                fix: "`agentcms create <dir>` で新規作成するか、プロジェクトディレクトリを指定してください",
                title: "プロジェクトが見つかりませんでした",
                message: "新しいサイトを作るか、作成済みのフォルダを選び直してください。",
            });
        }
        return root;
    }
    const root = findProjectRoot();
    if (!root) {
        failWith({
            code: "project.not_found",
            what: "CMS プロジェクトが見つかりません",
            why: "カレントディレクトリ（の上位）に artisan + docker-compose.yml がありません",
            fix: "`agentcms create <dir>` で新規作成するか、プロジェクトディレクトリ内で実行してください",
            title: "プロジェクトが見つかりませんでした",
            message: "新しいサイトを作るか、作成済みのフォルダを選び直してください。",
        });
    }
    return root;
}

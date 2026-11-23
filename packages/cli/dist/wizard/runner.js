import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { create } from "../commands/create.js";
import { dev } from "../commands/dev.js";
import { doctor } from "../commands/doctor.js";
import { verify } from "../commands/verify.js";
import { setEnvValue } from "../credentials.js";
import { EngineError, failWith, throwIfAborted, } from "../engine.js";
import { CommandAbortedError, runInheritCapture } from "../run.js";
import { composeUserArgs } from "../runtime-user.js";
import { setUiSink } from "../ui.js";
import { AGENT_CLIENTS } from "../mcp/agent-config.js";
import { sanitizeLogLine } from "./sanitize.js";
import { isNativeBindingMismatch, rebuildDependencies } from "./self-heal.js";
import { advanceStage, clearSetupState, readSetupState, writeSetupState, } from "./state.js";
const STAGES = [
    { id: "project", label: "プロジェクトを作成しています" },
    { id: "containers", label: "サイトの土台を起動しています" },
    { id: "dependencies", label: "必要なファイルを準備しています" },
    { id: "database", label: "データベースを準備しています" },
    { id: "build", label: "画面を組み立てています" },
    { id: "health", label: "動作を確認しています" },
    { id: "site", label: "サイト情報を設定しています" },
    { id: "check", label: "サイトを確認しています" },
];
/** StepId → 利用者向け stage。ここに無い step は進捗表示に影響しない */
const STEP_STAGE = {
    "create.started": { stage: "project" },
    "create.completed": { stage: "project", done: true },
    "dev.docker.started": { stage: "containers" },
    "dev.docker.completed": { stage: "containers", done: true },
    "dev.dependencies.started": { stage: "dependencies" },
    "dev.dependencies.completed": { stage: "dependencies", done: true },
    "dev.database.started": { stage: "database" },
    "dev.database.completed": { stage: "database", done: true },
    "dev.build.started": { stage: "build" },
    "dev.build.completed": { stage: "build", done: true },
    "dev.health.started": { stage: "health" },
    "dev.ready": { stage: "health", done: true },
};
/**
 * 環境チェックの表示名（GUI 用）。
 * CLI の表示名は診断用語のままにし、GUI 側だけ利用者の言葉へ置き換える。
 * ただし利用者が実際にインストールする製品名（Docker Desktop 等）はぼかさない
 * — ぼかすとエラー時に何をすればよいか分からなくなるため。
 */
const CHECK_TITLES = {
    "os.supported": "お使いのパソコン（OS）",
    "filesystem.suitable": "作業する場所",
    "node.version": "Node.js",
    "npm.installed": "npm",
    "git.installed": "Git",
    "docker.installed": "Docker Desktop",
    "docker.running": "Docker Desktop の起動状態",
    "docker.compose": "Docker Compose",
    "disk.available": "ディスクの空き容量",
    "filesystem.writable": "フォルダへの書き込み",
};
const MAX_LOG_LINES = 500;
/**
 * 中止後の後片付けコマンド（F-B3）。
 * **`-v` / `--volumes` を含めない** — 中止を理由にデータベースの中身を破棄しない。
 */
export const CANCEL_CLEANUP_ARGS = [
    "compose",
    "down",
    "--remove-orphans",
];
/**
 * サイト設定を更新する artisan コマンド（O-10）。
 * 値は **独立した引数**として渡す（shell を介さず、文字列連結もしない）ため、
 * 日本語・記号・引用符を含むサイト名でも安全に扱える。
 */
export function siteSettingArgs(projectPath, key, value) {
    return [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(projectPath),
        "app",
        "php",
        "artisan",
        "agentcms:site-setting",
        key,
        value,
    ];
}
export class WizardRunner {
    options;
    phase = "WELCOME";
    checks = [];
    environmentChecked = false;
    stageStatus = new Map();
    ready;
    failure;
    logs = [];
    controller = null;
    state = null;
    /** 進行中の操作が対象にしている project（cancel 後の後片付けに使う） */
    activeProjectPath = null;
    canceled = false;
    listeners = new Set();
    constructor(options) {
        this.options = options;
        this.resetStages();
        const saved = readSetupState(this.options.home);
        if (saved && saved.stage !== "ready") {
            // 再開先が消えている（利用者が削除した / 別マシンの state）なら再開を提案しない
            if (existsSync(saved.projectPath)) {
                this.state = saved;
                this.phase = "RESUMABLE";
            }
            else {
                clearSetupState(this.options.home);
            }
        }
    }
    snapshot() {
        return {
            phase: this.phase,
            checks: this.checks,
            environmentOk: this.checks.every((c) => c.status !== "fail"),
            environmentChecked: this.environmentChecked,
            stages: STAGES.map((s) => ({
                ...s,
                status: this.stageStatus.get(s.id) ?? "pending",
            })),
            ready: this.ready,
            failure: this.failure,
            resumable: this.phase === "RESUMABLE" && this.state
                ? {
                    projectName: this.state.projectName,
                    siteName: this.state.siteName,
                    projectPath: this.state.projectPath,
                }
                : undefined,
            canResume: this.state !== null && this.state.stage !== "project_selected",
            pickerAvailable: this.options.pickerAvailable,
            defaultLocation: this.options.defaultLocation,
            busy: this.controller !== null,
        };
    }
    /** 秘匿処理済みの詳細ログ（「詳細を表示」用） */
    detailLogs() {
        return this.logs;
    }
    subscribe(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }
    /** WELCOME → ENVIRONMENT（利用者が「はじめる」を押したとき） */
    enterEnvironment() {
        if (this.phase === "WELCOME" || this.phase === "RESUMABLE") {
            this.phase = "ENVIRONMENT";
            this.push({ type: "snapshot" });
        }
    }
    /** 前回の続きを破棄して最初からやり直す（プロジェクトは削除しない） */
    discardResume() {
        clearSetupState(this.options.home);
        this.state = null;
        if (this.phase === "RESUMABLE")
            this.phase = "WELCOME";
        this.push({ type: "snapshot" });
    }
    async runEnvironmentCheck() {
        if (this.controller)
            return;
        this.checks = [];
        this.failure = undefined;
        this.phase = "ENVIRONMENT";
        const controller = new AbortController();
        this.controller = controller;
        this.push({ type: "snapshot" });
        try {
            await this.withEngine(controller, async (ctx) => {
                // project はまだ存在しない。存在しない project の状態を報告しないよう
                // 環境スコープに限定し、基準は project を作る場所（ホーム配下）にする
                await doctor(ctx, {
                    scope: "environment",
                    baseDir: dirname(this.options.defaultLocation),
                });
            });
            this.environmentChecked = true;
        }
        catch (error) {
            // doctor 自体が落ちた場合も画面は ENVIRONMENT に留め、再チェックできるようにする
            this.environmentChecked = true;
            this.recordFailure(error);
            this.phase = "ENVIRONMENT";
        }
        finally {
            this.controller = null;
            this.push({ type: "snapshot" });
        }
    }
    /** ENVIRONMENT → PROJECT */
    enterProject() {
        this.phase = "PROJECT";
        this.failure = undefined;
        this.push({ type: "snapshot" });
    }
    async start(input) {
        if (this.controller)
            return;
        this.resetStages();
        this.failure = undefined;
        this.ready = undefined;
        this.logs = [];
        this.phase = "SETTING_UP";
        const now = new Date().toISOString();
        this.state = {
            version: 1,
            projectPath: input.projectPath,
            projectName: input.projectName,
            siteName: input.siteName,
            stage: "project_selected",
            createdAt: this.state?.createdAt ?? now,
            updatedAt: now,
        };
        this.persist();
        this.activeProjectPath = input.projectPath;
        await this.execute(async (ctx) => {
            const code = await create(input.projectPath, ctx);
            if (code !== 0) {
                throw new EngineError({
                    code: "create.failed",
                    what: "プロジェクトの作成に失敗しました",
                    why: "テンプレートの展開に失敗しました",
                    fix: "別の名前・保存場所でもう一度お試しください",
                    title: "プロジェクトを作成できませんでした",
                    message: "別の名前か保存場所を選んで、もう一度お試しください。",
                    recovery: [{ kind: "retry", label: "もう一度試す" }],
                });
            }
            applySiteName(input.projectPath, input.siteName);
            this.advance("created");
            await this.runDevWithSelfHeal(ctx, input.projectPath);
            await this.applySiteSettings(ctx, input.projectPath, input.siteName);
            await this.runFinalCheck(ctx, input.projectPath);
        });
    }
    /** 前回の続きから再開（作成済みプロジェクトに対して dev をやり直す） */
    async resume() {
        if (this.controller || !this.state)
            return;
        this.resetStages();
        this.stageStatus.set("project", "done");
        this.failure = undefined;
        this.ready = undefined;
        this.logs = [];
        this.phase = "SETTING_UP";
        const projectPath = this.state.projectPath;
        const siteName = this.state.siteName;
        this.activeProjectPath = projectPath;
        await this.execute(async (ctx) => {
            await this.runDevWithSelfHeal(ctx, projectPath);
            await this.applySiteSettings(ctx, projectPath, siteName);
            await this.runFinalCheck(ctx, projectPath);
        });
    }
    /** 進行中の処理を中止する（作成済みプロジェクトは削除しない） */
    cancel() {
        if (!this.controller)
            return;
        this.canceled = true;
        this.controller.abort();
    }
    async execute(body) {
        const controller = new AbortController();
        this.controller = controller;
        this.canceled = false;
        this.push({ type: "snapshot" });
        try {
            await this.withEngine(controller, body);
            this.advance("ready");
            this.phase = "READY";
        }
        catch (error) {
            // 中止時は docker 側に取り残しが出ないよう後片付けしてから結果を確定させる
            if (this.canceled)
                await this.cleanupAfterCancel();
            this.recordFailure(error);
            this.phase = "ERROR";
        }
        finally {
            this.controller = null;
            this.push({ type: "snapshot" });
        }
    }
    /**
     * F-A1: プラットフォーム不一致を検出したときだけ依存を作り直して 1 回だけ再試行する。
     * 原因の技術用語は Primary UI には出さない（詳細ログにのみ残る）。
     */
    async runDevWithSelfHeal(ctx, projectPath) {
        try {
            await dev({ ...ctx, root: projectPath });
        }
        catch (error) {
            if (!(error instanceof EngineError))
                throw error;
            if (!isNativeBindingMismatch(this.logs.join("\n")))
                throw error;
            this.push({
                type: "message",
                level: "info",
                text: "準備をやり直しています…",
            });
            const healed = await rebuildDependencies(projectPath, {
                signal: ctx.signal,
                onStdout: (line) => this.appendLog(line),
                onStderr: (line) => this.appendLog(line),
            });
            if (!healed.healed) {
                if (healed.reason)
                    this.appendLog(`self-heal: ${healed.reason}`);
                throw error;
            }
            this.resetStages();
            this.stageStatus.set("project", "done");
            await dev({ ...ctx, root: projectPath });
        }
    }
    /**
     * D8: 最終確認（既存の品質ゲート engine をそのまま利用）。
     *
     * 利用者は `agentcms verify` を手で打たない。READY にする前に同じ検証を通し、
     * 落ちたら **READY にしない**。Primary UI には gate 名（Pint / PHPStan 等）を出さず、
     * 「サイトを確認しています」だけを見せる（詳細は「詳細を表示」側）。
     */
    async runFinalCheck(ctx, projectPath) {
        throwIfAborted(ctx);
        this.stageStatus.set("check", "running");
        this.push({ type: "snapshot" });
        // verify が出す warn（＝ audit の脆弱性）を拾って完了画面に渡す
        const auditWarnings = [];
        const code = await verify({
            ...ctx,
            root: projectPath,
            onEvent: (event) => {
                if (event.type === "message" && event.level === "warn") {
                    auditWarnings.push(event.text);
                }
                ctx.onEvent?.(event);
            },
        }, { auditWarnOnly: true });
        if (code !== 0) {
            failWith({
                code: "verify.failed",
                what: "最終確認に失敗しました",
                why: "品質ゲートのいずれかが失敗しました（詳細ログ参照）",
                fix: "もう一度実行してください。解決しない場合は詳細ログのエラーを確認してください",
                title: "サイトの確認が完了しませんでした",
                message: "もう一度お試しください。作成済みのファイルはそのまま残っています。",
                recovery: [
                    { kind: "retry", label: "もう一度試す" },
                    { kind: "showDetails", label: "詳細を見る" },
                ],
            });
        }
        this.ready = {
            ...(this.ready ?? { projectPath }),
            auditWarnings,
        };
        this.stageStatus.set("check", "done");
        this.push({ type: "snapshot" });
    }
    /**
     * F-B3: 中止後の後片付け。
     *
     * 子プロセス（docker CLI）を kill しても、compose が作った container は残り得る。
     * そこで **project scope の compose down** を実行して、起動中の container と
     * `compose run` の一時 container をまとめて終了・削除する。
     *
     * `-v` は付けない — データベースの中身（named volume）は利用者の資産であり、
     * 中止を理由に破棄しない。project のファイルも削除しない。
     */
    async cleanupAfterCancel() {
        const root = this.activeProjectPath;
        if (!root || !existsSync(join(root, "docker-compose.yml")))
            return;
        this.appendLog("cancel: コンテナを停止します（データは削除しません）");
        // 中止処理そのものは中断できるべきでないため signal を渡さない
        await runInheritCapture("docker", [...CANCEL_CLEANUP_ARGS], root, {
            onStdout: (l) => this.appendLog(l),
            onStderr: (l) => this.appendLog(l),
        });
    }
    /**
     * O-10: 入力されたサイト名を、生成した project の site settings へ反映する。
     *
     * DB を直接書き換えず、管理画面と同じ経路
     * （content/schemas/site.json → SchemaRules → SettingsStore）を artisan 経由で使う。
     * seed 直後に上書きするため dev の完了後に実行する。
     * ここが失敗したら **READY にしない**（成功したように見せない）。
     */
    async applySiteSettings(ctx, projectPath, siteName) {
        throwIfAborted(ctx);
        this.stageStatus.set("site", "running");
        this.push({ type: "snapshot" });
        const result = await runInheritCapture("docker", siteSettingArgs(projectPath, "site_name", siteName), projectPath, {
            signal: ctx.signal,
            onStdout: (l) => this.appendLog(l),
            onStderr: (l) => this.appendLog(l),
        });
        if (result.code !== 0) {
            failWith({
                code: "site.settings_failed",
                what: "サイト情報の設定に失敗しました",
                why: "生成したプロジェクトへ site_name を反映できませんでした（上のログ参照）",
                fix: "もう一度実行してください。解決しない場合は管理画面の「サイト設定」から直接変更できます",
                title: "サイト情報の設定を完了できませんでした",
                message: "もう一度お試しください。作成済みのファイルはそのまま残っています。",
                recovery: [
                    { kind: "retry", label: "もう一度試す" },
                    { kind: "showDetails", label: "詳細を見る" },
                ],
            });
        }
        this.stageStatus.set("site", "done");
        this.push({ type: "snapshot" });
    }
    /** engine 呼び出しを wizard の event stream へ接続する */
    async withEngine(controller, body) {
        const ctx = {
            signal: controller.signal,
            onEvent: (event) => this.onEngineEvent(event),
        };
        // CLI 表示（console）を wizard 側へ迂回させる。
        // CLI のメッセージは診断用語（docker compose / composer 等）を含むため、
        // Primary UI には出さず詳細ログにのみ流す。進捗は stage 表示が担う。
        setUiSink((event) => {
            if (event.kind === "fail") {
                this.appendLog(`${event.failure.what}: ${event.failure.why}`);
                return;
            }
            if (event.kind === "heading")
                return;
            this.appendLog(event.text);
        });
        try {
            await body(ctx);
        }
        finally {
            setUiSink(null);
        }
    }
    onEngineEvent(event) {
        switch (event.type) {
            case "step": {
                const mapped = STEP_STAGE[event.id];
                if (mapped) {
                    this.stageStatus.set(mapped.stage, mapped.done ? "done" : "running");
                    this.push({ type: "snapshot" });
                }
                break;
            }
            case "check": {
                this.checks = [
                    ...this.checks.filter((c) => c.id !== event.id),
                    {
                        id: event.id,
                        name: event.name,
                        status: event.status,
                        title: CHECK_TITLES[event.id] ?? event.title ?? event.name,
                        message: event.message,
                        cause: event.cause,
                        detail: event.detail,
                        visible: event.id in CHECK_TITLES,
                    },
                ];
                this.push({ type: "snapshot" });
                break;
            }
            case "log":
                this.appendLog(event.line);
                break;
            case "message":
                this.appendLog(event.text);
                break;
            case "result":
                this.ready = {
                    ...(this.ready ?? { projectPath: this.state?.projectPath ?? "" }),
                    projectPath: this.state?.projectPath ?? "",
                    agents: AGENT_CLIENTS.map((c) => ({
                        id: c.id,
                        label: c.label,
                        file: c.file,
                    })),
                    [event.key]: event.value,
                };
                break;
            case "failure":
                this.appendLog(`${event.failure.what}: ${event.failure.why}`);
                break;
        }
    }
    appendLog(line) {
        const safe = sanitizeLogLine(line);
        this.logs = [...this.logs, safe].slice(-MAX_LOG_LINES);
        this.push({ type: "log", line: safe });
    }
    recordFailure(error) {
        if (error instanceof CommandAbortedError || this.canceled) {
            const resumable = this.snapshot().canResume;
            this.failure = {
                code: "wizard.canceled",
                title: "セットアップを中止しました。",
                message: resumable
                    ? "作成済みのファイルは残っています。あとで続きから再開できます。"
                    : "作成済みのファイルは残っています。もう一度やり直せます。",
                recovery: [
                    {
                        kind: "retry",
                        label: resumable ? "続きから再開する" : "もう一度試す",
                    },
                ],
            };
            return;
        }
        const failure = error instanceof EngineError ? error.failure : undefined;
        if (failure) {
            this.failure = toFailureView(failure);
            return;
        }
        this.appendLog(String(error));
        this.failure = {
            code: "wizard.unexpected",
            title: "予期しない問題が発生しました",
            message: "もう一度お試しください。解決しない場合は詳細をご確認ください。",
            recovery: [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
        };
    }
    advance(stage) {
        if (!this.state)
            return;
        this.state = {
            ...this.state,
            stage: advanceStage(this.state.stage, stage),
            updatedAt: new Date().toISOString(),
        };
        this.persist();
    }
    persist() {
        if (this.state)
            writeSetupState(this.state, this.options.home);
    }
    resetStages() {
        this.stageStatus = new Map();
    }
    push(event) {
        for (const listener of this.listeners)
            listener(event);
    }
}
export function toFailureView(failure) {
    return {
        code: failure.code,
        title: failure.title ?? failure.what,
        message: failure.message ?? failure.why,
        recovery: failure.recovery && failure.recovery.length > 0
            ? failure.recovery.map((r) => ({
                kind: r.kind,
                label: r.label,
                target: r.target,
            }))
            : [
                { kind: "retry", label: "もう一度試す" },
                { kind: "showDetails", label: "詳細を見る" },
            ],
    };
}
/** サイト名を .env（APP_NAME）へ反映する */
export function applySiteName(projectPath, siteName) {
    const envPath = join(projectPath, ".env");
    const env = readFileSync(envPath, "utf8");
    const quoted = `"${siteName.replace(/(["\\$`])/g, "\\$1")}"`;
    writeFileSync(envPath, setEnvValue(env, "APP_NAME", quoted), "utf8");
}

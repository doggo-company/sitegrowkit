/**
 * dev の高レベルステップ順（GUI の進捗 UI と regression test の基準）。
 * 実行時に skip される step はあるが、**出現する step はこの順序を崩さない**。
 */
export const DEV_STEP_ORDER = [
    "dev.started",
    "dev.docker.started",
    "dev.docker.completed",
    "dev.dependencies.started",
    "dev.dependencies.completed",
    "dev.database.started",
    "dev.database.completed",
    "dev.build.started",
    "dev.build.completed",
    "dev.health.started",
    "dev.ready",
];
/** create の高レベルステップ順 */
export const CREATE_STEP_ORDER = [
    "create.started",
    "create.files.completed",
    "create.identity.completed",
    "create.context.completed",
    "create.completed",
];
/**
 * engine 内部の失敗。process.exit の代わりに throw する。
 * CLI entrypoint がこれを捕捉して what/why/fix を表示し、終了コードへ変換する。
 */
export class EngineError extends Error {
    failure;
    constructor(failure) {
        super(failure.what);
        this.name = "EngineError";
        this.failure = failure;
    }
}
/** engine 層の失敗（process.exit しない）。CLI/GUI いずれからも安全に呼べる */
export function failWith(failure) {
    throw new EngineError(failure);
}
export function emit(ctx, event) {
    ctx?.onEvent?.(event);
}
export function step(ctx, id, label) {
    emit(ctx, { type: "step", id, label });
}
/** 中断済みなら EngineError で止める（長時間処理の各区切りで呼ぶ） */
export function throwIfAborted(ctx) {
    if (ctx?.signal?.aborted) {
        failWith({
            code: "engine.aborted",
            what: "処理を中止しました",
            why: "呼び出し側から中断が要求されました",
            fix: "もう一度実行してください（作成済みのプロジェクトはそのまま再開できます）",
            title: "中止しました",
            message: "処理を中止しました。もう一度実行できます。",
            recovery: [{ kind: "retry", label: "もう一度試す" }],
        });
    }
}

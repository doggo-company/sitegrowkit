import type { Failure } from "./ui.js";

/**
 * Engine 層の共通型（Phase 8.5-A）。
 *
 * CLI と GUI Setup Wizard の両方から同じ engine を呼べるようにするための土台:
 * - engine の関数は process.exit しない（EngineError を throw し、呼び出し側が終了コードへ変換する）
 * - 進捗は stdout parsing ではなく **明示的な EngineEvent** を source of truth とする
 * - project の場所は cwd 依存ではなく EngineContext.root で明示できる
 */

/** 高レベル進捗ステップ（GUI のチェックリスト表示に 1:1 対応する） */
export type StepId =
  | "doctor.started"
  | "doctor.check.completed"
  | "doctor.completed"
  | "create.started"
  | "create.files.completed"
  | "create.identity.completed"
  | "create.context.completed"
  | "create.completed"
  | "dev.started"
  | "dev.docker.started"
  | "dev.docker.completed"
  | "dev.dependencies.started"
  | "dev.dependencies.completed"
  | "dev.database.started"
  | "dev.database.completed"
  | "dev.build.started"
  | "dev.build.completed"
  | "dev.health.started"
  | "dev.ready"
  | "verify.started"
  | "verify.completed";

/**
 * dev の高レベルステップ順（GUI の進捗 UI と regression test の基準）。
 * 実行時に skip される step はあるが、**出現する step はこの順序を崩さない**。
 */
export const DEV_STEP_ORDER: readonly StepId[] = [
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
] as const;

/** create の高レベルステップ順 */
export const CREATE_STEP_ORDER: readonly StepId[] = [
  "create.started",
  "create.files.completed",
  "create.identity.completed",
  "create.context.completed",
  "create.completed",
] as const;

export type EngineEvent =
  | { type: "step"; id: StepId; label?: string }
  /** 人間向けの一行メッセージ（CLI の info/ok/warn と同じ粒度） */
  | { type: "message"; level: "info" | "ok" | "warn"; text: string }
  /** 子プロセスの生ログ（GUI では「詳細を表示」でのみ見せる） */
  | { type: "log"; stream: "stdout" | "stderr"; line: string }
  /** doctor の個別チェック結果（GUI は表示名ではなく id で分岐する） */
  | {
      type: "check";
      id: string;
      name: string;
      status: "pass" | "warn" | "fail" | "not-required";
      detail?: string;
      cause?: string;
      fix?: string;
      /** GUI 向けの平易なタイトル / 本文 */
      title?: string;
      message?: string;
    }
  /** 成果物（URL・認証情報の場所など。値そのものは含めない） */
  | { type: "result"; key: string; value: string }
  | { type: "failure"; failure: Failure };

export interface EngineContext {
  /** project root を明示する（未指定なら cwd から探索する CLI 既定動作） */
  root?: string;
  /** 中断（GUI の Cancel / ウィンドウを閉じた場合） */
  signal?: AbortSignal;
  /** 進捗イベントの受け口（GUI 用。CLI は未指定） */
  onEvent?: (event: EngineEvent) => void;
}

/**
 * engine 内部の失敗。process.exit の代わりに throw する。
 * CLI entrypoint がこれを捕捉して what/why/fix を表示し、終了コードへ変換する。
 */
export class EngineError extends Error {
  readonly failure: Failure;

  constructor(failure: Failure) {
    super(failure.what);
    this.name = "EngineError";
    this.failure = failure;
  }
}

/** engine 層の失敗（process.exit しない）。CLI/GUI いずれからも安全に呼べる */
export function failWith(failure: Failure): never {
  throw new EngineError(failure);
}

export function emit(ctx: EngineContext | undefined, event: EngineEvent): void {
  ctx?.onEvent?.(event);
}

export function step(
  ctx: EngineContext | undefined,
  id: StepId,
  label?: string,
): void {
  emit(ctx, { type: "step", id, label });
}

/** 中断済みなら EngineError で止める（長時間処理の各区切りで呼ぶ） */
export function throwIfAborted(ctx: EngineContext | undefined): void {
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

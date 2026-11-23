import pc from "picocolors";

/** GUI 向けの復旧アクション（CLI では表示しない） */
export interface RecoveryAction {
  kind: "retry" | "openUrl" | "openPath" | "showDetails" | "recheck";
  label: string;
  /** kind=openUrl / openPath のときの対象 */
  target?: string;
}

export interface Failure {
  what: string; // 何をしようとして失敗したか
  why: string; // なぜ動かないか（検出した原因）
  fix: string; // どう修正するか（具体的なコマンド・手順）
  /** machine-readable な識別子（GUI の分岐・計測用。CLI 表示には使わない） */
  code?: string;
  /** GUI 向けの平易なタイトル（未指定なら what を使う） */
  title?: string;
  /** GUI 向けの平易な本文（未指定なら why を使う。専門用語を避ける） */
  message?: string;
  /** GUI 向けの復旧アクション（fix をコマンド文字列のみに依存させない） */
  recovery?: RecoveryAction[];
}

/**
 * 表示の出口（Phase 8.5-A）。
 * CLI は console へ、GUI Setup Wizard は sink 経由で structured event を受け取る。
 * engine のコードは info/ok/warn/heading/fail だけを使い、出力先を意識しない。
 */
export type UiEvent =
  | { kind: "info" | "ok" | "warn" | "heading"; text: string }
  | { kind: "fail"; failure: Failure };

type UiSink = (event: UiEvent) => void;

let sink: UiSink | null = null;

/** GUI から表示を受け取る（null で CLI 既定の console 出力へ戻す） */
export function setUiSink(next: UiSink | null): void {
  sink = next;
}

export function info(msg: string): void {
  if (sink) return sink({ kind: "info", text: msg });
  console.log(`${pc.cyan("•")} ${msg}`);
}

export function ok(msg: string): void {
  if (sink) return sink({ kind: "ok", text: msg });
  console.log(`${pc.green("✔")} ${msg}`);
}

/**
 * 装飾なしの 1 行。CLI はそのまま console へ、GUI は詳細ログへ流す。
 * 「診断は出したが GUI の詳細に残らない」状態を作らないための出口
 * （support / debug で doctor の全判定を追えるようにする — Phase 8.5-C F-B2）。
 */
export function line(msg: string): void {
  if (sink) return sink({ kind: "info", text: msg });
  console.log(msg);
}

export function warn(msg: string): void {
  if (sink) return sink({ kind: "warn", text: msg });
  console.log(`${pc.yellow("▲")} ${msg}`);
}

/** 「失敗しました」だけで終わらせない構造化エラー表示 */
export function fail(f: Failure): void {
  if (sink) return sink({ kind: "fail", failure: f });
  console.error("");
  console.error(`${pc.red("✖")} ${pc.bold(f.what)}`);
  console.error(`  ${pc.dim("原因:")} ${f.why}`);
  console.error(`  ${pc.dim("修正:")} ${f.fix}`);
}

/**
 * CLI entrypoint 専用の即時終了。
 * **engine 層では使わない**（GUI からライブラリとして呼ばれるとプロセスごと落ちるため）。
 * engine 内部の失敗は engine.ts の failWith（EngineError throw）を使う。
 */
export function failAndExit(f: Failure): never {
  fail(f);
  process.exit(1);
}

export function heading(msg: string): void {
  if (sink) return sink({ kind: "heading", text: msg });
  console.log(`\n${pc.bold(msg)}`);
}

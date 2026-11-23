import pc from "picocolors";
let sink = null;
/** GUI から表示を受け取る（null で CLI 既定の console 出力へ戻す） */
export function setUiSink(next) {
    sink = next;
}
export function info(msg) {
    if (sink)
        return sink({ kind: "info", text: msg });
    console.log(`${pc.cyan("•")} ${msg}`);
}
export function ok(msg) {
    if (sink)
        return sink({ kind: "ok", text: msg });
    console.log(`${pc.green("✔")} ${msg}`);
}
/**
 * 装飾なしの 1 行。CLI はそのまま console へ、GUI は詳細ログへ流す。
 * 「診断は出したが GUI の詳細に残らない」状態を作らないための出口
 * （support / debug で doctor の全判定を追えるようにする — Phase 8.5-C F-B2）。
 */
export function line(msg) {
    if (sink)
        return sink({ kind: "info", text: msg });
    console.log(msg);
}
export function warn(msg) {
    if (sink)
        return sink({ kind: "warn", text: msg });
    console.log(`${pc.yellow("▲")} ${msg}`);
}
/** 「失敗しました」だけで終わらせない構造化エラー表示 */
export function fail(f) {
    if (sink)
        return sink({ kind: "fail", failure: f });
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
export function failAndExit(f) {
    fail(f);
    process.exit(1);
}
export function heading(msg) {
    if (sink)
        return sink({ kind: "heading", text: msg });
    console.log(`\n${pc.bold(msg)}`);
}

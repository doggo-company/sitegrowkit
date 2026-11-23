import { spawn, spawnSync } from "node:child_process";
/** シグナル名 → shell の慣例（`128 + signum`）。SIGKILL = 137 / SIGSEGV = 139 */
const SIGNAL_EXIT_CODES = {
    SIGHUP: 129,
    SIGINT: 130,
    SIGQUIT: 131,
    SIGKILL: 137,
    SIGSEGV: 139,
    SIGTERM: 143,
};
/** 中断によりコマンドが終了したことを表す */
export class CommandAbortedError extends Error {
    command;
    constructor(command) {
        super(`コマンドを中止しました: ${command}`);
        this.command = command;
        this.name = "CommandAbortedError";
    }
}
/**
 * 子プロセス実行の単一の入口（Phase 8.5-A）。
 *
 * 設計上の固定事項:
 * - **shell は使わない**（`shell: false`）。実行ファイル + 引数配列のみを受け取り、
 *   ユーザー入力を shell 文字列へ連結しない（command injection の遮断）。
 * - 出力は常に capture し、`echo` で CLI 表示、`onStdout/onStderr` で GUI へ行単位配信する。
 * - `signal` で中断でき、SIGTERM → 猶予後 SIGKILL で子プロセスを確実に終了する。
 */
export function run(cmd, args, opts = {}) {
    const { cwd, env, echo = false, onStdout, onStderr, signal, killGraceMs = 5000, input, } = opts;
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(new CommandAbortedError(cmd));
            return;
        }
        const child = spawn(cmd, args, {
            cwd,
            stdio: [input === undefined ? "ignore" : "pipe", "pipe", "pipe"],
            shell: false,
            env: env ? { ...process.env, ...env } : undefined,
        });
        if (input !== undefined) {
            child.stdin?.on("error", () => {
                /* 子が stdin を読まずに終了した場合の EPIPE は握りつぶす */
            });
            child.stdin?.end(input);
        }
        let stdout = "";
        let stderr = "";
        let aborted = false;
        let killTimer = null;
        const onAbort = () => {
            aborted = true;
            child.kill("SIGTERM");
            killTimer = setTimeout(() => child.kill("SIGKILL"), killGraceMs);
            killTimer.unref?.();
        };
        signal?.addEventListener("abort", onAbort, { once: true });
        const makeLineReader = (onLine) => () => {
            let buffer = "";
            return (chunk) => {
                if (!onLine)
                    return;
                buffer += chunk;
                let index = buffer.indexOf("\n");
                while (index >= 0) {
                    onLine(buffer.slice(0, index).replace(/\r$/, ""));
                    buffer = buffer.slice(index + 1);
                    index = buffer.indexOf("\n");
                }
            };
        };
        const pushOut = makeLineReader(onStdout)();
        const pushErr = makeLineReader(onStderr)();
        child.stdout?.on("data", (d) => {
            const text = d.toString();
            stdout += text;
            if (echo)
                process.stdout.write(d);
            pushOut(text);
        });
        child.stderr?.on("data", (d) => {
            const text = d.toString();
            stderr += text;
            if (echo)
                process.stderr.write(d);
            pushErr(text);
        });
        const cleanup = () => {
            signal?.removeEventListener("abort", onAbort);
            if (killTimer)
                clearTimeout(killTimer);
        };
        child.on("error", (err) => {
            cleanup();
            reject(err);
        });
        child.on("close", (code, killedBy) => {
            cleanup();
            if (aborted) {
                reject(new CommandAbortedError(cmd));
                return;
            }
            /*
             * **殺されたことを 1 に潰さない**（B-3）。
             *
             * signal で死ぬと Node は `code === null` を渡す。ここを 1 にすると
             * **OOM killer も segfault も普通の失敗と同じ顔**になり、出力も 0 バイトなので
             * 「テストが落ちた」のか「殺された」のかが誰にも分からなくなる。
             * shell の慣例（`128 + signum`）へ寄せ、名前も持たせる。
             */
            resolve({
                code: code ?? (killedBy ? (SIGNAL_EXIT_CODES[killedBy] ?? 128) : 1),
                stdout: stdout.trim(),
                stderr: stderr.trim(),
                signal: killedBy ?? null,
            });
        });
    });
}
/** 出力をそのままユーザーへ流すコマンド実行（dev / test 等） */
export async function runInherit(cmd, args, cwd, env, extra = {}) {
    const r = await run(cmd, args, { cwd, env, echo: true, ...extra });
    return r.code;
}
/**
 * 出力をユーザーへ流しつつ内容も取得する実行。
 * 失敗原因の分類（例: pnpm の build 承認不足 vs ネットワーク）に使う。
 */
export function runInheritCapture(cmd, args, cwd, extra = {}) {
    return run(cmd, args, { cwd, echo: true, ...extra });
}
/** 出力を取得するコマンド実行（doctor の検査等・同期） */
export function runCapture(cmd, args, cwd) {
    const r = spawnSync(cmd, args, {
        cwd,
        encoding: "utf8",
        shell: false,
    });
    // **こちらも殺されたことを 1 に潰さない**（B-3・`run()` と同じ扱い）
    return {
        code: r.status ?? (r.signal ? (SIGNAL_EXIT_CODES[r.signal] ?? 128) : 1),
        stdout: (r.stdout ?? "").trim(),
        stderr: (r.stderr ?? "").trim(),
        signal: r.signal ?? null,
    };
}
export function commandExists(cmd, args = ["--version"]) {
    return runCapture(cmd, args).code === 0;
}

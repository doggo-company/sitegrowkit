import { spawn } from "node:child_process";
import { homedir } from "node:os";
function expandHome(p) {
    return p.startsWith("~/") ? p.replace("~", homedir()) : p;
}
/** OpenSSH クライアント経由の実 Executor。BatchMode でパスワード入力を待たない */
export class SshExecutor {
    cfg;
    constructor(cfg) {
        this.cfg = cfg;
    }
    label() {
        const { host, port, user } = this.cfg.ssh;
        return `${user}@${host}:${port}`;
    }
    sshArgs() {
        const { port, identityFile } = this.cfg.ssh;
        const args = [
            "-p",
            String(port),
            "-o",
            "BatchMode=yes",
            "-o",
            "ConnectTimeout=10",
        ];
        if (identityFile)
            args.push("-i", expandHome(identityFile));
        return args;
    }
    destination() {
        return `${this.cfg.ssh.user}@${this.cfg.ssh.host}`;
    }
    exec(command, opts) {
        return spawnCapture("ssh", [...this.sshArgs(), this.destination(), command], opts?.timeoutMs ?? 60_000);
    }
    upload(localPath, remotePath) {
        const { port, identityFile } = this.cfg.ssh;
        const args = ["-P", String(port), "-o", "BatchMode=yes"];
        if (identityFile)
            args.push("-i", expandHome(identityFile));
        args.push(localPath, `${this.destination()}:${remotePath}`);
        // アップロードはサイズ次第で長い。10 分を上限にする
        return spawnCapture("scp", args, 600_000);
    }
}
/** 1 回のコマンドで受け取る標準出力・標準エラーそれぞれの上限（文字数） */
export const MAX_CAPTURE_CHARS = 16 * 1024 * 1024;
export function spawnCapture(cmd, args, timeoutMs) {
    return new Promise((resolve) => {
        const child = spawn(cmd, args, { stdio: ["ignore", "pipe", "pipe"] });
        let stdout = "";
        let stderr = "";
        let overflow = false;
        const timer = setTimeout(() => {
            child.kill("SIGTERM");
        }, timeoutMs);
        // 受け取る量に上限を付ける（サーバーの上のサイトが大量に出力しても、手元の CLI が
        // メモリを使い切らない）。超えたら止めて失敗にする
        const take = (cur, d) => {
            if (overflow)
                return cur;
            if (cur.length + d.length > MAX_CAPTURE_CHARS) {
                overflow = true;
                child.kill("SIGTERM");
                return cur;
            }
            return cur + d.toString();
        };
        child.stdout.on("data", (d) => (stdout = take(stdout, d)));
        child.stderr.on("data", (d) => (stderr = take(stderr, d)));
        child.on("error", (err) => {
            clearTimeout(timer);
            resolve({ code: 127, stdout, stderr: String(err) });
        });
        child.on("close", (code) => {
            clearTimeout(timer);
            resolve({
                code: overflow ? 1 : (code ?? 1),
                stdout: stdout.trim(),
                stderr: overflow
                    ? `${stderr.trim()}\n出力が ${MAX_CAPTURE_CHARS / 1024 / 1024}MB を超えたので止めました`.trim()
                    : stderr.trim(),
            });
        });
    });
}
/** シェルへ渡す 1 引数を安全に quote する（リモート側 sh 用） */
export function shq(arg) {
    return `'${arg.replaceAll("'", `'\\''`)}'`;
}

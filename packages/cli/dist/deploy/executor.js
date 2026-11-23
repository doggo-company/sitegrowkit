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
function spawnCapture(cmd, args, timeoutMs) {
    return new Promise((resolve) => {
        const child = spawn(cmd, args, { stdio: ["ignore", "pipe", "pipe"] });
        let stdout = "";
        let stderr = "";
        const timer = setTimeout(() => {
            child.kill("SIGTERM");
        }, timeoutMs);
        child.stdout.on("data", (d) => (stdout += d.toString()));
        child.stderr.on("data", (d) => (stderr += d.toString()));
        child.on("error", (err) => {
            clearTimeout(timer);
            resolve({ code: 127, stdout, stderr: String(err) });
        });
        child.on("close", (code) => {
            clearTimeout(timer);
            resolve({
                code: code ?? 1,
                stdout: stdout.trim(),
                stderr: stderr.trim(),
            });
        });
    });
}
/** シェルへ渡す 1 引数を安全に quote する（リモート側 sh 用） */
export function shq(arg) {
    return `'${arg.replaceAll("'", `'\\''`)}'`;
}

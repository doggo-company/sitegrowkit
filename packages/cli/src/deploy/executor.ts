import { spawn } from "node:child_process";
import { homedir } from "node:os";

import type { DeployTargetConfig } from "./types.js";

export interface ExecResult {
  code: number;
  stdout: string;
  stderr: string;
}

/**
 * リモート実行の境界。実サーバーは SshExecutor、テストは FakeExecutor が実装する。
 * Provider Adapter はこの境界越しにのみサーバーへ触れる（直接 spawn 禁止）。
 */
export interface RemoteExecutor {
  /** リモートでコマンドを実行（非対話・タイムアウトあり） */
  exec(command: string, opts?: { timeoutMs?: number }): Promise<ExecResult>;
  /** ローカルファイルをリモートパスへ転送 */
  upload(localPath: string, remotePath: string): Promise<ExecResult>;
  /** 接続先の表示用ラベル（ログ・plan 表示用。秘密情報を含めない） */
  label(): string;
}

function expandHome(p: string): string {
  return p.startsWith("~/") ? p.replace("~", homedir()) : p;
}

/** OpenSSH クライアント経由の実 Executor。BatchMode でパスワード入力を待たない */
export class SshExecutor implements RemoteExecutor {
  constructor(private readonly cfg: DeployTargetConfig) {}

  label(): string {
    const { host, port, user } = this.cfg.ssh;
    return `${user}@${host}:${port}`;
  }

  private sshArgs(): string[] {
    const { port, identityFile } = this.cfg.ssh;
    const args = [
      "-p",
      String(port),
      "-o",
      "BatchMode=yes",
      "-o",
      "ConnectTimeout=10",
    ];
    if (identityFile) args.push("-i", expandHome(identityFile));
    return args;
  }

  private destination(): string {
    return `${this.cfg.ssh.user}@${this.cfg.ssh.host}`;
  }

  exec(command: string, opts?: { timeoutMs?: number }): Promise<ExecResult> {
    return spawnCapture(
      "ssh",
      [...this.sshArgs(), this.destination(), command],
      opts?.timeoutMs ?? 60_000,
    );
  }

  upload(localPath: string, remotePath: string): Promise<ExecResult> {
    const { port, identityFile } = this.cfg.ssh;
    const args = ["-P", String(port), "-o", "BatchMode=yes"];
    if (identityFile) args.push("-i", expandHome(identityFile));
    args.push(localPath, `${this.destination()}:${remotePath}`);
    // アップロードはサイズ次第で長い。10 分を上限にする
    return spawnCapture("scp", args, 600_000);
  }
}

/** 1 回のコマンドで受け取る標準出力・標準エラーそれぞれの上限（文字数） */
export const MAX_CAPTURE_CHARS = 16 * 1024 * 1024;

export function spawnCapture(
  cmd: string,
  args: string[],
  timeoutMs: number,
): Promise<ExecResult> {
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
    const take = (cur: string, d: Buffer): string => {
      if (overflow) return cur;
      if (cur.length + d.length > MAX_CAPTURE_CHARS) {
        overflow = true;
        child.kill("SIGTERM");
        return cur;
      }
      return cur + d.toString();
    };
    child.stdout.on("data", (d: Buffer) => (stdout = take(stdout, d)));
    child.stderr.on("data", (d: Buffer) => (stderr = take(stderr, d)));
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
export function shq(arg: string): string {
  return `'${arg.replaceAll("'", `'\\''`)}'`;
}

import { execFileSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { isWsl } from "../runtime-user.js";

/**
 * AI エージェント連携の設定ファイル生成（Phase 9.1B / STEP 13）。
 *
 * 利用者に Terminal 操作・JSON 手編集・PATH 設定・Node/PHP のインストールを
 * 求めないための土台。**同梱ランタイムの実パス**から起動コマンドを組み立てる
 * （host の `node` に依存しない）。
 *
 * 各クライアントの設定形式は公式ドキュメントで確認した形のみを出力し、
 * 未確認の形式は推測で生成しない。
 */

export type AgentClientId = "claude-code" | "codex" | "cursor";

export interface AgentClient {
  id: AgentClientId;
  /** 画面に出す名前 */
  label: string;
  /** 生成物の相対パス（プロジェクト直下から） */
  file: string;
  format: "json" | "toml";
  /** 形式の出典（推測でないことを示す） */
  source: string;
}

export const AGENT_CLIENTS: readonly AgentClient[] = [
  {
    id: "claude-code",
    label: "Claude Code",
    file: ".mcp.json",
    format: "json",
    source: "https://code.claude.com/docs/en/mcp",
  },
  {
    id: "cursor",
    label: "Cursor",
    file: ".cursor/mcp.json",
    format: "json",
    source: "https://cursor.com/docs/context/mcp",
  },
  {
    id: "codex",
    label: "Codex",
    file: ".codex/config.toml",
    format: "toml",
    source: "https://learn.chatgpt.com/docs/extend/mcp?surface=cli",
  },
] as const;

/** MCP クライアントから見たサーバー名（設定ファイル内のキー） */
export const SERVER_KEY = "agent-cms-kit";

export interface LaunchCommand {
  command: string;
  args: string[];
  /** Windows 側のクライアントから WSL 内の CMS を使う構成か */
  viaWsl: boolean;
}

/**
 * MCP server の起動コマンド。
 *
 * - 通常（macOS / Linux）: 同梱 Node（`process.execPath`）+ 同梱 CLI
 * - WSL2: Windows 側のエージェントから起動されるため `wsl.exe` を挟む
 *   （WSL 内の Node と CLI をそのまま使う）
 */
export function launchCommand(root: string): LaunchCommand {
  const entry = cliEntry();
  const node = process.execPath;
  const args = [entry, "mcp", "--root", root];

  if (!isWsl()) {
    return { command: node, args, viaWsl: false };
  }

  const distro = wslDistro();

  return {
    command: "wsl.exe",
    args: [
      ...(distro ? ["-d", distro] : []),
      "--exec",
      node,
      ...args,
    ],
    viaWsl: true,
  };
}

/** 同梱 CLI の実体（dist/index.js）。開発時も packaged 時も同じ解決になる */
export function cliEntry(): string {
  return resolve(dirname(fileURLToPath(import.meta.url)), "..", "index.js");
}

function wslDistro(): string | null {
  const fromEnv = process.env.WSL_DISTRO_NAME;

  if (fromEnv !== undefined && fromEnv !== "") return fromEnv;

  try {
    // /etc/os-release から推測しない。名前が取れないときは既定 distro に任せる
    return null;
  } catch {
    return null;
  }
}

/** 設定ファイルの中身（保存せず内容だけ返す — コピー用） */
export function renderConfig(client: AgentClient, root: string): string {
  const { command, args } = launchCommand(root);

  if (client.format === "toml") {
    // Codex: ~/.codex/config.toml または .codex/config.toml の [mcp_servers.<name>]
    const argList = args.map((a) => JSON.stringify(a)).join(", ");

    return [
      `[mcp_servers.${SERVER_KEY}]`,
      `command = ${JSON.stringify(command)}`,
      `args = [${argList}]`,
      "",
    ].join("\n");
  }

  // Claude Code / Cursor: mcpServers 形式
  const entry: Record<string, unknown> = { command, args, env: {} };

  if (client.id === "claude-code") {
    // Claude Code は type 無しを stdio として読むが、公式例に合わせて明示する
    entry["type"] = "stdio";
  }

  return `${JSON.stringify({ mcpServers: { [SERVER_KEY]: orderKeys(entry) } }, null, 2)}\n`;
}

/** 設定ファイルを書き出す。戻り値は書き込んだ絶対パス */
export function writeConfig(client: AgentClient, root: string): string {
  const target = join(root, client.file);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, renderConfig(client, root), "utf8");

  return target;
}

function orderKeys(entry: Record<string, unknown>): Record<string, unknown> {
  const ordered: Record<string, unknown> = {};
  for (const key of ["type", "command", "args", "env"]) {
    if (key in entry) ordered[key] = entry[key];
  }

  return ordered;
}

/** 生成物が実際に起動できるかの軽い確認（存在チェックのみ。server は起動しない） */
export function launchCommandIsResolvable(): boolean {
  try {
    execFileSync(process.execPath, ["--version"], { stdio: "ignore" });

    return true;
  } catch {
    return false;
  }
}

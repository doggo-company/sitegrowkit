import { execFileSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { isWsl } from "../runtime-user.js";
export const AGENT_CLIENTS = [
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
];
/** MCP クライアントから見たサーバー名（設定ファイル内のキー） */
export const SERVER_KEY = "agent-cms-kit";
/**
 * MCP server の起動コマンド。
 *
 * - 通常（macOS / Linux）: 同梱 Node（`process.execPath`）+ 同梱 CLI
 * - WSL2: Windows 側のエージェントから起動されるため `wsl.exe` を挟む
 *   （WSL 内の Node と CLI をそのまま使う）
 */
export function launchCommand(root) {
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
export function cliEntry() {
    return resolve(dirname(fileURLToPath(import.meta.url)), "..", "index.js");
}
function wslDistro() {
    const fromEnv = process.env.WSL_DISTRO_NAME;
    if (fromEnv !== undefined && fromEnv !== "")
        return fromEnv;
    try {
        // /etc/os-release から推測しない。名前が取れないときは既定 distro に任せる
        return null;
    }
    catch {
        return null;
    }
}
/** 設定ファイルの中身（保存せず内容だけ返す — コピー用） */
export function renderConfig(client, root) {
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
    const entry = { command, args, env: {} };
    if (client.id === "claude-code") {
        // Claude Code は type 無しを stdio として読むが、公式例に合わせて明示する
        entry["type"] = "stdio";
    }
    return `${JSON.stringify({ mcpServers: { [SERVER_KEY]: orderKeys(entry) } }, null, 2)}\n`;
}
/** 設定ファイルを書き出す。戻り値は書き込んだ絶対パス */
export function writeConfig(client, root) {
    const target = join(root, client.file);
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, renderConfig(client, root), "utf8");
    return target;
}
function orderKeys(entry) {
    const ordered = {};
    for (const key of ["type", "command", "args", "env"]) {
        if (key in entry)
            ordered[key] = entry[key];
    }
    return ordered;
}
/** 生成物が実際に起動できるかの軽い確認（存在チェックのみ。server は起動しない） */
export function launchCommandIsResolvable() {
    try {
        execFileSync(process.execPath, ["--version"], { stdio: "ignore" });
        return true;
    }
    catch {
        return false;
    }
}

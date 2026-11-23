import { serveStdio } from "@modelcontextprotocol/server/stdio";

import { findProjectRoot, isProjectRoot } from "../project.js";
import { createServer, diagnostic } from "../mcp/server.js";

/**
 * `agentcms mcp` — AI エージェント向けの MCP server（stdio）。
 *
 * 固定事項（Phase 9.1B / STEP 11・14）:
 * - transport は **stdio のみ**。HTTP MCP は実装しない
 * - **stdout は MCP プロトコル専用**。ログ・診断はすべて stderr
 * - 実行できるのは Application Service の allowlist に載った操作だけ
 */
export function mcp(rootOption?: string): Promise<number> {
  const root = resolveRoot(rootOption);

  if (root === null) {
    diagnostic(
      "CMS プロジェクトが見つかりません。SiteGrowKit で作成したフォルダを指定してください。",
    );

    return Promise.resolve(1);
  }

  diagnostic(`project: ${root}`);

  const handle = serveStdio(
    (ctx) => createServer({ root, era: ctx.era, onDiagnostic: diagnostic }),
    { onerror: (error) => diagnostic(`transport error: ${error.message}`) },
  );

  return new Promise<number>((resolve) => {
    const shutdown = (): void => {
      void handle.close().finally(() => resolve(0));
    };

    process.once("SIGINT", shutdown);
    process.once("SIGTERM", shutdown);
    process.stdin.once("close", shutdown);
  });
}

function resolveRoot(rootOption?: string): string | null {
  if (rootOption !== undefined && rootOption !== "") {
    return isProjectRoot(rootOption) ? rootOption : null;
  }

  return findProjectRoot();
}

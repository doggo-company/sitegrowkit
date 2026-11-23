import {
  McpServer,
  inputRequired,
  inputResponse,
} from "@modelcontextprotocol/server";
import type { McpRequestContext, ServerContext } from "@modelcontextprotocol/server";

import { kitVersion } from "../version.js";
import { invokeOperation, type BridgeOptions, type OperationResult } from "./bridge.js";
import { DESTRUCTIVE_TOOLS, TOOLS, TOOL_OPERATIONS, type ToolName } from "./tools.js";

/**
 * SiteGrowKit の MCP server（Phase 9.1B / 9.1D）。
 *
 * 責務の分離（owner DECISION 1）:
 *   protocol / transport … 公式 SDK（@modelcontextprotocol/server）
 *   CMS の業務ロジック  … Application Service（PHP 側）
 *
 * このファイルは **業務ロジックを持たない**。
 * 「確認が要るかどうか」も判断しない — サーバーの ConfirmationGate が唯一の権威で、
 * ここは `confirmation_required` が返ってきたら利用者へ確認を求めるだけ。
 */

export interface McpServerOptions extends BridgeOptions {
  /** 2025 era の接続かどうか（確認フローが使えないので破壊的操作を止める） */
  era?: McpRequestContext["era"];
}

/** stdout は MCP プロトコル専用。診断は必ず stderr */
export function diagnostic(line: string): void {
  process.stderr.write(`[agentcms-mcp] ${line}\n`);
}

const CONFIRM_KEY = "confirm";

export function createServer(options: McpServerOptions): McpServer {
  const server = new McpServer(
    { name: "agent-cms-kit", version: kitVersion() },
    { capabilities: { tools: {} } },
  );

  for (const tool of TOOLS) {
    server.registerTool(
      tool.name,
      {
        title: tool.title,
        description: tool.description,
        inputSchema: tool.inputSchema,
        annotations: {
          title: tool.title,
          readOnlyHint: tool.readOnly,
          destructiveHint: DESTRUCTIVE_TOOLS.includes(tool.name),
          idempotentHint: tool.readOnly,
          openWorldHint: false,
        },
      },
      async (args: Record<string, unknown>, ctx: ServerContext) =>
        handle(tool.name, args, ctx, options),
    );
  }

  return server;
}

/**
 * すべてのツールが同じ流れを通る。
 *
 * 1 回目: 確認トークン無しで実行する
 *   → `confirmation_required` が返ったら、利用者へ確認を求めて止まる（何も変わらない）
 * 2 回目: 利用者が承認したら、その確認トークンつきで実行する
 *
 * **確認できないクライアントでは実行しない**（fail safe）。
 */
async function handle(
  name: ToolName,
  args: Record<string, unknown>,
  ctx: ServerContext,
  options: McpServerOptions,
): Promise<CallToolLike> {
  const operationId = TOOL_OPERATIONS[name];
  const answer = inputResponse(ctx.mcpReq.inputResponses, CONFIRM_KEY);

  // decline / cancel も「答えが返ってきた」状態。再度聞き直さず、確実に止める
  if (answer.kind === "elicit") {
    if (answer.action !== "accept" || answer.content?.confirm !== true) {
      return toolError("利用者が承認しなかったため、実行しませんでした。");
    }

    const token = ctx.mcpReq.requestState<string>();

    if (typeof token !== "string" || token === "") {
      return toolError(
        "確認情報が失われました。もう一度やり直してください（変更は行われていません）。",
      );
    }

    return toResult(
      await invokeOperation(
        { operationId, arguments: args, confirmationToken: token, actor: "mcp" },
        options,
      ),
    );
  }

  // page_create の空の slug は、サーバー（PHP）が管理画面と同じ読みで付ける（UX 一周 19 周目）
  const result = await invokeOperation(
    { operationId, arguments: args, actor: "mcp" },
    options,
  );

  if (result.status !== "confirmation_required" || !result.confirmation) {
    return toResult(result);
  }

  // 2025 era のクライアントは in-band 確認を扱えない → 実行しない
  if (options.era === "legacy") {
    return toolError(
      "この AI クライアントは実行前の確認に対応していないため、この操作は実行できません。管理画面から行ってください。",
    );
  }

  const { token, summary, impact } = result.confirmation;

  return inputRequired({
    inputRequests: {
      [CONFIRM_KEY]: inputRequired.elicit({
        message: [summary, ...impact.map((line) => `・${line}`), "実行しますか？"].join(
          "\n",
        ),
        requestedSchema: {
          type: "object",
          properties: {
            confirm: { type: "boolean", title: "実行する", description: summary },
          },
          required: ["confirm"],
        },
      }),
    },
    // ConfirmationGate のトークン。操作 ID・引数・実行者・期限に束縛され 1 回で失効するため、
    // クライアント経由で往復しても別の対象へは使えない
    requestState: token,
  });
}

type CallToolLike = ReturnType<typeof toResult> | ReturnType<typeof inputRequired>;

function toolError(message: string) {
  return {
    content: [{ type: "text" as const, text: message }],
    isError: true,
  };
}

/** Result → MCP の結果（PHASE9_1A_SPEC B.3 の対応表） */
function toResult(result: OperationResult) {
  if (result.status === "success") {
    return {
      content: [
        {
          type: "text" as const,
          text: JSON.stringify({ data: result.data, meta: result.meta }, null, 2),
        },
      ],
      structuredContent: { data: result.data, meta: result.meta ?? {} },
    };
  }

  if (result.status === "confirmation_required") {
    return toolError(
      "この操作には利用者の確認が必要です。管理画面から実行してください。",
    );
  }

  const details =
    result.errors && Object.keys(result.errors).length > 0
      ? "\n" +
        Object.entries(result.errors)
          .map(([field, messages]) => `- ${field}: ${messages.join(" / ")}`)
          .join("\n")
      : "";

  return toolError(`${result.message ?? "操作に失敗しました"}${details}`);
}

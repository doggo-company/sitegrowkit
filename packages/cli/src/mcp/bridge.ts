import { composeUserArgs } from "../runtime-user.js";
import { run } from "../run.js";

/**
 * MCP server → CMS の橋渡し（Phase 9.1B / STEP 11、owner DECISION 2）。
 *
 * 固定事項:
 * - **shell string を組み立てない**。`run()` は `shell:false` で実行ファイルと引数を分離する
 * - **payload は argv に載せない**。JSON は stdin へ渡す
 *   （shell escaping / OS 差 / argv 長制限 / プロセス一覧への露出 / injection の回避）
 * - 実行できるのは `agentcms:operation` **1 つだけ**。任意 Artisan コマンドは呼べない
 */

/** Application Service の Result（`Result::toArray()` と 1:1） */
export interface OperationResult {
  status:
    | "success"
    | "validation_error"
    | "not_found"
    | "forbidden"
    | "conflict"
    | "confirmation_required";
  data?: unknown;
  errors?: Record<string, string[]>;
  message?: string;
  confirmation?: {
    token: string;
    summary: string;
    impact: string[];
    expiresAt: string;
  };
  meta?: Record<string, unknown>;
}

export interface BridgeRequest {
  operationId: string;
  /** 操作固有の引数（そのまま Application Service へ渡る） */
  arguments?: Record<string, unknown>;
  /** 破壊的操作の確認トークン */
  confirmationToken?: string;
  /** 監査に残る呼び出し元の表示名 */
  actor?: string;
}

export interface BridgeOptions {
  /** CMS プロジェクトルート（artisan + docker-compose.yml がある場所） */
  root: string;
  signal?: AbortSignal;
  /** 実行の失敗内容を人間向けに記録する（stderr へ） */
  onDiagnostic?: (line: string) => void;
}

/** artisan コマンド名は固定。呼び出し側からは変更できない */
const ARTISAN_COMMAND = "agentcms:operation";

export async function invokeOperation(
  request: BridgeRequest,
  options: BridgeOptions,
): Promise<OperationResult> {
  const payload = JSON.stringify({
    arguments: request.arguments ?? {},
    confirmationToken: request.confirmationToken,
    actor: request.actor ?? "ai-agent",
  });

  const args = [
    "compose",
    "exec",
    "-T",
    ...composeUserArgs(options.root),
    "app",
    "php",
    "artisan",
    ARTISAN_COMMAND,
    request.operationId,
  ];

  const result = await run("docker", args, {
    cwd: options.root,
    signal: options.signal,
    input: payload,
  });

  if (result.stderr.trim() !== "") {
    options.onDiagnostic?.(result.stderr.trim());
  }

  const parsed = parseResult(result.stdout);

  if (parsed) return parsed;

  options.onDiagnostic?.(
    `operation ${request.operationId} の応答を解釈できませんでした (exit=${result.code})`,
  );

  return {
    status: "conflict",
    message:
      "CMS を実行できませんでした。SiteGrowKit のアプリで CMS を起動してから、もう一度お試しください。",
  };
}

/**
 * stdout から Result JSON を取り出す。
 * 前後に別の出力が混ざっても壊れないよう、**JSON として読める最後の行**を採用する。
 */
export function parseResult(stdout: string): OperationResult | null {
  const lines = stdout.split("\n").map((l) => l.trim()).filter(Boolean);

  for (let i = lines.length - 1; i >= 0; i--) {
    const line = lines[i];
    if (line === undefined || !line.startsWith("{")) continue;
    try {
      const value: unknown = JSON.parse(line);
      if (
        value &&
        typeof value === "object" &&
        typeof (value as { status?: unknown }).status === "string"
      ) {
        return value as OperationResult;
      }
    } catch {
      // 次の行を試す
    }
  }

  return null;
}

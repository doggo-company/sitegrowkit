import { composeUserArgs } from "../runtime-user.js";
import { run } from "../run.js";
/** artisan コマンド名は固定。呼び出し側からは変更できない */
const ARTISAN_COMMAND = "agentcms:operation";
export async function invokeOperation(request, options) {
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
    if (parsed)
        return parsed;
    options.onDiagnostic?.(`operation ${request.operationId} の応答を解釈できませんでした (exit=${result.code})`);
    return {
        status: "conflict",
        message: "CMS を実行できませんでした。SiteGrowKit のアプリで CMS を起動してから、もう一度お試しください。",
    };
}
/**
 * stdout から Result JSON を取り出す。
 * 前後に別の出力が混ざっても壊れないよう、**JSON として読める最後の行**を採用する。
 */
export function parseResult(stdout) {
    const lines = stdout.split("\n").map((l) => l.trim()).filter(Boolean);
    for (let i = lines.length - 1; i >= 0; i--) {
        const line = lines[i];
        if (line === undefined || !line.startsWith("{"))
            continue;
        try {
            const value = JSON.parse(line);
            if (value &&
                typeof value === "object" &&
                typeof value.status === "string") {
                return value;
            }
        }
        catch {
            // 次の行を試す
        }
    }
    return null;
}

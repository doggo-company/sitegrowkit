import type { ContentTypePlan } from "./plan-types.js";
import { parseResponse } from "./term-bridge.js";
import { composeUserArgs } from "../runtime-user.js";
import { run } from "../run.js";

/**
 * 投稿タイプの作成の橋渡し（R-13 Stage 2）。
 *
 * 分類項目（`term-bridge.ts`）と**同じ座**である。
 * - shell 文字列を組み立てない（実行ファイルと引数を分けて渡す）
 * - payload は argv に載せない。JSON は stdin へ
 * - 実行できるのは決め打ちの 1 コマンドだけ
 *
 * ## 分類項目との違いは「巻き戻さない」こと
 *
 * 型の作成は migration を伴う不可逆な構造変更なので、失敗しても
 * **作れた分は残る**。だから結果は「成功 / 失敗」の 2 値ではなく、
 * **どこまで作れたか**を必ず持って帰る。呼び出し側はそれを記録し、
 * 再実行が続きから進めるようにする。
 */
const COMMAND = "agentcms:create-content-type";

export interface TypeBridgeOptions {
  root: string;
  onDiagnostic?: (line: string) => void;
}

/**
 * 作成の結果。
 *
 * `created` / `skipped` は**どの結末でも**返る（途中で落ちても、そこまでの分）。
 * - `ok` … 要求した型がすべて在る状態になった
 * - `failed` … 途中で止まった。**作れた分は残っている**
 * - `unknown` … 応答が届かない・壊れている。何が起きたか分からない
 */
export type CreateTypesResult = {
  kind: "ok" | "failed" | "unknown";
  created: string[];
  skipped: string[];
  message?: string;
};

/** 投稿タイプをまとめて作る（既に在るものは相手側が飛ばす） */
export async function createContentTypes(
  types: ContentTypePlan[],
  options: TypeBridgeOptions,
): Promise<CreateTypesResult> {
  if (types.length === 0) {
    return { kind: "ok", created: [], skipped: [] };
  }

  const payload = JSON.stringify({
    types: types.map((t) => ({ id: t.id, label: t.label, preset: t.preset })),
  });

  let result;

  try {
    result = await run(
      "docker",
      [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(options.root),
        "app",
        "php",
        "artisan",
        COMMAND,
      ],
      { cwd: options.root, input: payload },
    );
  } catch (e) {
    return {
      kind: "unknown",
      created: [],
      skipped: [],
      message: `CMS の応答を確認できませんでした: ${e instanceof Error ? e.message : String(e)}`,
    };
  }

  if (result.stderr.trim() !== "") options.onDiagnostic?.(result.stderr.trim());

  const body = parseResponse(result.stdout);

  if (!body) {
    return {
      kind: "unknown",
      created: [],
      skipped: [],
      message: `応答を解釈できませんでした（exit=${result.code}）`,
    };
  }

  const created = stringList((body as { created?: unknown }).created);
  const skipped = stringList((body as { skipped?: unknown }).skipped);

  // exit code と応答が食い違う = どちらを信じてよいか分からない
  if (result.code !== 0 && body.status === "success") {
    return {
      kind: "unknown",
      created,
      skipped,
      message: `応答は成功ですが、コマンドは失敗しています（exit=${result.code}）`,
    };
  }

  if (body.status !== "success") {
    /*
     * **理由を落とさない**（R-13 7 件目 / 指摘 32 族）。
     *
     * `validation_error` の message は「入力内容を確認してください」しか無く、
     * **何が悪かったかは `errors` にしか無い**。落とすと、利用者は
     * 直し方の分からない断りだけを受け取ることになる。
     */
    const detail = Object.entries(
      ((body as { errors?: Record<string, unknown> }).errors ?? {}) as Record<
        string,
        unknown
      >,
    )
      .map(([field, messages]) =>
        Array.isArray(messages) ? `${field}: ${messages.join(" / ")}` : null,
      )
      .filter((v): v is string => v !== null);

    return {
      kind: "failed",
      created,
      skipped,
      message: [
        body.message ?? "投稿タイプを作成できませんでした",
        ...detail,
      ].join(" — "),
    };
  }

  /*
   * **成功と名乗る応答も確かめる。** 要求した型が
   * 「作った」か「既に在った」かのどちらかで全部揃っていなければ、成功にしない。
   */
  const accounted = new Set([...created, ...skipped]);
  const missing = types.filter((t) => !accounted.has(t.id)).map((t) => t.id);

  if (missing.length > 0) {
    return {
      kind: "unknown",
      created,
      skipped,
      message: `応答に含まれていない投稿タイプがあります: ${missing.join(", ")}`,
    };
  }

  return { kind: "ok", created, skipped };
}

function stringList(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((v): v is string => typeof v === "string")
    : [];
}

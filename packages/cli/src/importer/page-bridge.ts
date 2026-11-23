import type { ImportedPageSpec } from "./imported-page.js";
import { parseResponse } from "./term-bridge.js";
import { composeUserArgs } from "../runtime-user.js";
import { run } from "../run.js";

/**
 * ページの作成とトップページ指定の橋渡し（R03 / B-42）。
 *
 * 分類項目（`term-bridge.ts`）・投稿タイプ（`type-bridge.ts`）と**同じ座**である。
 * - shell 文字列を組み立てない（実行ファイルと引数を分けて渡す）
 * - payload は argv に載せない。JSON は stdin へ
 * - 実行できるのは決め打ちの 3 コマンドだけ
 *
 * ## 結果は 4 通りに分ける
 *
 * `agentcms:import-page` は**既に在れば触らない**（`kept`）ので、「成功」を 1 つにまとめると
 * **作ったのか、前からあったのか**が apply から見えなくなる。
 * 前からあったものには **前回の apply が途中で失敗して残した下書き**も含まれるので、
 * 呼び出し側が記録と突き合わせて判断できるように、区別して返す。
 *
 * `failed` は CMS が構造化された断りを返したとき。**作ったページは残っている**ことがある
 * （n 個目のブロックで断られた場合。コマンド側は削除を混ぜない）。
 * `unknown` は応答が読めない・食い違うとき。何が起きたか分からないので、そう言う。
 */
const IMPORT_COMMAND = "agentcms:import-page";
const OPERATION_COMMAND = "agentcms:operation";
const SETTING_COMMAND = "agentcms:site-setting";

/** サイト設定でトップページを指す鍵（`FrontPage::SETTING_KEY` と同じ綴り） */
export const FRONT_PAGE_SETTING = "front_page_id";

export interface PageBridgeOptions {
  root: string;
  onDiagnostic?: (line: string) => void;
}

export type ImportPageResult =
  | { kind: "created"; id: number; published: boolean }
  | { kind: "kept"; id: number; published: boolean }
  | { kind: "failed"; message: string }
  | { kind: "unknown"; message: string };

/**
 * 1 ページを起こす。**stdin の JSON は `ImportPageCommand` が受ける形そのもの**
 * （`title` / `slug` / `publish` / `layout` / `blocks`）。表示用の付帯（`source` / `front` / `passthrough`）は送らない。
 */
export async function importPage(
  page: ImportedPageSpec,
  options: PageBridgeOptions,
): Promise<ImportPageResult> {
  const payload = JSON.stringify({
    page: {
      title: page.title,
      slug: page.slug,
      publish: page.publish,
      layout: page.layout,
      blocks: page.blocks,
    },
  });

  const outcome = await invoke(IMPORT_COMMAND, [], payload, options);

  if (outcome.kind === "transport") {
    return {
      kind: "unknown",
      message: `CMS の応答を確認できませんでした: ${outcome.message}`,
    };
  }

  const body = outcome.body as Record<string, unknown>;

  // exit code と応答が食い違う = どちらを信じてよいか分からない
  if (outcome.exitCode !== 0 && body.status === "success") {
    return {
      kind: "unknown",
      message: `応答は成功ですが、コマンドは失敗しています（exit=${outcome.exitCode}）`,
    };
  }

  if (body.status !== "success") {
    return { kind: "failed", message: describeRefusal(body) };
  }

  const id = body.id;
  const action = body.action;
  const published = body.published;

  // **成功と名乗る応答も確かめる。** id と action が読めなければ成功にしない
  if (
    typeof id !== "number" ||
    !Number.isInteger(id) ||
    id <= 0 ||
    (action !== "created" && action !== "kept") ||
    typeof published !== "boolean"
  ) {
    return {
      kind: "unknown",
      message: `ページ [${page.slug}] の応答を解釈できませんでした`,
    };
  }

  return { kind: action, id, published };
}

/**
 * 断りを人が読める 1 行にする。
 *
 * `validation_error` の message は「入力内容を確認してください」しか持たないので、
 * `reason` と `errors` を落とすと**直し方の分からない断り**になる（`type-bridge.ts` と同じ）。
 */
function describeRefusal(body: Record<string, unknown>): string {
  const parts: string[] = [];

  if (typeof body.message === "string" && body.message !== "")
    parts.push(body.message);
  if (typeof body.reason === "string" && body.reason !== "")
    parts.push(body.reason);

  const errors = body.errors;

  if (errors && typeof errors === "object" && !Array.isArray(errors)) {
    for (const [field, messages] of Object.entries(
      errors as Record<string, unknown>,
    )) {
      if (Array.isArray(messages))
        parts.push(`${field}: ${messages.join(" / ")}`);
    }
  }

  return parts.length === 0
    ? "ページを作成できませんでした"
    : parts.join(" — ");
}

export type FrontPageReadResult =
  { kind: "ok"; id: number | null } | { kind: "unknown"; message: string };

/**
 * いまトップページに指定されているページの id を読む（`settings.get`）。
 *
 * **読めなかったものを「未設定」にしない。** 未設定と取り違えると、
 * 運用者が選んだトップを黙って差し替えることになる。
 */
export async function readFrontPageId(
  options: PageBridgeOptions,
): Promise<FrontPageReadResult> {
  const outcome = await invoke(
    OPERATION_COMMAND,
    ["settings.get"],
    JSON.stringify({ arguments: {} }),
    options,
  );

  if (outcome.kind === "transport") {
    return {
      kind: "unknown",
      message: `サイト設定を読み取れませんでした: ${outcome.message}`,
    };
  }

  if (outcome.exitCode !== 0 || outcome.body.status !== "success") {
    return {
      kind: "unknown",
      message:
        outcome.body.message ??
        `サイト設定を読み取れませんでした（exit=${outcome.exitCode}）`,
    };
  }

  const values = (outcome.body.data as { values?: unknown } | undefined)
    ?.values;

  // PHP の空の連想配列は JSON では []。新規サイトの実際の応答を未設定として扱う。
  if (Array.isArray(values) && values.length === 0) return { kind: "ok", id: null };

  if (!values || typeof values !== "object" || Array.isArray(values)) {
    return {
      kind: "unknown",
      message: "サイト設定の応答を解釈できませんでした",
    };
  }

  const raw = (values as Record<string, unknown>)[FRONT_PAGE_SETTING];

  if (raw === undefined || raw === null || raw === "")
    return { kind: "ok", id: null };

  const id =
    typeof raw === "number"
      ? raw
      : typeof raw === "string" && /^\d+$/.test(raw)
        ? Number(raw)
        : NaN;

  if (!Number.isInteger(id) || id <= 0) {
    return {
      kind: "unknown",
      message: `サイト設定 [${FRONT_PAGE_SETTING}] の値を解釈できません: ${String(raw)}`,
    };
  }

  return { kind: "ok", id };
}

export type SetFrontPageResult =
  { kind: "ok" } | { kind: "failed"; message: string };

/**
 * トップページにページを指定する（`agentcms:site-setting front_page_id <id>`）。
 *
 * 口は **`agentcms:site-setting` の 1 つ**（`ImportPageCommand` の宣言どおり、
 * import 側に 2 つ目の口を作らない）。検証（公開されているページか）は CMS 側が持つ。
 * 値は id の整数だけなので argv に載せてよい（Setup Wizard と同じ）。
 */
export async function setFrontPage(
  pageId: number,
  options: PageBridgeOptions,
): Promise<SetFrontPageResult> {
  let result;

  try {
    result = await runCommand(
      SETTING_COMMAND,
      [FRONT_PAGE_SETTING, String(pageId)],
      "",
      options,
    );
  } catch (e) {
    return {
      kind: "failed",
      message: `CMS を呼べませんでした: ${e instanceof Error ? e.message : String(e)}`,
    };
  }

  if (result.stderr.trim() !== "") options.onDiagnostic?.(result.stderr.trim());

  if (result.code !== 0) {
    const said = `${result.stdout}\n${result.stderr}`
      .split("\n")
      .map((l) => l.trim())
      .filter(Boolean)
      .join(" / ");

    return {
      kind: "failed",
      message: said === "" ? `exit=${result.code}` : said,
    };
  }

  return { kind: "ok" };
}

interface BridgeResponse {
  status?: string;
  message?: string;
  data?: unknown;
}

type Invocation =
  | { kind: "response"; body: BridgeResponse; exitCode: number }
  | { kind: "transport"; message: string };

async function invoke(
  command: string,
  args: string[],
  payload: string,
  options: PageBridgeOptions,
): Promise<Invocation> {
  let result;

  try {
    result = await runCommand(command, args, payload, options);
  } catch (e) {
    return {
      kind: "transport",
      message: e instanceof Error ? e.message : String(e),
    };
  }

  if (result.stderr.trim() !== "") options.onDiagnostic?.(result.stderr.trim());

  const body = parseResponse(result.stdout);

  if (!body) {
    return {
      kind: "transport",
      message: `応答を解釈できませんでした（exit=${result.code}）`,
    };
  }

  return { kind: "response", body, exitCode: result.code };
}

async function runCommand(
  command: string,
  args: string[],
  payload: string,
  options: PageBridgeOptions,
) {
  return run(
    "docker",
    [
      "compose",
      "exec",
      "-T",
      ...composeUserArgs(options.root),
      "app",
      "php",
      "artisan",
      command,
      ...args,
    ],
    { cwd: options.root, input: payload },
  );
}

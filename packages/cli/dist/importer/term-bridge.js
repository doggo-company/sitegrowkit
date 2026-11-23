/**
 * 分類の読み取りと作成の橋渡し（Phase 9.3B / #23・SPEC §U.3）。
 *
 * CMS は Docker の中にいるので、CLI からは artisan を 1 つ実行して JSON をやり取りする。
 *
 * 固定事項（`mcp/bridge.ts` と同じ）:
 * - **shell 文字列を組み立てない**。実行ファイルと引数を分けて渡す
 * - **payload は argv に載せない**。JSON は stdin へ
 * - 実行できるのは決め打ちの 2 コマンドだけ。任意の artisan は呼べない
 * - 接続情報や秘密は出力しない
 */
import { emptyCatalog } from "./plan-contract.js";
import { composeUserArgs } from "../runtime-user.js";
import { run } from "../run.js";
/** 読み取り: 既存の分類項目一覧（`term.list`）。archived も含める */
const READ_COMMAND = "agentcms:operation";
/** 書き込み: 分類項目の一括作成（`agentcms:import-terms`）。全件成功か 0 件か */
const WRITE_COMMAND = "agentcms:import-terms";
/**
 * 宣言された分類の既存項目を読む。
 *
 * **読めなかったものを「無い」にしない。** CMS が起動していない、同期されていない、
 * 応答を解釈できない — どれも「確認できなかった」であって「存在しない」ではない。
 * 取り違えると、既にある項目をもう一度作ってしまう。
 */
export async function fetchTaxonomyCatalog(taxonomyKeys, options) {
    const catalog = emptyCatalog();
    for (const key of taxonomyKeys) {
        const result = await invoke(READ_COMMAND, ["term.list"], JSON.stringify({
            arguments: { taxonomyKey: key, includeArchived: true },
        }), options);
        if (result.kind !== "response" ||
            result.exitCode !== 0 ||
            result.body.status !== "success") {
            catalog.failures[key] =
                (result.kind === "response" ? result.body.message : result.message) ??
                    `分類 [${key}] の既存項目を読み取れませんでした（CMS が起動していない可能性があります）`;
            continue;
        }
        const terms = result.body.data?.terms;
        if (!Array.isArray(terms)) {
            catalog.failures[key] = `分類 [${key}] の応答を解釈できませんでした`;
            continue;
        }
        const normalized = [];
        let broken = false;
        for (const raw of terms) {
            const term = normalizeTerm(raw);
            // **1 件でも読めなければ、その分類全体を「未確認」にする。**
            // 読めなかった項目を黙って捨てると、それが実在するのに
            // 「無い」と判断して同じものをもう一度作ってしまう
            if (term === null) {
                broken = true;
                break;
            }
            normalized.push(term);
        }
        if (broken) {
            catalog.failures[key] =
                `分類 [${key}] の既存項目に読み取れないものがあるため、重複の有無を判断できません`;
            continue;
        }
        catalog.terms[key] = normalized;
    }
    return catalog;
}
/**
 * 分類項目をまとめて作る。
 *
 * 相手側は **1 つのトランザクション**で実行するので、
 * 失敗したときに「途中まで作られた」状態は返ってこない。
 */
export async function createTerms(terms, options) {
    if (terms.length === 0) {
        return { kind: "ok", created: [] };
    }
    const outcome = await invoke(WRITE_COMMAND, [], 
    // **actor は送らない。** 監査に残る呼び出し元は CMS 側で固定されている
    // （入力で名乗れると「誰がやったか」を偽装できてしまう）
    JSON.stringify({ terms }), options);
    if (outcome.kind === "transport") {
        return {
            kind: "commit_unknown",
            message: `CMS の応答を確認できませんでした: ${outcome.message}`,
        };
    }
    const body = outcome.body;
    // exit code と応答が食い違う = どちらを信じてよいか分からない
    if (outcome.exitCode !== 0 && body.status === "success") {
        return {
            kind: "commit_unknown",
            message: `応答は成功ですが、コマンドは失敗しています（exit=${outcome.exitCode}）`,
        };
    }
    if (body.status !== "success") {
        // CMS が構造化された失敗を返した = 1 トランザクションが巻き戻っている
        return {
            kind: "definite_rollback",
            message: body.message ?? "分類項目を作成できませんでした",
        };
    }
    // **成功と名乗る応答も検証する。** 件数も中身も要求どおりでなければ成功にしない
    const created = verifyCreated(body.created, terms);
    if (created === null) {
        return {
            kind: "commit_unknown",
            message: "要求した分類項目と応答が一致しません",
        };
    }
    return { kind: "ok", created };
}
async function invoke(command, args, payload, options) {
    let result;
    try {
        result = await runCommand(command, args, payload, options);
    }
    catch (e) {
        return {
            kind: "transport",
            message: e instanceof Error ? e.message : String(e),
        };
    }
    if (result.stderr.trim() !== "") {
        options.onDiagnostic?.(result.stderr.trim());
    }
    const body = parseResponse(result.stdout);
    if (!body) {
        return {
            kind: "transport",
            message: `応答を解釈できませんでした（exit=${result.code}）`,
        };
    }
    return { kind: "response", body, exitCode: result.code };
}
async function runCommand(command, args, payload, options) {
    return run("docker", [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(options.root),
        "app",
        "php",
        "artisan",
        command,
        ...args,
    ], { cwd: options.root, input: payload });
}
/** stdout から JSON を 1 つ取り出す（前後に別の出力が混ざっても壊れない） */
export function parseResponse(stdout) {
    const lines = stdout
        .split("\n")
        .map((l) => l.trim())
        .filter(Boolean);
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
/**
 * 応答の 1 件を読む。**足りない・型が違うものは null**（推測で埋めない）。
 *
 * `archived` は boolean を要求する。「無ければ false」にすると、
 * 応答形式が変わったときに **archive 済みを有効として扱ってしまう**。
 */
function normalizeTerm(raw) {
    if (!raw || typeof raw !== "object" || Array.isArray(raw))
        return null;
    const term = raw;
    if (typeof term.id !== "string" || term.id === "")
        return null;
    if (typeof term.slug !== "string" || term.slug === "")
        return null;
    if (typeof term.name !== "string" || term.name === "")
        return null;
    if (typeof term.archived !== "boolean")
        return null;
    return {
        id: term.id,
        name: term.name,
        slug: term.slug,
        archived: term.archived,
    };
}
/**
 * 作成結果が **要求そのもの**であることを確かめる。
 *
 * 件数・分類・名前・slug のどれかが違えば、こちらが意図したものとは別の
 * 変更が起きている。空配列を「成功」として通すと、何も作られていないのに
 * apply が完了したことになる。
 */
function verifyCreated(raw, requested) {
    if (!Array.isArray(raw) || raw.length !== requested.length)
        return null;
    const created = [];
    for (let i = 0; i < requested.length; i++) {
        const item = raw[i];
        const want = requested[i];
        if (!item || typeof item !== "object" || Array.isArray(item) || !want)
            return null;
        const term = item;
        if (typeof term.id !== "string" || term.id === "")
            return null;
        if (term.taxonomy !== want.taxonomyKey)
            return null;
        if (term.slug !== want.slug)
            return null;
        if (term.name !== want.name)
            return null;
        created.push({
            id: term.id,
            slug: want.slug,
            name: want.name,
            taxonomy: want.taxonomyKey,
        });
    }
    return created;
}

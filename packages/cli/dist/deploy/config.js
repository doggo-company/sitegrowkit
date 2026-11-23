import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { isProductionTarget } from "./providers/xserver-setup.js";
/**
 * デプロイ設定の読み込み。場所: <project>/.agentcms/deploy/<target>.json
 * ホスト名・ユーザー名等の接続情報を含むため Git には含めない（.gitignore 済み）。
 * 秘密鍵・パスワード・DB 認証情報はこのファイルにも書かない
 * （SSH 鍵はパス参照、DB 認証はサーバー側 shared/.env のみ）。
 */
export function deployConfigPath(projectRoot, target) {
    return join(projectRoot, ".agentcms", "deploy", `${target}.json`);
}
/**
 * その target の**ひな形の名前**。
 *
 * **`staging.example.json` を固定で案内しない。** 本番を作ろうとしている人へ
 * staging のひな形を案内すると、**`"target": "staging"` のまま作って**、
 * ファイル名と中身が食い違う（`validate()` がそこで落ちる — 遠回りである）。
 */
function exampleFor(target) {
    return isProductionTarget(target)
        ? ".agentcms/deploy/production.example.json"
        : ".agentcms/deploy/staging.example.json";
}
export function loadDeployConfig(projectRoot, target) {
    const path = deployConfigPath(projectRoot, target);
    if (!existsSync(path)) {
        return {
            error: {
                what: `デプロイ設定がありません: ${path}`,
                why: `target "${target}" の接続設定が未作成です`,
                fix: `docs/deployment/xserver.md の手順に沿って ${path} を作成してください（${exampleFor(target)} をコピーして編集）`,
            },
        };
    }
    let raw;
    try {
        raw = JSON.parse(readFileSync(path, "utf8"));
    }
    catch (e) {
        return {
            error: {
                what: `デプロイ設定を JSON として読めません: ${path}`,
                why: e instanceof Error ? e.message : String(e),
                fix: "JSON 構文を修正してください",
            },
        };
    }
    const problems = validate(raw, target);
    if (problems.length > 0) {
        return {
            error: {
                what: `デプロイ設定が不正です: ${path}`,
                why: problems.join(" / "),
                fix: `${exampleFor(target)} の形式に合わせてください`,
            },
        };
    }
    const cfg = raw;
    return { config: { ...cfg, keepReleases: cfg.keepReleases ?? 3 } };
}
function validate(raw, target) {
    const problems = [];
    if (typeof raw !== "object" || raw === null)
        return ["オブジェクトではありません"];
    const o = raw;
    if (o.provider !== "xserver")
        problems.push(`provider は "xserver" のみ対応です（指定: ${String(o.provider)}）`);
    if (typeof o.target !== "string" || o.target.length === 0)
        problems.push("target が未指定です");
    // **ファイル名と中身がずれていてはいけない**（B-41）。
    // `ensureLayout()` はサーバー側 .env の `APP_ENV === cfg.target` を強制し、
    // health も target で staging / production を切り替える。ずれると
    // **本番へ staging の物差しを当てる**ことになる
    else if (o.target !== target)
        problems.push(`target がファイル名と一致しません（ファイル: ${target}.json / 中身: ${String(o.target)}）`);
    const ssh = o.ssh;
    if (!ssh || typeof ssh !== "object")
        problems.push("ssh がありません");
    else {
        if (typeof ssh.host !== "string" || ssh.host.length === 0)
            problems.push("ssh.host が未指定です");
        if (typeof ssh.port !== "number")
            problems.push("ssh.port が数値ではありません");
        if (typeof ssh.user !== "string" || ssh.user.length === 0)
            problems.push("ssh.user が未指定です");
    }
    const remote = o.remote;
    if (!remote || typeof remote !== "object")
        problems.push("remote がありません");
    else {
        for (const key of ["appDir", "docroot", "phpBin"]) {
            if (typeof remote[key] !== "string" ||
                remote[key].length === 0)
                problems.push(`remote.${key} が未指定です`);
        }
        if (typeof remote.appDir === "string" &&
            typeof remote.docroot === "string" &&
            remote.appDir.startsWith(remote.docroot))
            problems.push("remote.appDir が docroot 配下にあります（ソース・.env が Web 公開されるため禁止）");
    }
    const api = o.api;
    if (api !== undefined) {
        if (typeof api !== "object" || api === null)
            problems.push("api はオブジェクトです");
        else {
            for (const key of ["servername", "dbSuffix"]) {
                if (api[key] !== undefined && typeof api[key] !== "string")
                    problems.push(`api.${key} は文字列です`);
            }
            // API キーの誤コミット防止: 設定ファイルには絶対に書かせない（env XSERVER_API_KEY のみ）
            const suspicious = Object.entries(api).find(([k, v]) => /key|token|secret/i.test(k) ||
                (typeof v === "string" && /^xs_/.test(v)));
            if (suspicious)
                problems.push(`api.${suspicious[0]} は設定ファイルに書けません（API キーは環境変数 XSERVER_API_KEY で渡してください）`);
        }
    }
    const app = o.app;
    if (!app || typeof app !== "object")
        problems.push("app がありません");
    else {
        if (typeof app.url !== "string" || !app.url.startsWith("https://"))
            problems.push("app.url は https:// で始まる必要があります");
        if (typeof app.healthPath !== "string" ||
            !app.healthPath.startsWith("/"))
            problems.push("app.healthPath は / で始まる必要があります");
    }
    return problems;
}

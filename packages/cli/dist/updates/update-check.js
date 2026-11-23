import { existsSync, realpathSync } from "node:fs";
import { basename, join, resolve } from "node:path";
import { kitVersion, AGENT_CONTEXT_VERSION, TEMPLATE_SCHEMA_VERSION, } from "../version.js";
import { checkArchiveAgainstManifest, MAX_MANIFEST_BYTES, MAX_PUBLIC_KEY_BYTES, MAX_SIGNATURE_BYTES, verifySignedManifest, } from "./release-manifest.js";
import { readFileBounded } from "./safe-io.js";
import { compareVersionStrings, parseSemver } from "./semver.js";
/** project.json の上限。実物は 300 bytes 程度 */
export const MAX_PROJECT_JSON_BYTES = 64 * 1024;
/** 契約版番号として受け付ける範囲（manifest 側の schema と同じ） */
const MAX_CONTRACT_VERSION = 1_000_000;
function contractInt(v) {
    return typeof v === "number" &&
        Number.isInteger(v) &&
        v >= 0 &&
        v <= MAX_CONTRACT_VERSION
        ? v
        : null;
}
/**
 * `.agentcms/project.json` を読む。壊れていても捏造しない（unknown として扱う）。
 * 上限超過・権限・削除・非 JSON・範囲外の整数はすべて `problem` になる。
 */
export function readCurrentProject(root) {
    const path = join(root, ".agentcms", "project.json");
    const base = {
        root,
        kit_version: null,
        template_schema_version: null,
        agent_context_version: null,
    };
    const read = readFileBounded(path, MAX_PROJECT_JSON_BYTES, ".agentcms/project.json");
    if (!read.ok) {
        return { ...base, problem: read.reason };
    }
    let json;
    try {
        json = JSON.parse(read.bytes.toString("utf8"));
    }
    catch {
        return {
            ...base,
            problem: ".agentcms/project.json が JSON として読めません",
        };
    }
    if (typeof json !== "object" || json === null || Array.isArray(json)) {
        return {
            ...base,
            problem: ".agentcms/project.json がオブジェクトではありません",
        };
    }
    const o = json;
    const kv = typeof o.kit_version === "string" && parseSemver(o.kit_version)
        ? o.kit_version
        : null;
    const ts = contractInt(o.template_schema_version);
    const ac = contractInt(o.agent_context_version);
    const problems = [];
    if (kv === null)
        problems.push("kit_version が無い、または semver ではありません");
    if (ts === null)
        problems.push(`template_schema_version が 0〜${MAX_CONTRACT_VERSION} の整数ではありません`);
    if (ac === null)
        problems.push(`agent_context_version が 0〜${MAX_CONTRACT_VERSION} の整数ではありません`);
    return {
        root,
        kit_version: kv,
        template_schema_version: ts,
        agent_context_version: ac,
        ...(problems.length ? { problem: problems.join(" / ") } : {}),
    };
}
/**
 * プロジェクトルートを決める。`--project` 指定が無ければ cwd から `.agentcms/project.json` を上へ探す。
 * 見つけたルートは **realpath**（実際の絶対パス）に解決する。解決できなければ null。
 */
function locateProject(opts) {
    const toReal = (p) => {
        try {
            return realpathSync(p);
        }
        catch {
            return null;
        }
    };
    if (opts.project)
        return toReal(resolve(opts.project));
    let dir = resolve(opts.cwd ?? process.cwd());
    for (;;) {
        if (existsSync(join(dir, ".agentcms", "project.json")))
            return toReal(dir);
        const parent = resolve(dir, "..");
        if (parent === dir)
            return null;
        dir = parent;
    }
}
export function judgeVerdict(project, candidate) {
    if (!project || project.kit_version === null)
        return "unknown";
    const c = compareVersionStrings(candidate, project.kit_version);
    if (c === null)
        return "unknown";
    if (c > 0)
        return "newer";
    if (c < 0)
        return "older";
    return "same";
}
export function judgeCompatibility(project, manifest) {
    const cand = manifest.compatibility;
    const cur = {
        ts: project?.template_schema_version ?? null,
        ac: project?.agent_context_version ?? null,
    };
    let status;
    if (cur.ts === null || cur.ac === null) {
        status = "unknown";
    }
    else if (cand.template_schema_version < cur.ts ||
        cand.agent_context_version < cur.ac) {
        status = "candidate_behind";
    }
    else if (cand.template_schema_version > cur.ts ||
        cand.agent_context_version > cur.ac) {
        status = "migration_required";
    }
    else {
        status = "same";
    }
    return {
        status,
        template_schema: {
            current: cur.ts,
            candidate: cand.template_schema_version,
        },
        agent_context: { current: cur.ac, candidate: cand.agent_context_version },
    };
}
/** この CLI 自身の契約が候補より古いか */
export function cliContractBehind(manifest) {
    return (manifest.compatibility.template_schema_version > TEMPLATE_SCHEMA_VERSION ||
        manifest.compatibility.agent_context_version > AGENT_CONTEXT_VERSION);
}
/**
 * 導入可能性。**署名が通ったかは見ない**（ここへ来る時点で通っている）。
 * blocked の理由を全部列挙する — 1 つ直しても次で止まる、を避けるため。
 */
export function judgeAdmissibility(input) {
    const { project, manifest, verdict, compatibility } = input;
    const blocked = [];
    const hold = [];
    const cur = project?.kit_version ?? null;
    if (!project) {
        blocked.push("利用中の版が不明です（プロジェクトが見つかりません）。比較できないものは導入しません。プロジェクトの中で実行するか --project <dir> を指定してください");
    }
    else if (cur === null) {
        blocked.push(`利用中の版が不明です（${project.problem ?? ".agentcms/project.json を読めません"}）。比較できないものは導入しません`);
    }
    if (verdict === "older" && cur !== null) {
        blocked.push(`候補 v${manifest.version} は利用中の v${cur} より古い版です。古い版への差し戻しはこの経路では行いません`);
    }
    if (compatibility.status === "candidate_behind") {
        blocked.push(`候補の構造契約（template_schema_version ${compatibility.template_schema.candidate} / agent_context_version ${compatibility.agent_context.candidate}）がこのプロジェクト（${compatibility.template_schema.current} / ${compatibility.agent_context.current}）より後退しています。導入しません`);
    }
    else if (compatibility.status === "unknown" && project && cur !== null) {
        blocked.push(`プロジェクトの構造契約を読めません（${project.problem ?? "project.json の契約版番号が不正"}）。互換性を判定できないものは導入しません`);
    }
    if (compatibility.status === "migration_required") {
        hold.push(`構造契約が進みます（template_schema_version ${compatibility.template_schema.current} → ${compatibility.template_schema.candidate} / agent_context_version ${compatibility.agent_context.current} → ${compatibility.agent_context.candidate}）。CHANGELOG / リリースノートの移行手順を確認してから次工程へ進んでください`);
    }
    if (cliContractBehind(manifest)) {
        hold.push(`この CLI（v${kitVersion()}）の構造契約（template_schema_version ${TEMPLATE_SCHEMA_VERSION} / agent_context_version ${AGENT_CONTEXT_VERSION}）は候補より古いため、候補の内容を完全には解釈できません。候補版の CLI で改めて確認してください`);
    }
    if (blocked.length > 0) {
        return {
            status: "blocked",
            reasons: blocked,
            archive_verified: input.archiveVerified,
        };
    }
    if (hold.length > 0) {
        return {
            status: "migration_review_required",
            reasons: hold,
            archive_verified: input.archiveVerified,
        };
    }
    const reasons = [
        verdict === "newer"
            ? `候補 v${manifest.version} は利用中の v${cur} より新しく、構造契約（template_schema_version ${compatibility.template_schema.candidate} / agent_context_version ${compatibility.agent_context.candidate}）は同じです`
            : `候補 v${manifest.version} は利用中の版と同じで、構造契約も同じです（導入するものはありません。差分の確認だけ行えます）`,
        "契約番号が同じでも DB migration が増えている版はあります。参照プロジェクトとの差分と database/migrations を確認してください",
    ];
    if (!input.archiveVerified) {
        reasons.push("手元の配布物（.tgz）は未照合です。導入前に --archive を付けて再実行し、実体を照合してください");
    }
    return {
        status: "review_required",
        reasons,
        archive_verified: input.archiveVerified,
    };
}
export async function runUpdateCheck(opts) {
    const cli = kitVersion();
    const projectRoot = locateProject(opts);
    const project = projectRoot ? readCurrentProject(projectRoot) : null;
    const failed = (code, failure, extra) => ({
        status: "failed",
        cli_version: cli,
        code,
        failure,
        project,
        ...extra,
    });
    // 1. 入力ファイル（上限は実読込で守る。例外は Result）
    const manifestPath = resolve(opts.manifest);
    const signaturePath = resolve(opts.signature);
    const publicKeyPath = resolve(opts.publicKey);
    const manifestRead = readFileBounded(manifestPath, MAX_MANIFEST_BYTES, "manifest");
    if (!manifestRead.ok) {
        return failed("update.manifest_missing", {
            what: "配布物の manifest を読めません",
            why: `${manifestRead.reason}: ${manifestPath}`,
            fix: "配布元から取得した release-manifest.json のパスを --manifest に指定してください",
        });
    }
    const signatureRead = readFileBounded(signaturePath, MAX_SIGNATURE_BYTES, "署名ファイル");
    if (!signatureRead.ok) {
        return failed("update.signature_missing", {
            what: "配布物の署名を読めません",
            why: `${signatureRead.reason}: ${signaturePath}`,
            fix: "manifest と一緒に配布される release-manifest.json.sig のパスを --signature に指定してください。署名が無い配布物は真正性を確認できないので導入しないでください",
        });
    }
    const keyRead = readFileBounded(publicKeyPath, MAX_PUBLIC_KEY_BYTES, "信頼鍵");
    if (!keyRead.ok) {
        return failed("update.public_key_missing", {
            what: "信頼鍵（発行者の公開鍵）を読めません",
            why: `${keyRead.reason}: ${publicKeyPath}`,
            fix: "配布物とは別の経路（配布元の案内・docs/UPGRADING.md の手順）で受け取った公開鍵 PEM のパスを --public-key に指定してください。配布物の中に入っている鍵は使わないでください",
        });
    }
    // 2〜4. 署名 → schema
    const verified = verifySignedManifest({
        manifestBytes: manifestRead.bytes,
        signatureRaw: signatureRead.bytes,
        publicKeyPem: keyRead.bytes,
    });
    if (!verified.ok) {
        const fixByCode = {
            public_key_invalid: "Ed25519 の公開鍵 PEM（-----BEGIN PUBLIC KEY-----）を指定してください。秘密鍵・別方式の鍵は受け付けません",
            signature_malformed: "署名ファイルが途中で切れていないか、別のファイルを指定していないか確認し、配布元から再取得してください",
            signature_invalid: "この配布物は導入しないでください。manifest と署名を配布元から再取得し、信頼鍵が正しい経路で得たものか確認してください。それでも一致しなければサポートへ連絡してください",
            schema_invalid: "署名は通りましたが、この CLI が読める形式ではありません。CLI を先に更新するか、配布元へ確認してください",
            manifest_too_large: "release-manifest.json 以外のファイルを指定していないか確認してください",
            manifest_empty: "manifest ファイルが空です。配布元から再取得してください",
        };
        return failed(`update.${verified.code}`, {
            what: "配布物の真正性を確認できませんでした",
            why: verified.hint
                ? `${verified.reason}。${verified.hint}`
                : verified.reason,
            fix: fixByCode[verified.code] ?? "入力ファイルを確認してください",
        });
    }
    const manifest = verified.manifest;
    const warnings = [];
    // 5. アーカイブ実体の照合（任意）
    let archive = { status: "not_checked" };
    if (opts.archive) {
        const archivePath = resolve(opts.archive);
        const check = checkArchiveAgainstManifest(archivePath, manifest);
        if (check.status === "unreadable") {
            return failed(check.code === "not_found"
                ? "update.archive_missing"
                : "update.archive_unreadable", {
                what: check.code === "not_found"
                    ? "アーカイブが見つかりません"
                    : "アーカイブを照合できませんでした",
                why: `${check.reason}: ${archivePath}`,
                fix: "ダウンロードした .tgz / .zip のパスを確認し、ダウンロードが完了してから（別のプロセスが書き込んでいない状態で）再実行してください。照合できないものは導入しないでください",
            }, { manifest, archive: check });
        }
        if (check.status === "mismatch") {
            return failed("update.archive_mismatch", {
                what: "アーカイブが署名済み manifest の記述と一致しません",
                why: check.reason,
                fix: "このアーカイブは導入しないでください。ダウンロードをやり直し、同じ manifest で再確認してください。それでも一致しなければ配布元へ連絡してください",
            }, { manifest, archive: check });
        }
        const nameMatches = basename(archivePath) === manifest.artifact.name;
        if (!nameMatches) {
            warnings.push(`アーカイブのファイル名（${basename(archivePath)}）が manifest の記述（${manifest.artifact.name}）と異なります。内容は一致しています`);
        }
        archive = {
            status: "verified",
            path: archivePath,
            bytes: check.bytes,
            sha256: check.sha256,
            name_matches: nameMatches,
        };
    }
    // 6. いま使っている版との比較 → 導入可能性
    const verdict = judgeVerdict(project, manifest.version);
    const compatibility = judgeCompatibility(project, manifest);
    const admissibility = judgeAdmissibility({
        project,
        manifest,
        verdict,
        compatibility,
        archiveVerified: archive.status === "verified",
    });
    if (!project) {
        warnings.push("プロジェクト（.agentcms/project.json）が見つからないため、利用中の版は不明です。プロジェクトの中で実行するか --project を指定してください");
    }
    else if (project.problem) {
        warnings.push(`利用中の版を読めません: ${project.problem}`);
    }
    return {
        status: "verified",
        cli_version: cli,
        trusted_key_fingerprint: verified.trustedKeyFingerprint,
        manifest,
        archive,
        project,
        verdict,
        compatibility,
        admissibility,
        applied: false,
        warnings,
    };
}

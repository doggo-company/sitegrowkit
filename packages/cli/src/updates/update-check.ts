import { existsSync, realpathSync } from "node:fs";
import { basename, join, resolve } from "node:path";

import type { Failure } from "../ui.js";
import {
  kitVersion,
  AGENT_CONTEXT_VERSION,
  TEMPLATE_SCHEMA_VERSION,
} from "../version.js";
import {
  checkArchiveAgainstManifest,
  MAX_MANIFEST_BYTES,
  MAX_PUBLIC_KEY_BYTES,
  MAX_SIGNATURE_BYTES,
  type ArchiveCheck,
  type ReleaseManifest,
  verifySignedManifest,
} from "./release-manifest.js";
import { readFileBounded } from "./safe-io.js";
import { compareVersionStrings, parseSemver } from "./semver.js";

/**
 * `agentcms update check` の engine（process.exit しない・ネットへ出ない）。
 *
 * ## 何をするか / しないか
 *
 * する: ローカルにある manifest / 署名 / 信頼鍵（/ アーカイブ）を突き合わせ、
 * 「この配布物は発行者のものか」「いま使っている版と比べてどうか」「構造契約は合うか」を言う。
 *
 * しない: ダウンロード・展開・`core/` の差し替え・migration・`project.json` の書き換え。
 * **署名が通っても、それは「更新済み」ではない。** まだ何も適用していない。
 *
 * ## 署名検証と導入可能性は別（admissibility）
 *
 * 署名が通った（`status: "verified"`）ことと、その候補を次工程へ進めてよいことは
 * 別の判定である。後者は `admissibility` が持つ:
 *
 * | status | 意味 | exit |
 * | --- | --- | --- |
 * | `review_required` | 新しい版（または同じ版）で構造契約も合う。差分・migration をレビューする次工程へ進める | 0 |
 * | `migration_review_required` | 構造契約が進む／この CLI の契約が候補より古い。移行手順の確認待ち | 2 |
 * | `blocked` | 候補が古い／契約が後退／利用中の版が不明。導入を止める | 2 |
 *
 * ## 「不明」を「最新」と言わない
 *
 * プロジェクトが見つからない・`kit_version` が読めないときの判定は `unknown` で、
 * admissibility は `blocked`。比較できないものを「同じ版」「新しい版なし」に丸めない。
 *
 * ## 契約番号の一致は DB migration 不要の根拠にならない
 *
 * `template_schema_version` が同じでも、版の間で新しい DB migration が増える
 * （例: `imported_page_layouts`）。契約番号は「構造の互換」を言うだけで、
 * migration の有無は参照プロジェクトの差分と `database/migrations` を見て決める。
 */

export interface UpdateCheckOptions {
  manifest: string;
  signature: string;
  publicKey: string;
  archive?: string;
  /** プロジェクトルート（省略時は cwd から `.agentcms/` を探す） */
  project?: string;
  cwd?: string;
}

export type Verdict =
  | "newer" // 候補 > 利用中
  | "same"
  | "older" // 候補 < 利用中（導入しない）
  | "unknown"; // 利用中の版が分からない

export type CompatibilityStatus =
  | "same" // 構造契約が一致（DB migration の有無は別に確認する）
  | "migration_required" // 候補の契約が進んでいる（移行手順が要る）
  | "candidate_behind" // 候補の契約がプロジェクトより古い（導入しない）
  | "unknown"; // プロジェクト側が読めない

export type AdmissibilityStatus =
  "review_required" | "migration_review_required" | "blocked";

export interface Admissibility {
  status: AdmissibilityStatus;
  /** 判定の具体的な理由（人向け・記録向け。パスや例外は含めない） */
  reasons: string[];
  /** 手元の配布物実体を照合したか（false なら導入前に --archive で照合する） */
  archive_verified: boolean;
}

export interface CurrentProject {
  root: string;
  kit_version: string | null;
  template_schema_version: number | null;
  agent_context_version: number | null;
  /** project.json はあるが壊れている・欠けている */
  problem?: string;
}

export interface CompatibilityReport {
  status: CompatibilityStatus;
  template_schema: { current: number | null; candidate: number };
  agent_context: { current: number | null; candidate: number };
}

export interface UpdateCheckSuccess {
  status: "verified";
  cli_version: string;
  trusted_key_fingerprint: string;
  manifest: ReleaseManifest;
  archive:
    | { status: "not_checked" }
    | {
        status: "verified";
        path: string;
        bytes: number;
        sha256: string;
        name_matches: boolean;
      };
  project: CurrentProject | null;
  verdict: Verdict;
  compatibility: CompatibilityReport;
  admissibility: Admissibility;
  /** 常に false。この command は適用しない */
  applied: false;
  warnings: string[];
}

export interface UpdateCheckFailure {
  status: "failed";
  cli_version: string;
  code: string;
  failure: Failure;
  project: CurrentProject | null;
  /** 署名は通ったがアーカイブが合わなかったときだけ入る */
  manifest?: ReleaseManifest;
  archive?: ArchiveCheck;
}

export type UpdateCheckReport = UpdateCheckSuccess | UpdateCheckFailure;

/** project.json の上限。実物は 300 bytes 程度 */
export const MAX_PROJECT_JSON_BYTES = 64 * 1024;
/** 契約版番号として受け付ける範囲（manifest 側の schema と同じ） */
const MAX_CONTRACT_VERSION = 1_000_000;

function contractInt(v: unknown): number | null {
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
export function readCurrentProject(root: string): CurrentProject {
  const path = join(root, ".agentcms", "project.json");
  const base: CurrentProject = {
    root,
    kit_version: null,
    template_schema_version: null,
    agent_context_version: null,
  };
  const read = readFileBounded(
    path,
    MAX_PROJECT_JSON_BYTES,
    ".agentcms/project.json",
  );
  if (!read.ok) {
    return { ...base, problem: read.reason };
  }
  let json: unknown;
  try {
    json = JSON.parse(read.bytes.toString("utf8"));
  } catch {
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
  const o = json as Record<string, unknown>;
  const kv =
    typeof o.kit_version === "string" && parseSemver(o.kit_version)
      ? o.kit_version
      : null;
  const ts = contractInt(o.template_schema_version);
  const ac = contractInt(o.agent_context_version);
  const problems: string[] = [];
  if (kv === null)
    problems.push("kit_version が無い、または semver ではありません");
  if (ts === null)
    problems.push(
      `template_schema_version が 0〜${MAX_CONTRACT_VERSION} の整数ではありません`,
    );
  if (ac === null)
    problems.push(
      `agent_context_version が 0〜${MAX_CONTRACT_VERSION} の整数ではありません`,
    );
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
function locateProject(opts: UpdateCheckOptions): string | null {
  const toReal = (p: string): string | null => {
    try {
      return realpathSync(p);
    } catch {
      return null;
    }
  };
  if (opts.project) return toReal(resolve(opts.project));
  let dir = resolve(opts.cwd ?? process.cwd());
  for (;;) {
    if (existsSync(join(dir, ".agentcms", "project.json"))) return toReal(dir);
    const parent = resolve(dir, "..");
    if (parent === dir) return null;
    dir = parent;
  }
}

export function judgeVerdict(
  project: CurrentProject | null,
  candidate: string,
): Verdict {
  if (!project || project.kit_version === null) return "unknown";
  const c = compareVersionStrings(candidate, project.kit_version);
  if (c === null) return "unknown";
  if (c > 0) return "newer";
  if (c < 0) return "older";
  return "same";
}

export function judgeCompatibility(
  project: CurrentProject | null,
  manifest: ReleaseManifest,
): CompatibilityReport {
  const cand = manifest.compatibility;
  const cur = {
    ts: project?.template_schema_version ?? null,
    ac: project?.agent_context_version ?? null,
  };
  let status: CompatibilityStatus;
  if (cur.ts === null || cur.ac === null) {
    status = "unknown";
  } else if (
    cand.template_schema_version < cur.ts ||
    cand.agent_context_version < cur.ac
  ) {
    status = "candidate_behind";
  } else if (
    cand.template_schema_version > cur.ts ||
    cand.agent_context_version > cur.ac
  ) {
    status = "migration_required";
  } else {
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
export function cliContractBehind(manifest: ReleaseManifest): boolean {
  return (
    manifest.compatibility.template_schema_version > TEMPLATE_SCHEMA_VERSION ||
    manifest.compatibility.agent_context_version > AGENT_CONTEXT_VERSION
  );
}

/**
 * 導入可能性。**署名が通ったかは見ない**（ここへ来る時点で通っている）。
 * blocked の理由を全部列挙する — 1 つ直しても次で止まる、を避けるため。
 */
export function judgeAdmissibility(input: {
  project: CurrentProject | null;
  manifest: ReleaseManifest;
  verdict: Verdict;
  compatibility: CompatibilityReport;
  archiveVerified: boolean;
}): Admissibility {
  const { project, manifest, verdict, compatibility } = input;
  const blocked: string[] = [];
  const hold: string[] = [];
  const cur = project?.kit_version ?? null;

  if (!project) {
    blocked.push(
      "利用中の版が不明です（プロジェクトが見つかりません）。比較できないものは導入しません。プロジェクトの中で実行するか --project <dir> を指定してください",
    );
  } else if (cur === null) {
    blocked.push(
      `利用中の版が不明です（${project.problem ?? ".agentcms/project.json を読めません"}）。比較できないものは導入しません`,
    );
  }
  if (verdict === "older" && cur !== null) {
    blocked.push(
      `候補 v${manifest.version} は利用中の v${cur} より古い版です。古い版への差し戻しはこの経路では行いません`,
    );
  }
  if (compatibility.status === "candidate_behind") {
    blocked.push(
      `候補の構造契約（template_schema_version ${compatibility.template_schema.candidate} / agent_context_version ${compatibility.agent_context.candidate}）がこのプロジェクト（${compatibility.template_schema.current} / ${compatibility.agent_context.current}）より後退しています。導入しません`,
    );
  } else if (compatibility.status === "unknown" && project && cur !== null) {
    blocked.push(
      `プロジェクトの構造契約を読めません（${project.problem ?? "project.json の契約版番号が不正"}）。互換性を判定できないものは導入しません`,
    );
  }
  if (compatibility.status === "migration_required") {
    hold.push(
      `構造契約が進みます（template_schema_version ${compatibility.template_schema.current} → ${compatibility.template_schema.candidate} / agent_context_version ${compatibility.agent_context.current} → ${compatibility.agent_context.candidate}）。CHANGELOG / リリースノートの移行手順を確認してから次工程へ進んでください`,
    );
  }
  if (cliContractBehind(manifest)) {
    hold.push(
      `この CLI（v${kitVersion()}）の構造契約（template_schema_version ${TEMPLATE_SCHEMA_VERSION} / agent_context_version ${AGENT_CONTEXT_VERSION}）は候補より古いため、候補の内容を完全には解釈できません。候補版の CLI で改めて確認してください`,
    );
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
    reasons.push(
      "手元の配布物（.tgz）は未照合です。導入前に --archive を付けて再実行し、実体を照合してください",
    );
  }
  return {
    status: "review_required",
    reasons,
    archive_verified: input.archiveVerified,
  };
}

export async function runUpdateCheck(
  opts: UpdateCheckOptions,
): Promise<UpdateCheckReport> {
  const cli = kitVersion();
  const projectRoot = locateProject(opts);
  const project = projectRoot ? readCurrentProject(projectRoot) : null;

  const failed = (
    code: string,
    failure: Failure,
    extra?: Partial<UpdateCheckFailure>,
  ): UpdateCheckFailure => ({
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

  const manifestRead = readFileBounded(
    manifestPath,
    MAX_MANIFEST_BYTES,
    "manifest",
  );
  if (!manifestRead.ok) {
    return failed("update.manifest_missing", {
      what: "配布物の manifest を読めません",
      why: `${manifestRead.reason}: ${manifestPath}`,
      fix: "配布元から取得した release-manifest.json のパスを --manifest に指定してください",
    });
  }
  const signatureRead = readFileBounded(
    signaturePath,
    MAX_SIGNATURE_BYTES,
    "署名ファイル",
  );
  if (!signatureRead.ok) {
    return failed("update.signature_missing", {
      what: "配布物の署名を読めません",
      why: `${signatureRead.reason}: ${signaturePath}`,
      fix: "manifest と一緒に配布される release-manifest.json.sig のパスを --signature に指定してください。署名が無い配布物は真正性を確認できないので導入しないでください",
    });
  }
  const keyRead = readFileBounded(
    publicKeyPath,
    MAX_PUBLIC_KEY_BYTES,
    "信頼鍵",
  );
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
    const fixByCode: Record<string, string> = {
      public_key_invalid:
        "Ed25519 の公開鍵 PEM（-----BEGIN PUBLIC KEY-----）を指定してください。秘密鍵・別方式の鍵は受け付けません",
      signature_malformed:
        "署名ファイルが途中で切れていないか、別のファイルを指定していないか確認し、配布元から再取得してください",
      signature_invalid:
        "この配布物は導入しないでください。manifest と署名を配布元から再取得し、信頼鍵が正しい経路で得たものか確認してください。それでも一致しなければサポートへ連絡してください",
      schema_invalid:
        "署名は通りましたが、この CLI が読める形式ではありません。CLI を先に更新するか、配布元へ確認してください",
      manifest_too_large:
        "release-manifest.json 以外のファイルを指定していないか確認してください",
      manifest_empty:
        "manifest ファイルが空です。配布元から再取得してください",
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
  const warnings: string[] = [];

  // 5. アーカイブ実体の照合（任意）
  let archive: UpdateCheckSuccess["archive"] = { status: "not_checked" };
  if (opts.archive) {
    const archivePath = resolve(opts.archive);
    const check = checkArchiveAgainstManifest(archivePath, manifest);
    if (check.status === "unreadable") {
      return failed(
        check.code === "not_found"
          ? "update.archive_missing"
          : "update.archive_unreadable",
        {
          what:
            check.code === "not_found"
              ? "アーカイブが見つかりません"
              : "アーカイブを照合できませんでした",
          why: `${check.reason}: ${archivePath}`,
          fix: "ダウンロードした .tgz / .zip のパスを確認し、ダウンロードが完了してから（別のプロセスが書き込んでいない状態で）再実行してください。照合できないものは導入しないでください",
        },
        { manifest, archive: check },
      );
    }
    if (check.status === "mismatch") {
      return failed(
        "update.archive_mismatch",
        {
          what: "アーカイブが署名済み manifest の記述と一致しません",
          why: check.reason,
          fix: "このアーカイブは導入しないでください。ダウンロードをやり直し、同じ manifest で再確認してください。それでも一致しなければ配布元へ連絡してください",
        },
        { manifest, archive: check },
      );
    }
    const nameMatches = basename(archivePath) === manifest.artifact.name;
    if (!nameMatches) {
      warnings.push(
        `アーカイブのファイル名（${basename(archivePath)}）が manifest の記述（${manifest.artifact.name}）と異なります。内容は一致しています`,
      );
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
    warnings.push(
      "プロジェクト（.agentcms/project.json）が見つからないため、利用中の版は不明です。プロジェクトの中で実行するか --project を指定してください",
    );
  } else if (project.problem) {
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

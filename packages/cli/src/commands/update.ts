import {
  runUpdateCheck,
  type UpdateCheckOptions,
  type UpdateCheckReport,
} from "../updates/update-check.js";
import {
  writePrivateFileAtomically,
  type IoResult,
} from "../updates/safe-io.js";
import { fail, heading, info, ok, warn } from "../ui.js";

/**
 * `agentcms update check` — 署名付き配布物の確認（**適用はしない**）。
 *
 * 出口は 3 つ: 人向けの表示、`--json`（機械可読・秘密なし）、
 * `.agentcms/local/update-check.json`（非公開のローカル記録）。
 * どれも「更新済み」とは言わない。言えるのは「確認できた／できなかった」と
 * 「次工程へ進めるか（admissibility）」まで。
 *
 * ## 終了コード
 *
 * | exit | 意味 |
 * | --- | --- |
 * | 0 | 署名（と実体）が確認でき、`review_required` — 差分・migration のレビューへ進める |
 * | 1 | 確認できなかった（署名不一致・欠落・改変・鍵の問題・アーカイブ不一致） |
 * | 2 | 確認はできたが導入を進めない（`blocked` / `migration_review_required`） |
 */

export interface UpdateCheckCommandOptions extends UpdateCheckOptions {
  json?: boolean;
  /** `.agentcms/local/update-check.json` へ記録しない */
  record?: boolean;
}

export const UPDATE_CHECK_RECORD_REL = ".agentcms/local/update-check.json";
export const UPDATE_CHECK_RECORD_DIR = [".agentcms", "local"] as const;
export const UPDATE_CHECK_RECORD_FILE = "update-check.json";

export const EXIT_VERIFIED_REVIEW = 0;
export const EXIT_NOT_VERIFIED = 1;
export const EXIT_NOT_ADMISSIBLE = 2;

export function exitCodeFor(report: UpdateCheckReport): number {
  if (report.status !== "verified") return EXIT_NOT_VERIFIED;
  return report.admissibility.status === "review_required"
    ? EXIT_VERIFIED_REVIEW
    : EXIT_NOT_ADMISSIBLE;
}

export async function updateCheck(
  opts: UpdateCheckCommandOptions,
): Promise<number> {
  const report = await runUpdateCheck(opts);

  let recordProblem: string | null = null;
  if (opts.record !== false && report.project) {
    const written = writeUpdateCheckRecord(report.project.root, report);
    if (!written.ok) recordProblem = written.reason;
  }

  if (opts.json) {
    const out = toJsonOutput(report);
    if (recordProblem) out.record_problem = recordProblem;
    console.log(JSON.stringify(out, null, 2));
    return exitCodeFor(report);
  }

  render(report);
  if (recordProblem) {
    warn(
      `ローカル記録（${UPDATE_CHECK_RECORD_REL}）は書きませんでした: ${recordProblem}`,
    );
  }
  return exitCodeFor(report);
}

/* ---------------- 表示 ---------------- */

const VERDICT_TEXT: Record<string, string> = {
  newer: "新しい版があります",
  same: "いま使っている版と同じです",
  older: "候補はいま使っている版より古い版です",
  unknown:
    "利用中の版が分からないため比較できません（「最新」とは判定していません）",
};

function render(report: UpdateCheckReport): void {
  heading(
    `agentcms update check — 配布物の署名確認（この command は何も適用しません）`,
  );

  if (report.status === "failed") {
    if (report.manifest) {
      ok(
        `署名: 検証済み（候補 v${report.manifest.version}）— ただし下記の理由で導入できません`,
      );
    }
    fail(report.failure);
    console.log("");
    info(`この CLI: v${report.cli_version}`);
    renderCurrent(report.project);
    info("まだ何も適用していません。project.json も変更していません");
    return;
  }

  const m = report.manifest;
  ok(
    `署名: 検証済み（Ed25519 / 信頼鍵 ${shortFingerprint(report.trusted_key_fingerprint)}）`,
  );
  if (report.archive.status === "verified") {
    ok(
      `アーカイブ: 実体照合済み（${m.artifact.name} / ${report.archive.bytes.toLocaleString("en-US")} bytes / sha256 一致）`,
    );
  } else {
    warn(
      "アーカイブ: 未照合（--archive 未指定）。署名は確認できましたが、手元の配布物（.tgz）が manifest の記述どおりかは確認していません",
    );
  }
  console.log("");
  info(`この CLI: v${report.cli_version}`);
  renderCurrent(report.project);
  info(
    `候補版: v${m.version}（${m.artifact.name}、発行日時: ${m.released_at ?? "記載なし"}）`,
  );
  const verdictLine = `判定: ${VERDICT_TEXT[report.verdict] ?? report.verdict}`;
  if (report.verdict === "newer") ok(verdictLine);
  else if (report.verdict === "same") info(verdictLine);
  else warn(verdictLine);

  const c = report.compatibility;
  const pair = (cur: number | null, cand: number): string =>
    `${cur === null ? "不明" : cur} → ${cand}`;
  const compatLine = `互換性: template_schema_version ${pair(c.template_schema.current, c.template_schema.candidate)}、agent_context_version ${pair(c.agent_context.current, c.agent_context.candidate)}`;
  switch (c.status) {
    case "same":
      info(
        `${compatLine}（契約番号は同じ。DB migration の有無は契約番号では分からないので、差分と database/migrations で確認します）`,
      );
      break;
    case "migration_required":
      warn(`${compatLine}（構造契約が変わります）`);
      break;
    case "candidate_behind":
      warn(`${compatLine}（候補の契約がこのプロジェクトより後退）`);
      break;
    default:
      warn(`${compatLine}（プロジェクト側が読めないため判定できません）`);
  }
  for (const w of report.warnings) warn(w);

  console.log("");
  warn(
    "まだ何も適用していません。core/ の差し替え・migrate・.agentcms/project.json の kit_version 更新はどれも行っていません",
  );
  console.log("");

  const a = report.admissibility;
  switch (a.status) {
    case "blocked":
      fail({
        what: "導入を進めません（署名は確認できましたが、導入可能ではありません）",
        why: a.reasons.join(" / "),
        fix: "上の理由を解消してから再実行してください。理由が解消できないものは、この配布物を導入しないでください",
      });
      return;
    case "migration_review_required":
      warn("移行確認待ち: 次工程へ進む前に確認が必要です");
      for (const r of a.reasons) info(`  - ${r}`);
      info(
        "確認が済んだら、docs/UPGRADING.md 手順 B（隔離した参照プロジェクトで差分を読む）へ進んでください。この段階でも何も適用しません",
      );
      return;
    default:
      ok("導入可能性: 差分・migration をレビューする次工程へ進めます");
      for (const r of a.reasons) info(`  - ${r}`);
      console.log("");
      info("次の手順（docs/UPGRADING.md 手順 B〜C）:");
      if (!a.archive_verified) {
        info(
          "  0. ダウンロードした .tgz を --archive に付けて再実行し、実体を照合する（未照合のまま導入しない）",
        );
      }
      info(
        "  1. 作業ツリーをコミットし、バックアップ（DB + メディア）を取って verify する",
      );
      info(
        "  2. 隔離した場所に新しい版で参照プロジェクトを生成し、core/ と database/migrations の差分を読む",
      );
      info(
        "  3. 差分と移行手順を読んだうえで、あなたが core/ を差し替え、agentcms verify を通す",
      );
      info(
        "  4. 通ったら .agentcms/project.json の kit_version を新しい版へ書き換えてコミットする",
      );
  }
}

function renderCurrent(project: UpdateCheckReport["project"]): void {
  if (!project) {
    warn(
      "利用中: 不明（プロジェクトが見つかりません。--project <dir> で指定できます）",
    );
    return;
  }
  if (project.kit_version === null) {
    warn(
      `利用中: 不明（${project.problem ?? ".agentcms/project.json を読めません"}）`,
    );
    return;
  }
  info(
    `利用中: v${project.kit_version}（.agentcms/project.json。template_schema_version ${project.template_schema_version ?? "不明"} / agent_context_version ${project.agent_context_version ?? "不明"}）`,
  );
}

function shortFingerprint(fp: string): string {
  const hex = fp.replace(/^sha256:/, "");
  return `sha256:${hex.slice(0, 16)}…`;
}

/* ---------------- JSON / 記録 ---------------- */

/**
 * 機械可読な出力。**入っていないもの**: 鍵の中身、URL。
 * 失敗時は成功時の項目（verdict / candidate / admissibility）を持たない —
 * 過去の成功と混ざらないように。
 */
export function toJsonOutput(
  report: UpdateCheckReport,
): Record<string, unknown> {
  const project = report.project
    ? {
        kit_version: report.project.kit_version,
        template_schema_version: report.project.template_schema_version,
        agent_context_version: report.project.agent_context_version,
        ...(report.project.problem ? { problem: report.project.problem } : {}),
      }
    : null;
  if (report.status === "failed") {
    return {
      status: "failed",
      applied: false,
      cli_version: report.cli_version,
      code: report.code,
      what: report.failure.what,
      why: report.failure.why,
      fix: report.failure.fix,
      project,
      ...(report.manifest
        ? { candidate_version: report.manifest.version, signature: "verified" }
        : { signature: "not_verified" }),
      ...(report.archive ? { archive: report.archive.status } : {}),
    };
  }
  return {
    status: "verified",
    applied: false,
    cli_version: report.cli_version,
    signature: "verified",
    trusted_key_fingerprint: report.trusted_key_fingerprint,
    candidate: {
      version: report.manifest.version,
      artifact: report.manifest.artifact,
      compatibility: report.manifest.compatibility,
      released_at: report.manifest.released_at ?? null,
    },
    archive:
      report.archive.status === "verified"
        ? {
            status: "verified",
            bytes: report.archive.bytes,
            sha256: report.archive.sha256,
            name_matches: report.archive.name_matches,
          }
        : { status: "not_checked" },
    project,
    verdict: report.verdict,
    compatibility: report.compatibility,
    admissibility: report.admissibility,
    warnings: report.warnings,
  };
}

/**
 * ローカル記録の本文。`toJsonOutput` から **絶対パスを含み得る項目（why / fix）を落とす**。
 * 失敗の記録は `code` / `what` だけを持ち、成功の項目は持たない。
 */
export function toRecordBody(
  report: UpdateCheckReport,
  now: Date = new Date(),
): Record<string, unknown> {
  const out = toJsonOutput(report);
  delete out.why;
  delete out.fix;
  return {
    format_version: 1,
    checked_at: now.toISOString(),
    ...out,
  };
}

/**
 * `.agentcms/local/update-check.json` を原子的に書く。
 *
 * link は辿らない（親ディレクトリ・最終保存先・tmp のどれも）。tmp 名は乱数で、
 * O_EXCL|O_NOFOLLOW で作る。失敗しても既存の記録はそのまま残り、tmp は消える。
 * 詳細は `safe-io.ts`。
 */
export function writeUpdateCheckRecord(
  root: string,
  report: UpdateCheckReport,
  tmpTokenForTest?: string,
): IoResult<{ path: string }> {
  return writePrivateFileAtomically({
    root,
    dirSegments: UPDATE_CHECK_RECORD_DIR,
    fileName: UPDATE_CHECK_RECORD_FILE,
    content: `${JSON.stringify(toRecordBody(report), null, 2)}\n`,
    tmpTokenForTest,
  });
}

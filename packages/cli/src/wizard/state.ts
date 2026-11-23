import {
  chmodSync,
  mkdirSync,
  readFileSync,
  renameSync,
  writeFileSync,
} from "node:fs";
import { homedir } from "node:os";
import { dirname, isAbsolute, join } from "node:path";

/**
 * Setup Wizard の再開状態（Phase 8.5-B）。
 *
 * 保存するのは「どこまで安全に進んだか」だけ。
 * 禁止: admin password / session token / API キーなどの秘密情報。
 * 生成された認証情報は project の .env が単一情報源であり、ここには複製しない。
 */

/** 再開可能な地点。実行中の途中状態は保存しない（安全に戻れる地点のみ） */
export type SetupStage = "project_selected" | "created" | "ready";

export interface SetupState {
  /** フォーマット版（将来の移行判定用） */
  version: 1;
  projectPath: string;
  projectName: string;
  siteName: string;
  /** 最後に到達した安全な地点 */
  stage: SetupStage;
  /** ISO 8601 */
  createdAt: string;
  updatedAt: string;
}

const STAGE_ORDER: SetupStage[] = ["project_selected", "created", "ready"];

export function setupStateDir(home = homedir()): string {
  return join(home, ".agentcms");
}

export function setupStatePath(home = homedir()): string {
  return join(setupStateDir(home), "setup-state.json");
}

export function readSetupState(home = homedir()): SetupState | null {
  try {
    const raw = readFileSync(setupStatePath(home), "utf8");
    const parsed: unknown = JSON.parse(raw);
    return isSetupState(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

/**
 * 状態を書き出す（所有者のみ読み書き可）。
 * 秘密情報を含み得ないフォーマットだが、project の場所は個人情報に近いため 0600 で保護する。
 */
export function writeSetupState(state: SetupState, home = homedir()): void {
  const path = setupStatePath(home);
  mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
  const tmp = `${path}.tmp`;
  writeFileSync(tmp, `${JSON.stringify(state, null, 2)}\n`, {
    encoding: "utf8",
    mode: 0o600,
  });
  renameSync(tmp, path);
  try {
    chmodSync(path, 0o600);
  } catch {
    // 権限変更に失敗しても致命的ではない（Windows 等）
  }
}

export function clearSetupState(home = homedir()): void {
  try {
    writeFileSync(setupStatePath(home), "", { encoding: "utf8", mode: 0o600 });
  } catch {
    // 状態ファイルが無い場合は何もしない
  }
}

/** stage を前進させる（後退はさせない。再開判定が巻き戻るのを防ぐ） */
export function advanceStage(
  current: SetupStage,
  next: SetupStage,
): SetupStage {
  return STAGE_ORDER.indexOf(next) > STAGE_ORDER.indexOf(current)
    ? next
    : current;
}

function isSetupState(value: unknown): value is SetupState {
  if (typeof value !== "object" || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    v.version === 1 &&
    typeof v.projectPath === "string" &&
    // 相対パス・NUL 混入の state は cwd 依存 / 不正操作の余地があるため採用しない
    isAbsolute(v.projectPath) &&
    !v.projectPath.includes("\0") &&
    typeof v.projectName === "string" &&
    typeof v.siteName === "string" &&
    typeof v.stage === "string" &&
    STAGE_ORDER.includes(v.stage as SetupStage) &&
    typeof v.createdAt === "string" &&
    typeof v.updatedAt === "string"
  );
}

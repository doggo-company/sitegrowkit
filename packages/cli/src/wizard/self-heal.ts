import { existsSync, lstatSync, rmSync } from "node:fs";
import { isAbsolute, join, resolve } from "node:path";

import { pnpmCommand } from "../node-runtime.js";
import { runInheritCapture } from "../run.js";

/**
 * F-A1 自動復旧（Phase 8.5-B）。
 *
 * 症状: host 側で入れた node_modules をコンテナが読むと、プラットフォーム固有の
 * ネイティブバイナリ（rolldown / lightningcss / esbuild）が一致せず
 * "Cannot find native binding" 等で失敗する。
 *
 * 方針: **無条件に node_modules を消さない**。上記のシグネチャを検出したときだけ、
 * プロジェクトの marker と実体（symlink でない実ディレクトリ）を確認してから作り直し、
 * 再試行は 1 回のみ。原因表示は GUI の Primary UI には出さない（詳細ログ側に留める）。
 */

const NATIVE_BINDING_PATTERNS = [
  /Cannot find native binding/i,
  /Failed to load native binding/i,
  /@rolldown\/binding-[a-z0-9-]+/i,
  /lightningcss\.[a-z0-9-]+\.node/i,
  /@esbuild\/[a-z0-9-]+.*(?:not (?:found|supported)|incompatible)/i,
  /was compiled against a different Node\.js version/i,
];

/** ログがプラットフォーム不一致（= 作り直しで直る種類）を示しているか */
export function isNativeBindingMismatch(log: string): boolean {
  return NATIVE_BINDING_PATTERNS.some((re) => re.test(log));
}

export interface RemovalResult {
  removed: boolean;
  reason?: string;
}

/**
 * node_modules の安全な削除。
 * project marker が無い / symlink / ディレクトリでない場合は削除しない。
 */
export function removeNodeModules(root: string): RemovalResult {
  if (!isAbsolute(root)) {
    return { removed: false, reason: "project root が絶対パスではありません" };
  }
  const projectRoot = resolve(root);
  if (
    !existsSync(join(projectRoot, "artisan")) ||
    !existsSync(join(projectRoot, "package.json"))
  ) {
    return {
      removed: false,
      reason:
        "CMS プロジェクトの marker（artisan + package.json）が見つかりません",
    };
  }
  const target = join(projectRoot, "node_modules");
  if (!existsSync(target)) {
    return { removed: false, reason: "node_modules が存在しません" };
  }
  const stat = lstatSync(target);
  if (stat.isSymbolicLink()) {
    return {
      removed: false,
      reason:
        "node_modules が symlink です — リンク先の巻き込みを避けるため削除しません",
    };
  }
  if (!stat.isDirectory()) {
    return {
      removed: false,
      reason: "node_modules がディレクトリではありません",
    };
  }
  rmSync(target, { recursive: true, force: true });
  return { removed: true };
}

export interface HealForward {
  signal?: AbortSignal;
  onStdout?: (line: string) => void;
  onStderr?: (line: string) => void;
}

export interface HealResult {
  healed: boolean;
  /** 復旧を試みなかった / できなかった理由（詳細ログ用） */
  reason?: string;
}

/**
 * 依存を作り直す（コンテナ内 pnpm install）。
 * 呼び出し側は isNativeBindingMismatch が真のときにだけ呼ぶこと。
 */
export async function rebuildDependencies(
  root: string,
  forward: HealForward = {},
): Promise<HealResult> {
  const removal = removeNodeModules(root);
  if (!removal.removed) {
    return { healed: false, reason: removal.reason };
  }
  const install = pnpmCommand(root, ["install"]);
  const result = await runInheritCapture(install.cmd, install.args, root, {
    signal: forward.signal,
    onStdout: forward.onStdout,
    onStderr: forward.onStderr,
  });
  if (result.code !== 0) {
    return {
      healed: false,
      reason: "作り直し後の依存インストールに失敗しました",
    };
  }
  return { healed: true };
}

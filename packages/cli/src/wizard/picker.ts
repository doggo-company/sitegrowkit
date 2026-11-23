import { existsSync } from "node:fs";
import { homedir, platform } from "node:os";

import { run } from "../run.js";
import { isWsl } from "../runtime-user.js";

/**
 * OS ネイティブのフォルダ選択（Phase 8.5-B）。
 *
 * ブラウザの File System Access API は「backend が使える絶対パス」を返せないため、
 * 選択はローカル backend 側から OS のダイアログを開いて行う。
 *
 * セキュリティ: 実行は **固定のスクリプト + 引数配列**のみ。
 * ユーザー入力をスクリプト文字列へ連結しない（shell も使わない）。
 */

export type PickerKind = "macos" | "windows-wsl" | "unavailable";

export function pickerKind(): PickerKind {
  if (platform() === "darwin") return "macos";
  if (isWsl()) return "windows-wsl";
  return "unavailable";
}

export interface PickResult {
  ok: boolean;
  path?: string;
  /** 利用者がキャンセルした（エラーではない） */
  canceled?: boolean;
  reason?: string;
}

/** macOS: osascript の choose folder（引数はスクリプト本体のみ・入力は連結しない） */
const MACOS_SCRIPT =
  'try\n set f to choose folder with prompt "プロジェクトの保存場所を選んでください"\n POSIX path of f\non error number -128\n return "__CANCELED__"\nend try';

/** Windows(WSL2 経由): PowerShell の FolderBrowserDialog（固定スクリプト） */
const WINDOWS_SCRIPT = [
  "Add-Type -AssemblyName System.Windows.Forms | Out-Null;",
  "$d = New-Object System.Windows.Forms.FolderBrowserDialog;",
  "$d.Description = 'Select a folder for your SiteGrowKit project';",
  "if ($d.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) { Write-Output $d.SelectedPath } else { Write-Output '__CANCELED__' }",
].join(" ");

export async function pickFolder(): Promise<PickResult> {
  const kind = pickerKind();
  if (kind === "macos") {
    const r = await run("osascript", ["-e", MACOS_SCRIPT]);
    if (r.code !== 0) {
      return { ok: false, reason: "picker_failed" };
    }
    const out = r.stdout.trim();
    if (out === "__CANCELED__" || out === "")
      return { ok: false, canceled: true };
    return { ok: true, path: out.replace(/\/$/, "") };
  }

  if (kind === "windows-wsl") {
    const r = await run("powershell.exe", [
      "-NoProfile",
      "-NonInteractive",
      "-Command",
      WINDOWS_SCRIPT,
    ]);
    if (r.code !== 0) return { ok: false, reason: "picker_failed" };
    const out = r.stdout.trim();
    if (out === "__CANCELED__" || out === "")
      return { ok: false, canceled: true };
    const converted = await windowsPathToWsl(out);
    if (!converted) return { ok: false, reason: "path_conversion_failed" };
    return { ok: true, path: converted };
  }

  return { ok: false, reason: "unavailable" };
}

/**
 * Windows path を WSL path へ変換する（wslpath は WSL2 標準）。
 * 変換結果が実在することまで確認してから返す。
 */
export async function windowsPathToWsl(
  windowsPath: string,
): Promise<string | null> {
  const r = await run("wslpath", ["-u", windowsPath]);
  if (r.code !== 0) return null;
  const p = r.stdout.trim();
  if (p === "" || !existsSync(p)) return null;
  return p;
}

/** picker が使えない環境向けの案内（Primary UI では path を強調しない） */
export function pickerFallbackHint(): string {
  return `${homedir()} の中に作成します`;
}

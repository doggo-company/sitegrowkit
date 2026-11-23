import { existsSync } from "node:fs";
import { homedir, platform } from "node:os";
import { run } from "../run.js";
import { isWsl } from "../runtime-user.js";
export function pickerKind() {
    if (platform() === "darwin")
        return "macos";
    if (isWsl())
        return "windows-wsl";
    return "unavailable";
}
/** macOS: osascript の choose folder（引数はスクリプト本体のみ・入力は連結しない） */
const MACOS_SCRIPT = 'try\n set f to choose folder with prompt "プロジェクトの保存場所を選んでください"\n POSIX path of f\non error number -128\n return "__CANCELED__"\nend try';
/** Windows(WSL2 経由): PowerShell の FolderBrowserDialog（固定スクリプト） */
const WINDOWS_SCRIPT = [
    "Add-Type -AssemblyName System.Windows.Forms | Out-Null;",
    "$d = New-Object System.Windows.Forms.FolderBrowserDialog;",
    "$d.Description = 'Select a folder for your SiteGrowKit project';",
    "if ($d.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) { Write-Output $d.SelectedPath } else { Write-Output '__CANCELED__' }",
].join(" ");
export async function pickFolder() {
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
        if (r.code !== 0)
            return { ok: false, reason: "picker_failed" };
        const out = r.stdout.trim();
        if (out === "__CANCELED__" || out === "")
            return { ok: false, canceled: true };
        const converted = await windowsPathToWsl(out);
        if (!converted)
            return { ok: false, reason: "path_conversion_failed" };
        return { ok: true, path: converted };
    }
    return { ok: false, reason: "unavailable" };
}
/**
 * Windows path を WSL path へ変換する（wslpath は WSL2 標準）。
 * 変換結果が実在することまで確認してから返す。
 */
export async function windowsPathToWsl(windowsPath) {
    const r = await run("wslpath", ["-u", windowsPath]);
    if (r.code !== 0)
        return null;
    const p = r.stdout.trim();
    if (p === "" || !existsSync(p))
        return null;
    return p;
}
/** picker が使えない環境向けの案内（Primary UI では path を強調しない） */
export function pickerFallbackHint() {
    return `${homedir()} の中に作成します`;
}

// Agent CMS Kit — Windows launcher 診断 probe（Phase 8.5-D.1）
//
// 本番 launcher とは別の**診断専用**バイナリ。
// 「どの段階で止まっているか」を実測で 1 段ずつ確定するためだけに使う。
// 副作用のある処理（runtime staging / server 起動）は一切行わない。
//
// ログは application directory ではなく user-writable な canonical path へ書く:
//   %LOCALAPPDATA%\AgentCMSKit\probe.log
// 失敗した場合は %TEMP% へ fallback し、**ログ失敗で probe 自体を落とさない**。
using System;
using System.Diagnostics;
using System.IO;
using System.Text;

internal static class Probe
{
    private static string logPath;

    private static int Main(string[] args)
    {
        AppDomain.CurrentDomain.UnhandledException += (s, e) =>
        {
            Log("probe.unhandled " + Describe(e.ExceptionObject as Exception));
        };

        try
        {
            OpenLog();
            Log("probe.start");
            Log("  version=8.5-D.1");
            Log("  exe=" + SafeExePath());
            Log("  cwd=" + Directory.GetCurrentDirectory());
            Log("  os=" + Environment.OSVersion.VersionString);
            Log("  is64BitProcess=" + Environment.Is64BitProcess);
            Log("  is64BitOS=" + Environment.Is64BitOperatingSystem);
            Log("  clr=" + Environment.Version);
            Log("  user=" + Environment.UserName);
            Log("probe.log.ready");

            // STEP 2 — wsl.exe の位置（PATH ではなく canonical path も確認）
            string sysRoot = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
            string wslCanonical = Path.Combine(sysRoot, "System32", "wsl.exe");
            Log("wsl.path.canonical=" + wslCanonical + " exists=" + File.Exists(wslCanonical));
            string wsl = File.Exists(wslCanonical) ? wslCanonical : "wsl.exe";

            // STEP 2 — wsl --status
            RunAndLog("wsl.status", wsl, "--status");

            // STEP 3 — distro 一覧（-l -q）
            RunAndLog("wsl.list", wsl, "-l -q");

            // STEP 4 — 副作用のない最小コマンド
            RunAndLog("wsl.echo", wsl, "-- /bin/sh -c \"printf AGENTCMS_WSL_OK\"");

            // STEP 5 — 作業ディレクトリの扱い（A: 既定 / B: --cd ~ / C: 明示 home）
            RunAndLog("wsl.cwd.default", wsl, "-- /bin/sh -c \"pwd\"");
            RunAndLog("wsl.cwd.tilde", wsl, "--cd ~ -- /bin/sh -c \"pwd\"");
            RunAndLog("wsl.cwd.explicit", wsl, "--cd /home -- /bin/sh -c \"pwd\"");

            // STEP 6 — sh -lc と positional argument（$0）の受け渡し
            RunAndLog("wsl.shell.lc", wsl, "-- /bin/sh -lc \"printf ARG0=%s \\\"$0\\\"\" /tmp/agentcms-probe");

            // STEP 6 — stdin へ script を流す方式（多重 quote を避ける案）
            RunStdinAndLog("wsl.shell.stdin", wsl, "-- /bin/sh -s",
                "printf STDIN_OK=%s \"$1\"\n", "/tmp/agentcms-probe");

            // path 変換（空白・日本語を含み得る前提）
            RunAndLog("wsl.wslpath", wsl, "-- wslpath -u \"" + SafeExeDir() + "\"");

            Log("probe.completed");
            return 0;
        }
        catch (Exception ex)
        {
            Log("probe.failed " + Describe(ex));
            return 1;
        }
    }

    private static string SafeExePath()
    {
        try { return Process.GetCurrentProcess().MainModule.FileName; }
        catch { return "(unknown)"; }
    }

    private static string SafeExeDir()
    {
        try { return Path.GetDirectoryName(SafeExePath()); }
        catch { return "(unknown)"; }
    }

    private static void RunAndLog(string evt, string file, string arguments)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = file,
                Arguments = arguments,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                StandardOutputEncoding = Encoding.UTF8,
                StandardErrorEncoding = Encoding.UTF8,
            };
            using (var p = Process.Start(psi))
            {
                string stdout = p.StandardOutput.ReadToEnd();
                string stderr = p.StandardError.ReadToEnd();
                p.WaitForExit(30000);
                Log(evt + " exit=" + (p.HasExited ? p.ExitCode.ToString() : "timeout"));
                Log("  args=" + arguments);
                Log("  stdout=" + Flatten(stdout));
                Log("  stderr=" + Flatten(stderr));
            }
        }
        catch (Exception ex)
        {
            Log(evt + ".exception " + Describe(ex));
        }
    }

    private static void RunStdinAndLog(string evt, string file, string arguments,
                                       string script, string arg1)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = file,
                Arguments = arguments + " \"" + arg1 + "\"",
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardInput = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                StandardOutputEncoding = Encoding.UTF8,
                StandardErrorEncoding = Encoding.UTF8,
            };
            using (var p = Process.Start(psi))
            {
                p.StandardInput.NewLine = "\n";
                p.StandardInput.Write(script);
                p.StandardInput.Close();
                string stdout = p.StandardOutput.ReadToEnd();
                string stderr = p.StandardError.ReadToEnd();
                p.WaitForExit(30000);
                Log(evt + " exit=" + (p.HasExited ? p.ExitCode.ToString() : "timeout"));
                Log("  stdout=" + Flatten(stdout));
                Log("  stderr=" + Flatten(stderr));
            }
        }
        catch (Exception ex)
        {
            Log(evt + ".exception " + Describe(ex));
        }
    }

    private static string Flatten(string s)
    {
        if (string.IsNullOrEmpty(s)) return "(empty)";
        return s.Replace("\r", "").Replace("\n", "\\n").Trim();
    }

    private static string Describe(Exception ex)
    {
        if (ex == null) return "(null exception)";
        return ex.GetType().FullName + ": " + ex.Message + " | " + ex.StackTrace;
    }

    private static void OpenLog()
    {
        try
        {
            string dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AgentCMSKit");
            Directory.CreateDirectory(dir);
            logPath = Path.Combine(dir, "probe.log");
            File.WriteAllText(logPath, "");
        }
        catch
        {
            try
            {
                logPath = Path.Combine(Path.GetTempPath(), "agentcms-probe.log");
                File.WriteAllText(logPath, "");
            }
            catch
            {
                logPath = null; // ログできなくても probe は続行する
            }
        }
    }

    private static void Log(string line)
    {
        if (logPath == null) return;
        try
        {
            File.AppendAllText(
                logPath,
                DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ") + " " + line + "\n");
        }
        catch
        {
            // ログ失敗で probe を落とさない
        }
    }
}

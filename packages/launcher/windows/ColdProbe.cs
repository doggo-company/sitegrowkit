// Agent CMS Kit — cold-start single-WSL probe（Phase 8.5-D.5 / STEP 2-3）
//
// 目的: **WSL が cold な状態**から
//   Windows GUI process → 1 回だけの wsl.exe → /bin/sh -s（stdin script）
//   → 同一 Linux process 内で wslpath → 出力 → exit 0
// が成立するかだけを確かめる。runtime staging や Wizard 起動は**行わない**。
//
// Windows 側 process runner の各段階を 1 行ずつ記録し、
// launcher が「例外も残さず消える」事象を statement 単位で特定できるようにする。
using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading;

internal static class ColdProbe
{
    private static string logPath;

    /// 固定スクリプト。動的値は $1（Windows パス）だけで、本文へ連結しない。
    private const string Script =
        "printf 'probe.start argc=%s\\n' \"$#\"\n" +
        "DIST_WIN=\"$1\"\n" +
        "printf 'probe.arg=%s\\n' \"$DIST_WIN\"\n" +
        "DIST_LINUX=$(wslpath -u \"$DIST_WIN\")\n" +
        "printf 'probe.wslpath.exit=%s\\n' \"$?\"\n" +
        "printf 'probe.linux=%s\\n' \"$DIST_LINUX\"\n" +
        "printf 'probe.pwd=%s\\n' \"$(pwd)\"\n" +
        "printf 'probe.exists=%s\\n' \"$([ -d \"$DIST_LINUX\" ] && echo yes || echo no)\"\n" +
        "printf 'probe.ok\\n'\n";

    private static int Main(string[] args)
    {
        AppDomain.CurrentDomain.UnhandledException += (s, e) =>
            Log("probe.unhandled " + Describe(e.ExceptionObject as Exception));
        AppDomain.CurrentDomain.ProcessExit += (s, e) => Log("probe.processexit");

        OpenLog();
        int code = 1;
        try
        {
            Log("probe.begin");
            code = Run(args.Length > 0 ? args[0] : AppContext.BaseDirectory);
        }
        catch (Exception ex)
        {
            Log("probe.exception " + Describe(ex));
        }
        finally
        {
            Log("probe.finally");
        }
        return code;
    }

    private static int Run(string distPath)
    {
        string wsl = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.Windows),
            "System32", "wsl.exe");
        Log("wsl.exe=" + wsl + " exists=" + File.Exists(wsl));
        Log("dist=" + distPath);

        // **1 回だけ** wsl.exe を起動する。distro は明示指定（既定に依存しない）
        var psi = new ProcessStartInfo
        {
            FileName = wsl,
            Arguments = "-d Ubuntu-24.04 --exec /bin/sh -s -- " + Quote(distPath),
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            StandardOutputEncoding = Encoding.UTF8,
            StandardErrorEncoding = Encoding.UTF8,
        };
        Log("wsl.args=" + psi.Arguments);

        var sw = Stopwatch.StartNew();
        Log("wsl.process.starting");
        Process p = Process.Start(psi);
        Log("wsl.process.started pid=" + p.Id);

        var stdout = new StringBuilder();
        var stderr = new StringBuilder();
        p.OutputDataReceived += (s, e) => { if (e.Data != null) lock (stdout) stdout.AppendLine(e.Data); };
        p.ErrorDataReceived += (s, e) => { if (e.Data != null) lock (stderr) stderr.AppendLine(e.Data); };
        p.BeginOutputReadLine();
        p.BeginErrorReadLine();
        Log("wsl.output.reading");

        Log("wsl.stdin.begin");
        p.StandardInput.NewLine = "\n";
        p.StandardInput.Write(Script);
        p.StandardInput.Flush();
        p.StandardInput.Close();
        Log("wsl.stdin.closed");

        Log("wsl.wait.begin");
        bool exited = p.WaitForExit(180000);
        sw.Stop();
        Log("wsl.wait.completed exited=" + exited +
            " exit=" + (exited ? p.ExitCode.ToString() : "timeout") +
            " elapsedMs=" + sw.ElapsedMilliseconds);

        Thread.Sleep(300); // 非同期リーダーの取りこぼしを避ける
        lock (stdout) Log("wsl.stdout=" + Flatten(stdout.ToString()));
        lock (stderr) Log("wsl.stderr=" + Flatten(stderr.ToString()));

        return exited ? p.ExitCode : 1;
    }

    private static string Quote(string s)
    {
        // 空白・backslash を含む値は quote する（bare だと WSL 側で backslash が失われる）
        if (s.IndexOfAny(new[] { ' ', '\t', '"', '\\' }) < 0) return s;
        return "\"" + s.Replace("\"", "\\\"") + "\"";
    }

    private static string Flatten(string s)
    {
        if (string.IsNullOrEmpty(s)) return "(empty)";
        return s.Replace("\r", "").Replace("\n", " | ").Trim();
    }

    private static string Describe(Exception ex)
    {
        return ex == null ? "(null)" :
            ex.GetType().FullName + ": " + ex.Message + " | " + ex.StackTrace;
    }

    private static void OpenLog()
    {
        try
        {
            string dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AgentCMSKit");
            Directory.CreateDirectory(dir);
            logPath = Path.Combine(dir, "coldprobe.log");
            File.WriteAllText(logPath, "");
        }
        catch
        {
            try { logPath = Path.Combine(Path.GetTempPath(), "agentcms-coldprobe.log"); File.WriteAllText(logPath, ""); }
            catch { logPath = null; }
        }
    }

    private static void Log(string line)
    {
        if (logPath == null) return;
        try
        {
            File.AppendAllText(logPath,
                DateTime.UtcNow.ToString("HH:mm:ss.fff") + " " + line + "\n");
        }
        catch { }
    }
}

// Agent CMS Kit — Windows launcher（Phase 8.5-D / D.1）
//
// Explorer からのダブルクリックで実行される薄いネイティブ stub。
// wsl.exe 経由で WSL2 内の「同梱 Linux Node runtime + 同梱 CLI」を起動する。
//
// 設計上の固定事項:
//  - /target:winexe（GUI subsystem）でビルドするため **コンソール窓を出さない**
//  - 子プロセスも CreateNoWindow で起動する
//  - Windows 側に Node / npm / Git / wslu を要求しない
//  - 新しい第三者ランタイムを利用者へ要求しない
//    （.NET Framework 4.x は Windows 11 に標準搭載。in-box csc.exe でビルドする）
//  - **shell script は stdin で渡す**（F-D2）。
//    `sh -lc "script" arg` 形式は WSL 経由だと $0 が /bin/bash になり
//    positional argument が渡らないことを実測で確認したため使わない。
//    script 本文は固定テキストで、動的値は $1 として引数で受け取る（injection の遮断）
//  - 最初の実行可能行から %LOCALAPPDATA%\AgentCMSKit\launcher.log へ記録する
using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Windows.Forms;

/// 起動直後に出す小さな進捗ウィンドウ（Phase 8.5-D.6）。
/// 初回は runtime の準備に時間がかかるため、無反応に見せないためだけの最小 UI。
/// cancel / settings 等は持たない。専門用語（WSL / Node / runtime / staging）は出さない。
internal sealed class ProgressWindow : Form
{
    private readonly Label status;

    internal ProgressWindow()
    {
        Text = "SiteGrowKit";
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new System.Drawing.Size(420, 150);
        BackColor = System.Drawing.Color.White;

        var title = new Label
        {
            Text = "SiteGrowKit",
            Font = new System.Drawing.Font(System.Drawing.SystemFonts.MessageBoxFont.FontFamily, 13F,
                System.Drawing.FontStyle.Bold),
            AutoSize = true,
            Location = new System.Drawing.Point(24, 22),
        };
        status = new Label
        {
            Text = "起動の準備をしています…",
            AutoSize = false,
            Size = new System.Drawing.Size(372, 40),
            Location = new System.Drawing.Point(24, 56),
        };
        var bar = new ProgressBar
        {
            Style = ProgressBarStyle.Marquee,
            MarqueeAnimationSpeed = 30,
            Location = new System.Drawing.Point(24, 104),
            Size = new System.Drawing.Size(372, 14),
        };

        Controls.Add(title);
        Controls.Add(status);
        Controls.Add(bar);
    }

    internal void SetStatus(string text)
    {
        if (IsDisposed) return;
        try
        {
            if (InvokeRequired) BeginInvoke((MethodInvoker)(() => status.Text = text));
            else status.Text = text;
        }
        catch { /* UI 更新の失敗で起動処理を止めない */ }
    }

    internal void CloseSafely()
    {
        if (IsDisposed) return;
        try
        {
            if (InvokeRequired) BeginInvoke((MethodInvoker)Close);
            else Close();
        }
        catch { }
    }
}

internal static class Launcher
{
    private const string AppName = "SiteGrowKit";
    private static ProgressWindow progress;
    private static string logPath;

    /// <summary>
    /// WSL 側で実行する固定スクリプト。動的値は $1（配布物の場所）だけ。
    /// ユーザー入力は一切連結しない。
    /// </summary>
    /// 配布物が /mnt/c（DrvFS）上にあると executable bit が保証されないため、
    /// スクリプトを直接 exec せず **/bin/sh に渡して実行する**（実測で確定）。
    /// acceptance 環境の distro を明示指定する（既定 distro へ依存しない）
    private const string Distro = "Ubuntu-24.04";

    /// 単一の WSL 起動で実行する固定スクリプト。
    /// 動的値は $1（Windows パス）だけで、本文へ文字列連結しない。
    /// Windows → Linux のパス変換も **この同じ Linux process 内**で行う。
    private const string BootstrapScript =
        "DIST_WIN=\"$1\"\n" +
        "DIST_LINUX=$(wslpath -u \"$DIST_WIN\") || exit 1\n" +
        "exec /bin/sh \"$DIST_LINUX/launcher/start.sh\" \"$DIST_LINUX\"\n";

    [STAThread]
    private static int Main()
    {
        AppDomain.CurrentDomain.UnhandledException += (s, e) =>
            Log("launcher.unhandled " + Describe(e.ExceptionObject as Exception));

        OpenLog();
        Log("launcher.start");

        Application.EnableVisualStyles();
        progress = new ProgressWindow();
        int result = 1;

        // 起動処理は別スレッドで実行し、UI スレッドを塞がない
        // （ウィンドウが「応答なし」にならず、移動もできる）
        var worker = new System.Threading.Thread(() =>
        {
            try
            {
                Log("  os=" + Environment.OSVersion.VersionString +
                    " arch64=" + Environment.Is64BitProcess);
                Log("  cwd=" + SafeCwd());
                Log("launcher.log.ready");
                result = Run();
            }
            catch (Exception ex)
            {
                Log("launcher.failed " + Describe(ex));
                progress.CloseSafely();
                Alert("Agent CMS Kit を起動できませんでした。\n\n" +
                      "詳細は次のファイルに記録されています:\n" + (logPath ?? "(ログなし)"));
                result = 1;
            }
            finally
            {
                Log("launcher.finally");
                progress.CloseSafely();
            }
        });
        worker.IsBackground = true;
        worker.SetApartmentState(System.Threading.ApartmentState.STA);
        worker.Start();

        Log("progress.window.showing");
        Application.Run(progress);
        Log("progress.window.closed");

        // 進捗ウィンドウを閉じても **プロセスは終了させない**（Phase 8.5-D.8 / F-D5）。
        // ここで抜けると launcher が終了 → wsl.exe の stdout が閉じ → tee が EPIPE →
        // Wizard(node) が **SIGPIPE(141) で死ぬ**（実測: setup 途中で落ちた）。
        // ウィンドウ無しの supervisor として wsl.exe の終了まで待ち続ける
        // （D.7 で 10 分生存を確認した挙動と同じ）。
        Log("launcher.supervising");
        worker.Join();
        Log("launcher.exit code=" + result);
        return result;
    }

    private static int Run()
    {
        string baseDir = ResolveDistributionDir();
        if (baseDir == null)
        {
            Log("exe.probe.all.failed");
            Alert("Agent CMS Kit の場所を特定できませんでした。\n" +
                  "詳細は次のファイルに記録されています:\n" + (logPath ?? "(ログなし)"));
            return 1;
        }
        // 末尾の区切りは quote の解釈を壊すため落とす（実測: 末尾 \ が閉じ引用符を escape する）
        baseDir = baseDir.TrimEnd(Path.DirectorySeparatorChar);
        Log("distribution.path.ok");

        string runtimeDir = Path.Combine(baseDir, "runtime");
        string appEntry = Path.Combine(baseDir, "app", "dist", "index.js");
        if (!Directory.Exists(runtimeDir) || !File.Exists(appEntry))
        {
            Log("launcher.distribution.incomplete");
            Alert("Agent CMS Kit のファイルが見つかりませんでした。\n" +
                  "ダウンロードしたファイルを展開し直してください。");
            return 1;
        }
        Log("launcher.distribution.ok");

        string wsl = ResolveWsl();
        Log("wsl.path=" + wsl);

        // **wsl.exe は 1 回だけ起動する**（Phase 8.5-D.5）。
        // Windows → WSL の境界を何度も跨がず、wslpath も同じ Linux process 内で行う。
        // distro は明示指定し、既定 distro（docker-desktop 等）に依存しない。
        var psi = new ProcessStartInfo
        {
            FileName = wsl,
            Arguments = "-d " + Distro + " --exec /bin/sh -s -- " + QuoteIfNeeded(baseDir),
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            StandardOutputEncoding = Encoding.UTF8,
            StandardErrorEncoding = Encoding.UTF8,
        };
        Log("wsl.args=" + psi.Arguments);

        progress.SetStatus("初回の準備をしています。少し時間がかかる場合があります。");
        Log("wsl.process.starting");
        using (Process p = Process.Start(psi))
        {
            Log("wsl.process.started pid=" + p.Id);
            p.OutputDataReceived += (s2, e) =>
            {
                if (e.Data == null) return;
                Log("wsl.out " + e.Data);
                // Wizard が起動しブラウザが開いたら進捗ウィンドウを閉じる
                if (e.Data.Contains("ブラウザで") || e.Data.Contains("URL"))
                {
                    progress.CloseSafely();
                }
            };
            p.ErrorDataReceived += (s2, e) => { if (e.Data != null) Log("wsl.err " + e.Data); };
            p.BeginOutputReadLine();
            p.BeginErrorReadLine();
            Log("wsl.output.reading");

            Log("wsl.stdin.begin");
            p.StandardInput.NewLine = "\n";
            p.StandardInput.Write(BootstrapScript);
            p.StandardInput.Flush();
            p.StandardInput.Close();
            Log("wsl.stdin.closed");

            // Wizard が終わるまで hidden supervisor として待機する。
            // Windows launcher → wsl.exe → bundled Node の lifecycle を 1 本に保つ。
            Log("wsl.wait.begin");
            p.WaitForExit();
            Log("wsl.wait.completed exit=" + p.ExitCode);
            if (p.ExitCode != 0)
            {
                progress.CloseSafely();
                Alert("SiteGrowKitを起動できませんでした。\n\n" +
                      "WSL2とUbuntu 24.04の初回設定、Docker Desktopの起動を確認してください。\n" +
                      "詳しくは同梱のREADME_FIRST.htmlを開いてください。");
                return p.ExitCode;
            }
        }
        Log("launcher.ready");
        return 0;
    }

    private static string ResolveWsl()
    {
        string canonical = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.Windows),
            "System32", "wsl.exe");
        return File.Exists(canonical) ? canonical : "wsl.exe";
    }

    private static ProcessStartInfo NewPsi(string file, string arguments)
    {
        // encoding は **redirect する呼び出し側で設定する**。
        // redirect していない状態で指定すると InvalidOperationException になる（実測）
        return new ProcessStartInfo
        {
            FileName = file,
            Arguments = arguments,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
    }

    /// <summary>
    /// 引数配列を Windows のコマンドライン規則に従って組み立てる。
    ///
    /// **引用が不要な引数は引用しない**。wsl.exe は引用符つきの `--` を
    /// option terminator として解釈せず、リテラル引数として扱うため
    /// （実測: 引用ありだと wslpath 呼び出しが失敗する — F-D2）。
    /// 空白等を含む値だけを quote し、backslash は引用符の直前のみ 2 倍にする。
    /// </summary>
    private static string BuildArguments(string[] args)
    {
        var sb = new StringBuilder();
        foreach (string arg in args)
        {
            if (sb.Length > 0) sb.Append(' ');
            sb.Append(QuoteIfNeeded(arg));
        }
        return sb.ToString();
    }

    private static string QuoteIfNeeded(string arg)
    {
        // backslash を含む値（Windows パス）は **必ず quote する**。
        // bare のまま渡すと WSL の引数処理で backslash が失われることを実測で確認
        // （`wslpath -u C:\Users\...` → `C:Users...` になり exit 1）。
        // 一方 `--` や `-u` のような単純トークンは quote しない。
        if (arg.Length > 0 && arg.IndexOfAny(new[] { ' ', '\t', '"', '\\' }) < 0)
        {
            return arg;
        }

        var sb = new StringBuilder();
        sb.Append('"');
        int backslashes = 0;
        foreach (char c in arg)
        {
            if (c == '\\') { backslashes++; continue; }
            if (c == '"')
            {
                sb.Append('\\', backslashes * 2 + 1);
                sb.Append('"');
                backslashes = 0;
                continue;
            }
            sb.Append('\\', backslashes);
            backslashes = 0;
            sb.Append(c);
        }
        sb.Append('\\', backslashes * 2);
        sb.Append('"');
        return sb.ToString();
    }

    private static string SafeCwd()
    {
        try { return Directory.GetCurrentDirectory(); }
        catch { return "(unknown)"; }
    }

    /// <summary>
    /// 配布物ディレクトリの解決（Phase 8.5-D.2 / STEP 10）。
    ///
    /// 候補 API を **1 つずつ独立に試して結果をログへ残し**、
    /// 実在するディレクトリを返した最初の候補を採用する。
    /// cwd（double-click 時は %USERPROFILE% になる）には依存しない。
    /// </summary>
    private static string ResolveDistributionDir()
    {
        Log("exe.probe.begin");
        string chosen = null;

        // Candidate A: Process.MainModule.FileName
        chosen = TryCandidate("mainmodule", chosen, () =>
        {
            using (var p = Process.GetCurrentProcess())
            {
                return Path.GetDirectoryName(p.MainModule.FileName);
            }
        });

        // Candidate B: Assembly.GetExecutingAssembly().Location
        chosen = TryCandidate("assembly", chosen, () =>
        {
            string loc = System.Reflection.Assembly.GetExecutingAssembly().Location;
            return string.IsNullOrEmpty(loc) ? null : Path.GetDirectoryName(loc);
        });

        // Candidate C: AppDomain.CurrentDomain.BaseDirectory
        chosen = TryCandidate("basedir", chosen, () =>
        {
            string dir = AppDomain.CurrentDomain.BaseDirectory;
            return string.IsNullOrEmpty(dir) ? null : Path.GetFullPath(dir);
        });

        Log("exe.probe.selected=" + (chosen == null ? "(none)" : "ok"));
        return chosen;
    }

    private static string TryCandidate(string name, string alreadyChosen,
                                       Func<string> resolve)
    {
        try
        {
            string dir = resolve();
            if (string.IsNullOrEmpty(dir))
            {
                Log("exe.probe." + name + ".failed reason=empty");
                return alreadyChosen;
            }
            bool exists = Directory.Exists(dir);
            Log("exe.probe." + name + ".ok exists=" + exists +
                " length=" + dir.Length);
            // 最初に「実在するディレクトリ」を返した候補を採用する
            return alreadyChosen == null && exists ? dir : alreadyChosen;
        }
        catch (Exception ex)
        {
            Log("exe.probe." + name + ".failed " + Describe(ex));
            return alreadyChosen;
        }
    }

    private static string Describe(Exception ex)
    {
        return ex == null
            ? "(null exception)"
            : ex.GetType().FullName + ": " + ex.Message + " | " + ex.StackTrace;
    }

    private static void OpenLog()
    {
        try
        {
            string dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AgentCMSKit");
            Directory.CreateDirectory(dir);
            logPath = Path.Combine(dir, "launcher.log");
        }
        catch
        {
            try { logPath = Path.Combine(Path.GetTempPath(), "agentcms-launcher.log"); }
            catch { logPath = null; }
        }
    }

    private static void Log(string line)
    {
        if (logPath == null) return;
        try
        {
            File.AppendAllText(logPath,
                DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ") + " " + line + "\n");
        }
        catch { /* ログ失敗で launcher を落とさない */ }
    }

    private static void Alert(string message)
    {
        MessageBox.Show(message, AppName, MessageBoxButtons.OK, MessageBoxIcon.Warning);
    }
}

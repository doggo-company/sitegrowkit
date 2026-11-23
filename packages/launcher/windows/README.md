# Windows launcher（Phase 8.5-D）

`Agent CMS Kit.exe` は Explorer からのダブルクリック用の薄い stub です。

## 実装方式

- **C# / .NET Framework 4.x**、Windows 11 に**標準搭載**の `csc.exe` でビルドする
  （`C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe`）
- **利用者へ新しい第三者ランタイムを要求しない**（.NET Framework 4.x は OS 同梱）
- `/target:winexe`（GUI subsystem）でビルドするため**コンソール窓を出さない**
  （`file` の出力が `PE32 executable (GUI) ... Mono/.Net assembly` になることで確認できる）
- 子プロセスは `CreateNoWindow = true` で起動する

## ビルド手順（Windows 実機で実行）

```powershell
$csc = "C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
& $csc /nologo /target:winexe /platform:anycpu /optimize+ `
  /reference:System.Windows.Forms.dll /reference:System.dll /reference:System.Drawing.dll `
  /out:"Agent CMS Kit.exe" Launcher.cs
```

生成した `Agent CMS Kit.exe` を `dist/` に置くと `scripts/package-gui.mjs` が
Windows 配布物へ同梱します。

## 状態（Desktop 1 / 0.9.4-rc5）

- 同梱Nodeからサイト一覧を開き、新規セットアップ・既存サイトの起動と停止を操作できます。
- Windows 11実機でEXE → WSL2 → ブラウザ起動、およびEdgeでの実サイト停止・再起動・HTTP 200を確認しました。
- Ubuntu 24.04 / Docker DesktopのWSL Integrationが必要です。WindowsやUbuntuのNode/npmには依存しません。
- `desktop/` はGUIの操作部分です。`app/` は検証済みrc5 TGZから組み立てます。
- Windowsの発行者署名は未実施です。一般配布版とは区別します。
- 本体が失敗した場合は、teeの終了コードで成功にせず、本体の終了コードをダイアログへ伝えます。

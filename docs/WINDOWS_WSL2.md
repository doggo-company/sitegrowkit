# Windows 11 での利用（WSL2）

SiteGrowKit の Windows サポートは **WSL2（Windows Subsystem for Linux 2）内での実行**です。
ネイティブ Windows（PowerShell / cmd で直接実行）はサポートしていません。

> **検証状況（Verified Environment）**: 以下の実機環境で、配布パッケージからの
> Golden Path（install → doctor → create → dev → 公開/管理画面 → verify 9/9）の
> 受け入れテストに合格しています（host PHP なし・root 所有ファイル生成なし・手動介入なし）。
>
> - Windows 11 / WSL 2.6.3 / Ubuntu 24.04 / Docker Desktop 4.65 / x86_64
>
> これは上記の実測範囲の記載であり、すべての Windows 構成での動作を保証するものではありません。
> 異なる構成で問題が起きた場合の報告を歓迎します。

## セットアップ手順

1. **WSL2 + Ubuntu を導入**（PowerShell 管理者権限で）:
   ```powershell
   wsl --install
   ```
2. **Docker Desktop for Windows** をインストールし、Settings → Resources → **WSL Integration** で使用するディストリビューション（Ubuntu 等）を有効化
3. **WSL2 のターミナル内で** Node.js 20+ / Git / unzip を導入（pnpm は不要です —
   フロントエンドの依存インストールと build は Docker コンテナ内で実行します）:
   ```bash
   sudo apt update && sudo apt install -y git unzip
   curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
   ```
4. 以降は [GETTING_STARTED.md](GETTING_STARTED.md) と同じです（すべて WSL2 内で実行）。

## ファイルシステムに関する重要な推奨

プロジェクトは必ず **WSL2 側（Linux ファイルシステム、例: `~/projects/my-site`）** に作成してください。

- `/mnt/c/...`（Windows 側のマウント）はファイル I/O が非常に遅く、パーミッション・実行ビットの挙動も異なります
- Docker の bind mount 性能も Linux ファイルシステム側が大幅に有利です

## 確認ポイント

- `agentcms doctor` は WSL2 を自動検出して表示します
- Docker daemon に接続できない場合、ほとんどは Docker Desktop の WSL Integration が無効なことが原因です（doctor が案内します）

## 既知の相違点

- SSH 経由で Windows の WSL を操作すると、Docker の認証ヘルパーが `A specified logon session does not exist` を返す場合があります（2026-09-09 実測）。この場合はPCにサインインし、Docker Desktopを起動したうえで、PC上のWSLターミナルから再実行してください。認証設定の削除やネットワーク設定の変更は行わないでください。

- `.agentcms/local/admin-credentials.json` のパーミッション 0600 は WSL2 では有効、`/mnt/c` 配下では保証されません（この点でも Linux 側推奨）
- 改行コードは Git の `core.autocrlf=input`（WSL2 の既定的運用）を推奨します

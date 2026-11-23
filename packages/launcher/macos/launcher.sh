#!/bin/sh
# Agent CMS Kit — macOS launcher（Phase 8.5-D）
#
# Finder からのダブルクリックで実行される app bundle の実体。
# 同梱 Node runtime で同梱 CLI の `setup` を起動するだけで、
# 利用者の PATH / global npm / host Node には一切依存しない。
#
# 設計上の固定事項:
#  - cwd に依存しない（自分の位置から bundle 内を絶対パスで解決する）
#  - PATH へ node / npm / agentcms を追加しない
#  - Terminal.app を開かない（Finder 起動のまま GUI で完結する）

set -eu

# .../Agent CMS Kit.app/Contents/MacOS/<this> → Contents/Resources
BIN_DIR=$(cd "$(dirname "$0")" && pwd)
RESOURCES=$(cd "$BIN_DIR/../Resources" && pwd)

NODE="$RESOURCES/runtime/bin/node"
CLI="$RESOURCES/desktop/main.mjs"
LOG_DIR="$HOME/Library/Logs/Agent CMS Kit"
LOG="$LOG_DIR/launcher.log"

umask 077
mkdir -p "$LOG_DIR"

fail_dialog() {
  # GUI 起動のためエラーは Terminal ではなくダイアログで伝える
  /usr/bin/osascript -e "display alert \"Agent CMS Kit\" message \"$1\" as critical" >/dev/null 2>&1 || true
  exit 1
}

[ -x "$NODE" ] || fail_dialog "同梱ランタイムが見つかりませんでした。ダウンロードしたファイルを展開し直してください。"
[ -f "$CLI" ] || fail_dialog "Agent CMS Kit の本体が見つかりませんでした。ダウンロードしたファイルを展開し直してください。"

# 実行は「同梱 node + 同梱 CLI」のみ。ユーザー入力は受け取らない
# 起動用アプリは終了し、再度ダブルクリックしたときも画面を開けるようにする。
# ブラウザ側の「アプリを終了」または無操作30分でローカルサーバーは終了する。
/usr/bin/nohup "$NODE" "$CLI" >>"$LOG" 2>&1 < /dev/null &

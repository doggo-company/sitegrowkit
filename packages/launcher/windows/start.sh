#!/bin/sh
# Agent CMS Kit — WSL2 側の起動スクリプト（Phase 8.5-D / D.3）
#
# Windows launcher（.exe）から
#   wsl.exe --cd ~ -- /bin/sh -s <配布物の場所>
# の stdin へ渡された固定スクリプト経由で呼ばれる。
#
# 同梱 Linux Node runtime を **WSL2 の Linux filesystem へ stage** してから
# 同梱 CLI の `setup` を起動する。
#
# なぜ stage するか:
#  - 配布物が /mnt/c（DrvFS）上にあると executable bit が保証されず、Node を直接実行できない
#  - DrvFS 越しの実行は遅く、権限まわりの不具合も起きやすい
# stage は versioned / atomic / checksum 検証つきで、繰り返し起動しても安全（冪等）。
#
# 診断方針（D.3）:
#  - ログは配布物ではなく **~/.agentcms/logs/windows-bootstrap.log**（canonical user path）
#  - 各工程の exit code を明示取得する（set -e の無言終了で原因を失わない）
#  - stderr を捨てない

umask 077
LOG_DIR="$HOME/.agentcms/logs"
mkdir -p "$LOG_DIR" 2>/dev/null || true
BOOT_LOG="$LOG_DIR/windows-bootstrap.log"

log() {
  printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1" >> "$BOOT_LOG" 2>/dev/null || true
}

log "bootstrap.start pid=$$ ppid=$PPID argc=$#"
trap 'log "bootstrap.exit code=$?"' EXIT
trap 'log "bootstrap.signal=HUP"' HUP
trap 'log "bootstrap.signal=INT"' INT
trap 'log "bootstrap.signal=TERM"' TERM

DIST_DIR=${1:-}
if [ -z "$DIST_DIR" ]; then
  DIST_DIR=$(cd "$(dirname "$0")/.." 2>/dev/null && pwd)
fi
log "bootstrap.dist=$DIST_DIR"

RUNTIME_SRC="$DIST_DIR/runtime"
APP_SRC="$DIST_DIR/app"
VERSION_FILE="$DIST_DIR/launcher/RUNTIME_VERSION"

if [ ! -d "$RUNTIME_SRC" ] || [ ! -f "$APP_SRC/dist/index.js" ]; then
  log "bootstrap.distribution.incomplete"
  exit 1
fi
log "bootstrap.distribution.ok"

RUNTIME_VERSION=$(cat "$VERSION_FILE" 2>/dev/null || echo "unknown")
STAGE_ROOT="$HOME/.agentcms/runtime"
STAGE_DIR="$STAGE_ROOT/$RUNTIME_VERSION"
NODE_BIN="$STAGE_DIR/bin/node"

verify_runtime() {
  [ -x "$NODE_BIN" ] || return 1
  # 実行できることまで確認する（部分コピー・権限不足の検出）
  "$NODE_BIN" --version >/dev/null 2>&1 || return 1
  expected_file="$DIST_DIR/launcher/node.sha256"
  if [ -f "$expected_file" ] && command -v sha256sum >/dev/null 2>&1; then
    expected=$(cat "$expected_file")
    actual=$(sha256sum "$NODE_BIN" | cut -d' ' -f1)
    [ "$expected" = "$actual" ] || return 1
  fi
  return 0
}

stage_runtime() {
  log "runtime.stage.started version=$RUNTIME_VERSION"
  tmp="$STAGE_ROOT/.staging-$$"

  rm -rf "$tmp" 2>/dev/null
  mkdir -p "$tmp" 2>>"$BOOT_LOG"
  rc=$?
  if [ "$rc" -ne 0 ]; then log "runtime.stage.failed step=directory exit=$rc"; return 1; fi
  log "runtime.directory.ready"

  log "runtime.copy.started"
  cp -R "$RUNTIME_SRC/." "$tmp/" 2>>"$BOOT_LOG"
  rc=$?
  if [ "$rc" -ne 0 ]; then log "runtime.stage.failed step=copy exit=$rc"; return 1; fi
  log "runtime.copy.completed"

  log "runtime.permissions.started"
  chmod +x "$tmp/bin/node" 2>>"$BOOT_LOG"
  rc=$?
  if [ ! -x "$tmp/bin/node" ]; then
    log "runtime.stage.failed step=permissions exit=$rc"
    return 1
  fi
  log "runtime.permissions.ok"

  log "runtime.integrity.started"
  if [ -f "$DIST_DIR/launcher/node.sha256" ] && command -v sha256sum >/dev/null 2>&1; then
    expected=$(cat "$DIST_DIR/launcher/node.sha256")
    actual=$(sha256sum "$tmp/bin/node" | cut -d' ' -f1)
    if [ "$expected" != "$actual" ]; then
      log "runtime.stage.failed step=integrity"
      return 1
    fi
  fi
  log "runtime.integrity.ok"

  log "runtime.promote.started"
  rm -rf "$STAGE_DIR" 2>/dev/null
  mv "$tmp" "$STAGE_DIR" 2>>"$BOOT_LOG"
  rc=$?
  if [ "$rc" -ne 0 ]; then log "runtime.stage.failed step=promote exit=$rc"; return 1; fi
  log "runtime.promote.completed"

  log "runtime.stage.completed"
  return 0
}

if verify_runtime; then
  log "runtime.reuse.ok version=$RUNTIME_VERSION"
else
  if ! stage_runtime; then
    log "runtime.stage.aborted"
    exit 1
  fi
  if ! verify_runtime; then
    log "runtime.verify.failed"
    exit 1
  fi
fi

log "node.probe.started"
node_version=$("$NODE_BIN" --version 2>>"$BOOT_LOG")
rc=$?
if [ "$rc" -ne 0 ]; then
  log "node.probe.failed exit=$rc"
  exit 1
fi
log "node.probe.ok version=$node_version"

# 同梱 runtime + 同梱 CLI のみを実行する。PATH の node は使わない。
#
# 診断のため exec で置換せず、shell を親のまま待機する（Phase 8.5-D.6）。
# background & + 即 wait なので **detach ではない**（foreground chain を維持）。
# こうすることで PID・exit code・signal を記録できる。
# 実測: Windows launcher が stdin を閉じると WSL 側へ **SIGHUP** が届き、
# bootstrap と Wizard の両方が exit_code=129 (signal=1) で落ちていた。
# SIGHUP を **無視**する（ignore は子プロセスへ継承されるため Node も生き残る）。
# detach ではなく、foreground chain はそのまま維持する。
trap '' HUP
log "wizard.node.sighup.ignored"

# Wizard の出力は **stdout へ流したうえで** ログにも残す（Phase 8.5-D.8 / F-D4）。
# Windows launcher の進捗ウィンドウは wsl.exe の stdout に現れる
# 「ブラウザで…」を検出して閉じる。ログへ丸ごとリダイレクトしてしまうと
# その行が launcher に届かず、**進捗ウィンドウが永久に閉じない**。
# tee で二重化することで、診断ログと launcher 通知の両方を満たす。
log "wizard.node.exec.begin"
PIPE_DIR=$(mktemp -d "$LOG_DIR/.launch.XXXXXX") || exit 1
mkfifo "$PIPE_DIR/output" || exit 1
tee -a "$BOOT_LOG" < "$PIPE_DIR/output" &
TEE_PID=$!
"$NODE_BIN" "$DIST_DIR/desktop/main.mjs" > "$PIPE_DIR/output" 2>&1 &
NODE_PID=$!
log "wizard.node.pid=$NODE_PID"

# teeの成功で本体の失敗を隠さず、Nodeの終了コードをGUIへ返す。
wait "$NODE_PID"
rc=$?
wait "$TEE_PID"
rm -f "$PIPE_DIR/output"
rmdir "$PIPE_DIR"
if [ "$rc" -gt 128 ]; then
  log "wizard.node.unexpected_exit exit_code=$rc signal=$((rc - 128))"
else
  log "wizard.node.exit exit_code=$rc signal=none"
fi
exit "$rc"

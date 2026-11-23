import AppKit
import Foundation

// Finder から同梱ランタイムを起動する。シェルや利用者の PATH には依存しない。
let resources = Bundle.main.bundleURL.appendingPathComponent("Contents/Resources")
let runtime = resources.appendingPathComponent("runtime/bin/node")
let entry = resources.appendingPathComponent("desktop/main.mjs")
let files = FileManager.default

do {
    guard files.isExecutableFile(atPath: runtime.path), files.fileExists(atPath: entry.path) else {
        throw CocoaError(.fileReadNoSuchFile)
    }
    let logs = files.homeDirectoryForCurrentUser.appendingPathComponent("Library/Logs/Agent CMS Kit")
    try files.createDirectory(at: logs, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
    let log = logs.appendingPathComponent("launcher.log")
    if !files.fileExists(atPath: log.path) {
        guard files.createFile(atPath: log.path, contents: nil, attributes: [.posixPermissions: 0o600]) else {
            throw CocoaError(.fileWriteNoPermission)
        }
    }
    let output = try FileHandle(forWritingTo: log)
    try output.seekToEnd()
    let process = Process()
    process.executableURL = runtime
    process.arguments = [entry.path]
    process.currentDirectoryURL = resources
    process.standardInput = FileHandle.nullDevice
    process.standardOutput = output
    process.standardError = output
    try process.run()
    // サイト管理サーバーは別プロセスで継続し、再度開く操作も受け付ける。
    try output.close()
} catch {
    let app = NSApplication.shared
    app.setActivationPolicy(.accessory)
    app.activate(ignoringOtherApps: true)
    let alert = NSAlert()
    alert.messageText = "SiteGrowKitを起動できませんでした"
    alert.informativeText = "アプリをアプリケーションフォルダへコピーして、もう一度開いてください。改善しない場合は配布ファイルを取得し直してください。"
    alert.alertStyle = .critical
    alert.runModal()
    exit(1)
}

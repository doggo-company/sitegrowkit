import AppKit
import Foundation

// 生成した原画を、macOS の角丸タイルと透過余白を持つ配布用サイズへ書き出す。
let root = URL(fileURLWithPath: FileManager.default.currentDirectoryPath)
let source = root.appendingPathComponent("packages/launcher/branding/sitegrowkit-icon-source.png")
let destination = root.appendingPathComponent("packages/launcher/macos")
guard let original = NSImage(contentsOf: source) else { fatalError("アイコンの原画がありません") }
let iconset = root.appendingPathComponent(".codex/SiteGrowKit.iconset")
try FileManager.default.createDirectory(at: iconset, withIntermediateDirectories: true)

func render(_ size: Int, to file: URL) throws {
    guard let bitmap = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: size, pixelsHigh: size,
        bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false,
        colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0),
        let context = NSGraphicsContext(bitmapImageRep: bitmap) else { fatalError("描画領域を作成できません") }
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = context
    context.imageInterpolation = .high
    let scale = CGFloat(size) / 1254
    // 原画のクリーム色タイルの内側を採用し、外側のプレビュー背景を配布物へ含めない。
    let tile = NSRect(x: 98 * scale, y: 98 * scale, width: 1054 * scale, height: 1060 * scale)
    NSBezierPath(roundedRect: tile, xRadius: 260 * scale, yRadius: 260 * scale).addClip()
    original.draw(in: NSRect(x: 0, y: 0, width: size, height: size))
    NSGraphicsContext.restoreGraphicsState()
    guard let png = bitmap.representation(using: .png, properties: [:]) else { fatalError("PNGを書き出せません") }
    try png.write(to: file)
}

try render(1024, to: destination.appendingPathComponent("SiteGrowKit.png"))
for point in [16, 32, 128, 256, 512] {
    try render(point, to: iconset.appendingPathComponent("icon_\(point)x\(point).png"))
    try render(point * 2, to: iconset.appendingPathComponent("icon_\(point)x\(point)@2x.png"))
}
let converter = Process()
converter.executableURL = URL(fileURLWithPath: "/usr/bin/iconutil")
converter.arguments = ["-c", "icns", iconset.path, "-o", destination.appendingPathComponent("SiteGrowKit.icns").path]
try converter.run(); converter.waitUntilExit()
guard converter.terminationStatus == 0 else { fatalError("ICNSへの変換に失敗しました") }
print("SiteGrowKit.png / SiteGrowKit.icns を作成しました")

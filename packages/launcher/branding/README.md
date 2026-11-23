# SiteGrowKit アプリアイコン

既存ロゴのバーニーズ・マウンテン・ドッグと若葉を主役に、緑・茶色・黒、クリーム色の角丸タイルで「育てる」温かみを表現する。小さな表示で読みにくくなる文字は入れない。

- 原画: `sitegrowkit-icon-source.png`（OpenAI 組み込み imagegen、既存の `website/assets/logo.png` と `mark.png` を参照して生成）
- PNG: `../macos/SiteGrowKit.png`（1024 × 1024、実際の透過余白）
- macOS: `../macos/SiteGrowKit.icns`（16 / 32 / 128 / 256 / 512 pt、各1x・2x）
- 再書き出し: リポジトリ直下で `swift scripts/build-app-icon.swift`

原画の外側に生成された市松模様は透過ではないため、書き出し時にクリーム色タイルの輪郭で切り抜き、実際のアルファチャンネルを作る。原画をそのまま配布アイコンに使わない。

## 生成指示

Use case: logo-brand. Design a premium macOS application icon for SiteGrowKit, a warm Japanese CMS brand about nurturing and growing websites. Preserve the official mascot: a seated Bernese mountain dog facing right, black coat, warm rust-brown patches, white chest and muzzle, floppy ears, and exactly two fresh green leaves sprouting from its head. Simplify the graphic for 32px legibility. Use a cream rounded-square tile, restrained ceramic/paper shading, charcoal, earthy rust brown, and natural leaf green. Friendly and calm, no text, letters, badges, watermark, code symbols, mockup, or surrounding scene. Output a square icon with optical padding and transparent pixels outside the tile.

背景の再編集では、犬・若葉・タイルを保持してタイル外だけを透明にするよう指示した。最終的な透過と全サイズの出力はネイティブ AppKit で処理した。

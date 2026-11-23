# プロジェクト構造 — どこを編集してよいか

`agentcms create` で生成されたプロジェクトの構造と、所有権（誰が編集するか）の説明です。
AI エージェント向けの機械可読版は `.agentcms/architecture.md` / `.agentcms/rules/boundaries.md` にあります（内容は同期しています）。

## レイヤー構造

| パス | 内容 | 編集 |
| --- | --- | --- |
| `core/` | CMS Core（`agentcms/core`）。管理画面基盤・スキーマレジストリ・メディア・通知など | **禁止（読み取り専用）** |
| `app/` | プロジェクト側の薄い glue（Controller / Middleware / Provider） | ✅ あなた / AI |
| `modules/<Name>/` | コンテンツ型ごとの実装（Model / 公開テンプレート / migration / テスト） | ✅ あなた / AI |
| `content/schemas/*.json` | コンテンツ型定義（フォーム・バリデーション・一覧の単一情報源） | ✅（kit 標準スキーマは `overrides/` で調整） |
| `resources/views/` | 公開サイト（Blade + plain CSS。Tailwind は公開側では使わない） | ✅ |
| `resources/js/` | 管理画面（Inertia + Vue 3 + TS） | ✅ |
| `.agentcms/` | AI コンテキスト・ルール・レジストリ・import manifest | ルール類は `sync-agents` で管理 |
| `.agentcms/local/` | ローカル秘密情報（管理者認証情報） | Git 対象外・AI コンテキスト対象外 |
| `.agentcms/project.json` | project identity と生成元 Kit バージョン | CLI が管理 |

## なぜ `core/` を編集してはいけないのか

`core/` は将来の Kit アップデートで更新される領域です。直接編集すると更新が適用できなくなります。
機能が足りない場合は:

1. `core/src/Contracts/` のインターフェース・イベント・`core/config/` の設定で拡張できないか確認
2. できなければ、それは Kit 側の改善要望です（あなたのプロジェクト内でのハックにしない）

## コンテンツ型の追加

スキーマ駆動です。`content/schemas/<type>.json` を定義すると、管理画面のフォーム・一覧・バリデーション・モデルキャストが生成されます。既存の `news` / `works` などの JSON が実例です。実装は `modules/<Name>/` にまとめます（PSR-4: `Modules\<Name>\` → `modules/<Name>/`）。

## 検証

```bash
agentcms verify   # format / 静的解析 / テスト / build / secret scan / audit
agentcms test     # Pest のみ
pnpm e2e          # Playwright E2E（起動済み環境が前提）
```

## バージョンとアップデート

- 生成元 Kit バージョン: `.agentcms/project.json` の `kit_version`
- 互換性契約: `template_schema_version` / `agent_context_version`
- アップデート手順と所有権の詳細: [UPGRADING.md](UPGRADING.md)

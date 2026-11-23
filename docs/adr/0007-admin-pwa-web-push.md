# ADR-0007: Admin PWA と Web Push の設計

- Status: Accepted
- Date: 2026-08-08

## Context

Phase 3 は管理画面のみを installable PWA 化し、業務通知（新規お問い合わせ / メール通知失敗）を Web Push で届けることを要求する。制約: 公開サイトへ SW キャッシュ問題を持ち込まない / Firebase 必須にしない / 常駐 Queue Worker 必須にしない / ブラウザ実 Push は CI で安定再現できない。

## Decision

### 通知は `modules/Notifications/`（Project 層）

Media が確立した「非コンテンツ型モジュール」パターンを踏襲し、Core は変更しない。
Contact → Notifications はモジュール間の直接依存（`Notifier` を DI）。禁止されているのは core → project の依存のみ。

### Transport 境界

```
Notifier（アプリロジック: 宛先解決・preference 判定・結果処理）
  └─ PushTransport interface（境界）
       ├─ WebPushTransport（minishlink/web-push + VAPID — 実送信）
       └─ FakePushTransport（テスト）
```

境界より先（Push Service → ブラウザ受信）は自動テスト対象外と明示し、ローカル実機確認手順で補う。

### データ設計（2 テーブル）

```
push_subscriptions
├── user_id            # 1 ユーザー n 端末
├── endpoint (text) + endpoint_hash (sha256, unique)  # 長大 URL の一意性は hash 索引で担保
├── p256dh / auth
└── last_used_at       # 送信成功時刻

notification_preferences
├── user_id + type (unique)   # type = NotificationType enum の value
└── enabled
```

- 行が無い type は `NotificationType::defaultEnabled()` に従う（既定値から導出できる全組合せを永続化しない）
- 無効 subscription（404/410 = `isSubscriptionExpired`）は**削除**する。revoked フラグは持たない — 復活しない状態を保持する理由がない
- 一時的エラーは subscription を保持し、その場では再試行しない（Push はベストエフォート、問い合わせデータが最優先）

### 通知種別は enum `NotificationType`

case + label/group/defaultEnabled の match 行を足すだけで追加できる。送信・設定画面・preference 判定のすべてが enum を参照し、巨大 if への集約を構造的に防ぐ。将来のユーザー種別別表示は `visibleTypes()` の絞り込みで拡張する。

### 送信は同期（キュー不使用）

RecordedMailer と同方針。Contact 保存 → メール → Push の順で同一リクエスト内実行、`Notifier` は決して例外を投げない。共用レンタルサーバー（Phase 1 の DB Queue + Cron 方針)と矛盾せず、非同期化が必要になったら既存 Database Queue に載せ替えられる。

### PWA は /admin スコープ限定・保守的キャッシュ

- SW 実体は `public/admin-pwa/sw.js`、配信は `/admin/sw.js` ルート経由。`Service-Worker-Allowed: /admin` ヘッダーで scope を `/admin`（トレイリングスラッシュなし）にする — `/admin/` だと start_url `/admin` 自身が scope 外になる罠を回避
- キャッシュするのはハッシュ付き `/build/` アセット（cache-first）と offline ページのみ。管理画面 HTML / API は一切キャッシュしない（古い管理データを見せない）
- SW 更新は自動適用せず、トースト操作起点で `SKIP_WAITING`
- manifest / SW / offline は認証不要ルート（秘匿情報を含まない。auth を掛けるとブラウザが取得できずインストール不能）

## Consequences

- 通知種別の追加 = enum に case 追加 + 送信箇所で `Notifier::send()` 呼び出しのみ
- E2E は「SW 登録 / offline fallback / API 認可 / 実 Transport の失敗経路」まで、Pest は Transport 境界の内側を全て検証。実 Push 受信のみ手動確認
- minishlink/web-push は **v11** を明示指定（v8 の依存 thecodingmachine/safe v2 系は PHP 8.5 で deprecation 警告を全レスポンスへ混入させる）

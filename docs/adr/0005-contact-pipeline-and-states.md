# ADR-0005: Contact pipeline と状態設計

- Status: Accepted
- Date: 2026-08-08

## Context

仕様（CONTACT FORM）は送信順序（Validation → Turnstile siteverify → DB 保存 → 管理者通知 → 自動返信 → 通知結果記録）と、「メール送信に失敗しても問い合わせデータを失わない」こと、および `received / notification_sent / notification_failed / in_progress / completed / spam` 「などの状態を持てる設計」を要求している。

列挙された状態には「対応の進行状態」（received / in_progress / completed / spam）と「通知の成否」（notification_sent / notification_failed）という直交する 2 軸が混在している。1 つの enum に両軸を入れると「通知失敗かつ対応完了」等が表現できない。

## Decision

2 軸を分離して永続化する。

```
contact_submissions
├── status: received | in_progress | completed | spam   # 対応の進行状態（enum ContactStatus）
├── admin_notified_at: datetime|null                    # 管理者通知の成功時刻
├── admin_notification_error: string|null               # 管理者通知の失敗理由
├── autoreply_sent_at: datetime|null                    # 自動返信の成功時刻
└── autoreply_error: string|null                        # 自動返信の失敗理由
```

- `notification_sent` / `notification_failed` は `admin_notified_at` / `admin_notification_error` から導出される表示状態とする（導出値の永続化禁止原則）
- パイプラインは Controller 内で明示的な手順として実装し、DB 保存成功後のメール例外は `RecordedMailer`（core）が捕捉して結果カラムへ記録する。メール失敗はロールバックも 5xx も引き起こさない
- Turnstile 検証失敗はバリデーションエラーとして返し、DB へは保存しない（bot データを蓄積しない）

## Consequences

- 管理画面は status と通知結果を別カラムで表示する
- 将来「通知リトライ」機能は error カラムの有無で対象抽出できる

# セキュリティの問題を見つけたとき

SiteGrowKit のセキュリティの問題（脆弱性）を見つけたときは、**公開の Issue・Pull Request・SNS に書かず**、次のメールアドレスへお知らせください。

**hello@doggo-company.com**

件名に「SiteGrowKit セキュリティ」と入れてください。

## 書いてほしいこと

- 使っている版（`agentcms --version`）
- 問題の内容と、どこで起きるか
- 再現の手順（できれば）
- 想定される影響

秘密の値（パスワード・API キー・トークン・お客さんの個人情報）は送らないでください。

## 対象の版

最新の版を対象にします。

## 設計上の対策

CSRF・XSS・SQL インジェクションなどへの設計上の対策は [docs/SECURITY.md](docs/SECURITY.md) にまとめています。

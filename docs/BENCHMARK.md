# Benchmark

## Purpose

商品の主張 —「AI のトークンを、環境構築とやり直しで浪費しない。」— を検証可能にする。

AI が「できました」と言ったことは完了条件にしない。機械的な検証結果と実測メトリクスだけを根拠にする。

## Data Layout

| 用途 | 場所 |
| --- | --- |
| Phase 1 総括（人間向け） | [benchmarks/phase-1.md](benchmarks/phase-1.md) |
| 開発ジャーナル（節目ごと） | [benchmarks/phase-1-journal.md](benchmarks/phase-1-journal.md) |
| 機械可読メトリクス | `.agentcms/metrics/phase-1.json` |

## Rules

- token usage は実測できる場合のみ実測値として記録する（Claude Code transcript の usage フィールド等）
- 実測できない場合は `"exact_token_usage_available": false` を明記し、客観的な代替指標（セッション数 / tool call 数 / 作業時間 / 修正ループ数 / compaction 回数）だけを保存する
- 推定値は `estimated_tokens` として実測値と分離する
- 取得不能な値は N/A とし、推測で埋めない
- 失敗・やり直し・無駄な探索は改善材料なので、成功したように整形せず記録する
- **Acceptance に release artifact を使う場合（Phase 8 以降・必須）**: 使用した artifact の
  filename と正確な SHA-256 を benchmark（md）と metrics（json）の両方に記録する。
  zip はビルドごとにバイト列が変わるため、hash の記録がないと実機 acceptance に
  使われた個体を後から特定できない（Phase 8 Stage 0 finding: v0.7.0-rc2 の
  acceptance 個体は hash 未記録のため記録からは確定不能 — 過去分は書き換えず、
  `release/acceptance-evidence/` の保全個体を canonical evidence として扱う）

## Future Comparison Protocol

同じ規模の CMS サイトを以下 2 条件で構築し比較する。

- A. Vanilla Laravel + Claude Code
- B. SiteGrowKit + Claude Code

比較軸: 総トークン / Input / Output / Cache 利用量 / 完了時間 / ファイル探索量 / 修正ループ / テスト失敗 / 人間の介入 / Lighthouse / Visual Diff / 完成品質。

Phase 1 の記録がこの比較の Baseline となる。

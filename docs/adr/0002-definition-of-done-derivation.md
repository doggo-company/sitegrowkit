# ADR-0002: Definition of Done の導出と仕様ファイル名の正規化

- Status: Accepted
- Date: 2026-08-08

## Context

Phase 1 のゴール指示は「docs/PHASE1_SPEC.md に記載された Definition of Done をすべて満たすまで実装」だが、

1. 実ファイル名は `docs/PHASE1_SPE.md`（typo）だった
2. 仕様書に「Definition of Done」という明示セクションは存在しない

## Decision

1. `docs/PHASE1_SPE.md` を `docs/PHASE1_SPEC.md` へリネームし、以後これを唯一の Phase 1 仕様とする
2. DoD は仕様の QUALITY GATE（機械的検証を完了条件とする原則）と各セクションの成果物要件から導出し、docs/benchmarks/phase-1.md の「Definition of Done」チェックリスト（D1–D13 / Q1–Q12 / B1–B2）として固定する
3. 判定は「AI ができたと言った」ことではなく、各検証コマンドの実行結果のみを根拠とする

## Consequences

- DoD の追加・変更はこの ADR の改訂として扱う
- Lighthouse 100（Desktop / Mobile ×4 カテゴリ）は仕様上「目標」であるため、DoD 上は「実行とスコア記録・ごまかし実装の不在」を必須、100 達成は目標値として別記する

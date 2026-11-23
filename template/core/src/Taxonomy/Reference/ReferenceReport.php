<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

/**
 * 参照調査の結果（Phase 9.3B / STAGE 2A・SPEC §I.3）。
 *
 * **公開契約（STAGE 2A CORRECTION E）**
 *
 * 呼び出し側は **`summary()` の日本語文字列を解析せずに**判断できること。
 *
 * | 契約 | 意味 |
 * | --- | --- |
 * | `isComplete()` | すべての調査を確定できたか |
 * | `isDeletable()` | 削除してよいか（= 参照ゼロ **かつ** 調査完了） |
 * | `references()` | 見つかった参照（`Reference` のリスト） |
 * | `undeterminedReasons()` | 確定できなかった理由（日本語・list<string>） |
 * | `totalCount()` | 参照の合計件数 |
 * | `kinds()` | 参照が見つかった種別 |
 *
 * 互換のため `allowsDelete()` / `total()` / `hasReferences()` / `isUndetermined()` も残す。
 * 対応は `isDeletable() === allowsDelete()`、`totalCount() === total()`、
 * `isComplete() === ! isUndetermined()`。
 *
 * `summary()` は日本語で **3 状態を区別**する。
 *   1. 参照ゼロかつ調査完了
 *   2. 参照あり
 *   3. 調査未完了 / 判定不能
 */
final readonly class ReferenceReport
{
    /**
     * @param  list<Reference>  $references  参照が 1 件以上あるものだけ
     * @param  list<string>  $undetermined  確定できなかった調査の理由（日本語）
     */
    public function __construct(
        public array $references,
        public array $undetermined = [],
    ) {}

    // ── 公開契約 ────────────────────────────────────────────────

    /** すべての調査を確定できたか */
    public function isComplete(): bool
    {
        return $this->undetermined === [];
    }

    /** 削除してよいか。**参照ゼロ かつ 調査完了のときだけ true**（fail closed） */
    public function isDeletable(): bool
    {
        return $this->references === [] && $this->isComplete();
    }

    /** @return list<Reference> */
    public function references(): array
    {
        return $this->references;
    }

    /** @return list<string> */
    public function undeterminedReasons(): array
    {
        return $this->undetermined;
    }

    public function totalCount(): int
    {
        return array_sum(array_map(
            static fn (Reference $r): int => $r->count,
            $this->references,
        ));
    }

    /** @return list<string> 参照が見つかった種別 */
    public function kinds(): array
    {
        return array_map(
            static fn (Reference $r): string => $r->kind,
            $this->references,
        );
    }

    // ── 互換 API ────────────────────────────────────────────────

    public function hasReferences(): bool
    {
        return $this->references !== [];
    }

    /** `isComplete()` の否定 */
    public function isUndetermined(): bool
    {
        return ! $this->isComplete();
    }

    /** `isDeletable()` と同じ */
    public function allowsDelete(): bool
    {
        return $this->isDeletable();
    }

    /** `totalCount()` と同じ */
    public function total(): int
    {
        return $this->totalCount();
    }

    // ── 表示 ────────────────────────────────────────────────────

    /** Admin / API へ返す説明文（日本語）。3 状態を区別する */
    public function summary(): string
    {
        // 状態 3: 調査未完了（参照が見つかっているかどうかに関わらず未確定）
        if (! $this->isComplete()) {
            if (! $this->hasReferences()) {
                return '参照の有無を確認できませんでした。時間をおいて再試行するか、アーカイブしてください。';
            }

            return $this->foundText().'さらに確認できなかった調査があるため、参照の有無を確定できていません。';
        }

        // 状態 1: 参照ゼロかつ調査完了
        if (! $this->hasReferences()) {
            return 'この分類はどこからも参照されていません。';
        }

        // 状態 2: 参照あり
        return $this->foundText();
    }

    private function foundText(): string
    {
        $parts = array_map(
            static fn (Reference $r): string => "{$r->label} {$r->count} 件",
            $this->references,
        );

        return implode(' / ', $parts).'で使われています。';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            // 公開契約
            'complete' => $this->isComplete(),
            'deletable' => $this->isDeletable(),
            'total_count' => $this->totalCount(),
            'kinds' => $this->kinds(),
            'references' => array_map(
                static fn (Reference $r): array => $r->toArray(),
                $this->references,
            ),
            'undetermined' => $this->undetermined,
            'summary' => $this->summary(),
            // 互換
            'allows_delete' => $this->isDeletable(),
            'total' => $this->totalCount(),
        ];
    }
}

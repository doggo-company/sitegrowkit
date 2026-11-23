<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

/**
 * provider 1 つ分の調査結果（Phase 9.3B / STAGE 2A）。
 *
 * **「0 件」と「確定できなかった」を型で分ける。**
 * 0 件を undetermined と混同すると削除不能になり、
 * undetermined を 0 件と混同すると参照ごと消してしまう。
 *
 * 3 つ目の状態として **partial**（参照は見つかったが、確定できなかった対象もある）を持つ。
 * 見つかった参照を捨てずに、report 全体を未完了にできる。
 */
final readonly class ReferenceProbe
{
    /**
     * @param  list<string>  $samples
     * @param  string|null  $undeterminedReason  確定できなかった理由（日本語）
     */
    private function __construct(
        public int $count,
        public array $samples,
        public ?string $undeterminedReason,
    ) {}

    /** @param list<string> $samples */
    public static function found(int $count, array $samples = []): self
    {
        return new self($count, $samples, null);
    }

    public static function none(): self
    {
        return new self(0, [], null);
    }

    /** 調査しきれなかった（timeout / 破損 / DB エラー） */
    public static function undetermined(string $reason): self
    {
        return new self(0, [], $reason);
    }

    /**
     * 一部は確定できたが、確定できなかった対象も残っている。
     *
     * @param  list<string>  $samples
     */
    public static function partial(int $count, array $samples, string $reason): self
    {
        return new self($count, $samples, $reason);
    }

    public function isUndetermined(): bool
    {
        return $this->undeterminedReason !== null;
    }

    /** 見つかった参照があるか（undetermined と併存し得る） */
    public function hasFindings(): bool
    {
        return $this->count > 0;
    }
}

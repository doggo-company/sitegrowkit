<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * 階層検証の結果（Phase 9.3B / STAGE 2A CORRECTION・SPEC §D）。
 *
 * Application Service が **3 状態を必ず区別できる**契約にする。
 *
 * | 状態 | 意味 | Application Service の扱い |
 * | --- | --- | --- |
 * | `ok` | 正常な階層 | 続行してよい |
 * | `invalid` | 利用者入力の validation error | 入力を直せば通る。エラーを返す |
 * | `corrupt` | 既存データ破損・判定不能 | **安全側で拒否**する（入力の問題ではない） |
 */
final readonly class HierarchyCheck
{
    /**
     * @param  list<string>  $errors  利用者向けの validation error（日本語）
     * @param  string|null  $corruption  既存データ破損の説明（日本語）
     */
    private function __construct(
        public array $errors,
        public ?string $corruption,
    ) {}

    public static function ok(): self
    {
        return new self([], null);
    }

    /** @param list<string> $errors */
    public static function invalid(array $errors): self
    {
        return new self($errors, null);
    }

    public static function corrupt(string $reason): self
    {
        return new self([], $reason);
    }

    public function isOk(): bool
    {
        return $this->errors === [] && $this->corruption === null;
    }

    /** 利用者入力を直せば通るか */
    public function isInvalid(): bool
    {
        return $this->errors !== [];
    }

    /** 既存データが壊れていて判定できないか */
    public function isCorrupt(): bool
    {
        return $this->corruption !== null;
    }

    /**
     * 利用者へ見せるメッセージ（validation / corruption の両方を含む）。
     *
     * @return list<string>
     */
    public function messages(): array
    {
        if ($this->corruption !== null) {
            return [$this->corruption];
        }

        return $this->errors;
    }
}

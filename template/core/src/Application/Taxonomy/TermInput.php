<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

/**
 * Application Service 境界での**厳密な**入力読み取り（Phase 9.3B / STAGE 2B CORRECTION）。
 *
 * 原則:
 * - **許可キー以外は黙って無視せず拒否する。** 正しいキーと一緒に来ても全体を validation error にする
 * - **「未指定」「明示 null」「不正値」を潰さない。** 型不正を null へ変換して
 *   root 作成・root 移動へ落とさない
 * - 値の解決とエラー収集を 1 箇所に集め、各 Application Service が
 *   同じ判定を書き直さないようにする（継承ではなく composition）
 */
final class TermInput
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param  array<array-key, mixed>  $input  外部入力（キーが文字列とは限らない） */
    public function __construct(private readonly array $input) {}

    /**
     * 許可キー以外が 1 つでもあれば拒否する。
     *
     * @param  list<string>  $allowed
     */
    public function rejectUnknownKeys(array $allowed): void
    {
        foreach (array_keys($this->input) as $key) {
            $name = is_string($key) ? $key : (string) $key;

            if (! in_array($name, $allowed, true)) {
                $this->errors[$name][] = 'このキーは指定できません';
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }

    /** @param  list<string>  $messages */
    public function addErrors(string $key, array $messages): void
    {
        foreach ($messages as $message) {
            $this->errors[$key][] = $message;
        }
    }

    /**
     * 必須の空でない文字列（trim 後に判定）。
     */
    public function requiredString(string $key, string $label, int $max): string
    {
        if (! $this->has($key)) {
            $this->errors[$key][] = "{$label}を入力してください";

            return '';
        }

        $value = $this->input[$key];

        if (! is_string($value)) {
            $this->errors[$key][] = "{$label}は文字列で指定してください";

            return '';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            $this->errors[$key][] = "{$label}を入力してください";

            return '';
        }

        if (mb_strlen($trimmed) > $max) {
            $this->errors[$key][] = "{$label}は {$max} 文字以内にしてください";

            return '';
        }

        return $trimmed;
    }

    /**
     * 未指定なら `$default`、指定するなら **空でない文字列のみ**。
     *
     * **null / 空文字 / 数値 / 配列 / boolean はエラー**にする。
     * 「明示的な空文字」を「未指定」とみなして自動生成へ落とさない。
     */
    public function optionalNonEmptyString(string $key, string $label, ?string $default = null): ?string
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->input[$key];

        if (! is_string($value) || trim($value) === '') {
            $this->errors[$key][] = "{$label}は空でない文字列で指定してください";

            return $default;
        }

        return $value;
    }

    /**
     * 未指定なら `$default`、**明示 null は null**、それ以外は空でない文字列のみ。
     *
     * `parentId` 用。型不正を null（= root）へ変換しない。
     */
    public function nullableNonEmptyString(string $key, string $label, ?string $default = null): ?string
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->input[$key];

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            $this->errors[$key][] = "{$label}は空でない文字列か null で指定してください";

            return $default;
        }

        return $value;
    }

    /**
     * 未指定なら `$default`、明示 null は null、文字列はそのまま。
     *
     * **空文字は null へ正規化する**（説明を空にする＝未設定と同じ意味にするため。contract として明記）。
     */
    public function nullableString(string $key, string $label, ?string $default = null): ?string
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->input[$key];

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->errors[$key][] = "{$label}は文字列で指定してください";

            return $default;
        }

        return $value === '' ? null : $value;
    }

    /**
     * boolean のみ。`"true"` / `0` / `1` を暗黙変換しない。
     */
    public function boolean(string $key, string $label, ?bool $default = null): ?bool
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->input[$key];

        if (! is_bool($value)) {
            $this->errors[$key][] = "{$label}は true か false で指定してください";

            return $default;
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Theme;

use RuntimeException;

/**
 * Design Token（Phase 9.1D・owner 決定 G20）。
 *
 * 実体は JSON、出力は **CSS custom property**。
 * これにより色や余白の変更に build も deploy も要らない（共有ホスティング要件）。
 * Phase 6 の Figma `TokenExtraction`（colors / typography / spacing / radius / shadows）
 * と同じ語彙にしてあるので、import からそのまま流し込める。
 */
final class ThemeTokens
{
    /** CSS 変数へ出力するグループ（順序も出力順） */
    private const GROUPS = ['colors', 'typography', 'spacing', 'radius', 'shadows', 'container'];

    /** @var array<string, mixed>|null */
    private ?array $tokens = null;

    public function __construct(private readonly string $path) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->tokens !== null) {
            return $this->tokens;
        }

        if (! is_file($this->path)) {
            return $this->tokens = ['schemaVersion' => 1];
        }

        $raw = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($raw)) {
            throw new RuntimeException("theme.json が不正です: {$this->path}");
        }

        return $this->tokens = $raw;
    }

    /** `{colors.primary}` のような参照を解決する。解決できなければ null */
    public function resolve(string $reference): ?string
    {
        if (! preg_match('/^\{([a-zA-Z]+)\.([A-Za-z0-9_]+)\}$/', $reference, $m)) {
            return null;
        }

        $value = $this->all()[$m[1]][$m[2]] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** token 参照なら CSS 変数へ、そうでなければ値をそのまま返す */
    public function toCssValue(string $value): ?string
    {
        if (! preg_match('/^\{([a-zA-Z]+)\.([A-Za-z0-9_]+)\}$/', $value, $m)) {
            return $value;
        }

        return $this->resolve($value) === null
            ? null
            : "var({$this->variableName($m[1], $m[2])})";
    }

    /**
     * グループに実在するトークン名（`spacing` → `['section','gap','gapSmall']`）。
     *
     * @return list<string>
     */
    public function tokenNames(string $group): array
    {
        $values = $this->all()[$group] ?? null;

        if (! is_array($values)) {
            return [];
        }

        return array_map(strval(...), array_keys(array_filter($values, is_scalar(...))));
    }

    /** グループとトークン名から `var(--space-gap)` を作る。実在しなければ null */
    public function cssVariable(string $group, string $name): ?string
    {
        return in_array($name, $this->tokenNames($group), true)
            ? 'var('.$this->variableName($group, $name).')'
            : null;
    }

    /** breakpoint の px 値（レンダラの media query 用） */
    public function breakpoint(string $name): ?int
    {
        $value = $this->all()['breakpoints'][$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, int> */
    public function breakpoints(): array
    {
        $raw = $this->all()['breakpoints'] ?? [];
        $out = [];

        if (is_array($raw)) {
            foreach ($raw as $name => $value) {
                if (is_numeric($value)) {
                    $out[(string) $name] = (int) $value;
                }
            }
        }

        return $out;
    }

    /**
     * `:root { --color-primary: …; }` だけを組み立てる。
     *
     * document が出すクラスの規則は `AgentCms\Core\Render\DocumentCss` が作る。
     * theme と component registry の**両方**を見ないと、
     * 「宣言したのに規則が無い」を検出できないため（Phase 9.6 / P0-C4）。
     */
    public function toCss(): string
    {
        $lines = [];

        foreach (self::GROUPS as $group) {
            $values = $this->all()[$group] ?? null;

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $name => $value) {
                if (is_scalar($value)) {
                    $lines[] = '    '.$this->variableName($group, (string) $name).': '.$value.';';
                }
            }
        }

        return $lines === [] ? '' : ":root {\n".implode("\n", $lines)."\n}\n";
    }

    /**
     * breakpoint を **重ならない範囲**へ変換する。
     *
     * 重なる書き方（max-width だけ）にすると「tablet で隠すと mobile でも隠れる」となり、
     * breakpoint ごとの上書きという約束が守れない。
     *
     * @return array<string, string>
     */
    public function breakpointRanges(): array
    {
        $breakpoints = $this->breakpoints();
        asort($breakpoints);

        $names = array_keys($breakpoints);
        $ranges = [];

        foreach ($names as $index => $name) {
            $min = $breakpoints[$name];
            $next = $names[$index + 1] ?? null;
            $conditions = [];

            if ($min > 0) {
                $conditions[] = "(min-width: {$min}px)";
            }

            if ($next !== null) {
                $max = $breakpoints[$next] - 1;
                $conditions[] = "(max-width: {$max}px)";
            }

            if ($conditions !== []) {
                $ranges[(string) $name] = implode(' and ', $conditions);
            }
        }

        return $ranges;
    }

    private function variableName(string $group, string $name): string
    {
        $prefix = match ($group) {
            'colors' => 'color',
            'typography' => 'font',
            'spacing' => 'space',
            default => $group,
        };

        return '--'.$prefix.'-'.strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name);
    }
}

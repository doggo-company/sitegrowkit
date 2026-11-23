<?php

declare(strict_types=1);

namespace AgentCms\Core\Theme;

/**
 * document の style で使えるプロパティの目録（Phase 9.6 / P0-C1）。
 *
 * **validator と renderer と CSS 生成が同じ 1 つの表を読む。**
 * 3 か所に別々の対応表を置くと、「保存はできるが描かれない」「描かれるが検証されない」が生まれる。
 *
 * mode の意味（`PHASE9_6_SPEC.md` §3 の I-3 / I-4）:
 *
 * - `inline` … style 属性へ書く。値の集合が閉じていないので breakpoint override はできない
 * - `class`  … **クラス名だけ**を出す。値の集合が閉じている（token / enum）ので
 *   breakpoint ごとの上書きが成立する。inline は class より強いため、
 *   **override させたいプロパティは base も class で出す**
 */
final class StyleProperties
{
    /**
     * key => 仕様。
     *
     * - `kind: raw`   … 既存互換。値を検査しない（textAlign / color。閉じていないので class 化できない）
     * - `kind: token` … theme の `group` に**実在するトークン名**だけを受ける
     * - `kind: enum`  … 値 => CSS 値の対応表
     *
     * @var array<string, array<string, mixed>>
     */
    private const PROPERTIES = [
        // 画面幅ごとに変えられると宣言している以上、**値の集合を閉じて class で出す**。
        // inline のままだと class に勝ってしまい、breakpoint の上書きが原理的に効かない
        'textAlign' => ['label' => '文字寄せ', 'mode' => 'class', 'css' => 'text-align', 'kind' => 'enum', 'values' => [
            'left' => 'left',
            'center' => 'center',
            'right' => 'right',
        ]],
        'color' => ['label' => '文字色', 'mode' => 'inline', 'css' => 'color', 'kind' => 'raw'],
        'spaceTop' => ['label' => '上の余白', 'mode' => 'class', 'css' => 'margin-block-start', 'kind' => 'token', 'group' => 'spacing'],
        'spaceBottom' => ['label' => '下の余白', 'mode' => 'class', 'css' => 'margin-block-end', 'kind' => 'token', 'group' => 'spacing'],
        // 「表示する」は値ではなく **キーを外すこと**で表す。
        // `display: revert` を当てると詳細度で `.doc-container { display: flex }` に勝ってしまい、
        // 「表示する」を選んだだけでレイアウトが崩れる
        'visibility' => ['label' => '表示', 'mode' => 'class', 'css' => 'display', 'kind' => 'enum', 'values' => [
            'hidden' => 'none',
        ]],
    ];

    /**
     * 値の表示名（UX 一周 2026-09-28）。
     *
     * 編集画面の選択肢に `left` `gapSmall` `hidden` がそのまま出ていた。
     * **保存する値は変えない**（表示名だけ）。トークン名はテーマが決めるので、
     * 知っている名前だけに付け、知らない名前はそのまま出す。
     *
     * @var array<string, string>
     */
    private const VALUE_LABELS = [
        'left' => '左寄せ',
        'center' => '中央',
        'right' => '右寄せ',
        'hidden' => '隠す',
        'section' => '広い（区切りの間）',
        'gap' => 'ふつう',
        'gapSmall' => '狭い',
    ];

    /**
     * 値の表示名（トークン名を含む）。編集画面の `token` 型の選択肢にも使う。
     *
     * @return array<string, string>
     */
    public static function valueLabels(): array
    {
        return self::VALUE_LABELS;
    }

    public static function known(string $key): bool
    {
        return array_key_exists($key, self::PROPERTIES);
    }

    /**
     * 管理画面が入力欄を組み立てるための宣言。
     *
     * **ここに無いキーの入力欄は作れない。** 値の集合が閉じている（token / enum）なら
     * `values` を返し、画面は選択肢として出す。閉じていない（raw）なら `values` は null で、
     * 自由入力になる。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function specs(ThemeTokens $theme): array
    {
        $out = [];

        foreach (array_keys(self::PROPERTIES) as $key) {
            $values = self::allowedValues($key, $theme);

            $out[$key] = [
                'label' => (string) self::PROPERTIES[$key]['label'],
                'values' => $values,
                'valueLabels' => $values === null ? [] : array_intersect_key(self::VALUE_LABELS, array_flip($values)),
                'responsive' => self::isClassBased($key),
            ];
        }

        return $out;
    }

    /** クラス名で出すプロパティか（= breakpoint ごとに上書きできるか） */
    public static function isClassBased(string $key): bool
    {
        return self::known($key) && self::PROPERTIES[$key]['mode'] === 'class';
    }

    /** style 属性へ書くプロパティの CSS プロパティ名 */
    public static function inlineCssProperty(string $key): ?string
    {
        return self::known($key) && self::PROPERTIES[$key]['mode'] === 'inline'
            ? (string) self::PROPERTIES[$key]['css']
            : null;
    }

    /** @return list<string> クラス名で出すプロパティ（CSS 生成の対象） */
    public static function classBasedKeys(): array
    {
        return array_keys(array_filter(
            self::PROPERTIES,
            static fn (array $spec): bool => $spec['mode'] === 'class',
        ));
    }

    /**
     * その key が受け付ける値。**閉じている場合だけ**返す（raw は null）。
     *
     * @return list<string>|null
     */
    public static function allowedValues(string $key, ThemeTokens $theme): ?array
    {
        if (! self::known($key)) {
            return null;
        }

        $spec = self::PROPERTIES[$key];

        return match ($spec['kind']) {
            'token' => $theme->tokenNames((string) $spec['group']),
            'enum' => array_map(strval(...), array_keys((array) $spec['values'])),
            default => null,
        };
    }

    /**
     * 値を受け付けられない理由。受け付けるなら null。
     *
     * `raw` は**既存の document を無効化しない**ため、従来どおり
     * 「解決できない token 参照」だけを弾く。
     */
    public static function rejection(string $key, mixed $value, ThemeTokens $theme): ?string
    {
        if (! self::known($key)) {
            return 'は指定できません';
        }

        $spec = self::PROPERTIES[$key];

        if (! is_string($value)) {
            return 'の形式が不正です';
        }

        if ($spec['kind'] === 'raw') {
            return str_starts_with($value, '{') && $theme->resolve($value) === null
                ? 'のデザイントークンが見つかりません'
                : null;
        }

        $allowed = self::allowedValues($key, $theme) ?? [];

        return in_array($value, $allowed, true)
            ? null
            : 'に使えない値です（'.implode(' / ', $allowed).'）';
    }

    /** クラス名で出すプロパティの CSS 宣言（`margin-block-start:var(--space-gap)`）。 */
    public static function declaration(string $key, string $value, ThemeTokens $theme): ?string
    {
        if (! self::isClassBased($key)) {
            return null;
        }

        $spec = self::PROPERTIES[$key];

        $css = match ($spec['kind']) {
            'token' => $theme->cssVariable((string) $spec['group'], $value),
            'enum' => is_string($spec['values'][$value] ?? null) ? (string) $spec['values'][$value] : null,
            // raw は値の集合が閉じていないので class にできない（isClassBased で既に弾かれている）
            'raw' => null,
        };

        return $css === null ? null : $spec['css'].': '.$css;
    }

    /** `doc-spaceTop-gap` / `doc-mobile-spaceTop-gap`。renderer と CSS 生成で同じ形にする */
    public static function className(string $key, string $value, ?string $breakpoint = null): string
    {
        $parts = array_filter(['doc', $breakpoint, $key, $value], static fn (?string $p): bool => $p !== null);

        return implode('-', array_map(
            static fn (string $part): string => (string) preg_replace('/[^a-z0-9]+/i', '', $part),
            $parts,
        ));
    }
}

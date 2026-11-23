<?php

declare(strict_types=1);

namespace AgentCms\Core\Document;

use AgentCms\Core\Components\ComponentDefinition;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Theme\ThemeTokens;

/**
 * 編集中のアクセシビリティ検査（Phase 9.6 / P0-C8）。
 *
 * 基準は `web-a11y-check`（デジタル庁「ウェブアクセシビリティ導入ガイドブック」/
 * JIS X 8341-3:2016 / WCAG AA）と `PHASE9_1C_SPEC.md` §37 の表。
 *
 * ## 3 つの約束
 *
 * 1. **警告であって、エラーではない。** 保存も公開も止めない（Owner 決定 — §7.6）。
 *    直す判断は人がする。ここは気づく手がかりを出すだけ。
 * 2. **Registry が宣言したことだけを見る。** component の型名をここに書かない。
 *    `a11y` の宣言が無い component は、検査の対象にならない。
 * 3. **判定できないものを「合格」と言わない。** コンテンツから流し込まれる値や、
 *    テーマのトークンでない色は中身が分からないので `unknown`（要確認）にする。
 *    黙って通すと「検査したのに見つからなかった」と誤解される。
 *
 * ## 見ているもの
 *
 * | rule | 何を見るか | 出どころ |
 * | --- | --- | --- |
 * | `heading-order` | 見出しの段が飛んでいないか | `a11y.level` |
 * | `heading-empty` | 流し込みの見出しが空にならないか（自分で書く空の見出しは公開面に描かない） | `a11y.name` |
 * | `image-alt` | 画像に代替テキストがあるか（装飾なら空でよい） | `a11y.alt` / `a11y.decorative` |
 * | `control-name` | 操作できるものに名前があるか | `a11y.name` |
 * | `link-purpose` | 「こちら」だけになっていないか | `a11y.name` |
 * | `control-destination` | 行き先があるか（キーボードが空振りしない） | `a11y.destination` |
 * | `contrast` | 文字色と背景の比が足りているか | `style.color` と祖先の背景 |
 *
 * @phpstan-type Finding array{nodeId: string, type: string, rule: string, level: string, message: string}
 */
final class AccessibilityCheck
{
    /** 文字だけでは行き先が分からない言い回し（web-a11y ②） */
    private const VAGUE_LABELS = [
        'こちら', 'ここ', 'リンク', '詳細', '詳しく', 'もっと見る',
        'click here', 'here', 'read more', 'more', 'link',
    ];

    /**
     * まだ入れていないだけのもの（画像を選んでいない・リンク先が `#` のまま）。
     * 間違いではないので「要修正」とは言わず、次にすることを言う。
     */
    public const TODO = 'todo';

    /** 本文として必要な比。大きい文字なら 3:1 で足りる（WCAG 1.4.3） */
    private const CONTRAST_BODY = 4.5;

    private const CONTRAST_LARGE = 3.0;

    public function __construct(
        private readonly ComponentRegistry $components,
        private readonly ThemeTokens $theme,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return list<Finding>
     */
    public function inspect(array $document): array
    {
        $root = $document['root'] ?? null;

        if (! is_array($root)) {
            return [];
        }

        $findings = [];
        $previousLevel = null;

        $this->walk($root, null, $findings, $previousLevel);

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  string|null  $background  直近の祖先が持つ背景色（トークン参照）
     * @param  list<Finding>  $findings
     */
    private function walk(array $node, ?string $background, array &$findings, ?int &$previousLevel): void
    {
        $definition = $this->definitionFor($node);

        if ($definition !== null) {
            $this->check($node, $definition, $background, $findings, $previousLevel);
            $background = $this->backgroundOf($node, $definition) ?? $background;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walk($child, $background, $findings, $previousLevel);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function definitionFor(array $node): ?ComponentDefinition
    {
        $type = (string) ($node['type'] ?? '');

        // 登録が外れた component は検査できない。**黙って合格にはしない**が、
        // 描画も退避扱いなので、ここでは何も言わずに飛ばす（validator の担当）
        return $this->components->has($type) ? $this->components->get($type) : null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<Finding>  $findings
     */
    private function check(
        array $node,
        ComponentDefinition $definition,
        ?string $background,
        array &$findings,
        ?int &$previousLevel,
    ): void {
        $this->checkContrast($node, $background, $findings);

        if ($definition->a11y === []) {
            return;
        }

        $this->checkHeading($node, $definition, $findings, $previousLevel);
        $this->checkImage($node, $definition, $findings);
        $this->checkControl($node, $definition, $findings);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<Finding>  $findings
     */
    private function checkHeading(array $node, ComponentDefinition $definition, array &$findings, ?int &$previousLevel): void
    {
        $levelProp = $definition->a11y['level'] ?? null;

        if (! is_string($levelProp)) {
            return;
        }

        $level = $this->propValue($node, $definition, $levelProp);

        if (! is_numeric($level)) {
            return;
        }

        $level = (int) $level;

        // 段が 2 つ以上飛ぶと、読み上げで構造が追えなくなる（web-a11y ②）
        if ($previousLevel !== null && $level > $previousLevel + 1) {
            $findings[] = $this->finding(
                $node,
                'heading-order',
                'warning',
                "見出しの段が飛んでいます（{$previousLevel} の次が {$level}）。1 段ずつ下げてください。",
            );
        }

        // このページで最初の見出し。**公開側の雛形は見出しを持たない**ので、
        // ここが 1 で始まらないとページに一番大きい見出しが無いことになる
        if ($previousLevel === null && $level !== 1) {
            $findings[] = $this->finding(
                $node,
                'heading-order',
                'warning',
                "ページ最初の見出しが {$level} 段目です。一番大きい見出し（1 段目）から始めてください。",
            );
        }

        $previousLevel = $level;

        $nameProp = $definition->a11y['name'] ?? null;

        if (is_string($nameProp)) {
            $name = $this->propValue($node, $definition, $nameProp);

            /*
             * 自分で書く見出しが空のときは、何も言わない（UX 一周 3 周目 N3）。
             * 置いた直後の見出しは空になった（仮の文字を値に入れない）が、空の見出しは**公開面に描かない**
             * （`components/document/heading.blade.php`）ので、読み上げに空の見出しは出ない。
             * 編集画面では薄い字の「見出しを入力」が出ているので、書き忘れはそこで分かる。
             * 置いた直後に注意を出さない（最初の見出しで「要修正」を出さない、の約束）ためでもある。
             * 流し込みの見出しは、空かどうかが編集画面では分からないので、今までどおり確かめてもらう。
             */
            if ($this->bound($node, $nameProp)) {
                $findings[] = $this->unknownFinding($node, 'heading-empty', '見出しの文言はコンテンツ側から入ります。空にならないか確認してください。');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<Finding>  $findings
     */
    private function checkImage(array $node, ComponentDefinition $definition, array &$findings): void
    {
        $altProp = $definition->a11y['alt'] ?? null;

        if (! is_string($altProp)) {
            return;
        }

        $decorativeProp = $definition->a11y['decorative'] ?? null;

        if (is_string($decorativeProp) && $this->propValue($node, $definition, $decorativeProp) === true) {
            return;
        }

        /*
         * 🔴 **まだ画像を選んでいないなら、代替テキストを求めない**（UX 一周 2026-09-28）。
         *
         * 置いた直後に「代替テキストがありません（要修正）」が出ていた。最初の一手で注意される。
         * 足りないのは画像そのものなので、「未設定」として、次にすることだけを言う。
         */
        $srcProp = $definition->a11y['src'] ?? null;

        if (is_string($srcProp) && ! $this->bound($node, $srcProp)) {
            $src = $this->propValue($node, $definition, $srcProp);

            if ($src === null || $src === '' || $src === 0) {
                $findings[] = $this->finding($node, 'image-missing', self::TODO, 'まだ画像を選んでいません。「画像を選択」から選んでください。');

                return;
            }
        }

        if ($this->bound($node, $altProp)) {
            $findings[] = $this->unknownFinding($node, 'image-alt', '代替テキストはコンテンツ側から入ります。空にならないか確認してください。');

            return;
        }

        $alt = $this->propValue($node, $definition, $altProp);

        if (! is_string($alt) || trim($alt) === '') {
            $findings[] = $this->finding(
                $node,
                'image-alt',
                'warning',
                '画像の代替テキストがありません。内容を説明するか、装飾であれば「装飾目的」を選んでください。',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<Finding>  $findings
     */
    private function checkControl(array $node, ComponentDefinition $definition, array &$findings): void
    {
        if (! in_array('control-name', (array) ($definition->a11y['rules'] ?? []), true)) {
            return;
        }

        $nameProp = $definition->a11y['name'] ?? null;

        if (is_string($nameProp)) {
            if ($this->bound($node, $nameProp)) {
                $findings[] = $this->unknownFinding($node, 'control-name', '文言はコンテンツ側から入ります。行き先が分かる文言になるか確認してください。');
            } else {
                $name = $this->propValue($node, $definition, $nameProp);
                $name = is_string($name) ? trim($name) : '';

                if ($name === '') {
                    $findings[] = $this->finding($node, 'control-name', 'warning', '操作できるブロックに名前がありません。読み上げでは何のリンクか分かりません。');
                } elseif ($this->vague($name)) {
                    $findings[] = $this->finding(
                        $node,
                        'link-purpose',
                        'warning',
                        "「{$name}」だけでは行き先が分かりません。何へ行くのかを文言に含めてください。",
                    );
                }
            }
        }

        $destinationProp = $definition->a11y['destination'] ?? null;

        if (! is_string($destinationProp)) {
            return;
        }

        if ($this->bound($node, $destinationProp)) {
            $findings[] = $this->unknownFinding($node, 'control-destination', '行き先はコンテンツ側から入ります。空にならないか確認してください。');

            return;
        }

        $destination = $this->propValue($node, $definition, $destinationProp);
        $destination = is_string($destination) ? trim($destination) : '';

        /*
         * 置いた直後の `#` は「まだ決めていない」なので、要修正ではなく**未設定**として言う
         * （UX 一周 2026-09-28: 置いた直後に要修正が出ていた）。公開前に直すことは変わらない。
         */
        if ($destination === '' || $destination === '#') {
            $findings[] = $this->finding(
                $node,
                'control-destination',
                self::TODO,
                'リンク先をまだ決めていません。押したときに行くページを「リンク先」で選んでください（このままだと押しても何も起きません）。',
            );
        }
    }

    /**
     * 文字色と背景の比（WCAG 1.4.3）。
     *
     * **どちらかが分からなければ「要確認」**。トークンでない色は実際の値が読めず、
     * 大きい文字かどうかも document からは決められないので、
     * 本文の基準に足りないが大きい文字の基準は満たす範囲も「要確認」にする。
     *
     * @param  array<string, mixed>  $node
     * @param  list<Finding>  $findings
     */
    private function checkContrast(array $node, ?string $background, array &$findings): void
    {
        $color = $node['style']['color'] ?? null;

        if (! is_string($color) || $color === '') {
            return;
        }

        $foreground = $this->theme->resolve($color);
        $backgroundValue = $background === null
            ? ($this->theme->resolve('{colors.bg}') ?? null)
            : $this->theme->resolve($background);

        if ($foreground === null || $backgroundValue === null) {
            $findings[] = $this->unknownFinding(
                $node,
                'contrast',
                'テーマの色ではないため、背景との読みやすさを判定できません。実際の画面で確認してください。',
            );

            return;
        }

        $ratio = self::contrastRatio($foreground, $backgroundValue);

        if ($ratio === null) {
            $findings[] = $this->unknownFinding($node, 'contrast', '色を読み取れないため、背景との読みやすさを判定できません。');

            return;
        }

        $rounded = number_format($ratio, 1);

        if ($ratio < self::CONTRAST_LARGE) {
            $findings[] = $this->finding(
                $node,
                'contrast',
                'warning',
                "文字と背景の差が小さすぎます（{$rounded}:1）。本文には 4.5:1 以上が要ります。",
            );

            return;
        }

        if ($ratio < self::CONTRAST_BODY) {
            $findings[] = $this->unknownFinding(
                $node,
                'contrast',
                "文字と背景の差は {$rounded}:1 です。大きい文字なら足りますが、本文には 4.5:1 が要ります。",
            );
        }
    }

    /** @param array<string, mixed> $node */
    private function backgroundOf(array $node, ComponentDefinition $definition): ?string
    {
        foreach ($definition->propsSchema as $key => $spec) {
            if (($spec['type'] ?? null) !== 'token' || ($spec['css'] ?? null) !== 'background') {
                continue;
            }

            $value = $node['props'][$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * その prop が binding で供給されるか（= ここでは中身が分からない）。
     *
     * @param  array<string, mixed>  $node
     */
    private function bound(array $node, string $prop): bool
    {
        $binding = $node['binding'] ?? null;

        if (! is_array($binding)) {
            return false;
        }

        /*
         * **`prop` の無い binding は「供給されている」と見なさない**（SPEC §4.9 / P0-C5）。
         *
         * 先頭 prop へ落とす読みを残すと、**空という事実が「中身が分からない」に化ける**。
         * 描画側（`DocumentRenderer`）が流さないものを、ここだけ流れていることにしない。
         */
        return is_string($binding['prop'] ?? null) && $binding['prop'] === $prop;
    }

    /** @param array<string, mixed> $node */
    private function propValue(array $node, ComponentDefinition $definition, string $prop): mixed
    {
        return $node['props'][$prop] ?? $definition->propsSchema[$prop]['default'] ?? null;
    }

    private function vague(string $name): bool
    {
        // **`trim()` に多バイトの文字集合を渡さない。** バイト単位で削るので
        // 「こちら」のような文字列が壊れ、判定が素通りする
        $normalised = mb_strtolower((string) preg_replace(
            '/^[\s　.。、,!！?？><「」]+|[\s　.。、,!！?？><「」]+$/u',
            '',
            $name,
        ));

        foreach (self::VAGUE_LABELS as $vague) {
            // 「こちら」だけでなく「ここをクリック」「詳しくはこちら」も同じ問題。
            // 前でも後ろでも、曖昧な語が文言の端に来ていれば行き先は分からない
            if ($normalised === $vague
                || str_starts_with($normalised, $vague)
                || str_ends_with($normalised, $vague)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return Finding
     */
    private function finding(array $node, string $rule, string $level, string $message): array
    {
        return [
            'nodeId' => (string) ($node['id'] ?? ''),
            'type' => (string) ($node['type'] ?? ''),
            'rule' => $rule,
            'level' => $level,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return Finding
     */
    private function unknownFinding(array $node, string $rule, string $message): array
    {
        return $this->finding($node, $rule, 'unknown', $message);
    }

    /** WCAG の相対輝度による比。読み取れない色は null */
    public static function contrastRatio(string $foreground, string $background): ?float
    {
        $first = self::luminance($foreground);
        $second = self::luminance($background);

        if ($first === null || $second === null) {
            return null;
        }

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $color): ?float
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color), $match) !== 1) {
            return null;
        }

        $hex = $match[1];

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}

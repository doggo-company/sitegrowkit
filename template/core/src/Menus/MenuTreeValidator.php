<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

/**
 * 保存される木の形を検査する（Phase 9.9 / STAGE 22B・SPEC §B.4・§B.5・§B.8・§D.5）。
 *
 * ## 木として受け取る
 *
 * 入力は入れ子の配列で、`parent_id` は**含まない**。親子は構造そのものが表す。
 * こうすると「別のメニューの項目を親にした」「自分の子孫を親にした」という
 * 状態が**入力として作れない**。検査で弾くのではなく、書けなくする。
 *
 * ## 見るもの
 *
 * - 深さの上限（OG3 = 親 + 子の 2 階層）
 * - `link_type` ごとに **値を持ってよい列だけ**が埋まっているか（SPEC §B.4）
 * - 名前を導出できない link_type では `title` が入っているか（SPEC §B.8）
 * - `link_url` の書き出し（許した形以外を通さない。`javascript:` と `data:` は入らない。SPEC §D.5）
 *
 * **参照先が実在するか**はここでは見ない。それは DB を引く話で、
 * `MenuTreeWriter` が transaction の中で確かめる（形の検査と存在の検査を混ぜない）。
 */
final class MenuTreeValidator
{
    /** OG3。親 + 子まで */
    public const MAX_DEPTH = 2;

    /**
     * `link_url` に許す書き出し。
     *
     * `javascript:` と `data:` を弾くのが目的なので、**許可した形以外は通さない**
     * （禁止語を並べる方式にすると、書き方を変えられて抜ける）。
     */
    private const URL_PATTERN = '#^(https?://[^\s]+|mailto:[^\s]+|tel:[^\s]+|/[^\s]*|\#[^\s]*)$#i';

    /**
     * @param  list<mixed>  $items
     * @return array<string, list<string>> 空なら妥当。キーは `items.0.children.1.title` の形
     */
    public function validate(array $items): array
    {
        $errors = [];

        $this->walk($items, 'items', 1, $errors);

        return $errors;
    }

    /**
     * @param  list<mixed>  $items
     * @param  array<string, list<string>>  $errors
     */
    private function walk(array $items, string $path, int $depth, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[$path][] = '階層が深すぎます（'.self::MAX_DEPTH.' 階層までです）';

            return;
        }

        foreach ($items as $index => $item) {
            $at = "{$path}.{$index}";

            if (! is_array($item)) {
                $errors[$at][] = '項目の形が正しくありません';

                continue;
            }

            $this->item($item, $at, $errors);

            $children = $item['children'] ?? [];

            if (! is_array($children)) {
                $errors["{$at}.children"][] = '子の形が正しくありません';

                continue;
            }

            if ($children !== []) {
                $this->walk(array_values($children), "{$at}.children", $depth + 1, $errors);
            }
        }
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function item(array $item, string $at, array &$errors): void
    {
        $type = is_string($item['link_type'] ?? null)
            ? MenuLinkType::tryFrom($item['link_type'])
            : null;

        if ($type === null) {
            $errors["{$at}.link_type"][] = 'リンクの種類が正しくありません';

            return;
        }

        $this->references($item, $type, $at, $errors);
        $this->title($item, $type, $at, $errors);
        $this->target($item, $at, $errors);
    }

    /**
     * `link_type` ごとに**使ってよい列だけ**が埋まっているか。
     *
     * 使わない列に値が残っていると、同じことを 2 通りで持つ状態
     * （例: `page` なのに `link_url` もある）ができる。どちらが正しいかを
     * 後から決められないので、その場で止める。
     *
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function references(array $item, MenuLinkType $type, string $at, array &$errors): void
    {
        $allowed = $type->columns();

        foreach (MenuLinkType::ALL_COLUMNS as $column) {
            $value = $item[$column] ?? null;
            $filled = $value !== null && $value !== '';

            if (! in_array($column, $allowed, true)) {
                if ($filled) {
                    $errors["{$at}.{$column}"][] = "{$type->value} のリンクでは指定できません";
                }

                continue;
            }

            if (! $filled) {
                $errors["{$at}.{$column}"][] = 'このリンクの種類では必須です';

                continue;
            }

            if ($column === 'link_url' && (! is_string($value) || preg_match(self::URL_PATTERN, $value) !== 1)) {
                // 何が悪いかは言うが、入力をそのまま返さない
                $errors["{$at}.link_url"][] =
                    'http / https / mailto: / tel:、/ で始まるパス、# で始まるアンカーだけが使えます';
            }

            if (in_array($column, ['page_id', 'content_id'], true) && ! self::isPositiveInt($value)) {
                $errors["{$at}.{$column}"][] = '参照先の指定が正しくありません';
            }
        }
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function title(array $item, MenuLinkType $type, string $at, array &$errors): void
    {
        $title = $item['title'] ?? null;

        if ($title !== null && (! is_string($title) || trim($title) === '')) {
            $errors["{$at}.title"][] = '表示名が空です（省略するなら null にしてください）';

            return;
        }

        // 名前を作れない種類では省略できない（SPEC §B.8）
        if ($title === null && ! $type->derivesTitle()) {
            $errors["{$at}.title"][] = 'このリンクの種類では表示名が必要です';
        }

        if (is_string($title) && mb_strlen($title) > 120) {
            $errors["{$at}.title"][] = '表示名が長すぎます（120 文字まで）';
        }
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function target(array $item, string $at, array &$errors): void
    {
        $target = $item['target'] ?? MenuTarget::Self->value;

        if (! is_string($target) || MenuTarget::tryFrom($target) === null) {
            $errors["{$at}.target"][] = '開き方の指定が正しくありません';
        }
    }

    private static function isPositiveInt(mixed $value): bool
    {
        return is_int($value) ? $value > 0 : (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }
}

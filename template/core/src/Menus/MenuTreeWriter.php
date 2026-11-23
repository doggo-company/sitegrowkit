<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TermLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * 木ごと差し替える（Phase 9.9 / STAGE 22B・SPEC §E.2・OG5）。
 *
 * ## なぜ差し替えなのか
 *
 * 並べ替えと階層変更は、本質的に「木全体の形」を変える操作である。
 * 項目ごとの CRUD に割ると、途中で失敗したときに**木が壊れた状態で残る**。
 * 1 回の transaction で入れ替えるほうが、原子性を自前で組み直さずに済む。
 *
 * ## それでも id は捨てない
 *
 * 全部消して入れ直すと id が変わる。監査の連続性が切れ、
 * 「同じ項目を編集した」のか「消して作った」のかが後から読めなくなる。
 * だから **送られてきた id は保ち**、送られてこなかった項目だけを消す。
 *
 * ## 参照先の実在はここで見る
 *
 * 形の検査は `MenuTreeValidator` が済ませている。ここが見るのは
 * 「そのページは在るか」「その投稿タイプは在るか」で、**DB を引く話**である。
 * ゴミ箱にあるものは通す（データは失われていない。公開面で落とすのは読むときの仕事 — OG4）。
 */
final class MenuTreeWriter
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeRegistry $types,
        private readonly TermLink $terms,
    ) {}

    /**
     * 参照先が実在するか。**書く前に**呼ぶ。
     *
     * @param  list<mixed>  $items
     * @return array<string, list<string>> 空なら妥当
     */
    public function verifyReferences(array $items, string $path = 'items'): array
    {
        $errors = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $at = "{$path}.{$index}";
            $type = MenuLinkType::tryFrom((string) ($item['link_type'] ?? ''));

            match ($type) {
                MenuLinkType::Route => $this->verifyRoute($item, $at, $errors),
                MenuLinkType::Page => $this->verifyPage($item, $at, $errors),
                MenuLinkType::Content => $this->verifyContent($item, $at, $errors),
                MenuLinkType::ContentIndex => $this->verifyContentType($item, $at, $errors),
                MenuLinkType::Term => $this->verifyTerm($item, $at, $errors),
                default => null,
            };

            $children = $item['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $errors = [...$errors, ...$this->verifyReferences(array_values($children), "{$at}.children")];
            }
        }

        return $errors;
    }

    /**
     * 木を書き込む。**呼び出し側の transaction の中で呼ぶこと。**
     *
     * @param  list<mixed>  $items
     * @return list<int> 書き込んだ項目の id（親から順）
     */
    public function write(Menu $menu, array $items): array
    {
        $submitted = self::collectIds($items);

        /*
         * **先に親子を外す。** `parent_id` は cascade delete なので、
         * 親を消すと残すはずの子まで消える。いったん全部 root にしてから
         * 消す・入れ直す・繋ぎ直す、の順にすると順序に依存しない。
         */
        MenuItem::query()->where('menu_id', $menu->getKey())->update(['parent_id' => null]);

        MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->when($submitted !== [], static fn ($q) => $q->whereNotIn('id', $submitted))
            ->delete();

        $written = [];

        $this->persist($menu, $items, null, $written);

        return $written;
    }

    /**
     * 送られてきた id が **この menu のもの**か。
     *
     * 他の menu の id を混ぜると、項目を横取りできてしまう。
     *
     * @param  list<mixed>  $items
     * @return list<int> 不正な id
     */
    public function foreignIds(Menu $menu, array $items): array
    {
        $submitted = self::collectIds($items);

        if ($submitted === []) {
            return [];
        }

        $mine = MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->whereIn('id', $submitted)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return array_values(array_diff($submitted, $mine));
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<int>  $written
     */
    private function persist(Menu $menu, array $items, ?int $parentId, array &$written): void
    {
        // **並び順は書いた数で数える。** 配列の添字を使うと、飛ばした要素の分だけ穴が空く
        $order = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = MenuLinkType::from((string) $item['link_type']);

            $attributes = [
                'menu_id' => $menu->getKey(),
                'parent_id' => $parentId,
                'sort_order' => $order,
                'title' => isset($item['title']) && is_string($item['title']) ? trim($item['title']) : null,
                'link_type' => $type->value,
                'target' => is_string($item['target'] ?? null) ? $item['target'] : MenuTarget::Self->value,
            ];

            // 使わない列は **null で上書きする**（前の種類の値を残さない）
            foreach (MenuLinkType::ALL_COLUMNS as $column) {
                $attributes[$column] = in_array($column, $type->columns(), true)
                    ? $item[$column]
                    : null;
            }

            $id = self::intOrNull($item['id'] ?? null);

            if ($id !== null && MenuItem::query()->whereKey($id)->where('menu_id', $menu->getKey())->exists()) {
                MenuItem::query()->whereKey($id)->update($attributes);
                $row = $id;
            } else {
                $row = (int) MenuItem::query()->insertGetId($attributes + [
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $written[] = $row;
            $order++;

            $children = $item['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $this->persist($menu, array_values($children), $row, $written);
            }
        }
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function verifyRoute(array $item, string $at, array &$errors): void
    {
        /*
         * 保存時に見るのは **打ち間違いを拾うため**である。
         * module が入っていない生成物では、その route を指す項目は
         * そもそも作れない（seeder も入れない — SPEC §H.1）。
         */
        if (! Route::has((string) $item['link_route'])) {
            $errors["{$at}.link_route"][] = 'その名前のページはありません';
        }
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function verifyPage(array $item, string $at, array &$errors): void
    {
        // ゴミ箱にあるものも「在る」とみなす（消えてはいない）
        if (! DB::table('pages')->where('id', (int) $item['page_id'])->exists()) {
            $errors["{$at}.page_id"][] = 'そのページはありません';
        }
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function verifyContent(array $item, string $at, array &$errors): void
    {
        if (! $this->verifyContentType($item, $at, $errors)) {
            return;
        }

        $table = $this->schemas->get((string) $item['content_type'])->table;

        if (! DB::table($table)->where('id', (int) $item['content_id'])->exists()) {
            $errors["{$at}.content_id"][] = 'その投稿はありません';
        }
    }

    /**
     * 分類の一覧を指す項目（WP 一周 7 周目 2026-09-03）。
     *
     * 入口（投稿タイプ）と項目の**両方**を見る。
     * 保存できてしまうと、描くときに黙って消える項目になる。
     *
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function verifyTerm(array $item, string $at, array &$errors): bool
    {
        $ok = $this->verifyContentType($item, $at, $errors);

        $termId = is_string($item['taxonomy_term_id'] ?? null) ? $item['taxonomy_term_id'] : '';

        if ($this->terms->find($termId) === null) {
            $errors["{$at}.taxonomy_term_id"][] = 'その分類の項目はありません';

            return false;
        }

        return $ok;
    }

    /**
     * @param  array<mixed>  $item
     * @param  array<string, list<string>>  $errors
     */
    private function verifyContentType(array $item, string $at, array &$errors): bool
    {
        $type = (string) ($item['content_type'] ?? '');

        if (! $this->types->has($type) || ! $this->schemas->has($type)) {
            $errors["{$at}.content_type"][] = 'その投稿タイプはありません';

            return false;
        }

        return true;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function collectIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = self::intOrNull($item['id'] ?? null);

            if ($id !== null) {
                $ids[] = $id;
            }

            $children = $item['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $ids = [...$ids, ...self::collectIds(array_values($children))];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Media の使用箇所を Schema から動的に導出する（使用状況テーブルは持たない — ADR-0006）。
 *
 * Phase 9.2B（F-S3 の解消）: media 参照は **カラムだけでなく `data` JSON の中にもある**。
 * `images` の配列・`group` の中・`repeater` の各行まで **再帰的に**走査する。
 * ここを取りこぼすと「使用中の画像が削除できてしまう」事故になるため、
 * カラム検索と JSON 走査の両方を必ず通す。
 *
 * ## Phase 9.14（27E-3・ADR-0020）: **id だけでは足りない**
 *
 * 上の走査が探しているのは media の **id** である。ところが本文へ埋め込まれた画像は
 * **URL の文字列**として在る（WXR の取り込みが本文の URL を差し替えるのがその例で、
 * 人が richtext へ貼った画像も同じ形になる）。
 * id しか見ない走査は、そういう画像を「使われていない」と答える —
 * **管理画面の削除でも記事の画像が壊れる**。取り消しの都合ではなく、元からあった穴である。
 *
 * 照らし合わせるのは **`media.path`** で、URL 全体ではない。host は `APP_URL` を
 * 変えれば変わるが、保存した経路は変わらない。
 */
final class MediaUsageInspector
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * 1 件の使用箇所一覧。
     *
     * @return list<array{schema: string, label: string, field: string, items: list<array{id: int, title: string, url: string|null, trashed: bool}>}>
     */
    public function usages(int $mediaId): array
    {
        $result = [];
        $path = $this->pathOf($mediaId);
        $settings = $this->settingValues();

        foreach ($this->schemas->all() as $schema) {
            // 設定は専用テーブルの行ではなく settings のキーと値に保存される。
            if ($schema->kind === 'settings') {
                array_push($result, ...$this->settingUsages($schema, $mediaId, $path, $settings));

                continue;
            }

            if (! $this->scannable($schema)) {
                continue;
            }

            foreach ($this->columnMediaFields($schema) as $field) {
                $items = $this->present(
                    $schema,
                    DB::table($schema->table)->where($field->key, $mediaId)->limit(20)->get(),
                );

                if ($items !== []) {
                    $result[] = [
                        'schema' => $schema->id,
                        'label' => $schema->label,
                        'field' => $field->key,
                        'items' => $items,
                    ];
                }
            }

            foreach ($path === '' ? [] : $this->textFields($schema) as $field) {
                /*
                 * **path が空なら 1 度も探さない。** `like '%%'` は全行に当たるので、
                 * 「media の行が無い」を「全部で使われている」に化けさせてしまう。
                 */
                $items = $this->present(
                    $schema,
                    DB::table($schema->table)->where($field->key, 'like', '%'.$path.'%')->limit(20)->get(),
                );

                if ($items !== []) {
                    $result[] = [
                        'schema' => $schema->id,
                        'label' => $schema->label,
                        'field' => $field->key,
                        'items' => $items,
                    ];
                }
            }

            if ($path !== '' && $this->hasDataColumn($schema)) {
                /*
                 * **JSON は欄ごとに辿らない。** URL は richtext / text のどこにでも
                 * 入り得るうえ、入れ子の途中にも現れる。`data` を丸ごと見れば
                 * **取りこぼしが無い**（余分に拾う方へ倒す — 使用中と答える側は安全）。
                 */
                $items = $this->present(
                    $schema,
                    DB::table($schema->table)->where('data', 'like', '%'.$path.'%')->limit(20)->get(),
                );

                if ($items !== []) {
                    $result[] = [
                        'schema' => $schema->id,
                        'label' => $schema->label,
                        'field' => 'data',
                        'items' => $items,
                    ];
                }
            }

            foreach ($this->jsonMediaFields($schema) as $field) {
                $items = $this->present($schema, $this->rowsReferencingInJson($schema, $field, $mediaId));

                if ($items !== []) {
                    $result[] = [
                        'schema' => $schema->id,
                        'label' => $schema->label,
                        'field' => $field->key,
                        'items' => $items,
                    ];
                }
            }
        }

        // page_versions.document のブロック内画像も検査する（ADR-0006 の SchemaRegistry 外）
        array_push($result, ...$this->pageVersionsUsages($mediaId, $path));

        return $result;
    }

    /**
     * 複数 Media の使用中 ID 集合（一覧表示用）。
     *
     * @param  list<int>  $mediaIds
     * @return array<int, bool> media id => 使用中か
     */
    public function usedMap(array $mediaIds): array
    {
        $used = array_fill_keys($mediaIds, false);

        if ($mediaIds === []) {
            return $used;
        }

        $settings = $this->settingValues();
        $paths = $this->pathsOf($mediaIds);

        foreach ($this->schemas->all() as $schema) {
            if ($schema->kind === 'settings') {
                foreach ($mediaIds as $id) {
                    $used[$id] = $used[$id] || $this->settingUsages($schema, $id, $paths[$id] ?? '', $settings) !== [];
                }

                continue;
            }

            if (! $this->scannable($schema)) {
                continue;
            }

            foreach ($this->columnMediaFields($schema) as $field) {
                $ids = DB::table($schema->table)
                    ->whereIn($field->key, $mediaIds)
                    ->distinct()
                    ->pluck($field->key);

                foreach ($ids as $id) {
                    $used[(int) $id] = true;
                }
            }

            if ($this->jsonMediaFields($schema) === []) {
                continue;
            }

            // JSON 側は 1 行ずつ中身を見る（件数は管理画面の 1 ページぶん）
            foreach (DB::table($schema->table)->whereNotNull('data')->pluck('data') as $raw) {
                foreach ($this->mediaIdsInData($schema, $raw) as $id) {
                    if (array_key_exists($id, $used)) {
                        $used[$id] = true;
                    }
                }
            }
        }

        // page_versions.document のブロック内画像も検査する（ADR-0006 の SchemaRegistry 外）
        if (Schema::hasTable('page_versions')) {
            foreach (DB::table('page_versions')->pluck('document') as $raw) {
                foreach ($this->mediaIdsInPageDocument($raw) as $id) {
                    if (array_key_exists($id, $used)) {
                        $used[$id] = true;
                    }
                }
            }
            // path-based: image blocks store mediaId, not path, so no LIKE scan needed here
            // (path-based references in richtext within blocks are handled below via referencedByPath)
        }

        /*
         * **URL による参照も「使用中」である**（27E-3・ADR-0020）。
         * 一覧のためにここも見る — 見なければ、一覧では「未使用」と出るものが
         * 削除では拒否される、という食い違いになる。
         */
        foreach ($paths as $id => $path) {
            if ($used[$id] || $path === '') {
                continue;
            }

            $used[$id] = $this->referencedByPath($path);
        }

        return $used;
    }

    /**
     * 設定画像も使用中として保護する。削除判定ではキャッシュではなく保存済みの値を見る。
     *
     * @return array<string, mixed>
     */
    private function settingValues(): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        return DB::table('settings')->pluck('value', 'key')
            ->map(static fn ($value) => json_decode((string) $value, true))->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{schema: string, label: string, field: string, items: list<array{id: int, title: string, url: string|null, trashed: bool}>}>
     */
    private function settingUsages(ContentSchema $schema, int $mediaId, string $path, array $values): array
    {
        $usages = [];

        foreach ($schema->fields as $field) {
            $value = $values[$field->key] ?? null;
            $ids = [];
            $this->collectMediaIds($field, $value, $ids);
            $text = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! in_array($mediaId, $ids, true) && ($path === '' || ! is_string($text) || ! str_contains($text, $path))) {
                continue;
            }

            $route = "admin.{$schema->id}.edit";
            $usages[] = [
                'schema' => $schema->id,
                'label' => $schema->label,
                'field' => $field->key,
                'items' => [[
                    'id' => 0,
                    'title' => $schema->label.' / '.$field->label,
                    'url' => Route::has($route) ? route($route, absolute: false) : null,
                    'trashed' => false,
                ]],
            ];
        }

        return $usages;
    }

    /** その path を本文や `data` が含んでいるか */
    private function referencedByPath(string $path): bool
    {
        foreach ($this->schemas->all() as $schema) {
            if (! $this->scannable($schema)) {
                continue;
            }

            foreach ($this->textFields($schema) as $field) {
                if (DB::table($schema->table)->where($field->key, 'like', '%'.$path.'%')->exists()) {
                    return true;
                }
            }

            if ($this->hasDataColumn($schema)
                && DB::table($schema->table)->where('data', 'like', '%'.$path.'%')->exists()) {
                return true;
            }
        }

        return false;
    }

    /** 保存した経路。**URL 全体ではない**（host は `APP_URL` 次第で変わる） */
    private function pathOf(int $mediaId): string
    {
        return (string) DB::table('media')->where('id', $mediaId)->value('path');
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, string>
     */
    private function pathsOf(array $mediaIds): array
    {
        $paths = [];

        foreach (DB::table('media')->whereIn('id', $mediaIds)->get(['id', 'path']) as $row) {
            $paths[(int) $row->id] = (string) $row->path;
        }

        return $paths;
    }

    /**
     * 文字列を持つ欄。**URL はここに埋まる。**
     *
     * @return list<Field>
     */
    private function textFields(ContentSchema $schema): array
    {
        return array_values(array_filter(
            $schema->columnFields(),
            static fn (Field $f): bool => in_array($f->type, ['text', 'textarea', 'richtext', 'url'], true),
        ));
    }

    private function hasDataColumn(ContentSchema $schema): bool
    {
        return $schema->jsonFields() !== [];
    }

    /**
     * その型の表が実在するか。
     *
     * **schema が在っても表が無いことがある** — module を配っていない・migration を
     * まだ流していない、のどちらも正常な状態である。id を探す走査は
     * 「media の欄を持つ型」だけを見るので当たらなかったが、文字列の走査は
     * ほぼ全部の型を見るので、ここで初めて当たる。
     */
    private function scannable(ContentSchema $schema): bool
    {
        return Schema::hasTable($schema->table);
    }

    /** @return list<Field> カラムに保存される media 参照 */
    private function columnMediaFields(ContentSchema $schema): array
    {
        return array_values(array_filter(
            $schema->columnFields(),
            static fn (Field $f): bool => $f->referencesMedia() && in_array($f->type, ['media', 'file'], true),
        ));
    }

    /** @return list<Field> data JSON の中に media 参照を含み得るフィールド */
    private function jsonMediaFields(ContentSchema $schema): array
    {
        return array_values(array_filter(
            $schema->jsonFields(),
            fn (Field $f): bool => $this->containsMedia($f),
        ));
    }

    /**
     * 入れ子も辿って media 参照を含むか判定する。
     *
     * **自分自身が参照型のときも true。** `images` だけを見ていた頃は、
     * JSON に入る `file` / `media`（Field Group が足した項目はすべて JSON）を
     * 取りこぼし、入れ子の中で使われているファイルが削除できてしまった。
     */
    private function containsMedia(Field $field): bool
    {
        if ($field->referencesMedia()) {
            return true;
        }

        foreach ($field->fields as $child) {
            if ($this->containsMedia($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * data JSON の中から media id をすべて集める（group / repeater / images を再帰）。
     *
     * @return list<int>
     */
    private function mediaIdsInData(ContentSchema $schema, mixed $raw): array
    {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($data)) {
            return [];
        }

        $ids = [];

        foreach ($schema->jsonFields() as $field) {
            if (array_key_exists($field->key, $data)) {
                $this->collectMediaIds($field, $data[$field->key], $ids);
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $ids */
    private function collectMediaIds(Field $field, mixed $value, array &$ids): void
    {
        if ($value === null) {
            return;
        }

        if ($field->referencesMedia()) {
            foreach (is_array($value) ? $value : [$value] as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }

            return;
        }

        if ($field->type === 'group' && is_array($value)) {
            foreach ($field->fields as $child) {
                if (array_key_exists($child->key, $value)) {
                    $this->collectMediaIds($child, $value[$child->key], $ids);
                }
            }

            return;
        }

        if ($field->type === 'repeater' && is_array($value)) {
            foreach ($value as $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach ($field->fields as $child) {
                    if (array_key_exists($child->key, $row)) {
                        $this->collectMediaIds($child, $row[$child->key], $ids);
                    }
                }
            }
        }
    }

    /**
     * JSON の中でその media を参照している行。
     *
     * @return Collection<int, \stdClass>
     */
    private function rowsReferencingInJson(ContentSchema $schema, Field $field, int $mediaId)
    {
        return DB::table($schema->table)
            ->whereNotNull('data')
            ->get()
            ->filter(function (object $row) use ($field, $mediaId): bool {
                $data = json_decode((string) $row->data, true);

                if (! is_array($data) || ! array_key_exists($field->key, $data)) {
                    return false;
                }

                $ids = [];
                $this->collectMediaIds($field, $data[$field->key], $ids);

                return in_array($mediaId, $ids, true);
            })
            ->take(20)
            ->values();
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{id: int, title: string, url: string|null, trashed: bool}>
     */
    private function present(ContentSchema $schema, Collection $rows): array
    {
        $titleKey = $this->labelColumn($schema);

        /*
         * **行き先とゴミ箱を添える**（WP 一周 9 周目 2026-09-04 / 効くもの ③）。
         *
         * 「使用中のため削除できません」だけでは、消したい人は次に何をすればよいか
         * 分からない。**どこで使われているか**へ行けるようにする。
         *
         * 🔴 ゴミ箱に入っているものも「使用中」である（戻すときに要るので正しい）。
         * それを言わないと「消したのにまだ使用中」で二度止まる。
         */
        $route = "admin.{$schema->id}.edit";
        $editable = Route::has($route);

        /** @var list<array{id: int, title: string, url: string|null, trashed: bool}> $items */
        $items = $rows->map(static fn (\stdClass $row): array => [
            'id' => (int) $row->id,
            'title' => (string) ($titleKey !== null && isset($row->{$titleKey}) ? $row->{$titleKey} : '#'.$row->id),
            'url' => $editable ? route($route, ['id' => (int) $row->id], absolute: false) : null,
            'trashed' => ($row->deleted_at ?? $row->trashed_at ?? null) !== null,
        ])->values()->all();

        return $items;
    }

    /** タイトル表示に使えるカラム（最初の text フィールド） */
    private function labelColumn(ContentSchema $schema): ?string
    {
        foreach ($schema->fields as $field) {
            if ($field->type === 'text') {
                return $field->key;
            }
        }

        return null;
    }

    /**
     * page_versions.document に含まれる media id をすべて集める。
     *
     * image ブロックは `props.mediaId` に整数で保存する。
     * document JSON を再帰的に歩いて type=image のノードから mediaId を収集する。
     *
     * @return list<int>
     */
    private function mediaIdsInPageDocument(mixed $raw): array
    {
        $document = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($document)) {
            return [];
        }

        $ids = [];
        $root = $document['root'] ?? null;

        if (is_array($root)) {
            $this->collectDocumentMediaIds($root, $ids);
        }

        return array_values(array_unique($ids));
    }

    /**
     * document ノードを再帰的に歩いて media id を集める。
     *
     * @param  array<string, mixed>  $node
     * @param  list<int>  $ids
     */
    private function collectDocumentMediaIds(array $node, array &$ids): void
    {
        // image ブロックは props.mediaId に整数で保存する
        if (($node['type'] ?? null) === 'image') {
            $mediaId = $node['props']['mediaId'] ?? null;

            if (is_numeric($mediaId)) {
                $ids[] = (int) $mediaId;
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectDocumentMediaIds($child, $ids);
            }
        }
    }

    /**
     * page_versions.document でその media を使っているページの一覧。
     *
     * @return list<array{schema: string, label: string, field: string, items: list<array{id: int, title: string, url: string|null, trashed: bool}>}>
     */
    private function pageVersionsUsages(int $mediaId, string $path): array
    {
        if (! Schema::hasTable('page_versions') || ! Schema::hasTable('pages')) {
            return [];
        }

        // page_id をキーに「使用中」フラグを集める（複数バージョンでも page_id は 1 件）
        $matchingPageIds = [];

        foreach (DB::table('page_versions')->select('id', 'page_id', 'document')->cursor() as $row) {
            $ids = $this->mediaIdsInPageDocument($row->document);

            if (in_array($mediaId, $ids, true)) {
                $matchingPageIds[(int) $row->page_id] = true;

                continue;
            }

            // path による参照（richtext 内に埋め込まれた画像 URL など）
            if ($path !== '' && is_string($row->document) && str_contains($row->document, $path)) {
                $matchingPageIds[(int) $row->page_id] = true;
            }
        }

        if ($matchingPageIds === []) {
            return [];
        }

        /** @var list<array{id: int, title: string, url: string|null, trashed: bool}> $items */
        $items = DB::table('pages')
            ->whereIn('id', array_keys($matchingPageIds))
            ->get(['id', 'title', 'slug', 'trashed_at'])
            ->map(static fn (\stdClass $row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'url' => Route::has('admin.pages.edit') ? route('admin.pages.edit', ['id' => (int) $row->id], absolute: false) : null,
                'trashed' => $row->trashed_at !== null,
            ])
            ->values()
            ->all();

        return [[
            'schema' => 'page_versions',
            'label' => 'ページブロック',
            'field' => 'document',
            'items' => $items,
        ]];
    }
}

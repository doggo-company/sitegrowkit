<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRules;
use AgentCms\Core\Trash\TrashColumns;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;

/**
 * Content の書き込み入力を schema から正規化・検証する（Phase 9.1B / STEP 4）。
 *
 * 以前は SchemaResourceController::validated() にあった処理。
 * **Admin も API も MCP も同じ検証を通す**ために Application 層へ移した。
 */
final class ContentInput
{
    /** 同じ日の連番の打ち切り。ここまで埋まっていたら乱数へ倒す */
    private const MAX_DATE_SUFFIX = 200;

    public function __construct(
        private readonly ValidationFactory $validator,
        private readonly ContentReferences $references,
    ) {}

    /**
     * slug 自動生成 → schema 由来のバリデーション → NOT NULL 補完 までを行う。
     *
     * @param  array<string, mixed>  $attributes
     * @param  bool  $partial  true なら送られたキーだけを検証する（部分更新）
     * @return array{data: array<string, mixed>|null, errors: array<string, list<string>>}
     */
    public function prepare(
        ContentSchema $schema,
        string $table,
        array $attributes,
        ?int $ignoreId = null,
        bool $partial = false,
    ): array {
        $attributes = $this->deriveSlugs($schema, $table, $attributes, $partial);
        $attributes = $this->stampPublishedAt($schema, $attributes, $partial);
        $attributes = $this->applyDefaults($schema, $attributes, $partial);

        $rules = SchemaRules::for($schema, $table, $ignoreId);

        if ($partial) {
            // 入れ子のルールは `specs.weight` / `features.*.heading` のような形なので、
            // 先頭のフィールド名で残す（キー完全一致では入れ子が落ちてしまう）
            $touched = array_flip(array_keys($attributes));
            $rules = array_filter(
                $rules,
                static fn (string $path): bool => isset($touched[explode('.', $path)[0]]),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $validator = $this->validator->make($attributes, $rules);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return ['data' => null, 'errors' => $this->explainTrashedCollisions($schema, $table, $attributes, $errors)];
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();

        // 参照整合は DB の外部キーではなく Application 層で守る（owner 決定 G2-b）
        $referenceErrors = $this->references->validate($schema, $data);

        if ($referenceErrors !== []) {
            return ['data' => null, 'errors' => $referenceErrors];
        }

        return ['data' => $this->fillNotNullDefaults($schema, $table, $data), 'errors' => []];
    }

    /**
     * 一意な値がぶつかった相手が **ゴミ箱にいる**なら、そう言う（Phase 9.8 / OG5 = (a)）。
     *
     * ゴミ箱の投稿も slug を持ち続ける。持ち続けるのは、戻すときに
     * 「その slug は別の投稿に取られていました」を起こさないためである。
     * 代わりに「なぜか作れない」が起きるので、**理由を出す**。
     *
     * 黙って別の slug に書き換えない。利用者が決めた URL を、こちらの都合で変えない。
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function explainTrashedCollisions(
        ContentSchema $schema,
        string $table,
        array $attributes,
        array $errors,
    ): array {
        if (! $this->hasTrashColumn($table)) {
            return $errors;
        }

        foreach ($schema->fields as $field) {
            if (! $field->unique || ! isset($errors[$field->key])) {
                continue;
            }

            $value = $attributes[$field->key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $collidesWithTrashed = DB::table($table)
                ->where($field->key, $value)
                ->whereNotNull(TrashColumns::DELETED_AT)
                ->exists();

            if ($collidesWithTrashed) {
                $errors[$field->key] = [
                    "ゴミ箱にある{$schema->label}が同じ{$field->label}を使用しています。"
                    .'別の値にするか、ゴミ箱の投稿を確認してください',
                ];
            }
        }

        return $errors;
    }

    /** その表がゴミ箱に対応しているか（対応前の型では何も足さない） */
    private function hasTrashColumn(string $table): bool
    {
        try {
            return SchemaFacade::hasColumn($table, TrashColumns::DELETED_AT);
        } catch (\Throwable) {
            // 確かめられないなら説明を足さないだけ。元の検証結果は変えない
            return false;
        }
    }

    /**
     * slug 型フィールドが空なら `from` フィールドから導出する。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function deriveSlugs(ContentSchema $schema, string $table, array $attributes, bool $partial): array
    {
        foreach ($schema->fields as $field) {
            if ($field->type !== 'slug' || $field->from === null) {
                continue;
            }

            // 部分更新で slug に触れていないなら生成しない（既存値を壊さない）
            if ($partial && ! array_key_exists($field->key, $attributes)) {
                continue;
            }

            if (filled($attributes[$field->key] ?? null)) {
                continue;
            }

            $source = (string) ($attributes[$field->from] ?? '');
            $slug = self::mostlyAscii($source) ? Str::slug($source) : '';
            $attributes[$field->key] = $slug !== ''
                ? $this->unusedSlug($table, $field->key, $slug)
                : $this->dateSlug($table, $field->key);
        }

        return $attributes;
    }

    /**
     * 見出しから作れなかったときの URL（WP 一周 2 周目 2026-09-03）。
     *
     * `Str::slug('秋の庭しごとのご案内')` は**空文字**を返す。
     * 以前はここで `Str::random(8)` へ落ちていたので、URL が `mf55bgrs` になっていた。
     * 画面には「空のままにすると見出しから自動で作られます」と書いてあるのに、
     * 日本語で書く人には**案内文と結果が食い違って**いた。
     *
     * ## なぜローマ字にしないか
     *
     * かな漢字を読みへ変えるには辞書が要る。辞書を積んでも「生」が「なま」か「せい」かは
     * 文脈で変わるので、**必ず外す**。外した読みが URL になって配られると、
     * あとから直しても 404 を撒くことになる。**間違えようのない値のほうがよい。**
     *
     * ## 日付にした理由
     *
     * 意味は持たないが、**並びが分かり、口で言える**。`2026-09-03-1` は
     * 「その日の 1 本目」と読めて、あとから直せる。
     *
     * 重複はここで避ける。validator の unique に任せると、
     * 「URL を書いていないのに URL のエラーが出る」という説明のつかない画面になる。
     */
    /**
     * 見出しが英数字でほぼ書かれているか（UX 一周 2026-09-28）。
     *
     * 「青梅市 S様邸 新築工事」は `Str::slug` が **`s`** を返し、URL が `/blog/s` になった。
     * 日本語の中の 1 文字だけが残った URL は、意味も無く、次の記事と必ずぶつかる。
     * 英数字が半分に満たない見出しは、日付の URL へ倒す（下の `dateSlug`）。
     */
    private static function mostlyAscii(string $title): bool
    {
        $chars = preg_split('//u', (string) preg_replace('/\s+/u', '', $title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($chars === []) {
            return false;
        }

        $ascii = count(array_filter($chars, static fn (string $c): bool => preg_match('/^[A-Za-z0-9]$/', $c) === 1));

        return $ascii * 2 >= count($chars);
    }

    /**
     * 見出しから作った URL が既にあれば `-2` `-3` … を付ける（UX 一周 2026-09-28）。
     * 以前は同じ見出しの 2 本目で「URL が使われています」になり、URL を書いていない人には説明がつかなかった。
     */
    private function unusedSlug(string $table, string $column, string $base): string
    {
        $candidate = $base;

        for ($n = 2; DB::table($table)->where($column, $candidate)->exists(); $n++) {
            if ($n > self::MAX_DATE_SUFFIX) {
                return $base.'-'.Str::lower(Str::random(6));
            }

            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    private function dateSlug(string $table, string $column): string
    {
        $base = now()->format('Y-m-d');

        for ($n = 1; $n <= self::MAX_DATE_SUFFIX; $n++) {
            $candidate = "{$base}-{$n}";

            if (! DB::table($table)->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        // 同じ日に打ち切りまで埋まったとき。**一意であることを優先する**
        return $base.'-'.Str::lower(Str::random(6));
    }

    /**
     * schema の `default` を、未指定のフィールドへ適用する（新規作成時のみ）。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    /**
     * 「公開」を選んだのに日時が空なら、**その時刻を入れる**（WP 一周 2 周目 2026-09-03）。
     *
     * 公開面は `status` だけでなく `published_at` も見る
     * （`HasContentSchema::scopePublished()`）。日時が空のままだと
     * **管理画面には「公開」と出るのに、サイトには何も出ない**。
     * 書いた人は出したつもりで、警告も出ない。WordPress は押した時点で日時が入る。
     *
     * ## 触るのは空のときだけ
     *
     * 自分で入れた日時は**絶対に書き換えない**。未来の日時（予約投稿）もそのまま残す —
     * 「まだ出さない」という意思表示だからである。
     *
     * 部分更新で `published_at` に**触れていない**ときも何もしない。
     * 触れていないキーを埋めると、**編集しただけで公開日が今日に変わる**。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function stampPublishedAt(ContentSchema $schema, array $attributes, bool $partial): array
    {
        if (! $this->hasField($schema, 'status') || ! $this->hasField($schema, 'published_at')) {
            return $attributes;
        }

        if (($attributes['status'] ?? null) !== 'published') {
            return $attributes;
        }

        // 触れていないものは触らない（部分更新で既存の公開日を潰さない）
        if (! array_key_exists('published_at', $attributes) && $partial) {
            return $attributes;
        }

        $given = $attributes['published_at'] ?? null;

        if ($given !== null && ! (is_string($given) && trim($given) === '')) {
            return $attributes;
        }

        $attributes['published_at'] = now()->format('Y-m-d H:i:s');

        return $attributes;
    }

    private function hasField(ContentSchema $schema, string $key): bool
    {
        foreach ($schema->fields as $field) {
            if ($field->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyDefaults(ContentSchema $schema, array $attributes, bool $partial): array
    {
        if ($partial) {
            return $attributes;
        }

        foreach ($schema->fields as $field) {
            if ($field->default !== null && ! array_key_exists($field->key, $attributes)) {
                $attributes[$field->key] = $field->default;
            }
        }

        return $attributes;
    }

    /**
     * Project Scope Override（ADR-0010 Rev.1）で optional 化された文字列系フィールドは、
     * 未入力（null）でも NOT NULL カラムへ保存できるよう空文字で埋める。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillNotNullDefaults(ContentSchema $schema, string $table, array $data): array
    {
        foreach ($schema->columnFields() as $field) {
            if (
                $field->required === false
                && in_array($field->type, ['text', 'textarea', 'select', 'email', 'richtext'], true)
                && array_key_exists($field->key, $data)
                && $data[$field->key] === null
                && ! $this->columnIsNullable($table, $field->key)
            ) {
                $data[$field->key] = '';
            }
        }

        return $data;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (SchemaFacade::getColumns($table) as $col) {
            if (($col['name'] ?? null) === $column) {
                return (bool) ($col['nullable'] ?? true);
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Content;

use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\HasTrashState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Content Schema と Eloquent モデルを接続する trait（Project 層のモデルが use する）。
 * fillable / casts を Schema から導出し、二重管理を防ぐ。
 *
 * 使用側は `public static function schemaId(): string` を実装すること。
 *
 * ## ゴミ箱の既定除外もここが持つ（Phase 9.8 / STAGE 21B）
 *
 * **生成されたモデルは kit の更新では書き換わらない。** 利用者のリポジトリにある
 * ファイルだからである。除外を新しい generator にだけ足すと、21B より前に作られた
 * 型では「列はあるのに除外が効かない」状態になり、ゴミ箱の中身が一覧へ出る。
 *
 * だから `HasTrashState` は**この trait の中**に置く。schema と結ばれたモデルは
 * すべてこれを使っているので、core を更新するだけで既存の生成物にも届く。
 *
 * 投稿タイプでないモデル（`media` / `contact`）にも scope は付くが、
 * 判定側（`EditorialCollections`）が対象外と答えるので **条件を 1 つも足さない**。
 */
trait HasContentSchema
{
    use HasTrashState;

    public static function schema(): ContentSchema
    {
        return app(SchemaRegistry::class)->get(static::schemaId());
    }

    /**
     * この行の公開 URL（P1 ③ 段 1）。**形は `ContentPermalink` が決める**。
     *
     * view から呼ぶための薄い委譲である。`route('news.show', $item->slug)` を
     * view に書くと、**URL の形を知っている場所がまた 1 つ増える**。
     *
     * 公開されているかは**見ない** — preview は公開前の URL を要る側だからである。
     */
    public function publicPath(bool $absolute = true): ?string
    {
        return app(ContentPermalink::class)->entry(static::schemaId(), $this, $absolute);
    }

    public function initializeHasContentSchema(): void
    {
        $schema = static::schema();
        // 複合フィールドは data JSON の中なので、fillable はカラムのフィールドだけ
        $this->mergeFillable(array_map(fn (Field $f) => $f->key, $schema->columnFields()));
        $this->mergeCasts($schema->casts());

        if ($schema->needsDataColumn()) {
            $this->mergeFillable(['data']);
            $this->mergeCasts(['data' => 'array']);
        }
    }

    /**
     * 複合フィールドを含めた「1 件ぶんの値」。
     * カラムと data JSON の両方から組み立てる（保存は逆向きに分解される）。
     *
     * @return array<string, mixed>
     */
    public function schemaAttributes(): array
    {
        $values = [];

        foreach (static::schema()->columnFields() as $field) {
            $values[$field->key] = $this->getAttribute($field->key);
        }

        $data = $this->getAttribute('data');

        foreach (static::schema()->jsonFields() as $field) {
            $values[$field->key] = is_array($data) ? ($data[$field->key] ?? null) : null;
        }

        return $values;
    }

    /**
     * 公開済み（status=published かつ published_at が現在以前）のみ
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}

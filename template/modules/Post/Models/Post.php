<?php

declare(strict_types=1);

namespace Modules\Post\Models;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Model;

/*
 * agentcms:content-type — Phase 9.7 / STAGE 20B。
 *
 * 生成サイトが最初から持つ**唯一の投稿タイプ**（SPEC §C.2 / Owner Decision R1）。
 * 形は Content Type Builder が作るものと同じで、特別扱いはしていない —
 * 利用者はこの型を編集・無効化できる。
 *
 * 項目定義の単一情報源は content/schemas/post.json。
 * このクラスは schema id とテーブルを結ぶだけで、独自の業務ロジックを持たない
 * （fillable / casts は HasContentSchema が schema から導出する）。
 */
final class Post extends Model implements SchemaModel
{
    use HasContentSchema;

    protected $table = 'post';

    public static function schemaId(): string
    {
        return 'post';
    }
}

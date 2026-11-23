<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\ContentTypes\ContentTypeIdentifier;
use Illuminate\Database\Eloquent\Model;

/**
 * DB 登録された投稿タイプの汎用モデル（Phase 9.7 / ADR-0019）。
 *
 * ADR-0014 は型ごとに `modules/<Studly>/Models/<Studly>.php` を**生成**していた。
 * ADR-0019 でそれをやめたので、**テーブル名だけが違う 1 つのモデル**が要る。
 *
 * **テーブル名は `ContentTypeIdentifier` を通った値だけ**を使う。
 * 文字列を直接受け取る口を作らない — SQL 識別子は quote されるとはいえ、
 * 検証されていない語がテーブル名になる経路をそもそも作らないのが本 kit の作法である
 * （`ContentTypeIdentifier` の doc comment 参照）。
 *
 * この Stage で使う場所はまだ無い（DB 型を作れるのは 20C 以降）。
 * **先に置くのは、生成モデルを前提にした実装が増えないようにするため**である。
 */
final class ManagedEntry extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * 検証済みの内部 ID からモデルを作る。
     *
     * `$table` を外から代入できないよう、**この入口だけ**を公開する。
     */
    public static function forType(ContentTypeIdentifier $identifier): self
    {
        $model = new self;
        $model->setTable($identifier->table());

        return $model;
    }

    /**
     * 文字列からモデルを作る。**検証を通らなければ null**。
     *
     * @param  list<string>  $errors  失敗理由（呼び出し側の配列へ追記する）
     */
    public static function tryForType(string $contentTypeId, array &$errors = []): ?self
    {
        $identifier = ContentTypeIdentifier::forRegistered($contentTypeId, $errors);

        return $identifier === null ? null : self::forType($identifier);
    }
}

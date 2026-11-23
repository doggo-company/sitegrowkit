<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Content\SchemaModel;
use AgentCms\Core\Trash\HasTrashState;
use AgentCms\Core\Trash\TrashColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Contact\Models\ContactSubmission;
use Modules\Media\Models\Media;
use Tests\TestCase;

/**
 * **前の generator が出したモデル**にも既定除外が効く（Phase 9.8 / STAGE 21B 是正）。
 *
 * 21B より前に管理画面で作られた型のモデルは `HasContentSchema` しか使っていない。
 * migration は列を足すが、**モデルは kit の更新では書き換わらない**（生成物は
 * 利用者のリポジトリにある）。trait を新しい generator にだけ足すと、
 * 既存の型では列があるのに除外が効かず、ゴミ箱の中身が一覧へ出てしまう。
 *
 * だから除外は **`HasContentSchema` の側**に持たせる。schema と結ばれたモデルは
 * すべてそれを使っているので、core の更新だけで既存の生成物にも届く。
 */
final class TrashLegacyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_旧生成モデルでもゴミ箱は一覧に出ない(): void
    {
        $kept = $this->entry('kept');
        $trashed = $this->entry('gone');
        $this->putInTrash($trashed);

        $ids = LegacyGeneratedPost::query()->pluck('id')->all();

        $this->assertSame([$kept], $ids, '旧生成モデルからゴミ箱が漏れている');
    }

    public function test_旧生成モデルでも件数がゴミ箱を数えない(): void
    {
        $this->entry('kept');
        $this->putInTrash($this->entry('gone'));

        $this->assertSame(1, LegacyGeneratedPost::query()->count());
        $this->assertSame(2, DB::table('post')->count());
    }

    public function test_旧生成モデルでもidを知っていれば読めるにはしない(): void
    {
        $trashed = $this->entry('gone');
        $this->putInTrash($trashed);

        $this->assertNull(LegacyGeneratedPost::query()->find($trashed));
    }

    public function test_旧生成モデルでも明示すればゴミ箱を読める(): void
    {
        $trashed = $this->entry('gone');
        $this->putInTrash($trashed);

        $this->assertNotNull(LegacyGeneratedPost::query()->withTrashed()->find($trashed));
    }

    public function test_除外はschemaと結ばれたモデル全部に届く(): void
    {
        /*
         * **trait を 2 か所に書かない。** 「新しい generator が付ける」だけだと、
         * 既に生成された型に届かない。`HasContentSchema` を使っているモデルは
         * すべて対象になる、という 1 本の道にする。
         */
        $this->assertContains(
            HasTrashState::class,
            class_uses_recursive(LegacyGeneratedPost::class),
            'HasContentSchema だけのモデルに既定除外が届いていない',
        );
    }

    public function test_ゴミ箱の対象でない型には条件を足さない(): void
    {
        /*
         * `media` / `contact` も `HasContentSchema` を使う。scope は付くが、
         * 投稿タイプではないので **条件を 1 つも足さない**（列も無い）。
         */
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, ContactSubmission::query()->count());
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug,
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function putInTrash(int $id): void
    {
        DB::table('post')->where('id', $id)->update([TrashColumns::DELETED_AT => now()]);
    }
}

/**
 * 21B より前の `ContentTypeGenerator` が出していたモデルの形。
 *
 * **`HasTrashState` を書かない**のがこの再現の要点である。
 */
final class LegacyGeneratedPost extends Model implements SchemaModel
{
    use HasContentSchema;

    protected $table = 'post';

    public static function schemaId(): string
    {
        return 'post';
    }
}

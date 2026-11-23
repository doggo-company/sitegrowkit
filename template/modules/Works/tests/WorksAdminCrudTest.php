<?php

declare(strict_types=1);

namespace Modules\Works\Tests;

use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * Schema 駆動 admin CRUD（core SchemaResourceController）の検証。
 */
final class WorksAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private const VALID = [
        'title' => 'テスト実績',
        'slug' => 'test-work',
        'category' => 'web',
        'body' => '<p>本文</p>',
        'status' => 'draft',
    ];

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/works')->assertRedirect('/login');
        $this->post('/admin/works', self::VALID)->assertRedirect('/login');
    }

    public function test_admin_can_create_edit_update_delete(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/works')->assertOk();
        $this->actingAs($admin)->get('/admin/works/create')->assertOk();

        $this->actingAs($admin)->post('/admin/works', self::VALID)
            ->assertRedirect('/admin/works');
        $work = Work::sole();
        $this->assertSame('テスト実績', $work->title);

        $this->actingAs($admin)->get("/admin/works/{$work->id}/edit")->assertOk();

        $this->actingAs($admin)->put("/admin/works/{$work->id}", array_merge(self::VALID, ['title' => '更新済み']))
            ->assertRedirect('/admin/works');
        $this->assertSame('更新済み', $work->fresh()->title);

        // 削除は破壊的操作なので、確認トークン無しの 1 回目では消えない（Phase 9.1B / STEP 7）
        $this->actingAs($admin)->delete("/admin/works/{$work->id}")->assertRedirect();
        $this->assertSame(1, Work::count());

        $token = app(DeleteContent::class)
            ->execute('works', (int) $work->id, OperationContext::admin((int) $admin->id, $admin->name))
            ->confirmation->token;

        $this->actingAs($admin)
            ->delete("/admin/works/{$work->id}", ['confirmation_token' => $token])
            ->assertRedirect('/admin/works');
        $this->assertSame(0, Work::count());
    }

    public function test_validation_derives_from_schema(): void
    {
        // category は choices 外、status は不正値 → Schema 由来ルールで弾かれる
        $this->actingAs($this->admin())
            ->post('/admin/works', array_merge(self::VALID, ['category' => 'invalid', 'status' => 'archived']))
            ->assertSessionHasErrors(['category', 'status']);
    }

    public function test_slug_is_generated_from_title_when_blank(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/works', array_merge(self::VALID, ['title' => 'My Great Work', 'slug' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('my-great-work', Work::sole()->slug);
    }

    public function test_slug_must_be_unique(): void
    {
        Work::query()->create(array_merge(self::VALID, ['published_at' => null]));

        $this->actingAs($this->admin())
            ->post('/admin/works', self::VALID)
            ->assertSessionHasErrors('slug');
    }

    // 画像アップロード自体の検証（SVG 拒否・ファイル名再生成等）は Phase 2 で
    // Media Module へ移動した（modules/Media/tests/MediaTest.php — ADR-0006）。
    // Works 側は Media Entity への参照整合のみを検証する。
    public function test_main_image_must_reference_existing_media(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/works', array_merge(self::VALID, ['main_image_id' => 9999]))
            ->assertSessionHasErrors('main_image_id');

        $this->assertSame(0, Work::count());
    }

    public function test_work_can_reference_media_entity(): void
    {
        Storage::fake('public');
        $media = Media::query()->create([
            'name' => 'テスト画像',
            'alt' => 'alt',
            'path' => 'media/test.png',
            'mime' => 'image/png',
            'size' => 100,
            'width' => 10,
            'height' => 10,
            'has_webp' => false,
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/works', array_merge(self::VALID, ['main_image_id' => $media->id]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Work::sole()->mainImage->is($media));
    }
}

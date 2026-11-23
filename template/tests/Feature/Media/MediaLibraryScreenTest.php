<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Media\MediaVisibility;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Media\Application\UpdateMedia;
use Modules\Media\Models\Media;
use Tests\TestCase;

/**
 * メディア管理の画面（Phase 9.5-D / SPEC §8）。
 *
 * 画面は 1 つのまま。分かれるのは **見せ方**だけで、
 * 種別の判定も公開範囲の変更も、決めるのはサーバーである。
 */
final class MediaLibraryScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function image(string $name = '写真'): Media
    {
        $path = 'media/'.fake()->uuid().'.png';
        Storage::disk('public')->put($path, 'png');

        return Media::query()->create([
            'name' => $name,
            'original_name' => $name.'.png',
            'path' => $path,
            'mime' => 'image/png',
            'size' => 10,
            'width' => 40,
            'height' => 30,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function file(array $overrides = []): Media
    {
        $path = 'files/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-');

        return Media::query()->create(array_merge([
            'kind' => 'file',
            'disk' => 'local',
            'name' => '会社案内',
            'original_name' => '会社案内.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 10,
            'width' => null,
            'height' => null,
        ], $overrides));
    }

    // --- タブ -------------------------------------------------------------

    public function test_the_library_shows_both_kinds_by_default(): void
    {
        $this->image();
        $this->file();

        $this->actingAs($this->admin())
            ->get('/admin/media')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kind', null)
                ->has('items.data', 2));
    }

    public function test_the_image_tab_shows_only_images(): void
    {
        $image = $this->image();
        $this->file();

        $this->actingAs($this->admin())
            ->get('/admin/media?kind=image')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kind', 'image')
                ->has('items.data', 1)
                ->where('items.data.0.id', $image->id));
    }

    public function test_the_file_tab_shows_only_files(): void
    {
        $this->image();
        $file = $this->file();

        $this->actingAs($this->admin())
            ->get('/admin/media?kind=file')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kind', 'file')
                ->has('items.data', 1)
                ->where('items.data.0.id', $file->id)
                // ファイルは寸法を持たず、直 URL も持たない
                ->where('items.data.0.width', null)
                ->where('items.data.0.url', null)
                ->where('items.data.0.original_name', '会社案内.pdf'));
    }

    public function test_an_unknown_tab_falls_back_to_everything(): void
    {
        $this->image();
        $this->file();

        // 知らない値で画面を壊さない
        $this->actingAs($this->admin())
            ->get('/admin/media?kind=zip')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kind', null)->has('items.data', 2));
    }

    // --- picker -----------------------------------------------------------

    public function test_the_picker_endpoint_filters_by_kind(): void
    {
        $image = $this->image();
        $file = $this->file();

        $this->actingAs($this->admin())
            ->getJson('/admin/media/select?kind=image')
            ->assertOk()
            ->assertJsonPath('media.0.id', $image->id)
            ->assertJsonCount(1, 'media');

        $this->actingAs($this->admin())
            ->getJson('/admin/media/select?kind=file')
            ->assertOk()
            ->assertJsonPath('media.0.id', $file->id)
            ->assertJsonPath('media.0.url', null)
            ->assertJsonCount(1, 'media');
    }

    public function test_the_picker_endpoint_still_returns_everything_without_a_kind(): void
    {
        $this->image();
        $this->file();

        $this->actingAs($this->admin())
            ->getJson('/admin/media/select')
            ->assertOk()
            ->assertJsonCount(2, 'media');
    }

    /**
     * 絞り込みは **サーバー**で行う（SPEC §8.2 / §10）。
     *
     * 取ってから手元で絞ると、新しい 200 件がすべて画像だったときに
     * ファイルが 1 件も出ない。上限は種別の中で効かせる。
     */
    public function test_the_picker_asks_the_server_to_filter(): void
    {
        $library = (string) File::get(base_path('resources/js/composables/useMediaLibrary.ts'));

        $this->assertStringContainsString('?kind=', $library);

        // 画像を使う入力欄はどれも同じ画像の取得を共有する
        foreach ([
            'resources/js/components/schema/MediaPicker.vue',
            'resources/js/components/schema/ImagesInput.vue',
        ] as $path) {
            $source = (string) File::get(base_path($path));
            $this->assertMatchesRegularExpression(
                "/useMediaLibrary\(\s*(props\.kind|'image')\s*\)/",
                $source,
                "{$path} が種別を渡していない",
            );
        }
    }

    /**
     * 絞り込みが **上限より先**に効いていること（SPEC §8.2 / §10）。
     *
     * 上限 200 件を先にかけてから絞ると、新しい画像が 200 件を超えた時点で
     * **古いファイルが 1 件も出なくなる**。実データで両方向を確かめる。
     */
    public function test_an_old_file_survives_a_flood_of_newer_images(): void
    {
        $file = $this->file();

        // このあとに画像を 201 件。file はいちばん古い行になる
        foreach (range(1, 201) as $n) {
            $this->image("写真 {$n}");
        }

        $this->actingAs($this->admin())
            ->getJson('/admin/media/select?kind=file')
            ->assertOk()
            ->assertJsonCount(1, 'media')
            ->assertJsonPath('media.0.id', $file->id);

        // 打ち切られる側にも他の種別は混ざらない
        $images = $this->actingAs($this->admin())
            ->getJson('/admin/media/select?kind=image')
            ->assertOk()
            ->json('media');

        $this->assertCount(200, $images);
        $this->assertSame(['image'], array_values(array_unique(array_column($images, 'kind'))));
    }

    /** 逆向き。古い画像 1 件が、あとから増えたファイル 201 件に押し流されない */
    public function test_an_old_image_survives_a_flood_of_newer_files(): void
    {
        $image = $this->image();

        foreach (range(1, 201) as $n) {
            $this->file(['name' => "資料 {$n}", 'original_name' => "資料-{$n}.pdf"]);
        }

        $this->actingAs($this->admin())
            ->getJson('/admin/media/select?kind=image')
            ->assertOk()
            ->assertJsonCount(1, 'media')
            ->assertJsonPath('media.0.id', $image->id);

        $files = $this->actingAs($this->admin())
            ->getJson('/admin/media/select?kind=file')
            ->assertOk()
            ->json('media');

        $this->assertCount(200, $files);
        $this->assertSame(['file'], array_values(array_unique(array_column($files, 'kind'))));
    }

    // --- 公開範囲 ---------------------------------------------------------

    public function test_making_a_file_private_needs_confirmation_first(): void
    {
        $file = $this->file();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/admin/media/{$file->id}", [
                'name' => $file->name,
                'visibility' => 'private',
            ])
            ->assertSessionHasNoErrors();

        // 1 回目は変わらない
        $this->assertSame(MediaVisibility::Public, $file->refresh()->visibility);

        $token = $this->tokenFor($admin, $file, 'private');

        $this->actingAs($admin)
            ->patch("/admin/media/{$file->id}", [
                'name' => $file->name,
                'visibility' => 'private',
                'confirmation_token' => $token,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(MediaVisibility::Private, $file->refresh()->visibility);
    }

    public function test_making_a_file_public_again_also_needs_confirmation(): void
    {
        $file = $this->file(['visibility' => 'private']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/admin/media/{$file->id}", [
                'name' => $file->name,
                'visibility' => 'public',
            ]);

        $this->assertSame(MediaVisibility::Private, $file->refresh()->visibility);

        $this->actingAs($admin)
            ->patch("/admin/media/{$file->id}", [
                'name' => $file->name,
                'visibility' => 'public',
                'confirmation_token' => $this->tokenFor($admin, $file, 'public'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(MediaVisibility::Public, $file->refresh()->visibility);
    }

    public function test_saving_the_name_without_changing_visibility_needs_no_confirmation(): void
    {
        $file = $this->file();

        $this->actingAs($this->admin())
            ->patch("/admin/media/{$file->id}", [
                'name' => '新しい名前',
                'visibility' => 'public',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('新しい名前', $file->refresh()->name);
    }

    public function test_an_image_cannot_be_given_a_visibility(): void
    {
        $image = $this->image();

        $this->actingAs($this->admin())
            ->patch("/admin/media/{$image->id}", [
                'name' => $image->name,
                'visibility' => 'private',
            ])
            ->assertSessionHasErrors('visibility');

        $this->assertSame(MediaVisibility::Public, $image->refresh()->visibility);
    }

    /**
     * 代替テキストは画像の概念。画面で出していないものが、送られてきたからといって
     * 保存されてはいけない（null が '' に変わってしまう）。
     */
    public function test_a_file_never_gains_an_alt(): void
    {
        $file = $this->file();

        $this->actingAs($this->admin())
            ->patch("/admin/media/{$file->id}", [
                'name' => $file->name,
                'alt' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($file->refresh()->alt);
    }

    public function test_an_image_keeps_its_alt(): void
    {
        $image = $this->image();

        $this->actingAs($this->admin())
            ->patch("/admin/media/{$image->id}", [
                'name' => $image->name,
                'alt' => '青い空',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('青い空', $image->refresh()->alt);
    }

    // --- 文言 -------------------------------------------------------------

    public function test_uploading_a_file_does_not_say_image(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agentcms-test-');
        file_put_contents($path, "%PDF-1.7\n%%EOF\n");

        $this->actingAs($this->admin())
            ->post('/admin/media', [
                'file' => new UploadedFile($path, '資料.pdf', null, null, true),
            ])
            ->assertSessionHas('success', 'ファイルをアップロードしました');

        @unlink($path);
    }

    public function test_uploading_an_image_still_says_image(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/media', [
                'file' => UploadedFile::fake()->image('photo.png', 40, 30),
            ])
            ->assertSessionHas('success', '画像をアップロードしました');
    }

    /**
     * 一般ファイルを扱えるようにしても、**AI へ公開する操作は増やさない**（D10）。
     *
     * 増えた口はダウンロードの 2 route だけで、どちらも operation ではない。
     */
    public function test_the_operation_surface_did_not_grow(): void
    {

        // 公開しているのは従来どおりのこの 4 つだけ（upload / delete は元から非公開）
        foreach (['media.list', 'media.get', 'media.update', 'media.replace'] as $id) {
            $this->assertTrue(OperationCatalog::allows($id), "{$id} が公開されていない");
        }

        foreach (['media.download', 'media.visibility', 'media.upload', 'media.delete'] as $invented) {
            $this->assertFalse(OperationCatalog::allows($invented), "{$invented} を増やしている");
        }
    }

    private function tokenFor(User $admin, Media $file, string $visibility): string
    {
        $result = app(UpdateMedia::class)->execute(
            (int) $file->id,
            ['name' => $file->name, 'visibility' => $visibility],
            OperationContext::admin((int) $admin->id, (string) $admin->name),
        );

        return (string) $result->confirmation?->token;
    }
}

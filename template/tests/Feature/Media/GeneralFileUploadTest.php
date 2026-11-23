<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaVisibility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Models\Media;
use RuntimeException;
use Tests\TestCase;

/**
 * 一般ファイルのアップロード・差し替え・削除（Phase 9.5-D / SPEC §3・§5）。
 *
 * 画像と同じ 1 つのアップロード欄から入り、**種別はサーバーが決める**。
 * 置き場所・検査・配信だけが画像と分かれる。
 */
final class GeneralFileUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaries as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function pdf(string $name = '会社案内.pdf'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agentcms-test-');
        $this->temporaries[] = $path;
        file_put_contents($path, "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(?UploadedFile $file = null): Media
    {
        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => $file ?? $this->pdf()])
            ->assertSessionHasNoErrors();

        return Media::query()->latest('id')->firstOrFail();
    }

    // --- upload ---------------------------------------------------------

    public function test_a_pdf_becomes_a_file_kind_media_on_the_private_disk(): void
    {
        $media = $this->upload();

        $this->assertSame(MediaKind::File, $media->kind);
        $this->assertSame(MediaVisibility::Public, $media->visibility);
        $this->assertSame('local', $media->disk);
        $this->assertSame('application/pdf', $media->mime);
        $this->assertNull($media->width);
        $this->assertNull($media->height);
        $this->assertNull($media->alt, '代替テキストは画像の概念なので持たせない');
        $this->assertSame('会社案内.pdf', $media->original_name);
        $this->assertSame('会社案内', $media->name);
    }

    public function test_the_stored_name_is_regenerated_and_never_reaches_the_public_disk(): void
    {
        $media = $this->upload();

        $this->assertStringStartsWith('files/', $media->path);
        $this->assertStringNotContainsString('会社案内', $media->path);
        Storage::disk('local')->assertExists($media->path);

        // 直リンクで開ける経路を作らない
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_extension_we_do_not_accept_is_refused_before_anything_is_stored(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agentcms-test-');
        $this->temporaries[] = $path;
        file_put_contents($path, 'archive');

        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => new UploadedFile($path, 'data.zip', null, null, true)])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * SPEC §5: DB 行の作成に失敗したら、**保存したばかりの実体を消す**。
     * まだ誰も参照していないので、孤児にする理由が無い。
     */
    public function test_a_database_failure_takes_the_freshly_stored_file_with_it(): void
    {
        /*
         * **表を落として失敗を作らない。** `Schema::drop()` は DDL で、
         * **MySQL は DDL で暗黙 commit する**ため取引が閉じ、あとの補償が効かなくなる。
         * 見たいのは「登録に失敗したら、置いたファイルも片付けるか」なので、
         * **登録そのものを落とす**。
         */
        Media::creating(static function (): void {
            throw new RuntimeException('登録に失敗しました（試験）');
        });

        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => $this->pdf()]);

        $this->assertSame([], Storage::disk('local')->allFiles('files'));
    }

    // --- replace --------------------------------------------------------

    public function test_a_file_cannot_be_replaced_by_an_image(): void
    {
        $media = $this->upload();
        $originalPath = $media->path;

        $this->actingAs($this->admin())
            ->post("/admin/media/{$media->id}/replace", [
                'file' => UploadedFile::fake()->image('photo.png', 40, 30),
            ])
            ->assertSessionHasErrors('file');

        $media->refresh();
        $this->assertSame(MediaKind::File, $media->kind);
        $this->assertSame($originalPath, $media->path);
        Storage::disk('local')->assertExists($originalPath);
        $this->assertSame([], Storage::disk('public')->allFiles(), '拒否したのに画像を保存してはいけない');
    }

    public function test_an_image_cannot_be_replaced_by_a_file(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => UploadedFile::fake()->image('photo.png', 40, 30)])
            ->assertSessionHasNoErrors();

        $image = Media::query()->latest('id')->firstOrFail();

        $this->actingAs($this->admin())
            ->post("/admin/media/{$image->id}/replace", ['file' => $this->pdf()])
            ->assertSessionHasErrors('file');

        $this->assertSame(MediaKind::Image, $image->refresh()->kind);
        $this->assertSame([], Storage::disk('local')->allFiles('files'));
    }

    public function test_replacing_a_file_keeps_the_id_and_removes_the_old_original_afterwards(): void
    {
        $media = $this->upload();
        $id = $media->id;
        $oldPath = $media->path;

        $this->actingAs($this->admin())
            ->post("/admin/media/{$id}/replace", ['file' => $this->pdf('新しい会社案内.pdf')])
            ->assertSessionHasNoErrors();

        $media->refresh();
        $this->assertSame($id, $media->id, 'id が変わると参照が切れる');
        $this->assertNotSame($oldPath, $media->path);
        $this->assertSame('新しい会社案内.pdf', $media->original_name);

        Storage::disk('local')->assertExists($media->path);
        Storage::disk('local')->assertMissing($oldPath);
    }

    /**
     * SPEC §5: DB 更新より前で落ちたら、**旧側には一切触れない**。
     * 新しく置いたものだけを片付ける。
     */
    public function test_a_failed_replace_leaves_the_old_file_in_place(): void
    {
        $media = $this->upload();
        $oldPath = $media->path;

        // **DB の更新だけ**を落とす（新しい実体は既に保存されている段階・DDL を使わない）
        Media::updating(static function (): void {
            throw new RuntimeException('更新に失敗しました（試験）');
        });

        $this->actingAs($this->admin())
            ->post("/admin/media/{$media->id}/replace", ['file' => $this->pdf('差し替え.pdf')]);

        Storage::disk('local')->assertExists($oldPath);
        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('files'),
            '新しい実体が孤児として残っている',
        );
    }

    // --- delete ---------------------------------------------------------

    public function test_deleting_a_file_removes_the_row_and_the_original(): void
    {
        $media = $this->upload();
        $path = $media->path;

        $admin = $this->admin();

        // 1 回目は確認を返して止まる
        $this->actingAs($admin)->delete("/admin/media/{$media->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, Media::count());
        Storage::disk('local')->assertExists($path);

        $token = app(DeleteMedia::class)
            ->execute((int) $media->id, OperationContext::admin((int) $admin->id, (string) $admin->name))
            ->confirmation?->token;

        $this->actingAs($admin)
            ->delete("/admin/media/{$media->id}", ['confirmation_token' => $token])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Media::count());
        Storage::disk('local')->assertMissing($path);
    }
}

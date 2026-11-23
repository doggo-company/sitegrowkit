<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Media\VariantCapability;
use AgentCms\Core\Media\VariantFormat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use RuntimeException;
use Tests\TestCase;

/**
 * 失敗したときに何を守るか（Phase 9.5-D / SPEC §5）。
 *
 * 方針は 1 つ: 「DB に無いファイル」は害が小さく、「ファイルの無い DB 行」は
 * 画面が壊れる。**どちらかを選ぶなら前者へ倒す。**
 * ただし、まだ誰も参照していない実体は孤児にする理由が無いので、その場で消す。
 */
final class MediaCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function uploadImage(string $name = 'photo.png'): Media
    {
        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => UploadedFile::fake()->image($name, 60, 40)])
            ->assertSessionHasNoErrors();

        return Media::query()->latest('id')->firstOrFail();
    }

    /** この環境が png から何か 1 つでも作れるか */
    private function skipUnlessSomethingCanBeGenerated(): void
    {
        $capability = new VariantCapability;

        foreach (VariantFormat::preferenceOrder() as $format) {
            if ($capability->supports('image/png', $format, 60, 40)) {
                return;
            }
        }

        $this->markTestSkipped('この環境では variant を生成できない');
    }

    private function skipUnlessVariantsExist(Media $media): void
    {
        if ($media->variants()->count() === 0) {
            $this->markTestSkipped('この環境では variant を生成できない');
        }
    }

    /**
     * **DB の失敗は、表を落として作らない。**
     *
     * `Schema::drop()` は DDL である。**MySQL は DDL で暗黙 commit する**ので、
     * その時点で取引が閉じ、**あとの補償や rollback が効かなくなる**
     * （守りが働いていても試験だけが落ちる。SQLite では起きないので長く見えなかった）。
     *
     * 見たいのは「**登録に失敗したとき、置いたファイルを片付けるか**」なので、
     * **登録そのものを落とす**（保存の直前で例外を投げる）。DB の都合ではなく、
     * この試験が言っていることそのものである。
     */
    /** 登録を落とす（DDL を使わない）。**取引の中で投げる**ので、補償の経路がそのまま走る */
    private function failMediaInsert(): void
    {
        Media::creating(static function (): void {
            throw new RuntimeException('登録に失敗しました（試験）');
        });
    }

    public function test_a_failed_insert_removes_the_original_and_every_variant(): void
    {
        $this->failMediaInsert();

        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => UploadedFile::fake()->image('photo.png', 60, 40)]);

        $this->assertSame(
            [],
            Storage::disk('public')->allFiles('media'),
            '原本も派生物も残してはいけない（まだ誰も参照していない）',
        );
    }

    /**
     * SPEC §5 / D7: 派生物を**記録できなくても upload は成功する**。
     *
     * 派生物は「あれば速い」ものであって、それが理由で原本の登録が失敗してよいものではない。
     * 同じ transaction に入れると、記録の失敗が原本ごと巻き戻してしまう。
     */
    public function test_a_variant_that_cannot_be_recorded_does_not_fail_the_upload(): void
    {
        // 作れる形式が 1 つも無い環境では、記録の失敗そのものが起きない（空振りのテストになる）
        $this->skipUnlessSomethingCanBeGenerated();

        // 原本の登録は通したまま、**派生物の記録だけ**を落とす（DDL を使わない）
        MediaVariant::creating(static function (): void {
            throw new RuntimeException('派生物の記録に失敗しました（試験）');
        });

        $this->actingAs($this->admin())
            ->post('/admin/media', ['file' => UploadedFile::fake()->image('photo.png', 60, 40)])
            ->assertSessionHasNoErrors();

        $media = Media::sole();
        $this->assertSame(0, MediaVariant::count(), '記録できていないはず');
        Storage::disk('public')->assertExists($media->path);

        // 記録できなかった実体は残さない（DB が知らないファイルを増やさない）
        $this->assertSame([$media->path], Storage::disk('public')->allFiles('media'));
    }

    public function test_replacing_an_image_removes_the_old_variants_only_after_the_commit(): void
    {
        $media = $this->uploadImage();
        $this->skipUnlessVariantsExist($media);

        $oldPath = $media->path;
        $oldVariantPaths = $media->variants->pluck('path')->all();

        $this->actingAs($this->admin())
            ->post("/admin/media/{$media->id}/replace", [
                'file' => UploadedFile::fake()->image('after.png', 30, 20),
            ])
            ->assertSessionHasNoErrors();

        $media->refresh()->load('variants');

        Storage::disk('public')->assertMissing($oldPath);

        foreach ($oldVariantPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        // 新しい派生物は新しい寸法で作り直されている
        foreach ($media->variants as $variant) {
            Storage::disk('public')->assertExists($variant->path);
            $this->assertSame(30, $variant->width);
            $this->assertNotContains($variant->path, $oldVariantPaths);
        }
    }

    public function test_a_failed_replace_keeps_the_old_variants_and_drops_the_new_ones(): void
    {
        $media = $this->uploadImage();
        $this->skipUnlessVariantsExist($media);

        $oldPath = $media->path;
        $oldVariantPaths = $media->variants->pluck('path')->all();

        // **DB の更新だけ**を落とす（新しい実体と派生物は既に保存されている段階・DDL を使わない）
        Media::updating(static function (): void {
            throw new RuntimeException('更新に失敗しました（試験）');
        });

        $this->actingAs($this->admin())
            ->post("/admin/media/{$media->id}/replace", [
                'file' => UploadedFile::fake()->image('after.png', 30, 20),
            ]);

        $expected = [$oldPath, ...$oldVariantPaths];
        sort($expected);
        $actual = Storage::disk('public')->allFiles('media');
        sort($actual);

        $this->assertSame($expected, $actual, '旧側はそのまま、新側だけが片付いているはず');
    }

    public function test_deleting_an_image_removes_its_variant_rows_and_files(): void
    {
        $media = $this->uploadImage();
        $this->skipUnlessVariantsExist($media);

        $admin = $this->admin();
        $token = app(DeleteMedia::class)
            ->execute((int) $media->id, OperationContext::admin((int) $admin->id, (string) $admin->name))
            ->confirmation?->token;

        $this->actingAs($admin)
            ->delete("/admin/media/{$media->id}", ['confirmation_token' => $token])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Media::count());
        $this->assertSame(0, MediaVariant::count(), 'RESTRICT なので行が残ると media も消せない');
        $this->assertSame([], Storage::disk('public')->allFiles('media'));
    }

    // --- 幅候補（レスポンシブ画像 / release-20260909） ---------------------------

    /**
     * 幅候補を持つ画像を建てる。upload は 60px 幅なので、既定の 640 では拡大になって作られない。
     * 幅候補を 30 に設定して、「同寸 + 縮小」の両方の行がある世界を作る。
     */
    private function uploadImageWithWidths(): Media
    {
        config()->set('agentcms.variants.widths', '30');
        $media = $this->uploadImage();

        if ($media->variants()->where('width', 30)->count() === 0) {
            $this->markTestSkipped('この環境では幅候補を生成できない');
        }

        return $media;
    }

    public function test_deleting_an_image_removes_its_width_variants_too(): void
    {
        $media = $this->uploadImageWithWidths();
        $this->assertGreaterThan(1, count(Storage::disk('public')->allFiles('media/variants')));

        $admin = $this->admin();
        $token = app(DeleteMedia::class)
            ->execute((int) $media->id, OperationContext::admin((int) $admin->id, (string) $admin->name))
            ->confirmation?->token;

        $this->actingAs($admin)
            ->delete("/admin/media/{$media->id}", ['confirmation_token' => $token])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, MediaVariant::count());
        $this->assertSame([], Storage::disk('public')->allFiles('media'), '幅候補の実体が残っている');
    }

    public function test_replacing_an_image_removes_the_old_width_variants_and_makes_new_ones(): void
    {
        $media = $this->uploadImageWithWidths();
        $oldFiles = Storage::disk('public')->allFiles('media');

        $this->actingAs($this->admin())
            ->post("/admin/media/{$media->id}/replace", [
                'file' => UploadedFile::fake()->image('after.png', 80, 50),
            ])
            ->assertSessionHasNoErrors();

        foreach ($oldFiles as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $media->refresh();
        $small = $media->variants()->where('width', 30)->first();
        $this->assertNotNull($small, '差し替え後の原本からも幅候補が作られるべき');
        $this->assertSame(19, $small->height, '80×50 → 30×19（比率維持・四捨五入）');
        Storage::disk('public')->assertExists($small->path);

        // 残っているのは新しい原本 + 新しい行の実体だけ
        $this->assertCount($media->variants()->count() + 1, Storage::disk('public')->allFiles('media'));
    }
}

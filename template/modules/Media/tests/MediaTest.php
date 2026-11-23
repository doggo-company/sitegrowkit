<?php

declare(strict_types=1);

namespace Modules\Media\Tests;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Media\MediaUsageInspector;
use AgentCms\Core\Media\VariantCapability;
use AgentCms\Core\Media\VariantFormat;
use AgentCms\Core\Media\VariantTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * Media Module（ADR-0006）: アップロード検証・Entity 保存・削除安全性。
 * Phase 1 の画像セキュリティ（MIME 内容検査・ファイル名再生成）はここへ移動した。
 * SVG は Phase 8.5-F で「禁止」から「sanitize して許可」へ変更（F-UX5）。
 */
final class MediaTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function uploadValid(array $overrides = []): void
    {
        $this->actingAs($this->admin())->post('/admin/media', array_merge([
            'file' => UploadedFile::fake()->image('photo.png', 800, 600),
            'name' => 'テスト画像',
            'alt' => 'テスト用のサンプル画像',
        ], $overrides))->assertSessionHasNoErrors();
    }

    public function test_upload_creates_media_entity_with_dimensions_and_safe_filename(): void
    {
        $this->uploadValid();

        $media = Media::sole();
        $this->assertSame('テスト画像', $media->name);
        $this->assertSame('テスト用のサンプル画像', $media->alt);
        $this->assertSame(800, $media->width);
        $this->assertSame(600, $media->height);
        $this->assertSame('image/png', $media->mime);
        $this->assertGreaterThan(0, $media->size);
        // ファイル名は再生成され、元の名前を含まない・media/ 配下（非実行領域）
        $this->assertStringStartsWith('media/', $media->path);
        $this->assertStringNotContainsString('photo', $media->path);
        Storage::disk('public')->assertExists($media->path);
    }

    /**
     * Phase 9.5-D: 新しい派生物は `media_variants` にだけ書く。
     * `has_webp` は **更新しない**（旧データの legacy fallback のためだけに残っている）。
     */
    public function test_upload_records_variants_as_rows_and_leaves_the_legacy_flag_alone(): void
    {
        $this->uploadValid();
        $media = Media::sole();

        $this->assertFalse($media->has_webp, 'has_webp は書き込み対象ではない');

        // 同寸の AVIF / WebP に加えて、原本より小さい幅候補（release-20260909）も行になる
        $capability = new VariantCapability;
        $expected = array_map(
            static fn (VariantTarget $target): string => $target->key(),
            $capability->targetsFor('image/png', 800, 600),
        );

        $this->assertSame(
            $expected,
            $media->variants()->orderBy('id')->get()
                ->map(static fn (MediaVariant $variant): string => $variant->format.'@'.$variant->width)
                ->all(),
        );

        foreach ($media->variants as $variant) {
            Storage::disk('public')->assertExists($variant->path);
            // 拡張子置換ではないので、原本と衝突しない
            $this->assertStringStartsWith('media/variants/', $variant->path);
        }

        // 原本は生成の可否に関わらず必ず在る
        Storage::disk('public')->assertExists($media->path);

        if (in_array(VariantFormat::Webp->value.'@800', $expected, true)) {
            // 同寸の WebP（幅候補ではなく、原本の幅と等しい行）
            $webp = $media->variants->first(
                static fn (MediaVariant $variant): bool => $variant->format === 'webp' && $variant->width === 800,
            );
            $this->assertNotNull($webp);
            $this->assertSame($webp->url(), $media->webpUrl(), 'webpUrl は同寸の variant の行を指すべき');
        } else {
            // 能力が無い環境。variant は無いが upload は成功しており、原本で配信する
            $this->assertSame([], $media->variantSources());
            $this->assertNull($media->webpUrl());
        }
    }

    /**
     * Phase 9.5-D / SPEC §7.1: 写し替えが済むまでは、旧 `has_webp` も情報源である。
     * migration では backfill しないので、ここが繋がっていないと既存サイトの配信が落ちる。
     */
    public function test_an_old_row_still_serves_its_webp_through_the_legacy_flag(): void
    {
        $media = Media::query()->create([
            'name' => '移行前の画像',
            'path' => 'media/legacy.png',
            'mime' => 'image/png',
            'size' => 100,
            'width' => 10,
            'height' => 10,
            'has_webp' => true,
        ]);

        $this->assertSame(
            Storage::disk('public')->url('media/legacy.webp'),
            $media->webpUrl(),
        );
    }

    /**
     * Phase 9.5-D / D7・SPEC §4: **派生物を作れなくても upload は成功する**。
     *
     * 実行環境に encoder が無い場面は、`variants.enabled=false` と同じ経路を通る
     * （どちらも `VariantCapability::supports()` が false を返す）。
     * 本番コードをテスト都合で弱めずに、その形式だけ skip されることを固定できる。
     */
    public function test_upload_succeeds_and_serves_the_original_when_no_variant_can_be_made(): void
    {
        config(['agentcms.variants.enabled' => false]);

        $this->uploadValid();

        $media = Media::sole();

        // 原本は在り、派生物は 1 つも無い
        Storage::disk('public')->assertExists($media->path);
        $this->assertSame(0, $media->variants()->count());
        $this->assertSame([], $media->variantSources());
        $this->assertNull($media->webpUrl());

        // 出すものが無いので <picture> で包まない（原本の <img> だけ）
        $html = Blade::render('<x-media-img :media="$media" />', ['media' => $media->load('variants')]);

        $this->assertStringNotContainsString('<picture', $html);
        $this->assertStringContainsString($media->url(), $html);
    }

    /**
     * Phase 9.5-D / D13: GIF を画像 allowlist へ足す。
     * ただし **原本のみ**で配信する（GD で再エンコードするとアニメーションが失われる）。
     */
    public function test_gif_is_accepted_and_served_as_the_original_only(): void
    {
        $this->uploadValid(['file' => UploadedFile::fake()->image('motion.gif', 200, 100)]);

        $media = Media::sole();
        $this->assertSame('image/gif', $media->mime);
        $this->assertSame(200, $media->width);
        $this->assertFalse($media->has_webp);
        $this->assertNull($media->webpUrl());
        $this->assertSame([], $media->variantSources());
    }

    public function test_name_defaults_to_original_filename(): void
    {
        $this->uploadValid(['name' => null]);
        $this->assertSame('photo', Media::sole()->name);
    }

    public function test_corrupt_image_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admin/media', [
            'file' => UploadedFile::fake()->createWithContent('broken.png', 'this is not an image'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
    }

    /**
     * Phase 8.5-F / F-UX5: SVG は許可するが **能動的コンテンツは保存しない**。
     * 受け取った bytes ではなく sanitizer が再構築した内容だけが保存される。
     */
    public function test_svg_is_stored_without_event_handlers(): void
    {
        $payload = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)">'
            .'<rect width="10" height="10" fill="#000"/></svg>';

        $this->actingAs($this->admin())->post('/admin/media', [
            'file' => UploadedFile::fake()->createWithContent('logo.svg', $payload),
        ])->assertSessionHasNoErrors();

        $media = Media::query()->firstOrFail();
        $stored = Storage::disk('public')->get($media->path);

        $this->assertStringNotContainsStringIgnoringCase('onload', (string) $stored);
        $this->assertStringContainsString('<rect', (string) $stored);
        $this->assertSame('image/svg+xml', $media->mime);
    }

    public function test_svg_with_script_element_is_stored_without_script(): void
    {
        $payload = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            .'<script>alert(1)</script><rect width="10" height="10"/></svg>';

        $this->actingAs($this->admin())->post('/admin/media', [
            'file' => UploadedFile::fake()->createWithContent('x.svg', $payload),
        ])->assertSessionHasNoErrors();

        $stored = (string) Storage::disk('public')->get(Media::query()->firstOrFail()->path);

        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsString('alert(1)', $stored);
    }

    public function test_svg_with_doctype_entity_is_rejected(): void
    {
        $payload = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';

        $this->actingAs($this->admin())->post('/admin/media', [
            'file' => UploadedFile::fake()->createWithContent('xxe.svg', $payload),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
    }

    /**
     * Phase 8.5-F / F-UX6: 差し替えても **Media の id は変わらない**。
     * 参照しているコンテンツを壊さないことが要件。
     */
    public function test_replace_keeps_record_id_and_updates_metadata(): void
    {
        $this->uploadValid(['file' => UploadedFile::fake()->image('before.png', 400, 300)]);

        $media = Media::sole();
        $id = $media->id;
        $oldPath = $media->path;

        $this->actingAs($this->admin())
            ->post("/admin/media/{$id}/replace", [
                'file' => UploadedFile::fake()->image('after.png', 640, 480),
            ])
            ->assertSessionHasNoErrors();

        $media->refresh();

        $this->assertSame($id, $media->id, 'id が変わると既存の参照が壊れる');
        $this->assertNotSame($oldPath, $media->path);
        $this->assertSame(640, $media->width);
        $this->assertSame(480, $media->height);
        // 旧ファイルは成功後に片付けられる
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_replace_with_invalid_file_keeps_existing_media(): void
    {
        $this->uploadValid(['file' => UploadedFile::fake()->image('keep.png', 400, 300)]);

        $media = Media::sole();
        $oldPath = $media->path;

        $this->actingAs($this->admin())
            ->post("/admin/media/{$media->id}/replace", [
                'file' => UploadedFile::fake()->createWithContent('broken.png', 'not-an-image'),
            ])
            ->assertSessionHasErrors('file');

        $media->refresh();

        // 失敗しても既存の画像・参照は無傷
        $this->assertSame($oldPath, $media->path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_media_edit_page_is_reachable(): void
    {
        $this->uploadValid();

        $this->actingAs($this->admin())
            ->get('/admin/media/'.Media::sole()->id)
            ->assertOk();
    }

    public function test_oversized_dimensions_are_rejected(): void
    {
        config(['agentcms.uploads.max_dimension' => 500]);

        $this->actingAs($this->admin())->post('/admin/media', [
            'file' => UploadedFile::fake()->image('big.png', 900, 300),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
    }

    public function test_update_name_and_alt(): void
    {
        $this->uploadValid();
        $media = Media::sole();

        $this->actingAs($this->admin())
            ->patch("/admin/media/{$media->id}", ['name' => '新しい名称', 'alt' => '新しい alt'])
            ->assertSessionHasNoErrors();

        $media->refresh();
        $this->assertSame('新しい名称', $media->name);
        $this->assertSame('新しい alt', $media->alt);
    }

    public function test_unused_media_can_be_deleted_and_files_are_removed(): void
    {
        $this->uploadValid();
        $media = Media::sole();
        $path = $media->path;

        $admin = $this->admin();

        // 削除は破壊的操作なので、確認トークン無しの 1 回目では消えない（Phase 9.1B / STEP 7）
        $this->actingAs($admin)
            ->delete("/admin/media/{$media->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Media::count());
        Storage::disk('public')->assertExists($path);

        $token = app(DeleteMedia::class)
            ->execute((int) $media->id, OperationContext::admin((int) $admin->id, $admin->name))
            ->confirmation->token;

        $this->actingAs($admin)
            ->delete("/admin/media/{$media->id}", ['confirmation_token' => $token])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Media::count());
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing(ImageProcessor::webpPath($path));
    }

    public function test_used_media_cannot_be_deleted(): void
    {
        $this->uploadValid();
        $media = Media::sole();
        $work = $this->makeWork($media->id);

        $this->actingAs($this->admin())
            ->delete("/admin/media/{$media->id}")
            ->assertSessionHasErrors('media');

        $this->assertSame(1, Media::count());
        Storage::disk('public')->assertExists($media->path);

        // エラーメッセージに使用箇所（Works タイトル）が含まれる
        $errors = session('errors')->get('media');
        $this->assertStringContainsString($work->title, implode(' ', $errors));
    }

    public function test_usage_inspector_reports_unused_media_as_unused(): void
    {
        $this->uploadValid();
        $media = Media::sole();
        $inspector = app(MediaUsageInspector::class);

        $this->assertSame([], $inspector->usages($media->id));
        $this->assertSame([$media->id => false], $inspector->usedMap([$media->id]));
    }

    public function test_usage_inspector_derives_usage_from_schemas(): void
    {
        $this->uploadValid();
        $media = Media::sole();
        $work = $this->makeWork($media->id);
        $inspector = app(MediaUsageInspector::class);

        $usages = $inspector->usages($media->id);
        $this->assertCount(1, $usages);
        $usage = $usages[0] ?? [];
        $this->assertSame('works', $usage['schema'] ?? null);
        $this->assertSame($work->title, $usage['items'][0]['title'] ?? null);
        $this->assertSame([$media->id => true], $inspector->usedMap([$media->id]));
    }

    public function test_media_endpoints_require_authentication(): void
    {
        $this->get('/admin/media')->assertRedirect('/login');
        $this->post('/admin/media', [])->assertRedirect('/login');
        $this->get('/admin/media/select')->assertRedirect('/login');
    }

    public function test_public_work_renders_media_with_required_attributes(): void
    {
        $this->uploadValid();
        $media = Media::sole();
        $this->makeWork($media->id, published: true);

        $response = $this->get('/works/media-test-work');
        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('alt="テスト用のサンプル画像"', $html);
        $this->assertStringContainsString('width="800"', $html);
        $this->assertStringContainsString('height="600"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
    }

    private function makeWork(int $mediaId, bool $published = false): Work
    {
        return Work::query()->create([
            'title' => 'メディアテスト実績',
            'slug' => 'media-test-work',
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subDay() : null,
            'main_image_id' => $mediaId,
        ]);
    }
}

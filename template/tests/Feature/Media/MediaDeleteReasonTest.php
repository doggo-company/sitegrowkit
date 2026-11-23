<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Media\MediaUsageInspector;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 「消せない」だけで終わらせない（WP 一周 9 周目 2026-09-04 / 効くもの ③）。
 *
 * ## 何が起きていたか
 *
 * 本文で使っている画像は、一覧の削除ボタンが**灰色で押せない**。
 * 押せない理由は `title` にも `aria-label` にも無く、出ているのは
 * 行の中の「使用中」という札だけだった。
 *
 * どこで使われているのか・先に何をすれば消せるのかが、**その画面のどこにも無い**。
 *
 * 🔴 **投稿をゴミ箱へ入れても「使用中」のまま**である。戻すときに要るので正しいが、
 * それも書かれていないので「ゴミ箱に入れたのにまだ消せない」で二重に止まる。
 *
 * ## どう直すか
 *
 * 使用箇所は `MediaUsageInspector::usages()` が既に持っている。
 * **行き先（編集画面）と、ゴミ箱に入っているかを添えて**、画面から辿れるようにする。
 */
final class MediaDeleteReasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        User::factory()->create(['role' => Role::Owner->value]);
    }

    /** 使用箇所に、そこへ行ける道が付いている */
    public function test_サイトの共有画像は設定への導線を示して削除から保護する(): void
    {
        $media = $this->media();
        app(SettingsStore::class)->put(['social_image_id' => $media]);
        $inspector = app(MediaUsageInspector::class);

        $this->assertSame([$media => true], $inspector->usedMap([$media]));
        $usage = $inspector->usages($media)[0] ?? [];
        $this->assertSame('site', $usage['schema'] ?? null);
        $this->assertSame('/admin/site', $usage['items'][0]['url'] ?? null);
        $this->actingAs(User::query()->firstOrFail())->delete("/admin/media/{$media}")
            ->assertSessionHasErrors('media');
        $this->assertDatabaseHas('media', ['id' => $media]);

        // 設定から外した後は、一覧と削除判定の両方で未使用に戻る。
        app(SettingsStore::class)->put(['social_image_id' => null]);
        $this->assertSame([$media => false], $inspector->usedMap([$media]));
        $this->assertSame([], $inspector->usages($media));
    }

    /** 使用箇所に、そこへ行ける道が付いている */
    public function test_使用箇所に編集画面への道が付く(): void
    {
        $media = $this->media();
        $id = $this->entry('予防接種のご案内', $media);

        $usage = app(MediaUsageInspector::class)->usages($media)[0] ?? [];
        $item = $usage['items'][0] ?? [];

        $this->assertSame('予防接種のご案内', $item['title'] ?? null);
        $this->assertSame("/admin/post/{$id}/edit", $item['url'] ?? null);
    }

    /** ゴミ箱にあるものは、そう分かる（「消したのにまだ使用中」で止まらせない） */
    public function test_ゴミ箱にあるものはそう分かる(): void
    {
        $media = $this->media();
        $id = $this->entry('ゴミ箱へ入れる記事', $media);

        DB::table('post')->where('id', $id)->update(['deleted_at' => now()]);

        $item = app(MediaUsageInspector::class)->usages($media)[0]['items'][0] ?? [];

        $this->assertTrue($item['trashed'] ?? null, 'ゴミ箱に入っていることが分からない');
    }

    /** ふつうに使われているものは、ゴミ箱ではない */
    public function test_使われているものはゴミ箱ではない(): void
    {
        $media = $this->media();
        $this->entry('公開中の記事', $media);

        $item = app(MediaUsageInspector::class)->usages($media)[0]['items'][0] ?? [];

        $this->assertFalse($item['trashed'] ?? null);
    }

    /**
     * 一覧が「押せない理由」と「行き先」を出す。
     *
     * 押せないボタンだけを置くと、消したい人は「壊れている」と読むか何度も押す。
     */
    public function test_一覧は押せない理由と行き先を出す(): void
    {
        $source = File::get(base_path('resources/js/pages/admin/media/Index.vue'));

        $this->assertStringContainsString('media-used-reason', $source);
        $this->assertStringContainsString('使用中', $source);

        // 消せない側にも、次にできることの入口が要る
        $this->assertMatchesRegularExpression('#/admin/media/\$\{?item\.id#', $source);
    }

    /** ブラウザの alert は使わない（押せないボタンの @click は元から動かない） */
    public function test_一覧はalertを使わない(): void
    {
        $this->assertStringNotContainsString(
            'alert(',
            File::get(base_path('resources/js/pages/admin/media/Index.vue')),
        );
    }

    /** 詳細は使用箇所を link で出し、次にできることを言う */
    public function test_詳細は使用箇所をlinkで出す(): void
    {
        $source = File::get(base_path('resources/js/pages/admin/media/Edit.vue'));

        $this->assertStringContainsString('media-usage-link', $source);
        $this->assertStringContainsString('ゴミ箱', $source);
    }

    private function media(): int
    {
        return (int) DB::table('media')->insertGetId([
            'name' => '診療時間の表',
            'alt' => '診療時間の表',
            'path' => 'media/probe-'.uniqid().'.png',
            'mime' => 'image/png',
            'size' => 100,
            'width' => 100,
            'height' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entry(string $title, int $media): int
    {
        $path = (string) DB::table('media')->where('id', $media)->value('path');

        return (int) DB::table('post')->insertGetId([
            'title' => $title,
            'slug' => 'p-'.uniqid(),
            'body' => '<p><img src="/storage/'.$path.'"></p>',
            'status' => 'published',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

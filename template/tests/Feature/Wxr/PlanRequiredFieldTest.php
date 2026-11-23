<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\Apply\ContentImporter;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemFields;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use AgentCms\Core\Wxr\WxrItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 入らない記事を、入れる前に言う（Phase 9.14 / STAGE 27D-3b・SPEC §F）。
 *
 * ## なぜ plan で言うのか
 *
 * §F は「**止めるは plan の段階で全部出す**」である。
 * apply の途中で 1 件ずつ止まると、直しては流し、を何度も繰り返すことになる。
 *
 * 必須の欄に対応する値が WXR に無い記事は、`CreateContent` が断る。
 * plan がそれを見ないと、**十年分を流し始めてから 1 件目で落ちる**。
 */
final class PlanRequiredFieldTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-required-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active',
                'public_slug' => 'news',
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_必須の欄が空なら止める(): void
    {
        // `news` は `body` が必須。本文の空い WordPress 記事は普通にある
        $plan = $this->plan(
            '<item><title>題名</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>aru</wp:post_name></item>',
        );

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());

        $found = $plan->findings()['required_field_missing'];

        $this->assertSame(1, $found['total']);
        $this->assertSame(
            [['external_id' => '11', 'source_type' => 'news']],
            $found['shown'],
        );
    }

    public function test_1件目で止めず全部数える(): void
    {
        $body = '';

        for ($i = 1; $i <= 3; $i++) {
            $body .= "<item><title>題名</title><wp:post_id>{$i}</wp:post_id>"
                .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
                .'<wp:post_name>aru</wp:post_name></item>';
        }

        $this->assertSame(3, $this->plan($body)->findings()['required_field_missing']['total']);
    }

    public function test_補いが効く欄の空では止めない(): void
    {
        /*
         * §F は空の `title` を `(無題)`、空の `post_name` を id から作ると定めている。
         * **この 2 つは空でも埋まる**ので、数えると「直しようのない理由」で止まる。
         */
        $plan = $this->plan(
            '<item><title></title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>',
        );

        $this->assertArrayNotHasKey('required_field_missing', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_埋まっていれば止めない(): void
    {
        $plan = $this->plan(
            '<item><title>題名</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>aru</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>',
        );

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    // ── 型として止める ──────────────────────────────────────

    public function test_対応の無い必須の欄を持つ型は型として止める(): void
    {
        /*
         * **原因は型の設計であって記事ではない。**
         *
         * 十年分の件数で報告しても直す先が分からない。
         * 「この型は取り込み先にできない」と 1 件で言う方が短く、正しい。
         */
        // WXR に対応の無い欄を、必須として型へ足す
        $this->makeGroup('kakaku', [
            ['key' => 'price', 'type' => 'text', 'label' => '価格', 'required' => true],
        ], [['kind' => 'content_type', 'value' => 'news']]);

        $plan = $this->plan(
            '<item><title>題名</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>aru</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>'
            .'<item><title>題名</title><wp:post_id>12</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>aru2</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>',
        );

        $found = $plan->findings()['unmappable_required_field'];

        // **記事は 2 件あるが、報告は型として 1 件**
        $this->assertSame(1, $found['total']);
        $this->assertSame('news', $found['shown'][0]['external_id']);
        $this->assertFalse($plan->isUsable());
    }

    // ── 対応表を 2 か所に持たない ──────────────────────────

    public function test_planとapplyが同じ対応表を読む(): void
    {
        /*
         * 片方だけ欄を足すと、**plan が言ったのと違うものが入る**。
         * plan hash はそれを見つけられない — hash は plan 側の判断から作るので、
         * 両方が同じ誤りをすれば一致してしまう（`ItemTarget` と同じ理由）。
         */
        $item = new WxrItem(
            postId: '11',
            postType: 'news',
            status: 'publish',
            title: '題名',
            slug: 'aru',
            content: '本文',
            excerpt: '',
            publishedAt: null,
            parentId: null,
            attachmentUrl: null,
        );

        $method = new \ReflectionMethod(ContentImporter::class, 'attributes');
        // 第3引数はメディアの URL の対応表（27E-3）。ここでは置換を見ないので空
        $passed = $method->invoke(app(ContentImporter::class), $item, 'news', []);

        foreach (array_keys($passed) as $key) {
            $this->assertContains($key, ItemFields::known(), "{$key} が対応表に無い");
        }

        // 対応表の側にあって schema にある欄は、必ず渡る
        foreach (ItemFields::known() as $key) {
            if (app(ContentTypeResolver::class)->schema('news')->hasField($key)
                && ItemFields::of($item)[$key] !== null) {
                $this->assertArrayHasKey($key, $passed, "{$key} が渡っていない");
            }
        }
    }

    public function test_対応表の一覧が2か所でずれない(): void
    {
        /*
         * F-9 — `known()` と `of()` が欄の一覧を**別々に持っている**。
         * `of()` へ足して `known()` へ足し忘れると、その欄は
         * **plan の必須検査から外れて apply で落ちる**（F-1・F-4 と同じ形）。
         *
         * `assertSame` なので**両方向が同時に固定される** —
         * 順序まで一致を求めるので、どちらへ足し忘れても赤くなる。
         * schema にも fixture にも依らないので、型の定義が変わっても揺れない。
         */
        $item = new WxrItem(
            postId: '11',
            postType: 'news',
            status: 'publish',
            title: '題名',
            slug: 'aru',
            content: '本文',
            excerpt: '',
            publishedAt: null,
            parentId: null,
            attachmentUrl: null,
        );

        $this->assertSame(ItemFields::known(), array_keys(ItemFields::of($item)));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array{kind: string, value: string}>  $locations
     */
    private function makeGroup(string $key, array $fields, array $locations): void
    {
        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => $key,
            'label' => '取り込み用の束',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($locations as $location) {
            DB::table('field_group_locations')->insert([
                'field_group_id' => $id,
                'kind' => $location['kind'],
                'value' => $location['value'],
            ]);
        }
    }

    private function plan(string $body): WxrPlan
    {
        $path = $this->dir.'/plan.xml';

        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>');

        return app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], [], false));
    }
}

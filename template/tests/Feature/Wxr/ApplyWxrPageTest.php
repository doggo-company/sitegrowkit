<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\Pages\PageRepository;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * ページの移行（release-20260909 / wxr-pages）。
 *
 * plan が言ったことと apply が書くものが一致すること、再実行で二重に入らないこと、
 * 危険な HTML が版に残らないこと、取り消しで消えることを固定する。
 */
final class ApplyWxrPageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-pages-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_公開のページを題名と本文と公開日時つきで入れる(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('11', 'company', '<p>会社について</p>', publishedAt: '2024-05-01 09:00:00').'</item>'));

        $page = Page::query()->where('slug', 'company')->firstOrFail();

        $this->assertSame('ページ 11', $page->title);
        $this->assertSame(PageStatus::Published, $page->status);
        $this->assertSame('2024-05-01 09:00:00', $page->published_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($page->published_version_id);
        $this->assertSame($page->draft_version_id, $page->published_version_id);

        $version = DB::table('page_versions')->where('id', $page->published_version_id)->first();
        $document = json_decode((string) $version->document, true);

        $this->assertSame('section', $document['root']['children'][0]['type']);
        $this->assertSame('richtext', $document['root']['children'][0]['children'][0]['type']);
        $this->assertStringContainsString('会社について', $document['root']['children'][0]['children'][0]['props']['html']);

        $record = DB::table('import_records')->where('kind', 'page')->first();
        $this->assertSame('11', $record->external_id);
        $this->assertSame((string) $page->id, $record->target_id);

        $counts = json_decode((string) DB::table('import_batches')->value('counts'), true);
        $this->assertSame(1, $counts['page']['create'] ?? 0);
    }

    public function test_再実行しても同じページを二重に作らない(): void
    {
        $xml = $this->wxr('<item>'.$this->page('11', 'company', '<p>本文</p>').'</item>');

        $this->importing($xml);
        $this->importing($xml, fresh: true);

        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame(1, DB::table('import_records')->where('kind', 'page')->count());

        $plan = $this->planFor($this->write('c.xml', $xml));
        $this->assertSame(1, $plan->counts()['skip_existing'] ?? 0);
    }

    public function test_危険なhtmlは版に残さない(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('11', 'company', '<p>安全</p><script>alert(1)</script><img src="x" onerror="alert(2)">').'</item>'));

        $html = $this->bodyHtml('company');

        $this->assertStringContainsString('安全', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_取り込めたメディアのurlだけ書き換える(): void
    {
        // メディアの取り込みは `--media` の話。ここでは対応表が空なので元の URL のまま残る
        $this->importing($this->wxr('<item>'.$this->page('11', 'company', '<p><img src="https://old.example/wp-content/uploads/a.jpg"></p>').'</item>'));

        $this->assertStringContainsString('https://old.example/wp-content/uploads/a.jpg', $this->bodyHtml('company'));
    }

    public function test_使えないurlは題名から作り直して言う(): void
    {
        $xml = $this->wxr('<item>'.$this->page('11', '会社について', '<p>本文</p>').'</item>');

        $plan = $this->planFor($this->write('p.xml', $xml));
        $this->assertSame(1, $plan->findings()['page_slug_generated']['total']);
        $this->assertTrue($plan->isUsable());

        $this->importing($xml);

        $page = Page::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $page->slug);
    }

    public function test_既にあるurlとぶつかれば別のurlで入れる(): void
    {
        Page::query()->create(['title' => '既存', 'slug' => 'company', 'status' => PageStatus::Draft->value, 'managed_by' => Page::MANAGED_BY_DOCUMENT]);

        $this->importing($this->wxr('<item>'.$this->page('11', 'company', '<p>本文</p>').'</item>'));

        $this->assertSame(2, DB::table('pages')->count());
        $this->assertSame(1, DB::table('pages')->where('slug', 'company')->count());
        $this->assertSame(1, DB::table('pages')->where('slug', 'company-2')->count());
    }

    public function test_本文が空の公開ページは下書きで入れて言う(): void
    {
        $xml = $this->wxr('<item>'.$this->page('11', 'empty', '').'</item>');

        $plan = $this->planFor($this->write('e.xml', $xml));
        $this->assertSame(1, $plan->findings()['page_empty_kept_draft']['total']);
        $this->assertTrue($plan->isUsable());

        $this->importing($xml);

        $this->assertSame(PageStatus::Draft, Page::query()->firstOrFail()->status);
    }

    public function test_下書きのページは下書きのまま入れる(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('11', 'draft', '<p>本文</p>', status: 'draft').'</item>'));

        $page = Page::query()->firstOrFail();
        $this->assertSame(PageStatus::Draft, $page->status);
        $this->assertNull($page->published_version_id);
    }

    public function test_予約のページは未来の公開日時で入れる(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('11', 'soon', '<p>本文</p>', status: 'future', publishedAt: '2099-01-01 09:00:00').'</item>'));

        $page = Page::query()->firstOrFail();
        $this->assertSame(PageStatus::Published, $page->status);
        $this->assertTrue($page->isScheduled());
        $this->assertFalse($page->isPublicallyVisible());
    }

    public function test_予約なのに日時が読めなければ止める(): void
    {
        $plan = $this->planFor($this->write('f.xml', $this->wxr('<item>'.$this->page('11', 'soon', '<p>本文</p>', status: 'future', publishedAt: '').'</item>')));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['page_schedule_unreadable']['total']);
    }

    public function test_親子とshortcodeと長い抜粋は止めずに言う(): void
    {
        $plan = $this->planFor($this->write('g.xml', $this->wxr(
            '<item>'.$this->page('11', 'child', '<p>[gallery ids="1,2"]</p>', parent: '5', excerpt: str_repeat('あ', 201)).'</item>',
        )));

        $found = $plan->findings();
        $this->assertSame(1, $found['page_parent_flattened']['total']);
        $this->assertSame(1, $found['page_shortcode_kept']['total']);
        $this->assertSame(1, $found['page_seo_description_dropped']['total']);
        $this->assertTrue($plan->isUsable(), json_encode($found, JSON_UNESCAPED_UNICODE));
    }

    public function test_shortcodeは本文から消さない(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('11', 'gal', '<p>[gallery ids="1,2"]</p>').'</item>'));

        $this->assertStringContainsString('[gallery ids=&quot;1,2&quot;]', htmlspecialchars($this->bodyHtml('gal'), ENT_QUOTES));
    }

    public function test_抜粋はseoのdescriptionに入る(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('11', 'company', '<p>本文</p>', excerpt: '会社の紹介').'</item>'));

        $this->assertSame('会社の紹介', Page::query()->firstOrFail()->seo['description'] ?? null);
    }

    public function test_大きすぎる本文と長すぎる題名は止める(): void
    {
        $plan = $this->planFor($this->write('h.xml', $this->wxr(
            '<item>'.$this->page('11', 'big', str_repeat('a', 50001)).'</item>'
            .'<item>'.$this->page('12', 'long', '<p>本文</p>', title: str_repeat('あ', 201)).'</item>',
        )));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['page_body_too_large']['total']);
        $this->assertSame(1, $plan->findings()['page_title_too_long']['total']);
        $this->assertSame(0, DB::table('pages')->count());
    }

    public function test_取り消しで触っていないページだけ消す(): void
    {
        $this->importing($this->wxr(
            '<item>'.$this->page('11', 'keep', '<p>本文</p>').'</item>'
            .'<item>'.$this->page('12', 'gone', '<p>本文</p>').'</item>',
        ));

        $batchId = (string) DB::table('import_batches')->value('id');

        // 人が後から触った方は残す
        $this->travel(2)->minutes();
        Page::query()->where('slug', 'keep')->firstOrFail()->forceFill(['title' => '人が直した'])->save();

        $this->artisan('agentcms:wxr-rollback', ['batch' => $batchId, '--i-have-a-backup' => true])->assertExitCode(0);

        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame('keep', DB::table('pages')->value('slug'));
        $this->assertSame(0, DB::table('page_versions')->whereNotIn('page_id', DB::table('pages')->select('id'))->count());
        $this->assertSame(1, DB::table('import_records')->where('kind', 'page')->count());
    }

    public function test_同一秒の変更を取り消しで消さない(): void
    {
        $this->freezeTime();
        $this->importing($this->wxr('<item>'.$this->page('61', 'edited-page', '<p>本文</p>').'</item>'));
        $batchId = (string) DB::table('import_batches')->value('id');
        Page::query()->where('slug', 'edited-page')->firstOrFail()->forceFill(['title' => '同じ秒の編集'])->save();
        $this->artisan('agentcms:wxr-rollback', ['batch' => $batchId, '--i-have-a-backup' => true])->assertExitCode(0);
        $this->assertTrue(Page::query()->where('title', '同じ秒の編集')->exists());
    }

    public function test_取り込み後にトップ指定されたページを取り消しで消さない(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('71', 'new-front', '<p>サイトのトップ</p>').'</item>'));
        $page = Page::query()->firstOrFail();
        app(SettingsStore::class)->put(['front_page_id' => $page->id]);
        $batchId = (string) DB::table('import_batches')->value('id');
        $this->artisan('agentcms:wxr-rollback', ['batch' => $batchId, '--i-have-a-backup' => true])->assertExitCode(0);
        $this->assertTrue(Page::query()->whereKey($page->id)->exists());
        $this->get('/')->assertOk()->assertSee('サイトのトップ');
    }

    public function test_公開したページの本文が実urlから読める(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('62', 'about', '<p>移行した会社案内の本文</p>').'</item>'));
        $page = Page::query()->firstOrFail();
        $this->get('/'.$page->slug)->assertOk()->assertSee('移行した会社案内の本文');
    }

    public function test_routeに取られたurlは別のurlで入れて言う(): void
    {
        /*
         * `/about` `/contact` `/news` は名前付き route が先に受ける。同じ綴りの Page は
         * DB に入っても読めないので、`-2` 付きで入れて `page_slug_reserved` で元の綴りを言う。
         * 既存の画面は**上書きしない**（`/about` は従来どおり同梱ページ）。
         */
        $xml = $this->wxr(
            '<item>'.$this->page('11', 'about', '<p>移行した会社案内</p>').'</item>'
            .'<item>'.$this->page('12', 'contact', '<p>移行した連絡先</p>').'</item>'
            .'<item>'.$this->page('13', 'news', '<p>移行したお知らせ</p>').'</item>',
        );

        $plan = $this->planFor($this->write('r.xml', $xml));
        $found = $plan->findings();

        $this->assertSame(3, $found['page_slug_reserved']['total']);
        $this->assertSame(['about' => 1, 'contact' => 1, 'news' => 1], $found['page_slug_reserved']['by_source']);
        $this->assertArrayNotHasKey('page_slug_generated', $found);
        $this->assertTrue($plan->isUsable(), json_encode($found, JSON_UNESCAPED_UNICODE));

        $this->importing($xml);

        $this->assertSame(['about-2', 'contact-2', 'news-2'], Page::query()->orderBy('id')->pluck('slug')->all());
        $this->assertSame(0, DB::table('pages')->whereIn('slug', ['about', 'contact', 'news'])->count());

        $this->get('/about-2')->assertOk()->assertSee('移行した会社案内');
        $this->get('/contact-2')->assertOk()->assertSee('移行した連絡先');
        $this->get('/about')->assertDontSee('移行した会社案内');
    }

    public function test_編集した本文は取り消しで消さない(): void
    {
        // 時刻は一切進めない。版が増えれば snapshot と一致しないので残る
        $this->freezeTime();
        $this->importing($this->wxr('<item>'.$this->page('63', 'edited-body', '<p>本文</p>').'</item>'));
        $batchId = (string) DB::table('import_batches')->value('id');

        $page = Page::query()->where('slug', 'edited-body')->firstOrFail();
        app(PageRepository::class)
            ->createVersion($page, DocumentTree::empty(), 'admin', null);

        $this->artisan('agentcms:wxr-rollback', ['batch' => $batchId, '--i-have-a-backup' => true])->assertExitCode(0);

        $this->assertTrue(Page::query()->where('slug', 'edited-body')->exists());
        $this->assertSame(1, DB::table('import_records')->where('kind', 'page')->count());
    }

    public function test_ページのコメントと分類は入れずに件数で言う(): void
    {
        $plan = $this->planFor($this->write('t.xml', $this->wxr(
            '<item>'.$this->page('11', 'company', '<p>本文</p>')
            .'<category domain="category" nicename="info"><![CDATA[お知らせ]]></category>'
            .'<wp:comment><wp:comment_id>1</wp:comment_id><wp:comment_author>a</wp:comment_author><wp:comment_content>c</wp:comment_content><wp:comment_approved>1</wp:comment_approved><wp:comment_parent>0</wp:comment_parent></wp:comment>'
            .'<wp:comment><wp:comment_id>2</wp:comment_id><wp:comment_author>b</wp:comment_author><wp:comment_content>d</wp:comment_content><wp:comment_approved>1</wp:comment_approved><wp:comment_parent>0</wp:comment_parent></wp:comment>'
            .'</item>',
        )));

        $found = $plan->findings();
        $this->assertSame(2, $found['page_comments_dropped']['total']);
        $this->assertSame(1, $found['page_terms_dropped']['total']);
        $this->assertSame(['category' => 1], $found['page_terms_dropped']['by_source']);
        $this->assertTrue($plan->isUsable(), json_encode($found, JSON_UNESCAPED_UNICODE));
    }

    public function test_取り消しは台帳の行とページを同じtransactionで消す(): void
    {
        $this->importing($this->wxr('<item>'.$this->page('64', 'plain', '<p>本文</p>').'</item>'));
        $batchId = (string) DB::table('import_batches')->value('id');

        $this->artisan('agentcms:wxr-rollback', ['batch' => $batchId, '--i-have-a-backup' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('pages')->count());
        $this->assertSame(0, DB::table('page_versions')->count());
        $this->assertSame(0, DB::table('import_records')->where('kind', 'page')->count());
    }

    // ── helpers ──────────────────────────────────────────────

    private function bodyHtml(string $slug): string
    {
        $page = Page::query()->where('slug', $slug)->firstOrFail();
        $version = DB::table('page_versions')->where('id', $page->draft_version_id)->first();
        $document = json_decode((string) $version->document, true);

        return (string) $document['root']['children'][0]['children'][0]['props']['html'];
    }

    private function planFor(string $path): WxrPlan
    {
        return app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], [], false));
    }

    private function importing(string $xml, bool $fresh = false): void
    {
        $path = $this->write($fresh ? 'b.xml' : 'a.xml', $xml);

        $this->apply($path)->assertExitCode(0);
    }

    private function apply(string $path): PendingCommand
    {
        $plan = $this->planFor($path);

        return $this->artisan('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => (string) $plan->fingerprint(),
        ]);
    }

    private function page(
        string $id,
        string $slug,
        string $content,
        string $status = 'publish',
        ?string $publishedAt = '2024-01-01 00:00:00',
        ?string $parent = null,
        string $excerpt = '',
        ?string $title = null,
    ): string {
        $title ??= "ページ {$id}";

        /*
         * WordPress の書き出しでは post_date が現地時刻、post_date_gmt が UTC。
         * 同じ文字列を両方に書くと、読み手が GMT を UTC→現地へ移して 9 時間ずれる
         * （WxrReader::date / F-17）。引数は現地時刻として渡し、GMT はここから作る。
         */
        $local = $publishedAt ?? '';
        $gmt = '';
        if ($local !== '' && ! str_starts_with($local, '0000-00-00')) {
            $gmt = (new \DateTimeImmutable($local, new \DateTimeZone(date_default_timezone_get())))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        return "<title>{$title}</title><wp:post_id>{$id}</wp:post_id>"
            ."<wp:post_type>page</wp:post_type><wp:status>{$status}</wp:status>"
            ."<wp:post_name>{$slug}</wp:post_name>"
            ."<wp:post_date_gmt>{$gmt}</wp:post_date_gmt><wp:post_date>{$local}</wp:post_date>"
            .($parent !== null ? "<wp:post_parent>{$parent}</wp:post_parent>" : '')
            ."<excerpt:encoded><![CDATA[{$excerpt}]]></excerpt:encoded>"
            ."<content:encoded><![CDATA[{$content}]]></content:encoded>";
    }

    private function wxr(string $body = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 記事を分類へ結び付ける（Phase 9.14 / STAGE 27D-5a・SPEC §C.3・§E.1・§D.2.1）。
 *
 * ## 専用の書き込み経路を作らない
 *
 * 結び付けは `CreateContent` の `taxonomies` 引数で渡る（§E.1）。
 * `taxonomy_term_assignments` へ直接書くと、validation も policy も監査も抜けた
 * **import だけ通る穴**になる。
 *
 * ## 辿るのは台帳
 *
 * item が持っているのは元の綴りだけで、こちらの綴りは当て直されていることがある。
 * 綴りで辿ると、**当てが働いた分類の割り当てが丸ごと落ちる**。
 * 元の綴り → 代表の `wp:term_id`（`TermGraph`）→ こちらの id（台帳）と辿る。
 *
 * ## 作るときにしか結び付けない
 *
 * §D.2.1 — 台帳に記録があって `skip_existing` になった記事の分類は触らない。
 * 触ると、移行の後で人が外した分類を**再実行が黙って戻す**。
 */
final class ApplyWxrAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    /** この段で書いてよい表 */
    private const ALLOWED = [
        'import_batches', 'audit_logs', 'import_records', 'news',
        'content_entry_revisions', 'content_schema_manifests',
        'taxonomy_terms', 'taxonomy_term_assignments',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-assign-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

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

    public function test_記事が分類へ結び付く(): void
    {
        $this->importing(
            $this->term('1', 'oshirase', 'お知らせ')
            .$this->entry('11', ['category' => 'oshirase']),
            ['category:news_category'],
        );

        $assignment = DB::table('taxonomy_term_assignments')->first();

        $this->assertNotNull($assignment, '記事に分類が付いていません');
        $this->assertSame('news', $assignment->content_type);
        $this->assertSame((int) DB::table('news')->value('id'), (int) $assignment->content_id);
        $this->assertSame(
            (string) DB::table('taxonomy_terms')->where('slug', 'oshirase')->value('id'),
            $assignment->term_id,
        );
    }

    public function test_当てた綴りでも結び付く(): void
    {
        /*
         * `Str::slug('お知らせ')` は空なので、こちらの綴りは `term-7` になる。
         * **item が持っているのは元の綴りだけ**なので、綴りで辿る実装はここで落ちる。
         * 日本語のサイトではこれがほとんどになる。
         */
        $this->importing(
            $this->term('7', 'お知らせ', 'お知らせ')
            .$this->entry('11', ['category' => 'お知らせ']),
            ['category:news_category'],
        );

        $this->assertSame('term-7', DB::table('taxonomy_terms')->value('slug'));
        $this->assertSame(
            (string) DB::table('taxonomy_terms')->value('id'),
            DB::table('taxonomy_term_assignments')->value('term_id'),
        );
    }

    public function test_既にあった項目へも結び付く(): void
    {
        /*
         * こちらが作っていない項目（`reuse`）でも、記事は結び付く。
         * 所有しないことと、結び付けないことは別である。
         */
        $existing = $this->existingTerm('news_category', 'oshirase');

        $this->importing(
            $this->term('1', 'oshirase', 'お知らせ')
            .$this->entry('11', ['category' => 'oshirase']),
            ['category:news_category'],
        );

        $this->assertSame($existing, DB::table('taxonomy_term_assignments')->value('term_id'));
    }

    public function test_重なった定義でも代表へ結び付く(): void
    {
        // 割り当ては綴りで来るので、重なった id ではなく**代表の対象**へ付く
        $this->importing(
            $this->term('1', 'oshirase', 'お知らせ')
            .$this->term('2', 'oshirase', 'お知らせ')
            .$this->entry('11', ['category' => 'oshirase']),
            ['category:news_category'],
        );

        $this->assertSame(1, DB::table('taxonomy_terms')->count());
        $this->assertSame(1, DB::table('taxonomy_term_assignments')->count());
    }

    public function test_割り当ての無い記事は結び付けない(): void
    {
        $this->importing($this->entry('11', []));

        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(0, DB::table('taxonomy_term_assignments')->count());
    }

    public function test_既にある記事へは後から付けない(): void
    {
        /*
         * §D.2.1 — 結び付けは**記事を作るときにしか起きない**。
         * 移行の後で人が外した分類を、再実行が黙って戻してはならない。
         */
        $body = $this->term('1', 'oshirase', 'お知らせ')
            .$this->entry('11', ['category' => 'oshirase']);

        $this->importing($body, ['category:news_category']);
        $this->assertSame(1, DB::table('taxonomy_term_assignments')->count());

        // 人が外した
        DB::table('taxonomy_term_assignments')->delete();

        $this->importing($body, ['category:news_category'], fresh: true);

        $this->assertSame(0, DB::table('taxonomy_term_assignments')->count(), '再実行が分類を戻しています');
    }

    public function test_許した表以外は1行も変わらない(): void
    {
        $path = $this->write('a.xml', $this->wxr(
            $this->term('1', 'oshirase', 'お知らせ')
            .$this->entry('11', ['category' => 'oshirase']),
        ));

        $before = $this->snapshot(self::ALLOWED);
        $writes = $this->watchWrites(self::ALLOWED);

        $this->apply($path, ['category:news_category'])->assertExitCode(0);

        $this->assertSame([], $writes(), '許していない表への書き込みが出ています');
        $this->assertSame($before, $this->snapshot(self::ALLOWED), '許していない表の中身が変わっています');
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  list<string>  $taxonomies
     */
    private function importing(string $body, array $taxonomies = [], bool $fresh = false): void
    {
        $path = $this->write($fresh ? 'b.xml' : 'a.xml', $this->wxr($body));

        $this->apply($path, $taxonomies)->assertExitCode(0);
    }

    /**
     * @param  list<string>  $taxonomies
     */
    private function apply(string $path, array $taxonomies): PendingCommand
    {
        $options = [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => $this->hashFor($path, $taxonomies),
        ];

        if ($taxonomies !== []) {
            $options['--taxonomy'] = $taxonomies;
        }

        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-apply', $options);
    }

    /**
     * @param  list<string>  $taxonomies
     */
    private function hashFor(string $path, array $taxonomies): string
    {
        return (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], $taxonomies, false))
            ->fingerprint();
    }

    private function existingTerm(string $taxonomy, string $slug): string
    {
        $taxonomyId = (string) DB::table('taxonomies')->where('key', $taxonomy)->value('id');
        $id = (string) Str::ulid();

        DB::table('taxonomy_terms')->insert([
            'id' => $id,
            'taxonomy_id' => $taxonomyId,
            'name' => '既にある項目',
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, string>  $assignments  domain => nicename
     */
    private function entry(string $id, array $assignments): string
    {
        $categories = '';

        foreach ($assignments as $domain => $nicename) {
            $categories .= "<category domain=\"{$domain}\" nicename=\"{$nicename}\">"
                ."<![CDATA[{$nicename}]]></category>";
        }

        return "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            ."<wp:post_name>kiji-{$id}</wp:post_name>"
            ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded>"
            .$categories
            .'</item>';
    }

    private function term(string $termId, string $slug, string $name): string
    {
        return '<wp:term>'
            ."<wp:term_id>{$termId}</wp:term_id>"
            .'<wp:term_taxonomy>category</wp:term_taxonomy>'
            ."<wp:term_slug>{$slug}</wp:term_slug>"
            ."<wp:term_name><![CDATA[{$name}]]></wp:term_name>"
            .'<wp:term_parent></wp:term_parent>'
            .'</wp:term>';
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

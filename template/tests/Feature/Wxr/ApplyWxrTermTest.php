<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Wxr\Apply\TermImporter;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 分類項目を入れる（Phase 9.14 / STAGE 27D-4・SPEC §C.3・§E.1）。
 *
 * ## 親から先に作る
 *
 * `CreateTerm` が受け取る親は**こちらの ULID** である。
 * 親を先に作って id を覚えていなければ、子を作れない。
 *
 * ## 作っていないものを所有しない
 *
 * 既にあった項目を使い回した場合も、重なった `wp:term_id` の場合も、
 * **この取り込みがその項目を作ったわけではない**ので所有権を持たない。
 * 所有すると、取り消しで**元からあった項目まで消える**。
 */
final class ApplyWxrTermTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    /** この段で書いてよい表 */
    private const ALLOWED = [
        'import_batches', 'audit_logs', 'import_records', 'news',
        'content_entry_revisions', 'content_schema_manifests', 'taxonomy_terms',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-term-'.bin2hex(random_bytes(6));
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

    public function test_分類項目を作る(): void
    {
        $this->importing($this->term('1', 'oshirase', 'お知らせ'));

        $this->assertSame(1, DB::table('taxonomy_terms')->count());
        $this->assertSame('oshirase', DB::table('taxonomy_terms')->value('slug'));
        $this->assertSame('お知らせ', DB::table('taxonomy_terms')->value('name'));
    }

    public function test_作った項目を所有する(): void
    {
        $this->importing($this->term('1', 'oshirase', 'お知らせ'));

        $record = DB::table('import_records')->where('kind', 'term')->first();

        $this->assertNotNull($record);
        $this->assertSame('news_category', $record->target_type);
        $this->assertSame('1', $record->external_id);
        $this->assertSame((string) DB::table('taxonomy_terms')->value('id'), $record->target_id);
        $this->assertSame(1, (int) $record->created_by_import);
    }

    public function test_当てた綴りで作る(): void
    {
        // `Str::slug('お知らせ')` は空になるので、term_id から当てた綴りが入る
        $this->importing($this->term('7', 'お知らせ', 'お知らせ'));

        $this->assertSame('term-7', DB::table('taxonomy_terms')->value('slug'));
    }

    public function test_親を先に作る(): void
    {
        /*
         * WXR には**子が先**に書かれている。作る順は依存の順でなければならない。
         */
        $this->importing(
            $this->term('2', 'ko', '子', 'oya')
            .$this->term('1', 'oya', '親'),
        );

        $parent = DB::table('taxonomy_terms')->where('slug', 'oya')->first();
        $child = DB::table('taxonomy_terms')->where('slug', 'ko')->first();

        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_既にある項目は作らず使う(): void
    {
        /*
         * §C.3 は「既存の分類へ**項目だけ**足す」。既にあるなら足しようがないので使う。
         * **こちらが作っていないので所有しない** — 取り消しで消してはならない。
         */
        $existing = $this->existingTerm('news_category', 'oshirase');

        $this->importing($this->term('1', 'oshirase', 'お知らせ'));

        $this->assertSame(1, DB::table('taxonomy_terms')->count());

        $record = DB::table('import_records')->where('kind', 'term')->first();

        $this->assertNotNull($record);
        $this->assertSame($existing, $record->target_id);
        $this->assertSame(0, (int) $record->created_by_import);
    }

    public function test_重なった定義は代表の対象を共有する(): void
    {
        $this->importing(
            $this->term('1', 'oshirase', 'お知らせ')
            .$this->term('2', 'oshirase', 'お知らせ'),
        );

        // 作るのは 1 つだけ
        $this->assertSame(1, DB::table('taxonomy_terms')->count());

        $records = DB::table('import_records')->where('kind', 'term')->orderBy('external_id')->get();

        $this->assertCount(2, $records);
        // 同じ対象を指す
        $this->assertSame($records[0]->target_id, $records[1]->target_id);
        // **所有するのは代表 1 件だけ**
        $this->assertSame(1, (int) $records[0]->created_by_import);
        $this->assertSame(0, (int) $records[1]->created_by_import);
    }

    public function test_2回流しても二重に作らない(): void
    {
        $body = $this->term('1', 'oshirase', 'お知らせ');

        $this->importing($body);
        $this->importing($body, fresh: true);

        $this->assertSame(1, DB::table('taxonomy_terms')->count());
        $this->assertSame(1, DB::table('import_records')->where('kind', 'term')->count());
    }

    public function test_aliasの記録が欠けても再実行で埋まり二重にならない(): void
    {
        /*
         * **alias の台帳行は代表と別の transaction で書く。**
         * その判断が正しいことを、**壊れた状態からの回復**で確かめる。
         *
         * alias の行が欠けても、代表の行があるので対象は取り消せる
         * （27D-3 の「取り消せない対象ができる」危険は起きない）。
         * 再実行すると、同じ綴りなので `reuse` に落ち、二重には作られない。
         */
        $body = $this->term('1', 'oshirase', 'お知らせ').$this->term('2', 'oshirase', 'お知らせ');

        $this->importing($body);

        // alias の行だけを消す（代表と項目はそのまま）
        DB::table('import_records')->where('kind', 'term')->where('external_id', '2')->delete();
        $this->assertSame(1, DB::table('import_records')->where('kind', 'term')->count());

        $this->importing($body, fresh: true);

        // 項目は増えない
        $this->assertSame(1, DB::table('taxonomy_terms')->count());

        $alias = DB::table('import_records')->where('kind', 'term')->where('external_id', '2')->first();

        $this->assertNotNull($alias, 'alias の記録が埋まっていません');
        $this->assertSame(0, (int) $alias->created_by_import);
    }

    public function test_許した表以外は1行も変わらない(): void
    {
        $path = $this->write('a.xml', $this->wxr($this->term('1', 'oshirase', 'お知らせ')));

        $before = $this->snapshot(self::ALLOWED);
        $writes = $this->watchWrites(self::ALLOWED);

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $this->assertSame([], $writes(), '許していない表への書き込みが出ています');
        $this->assertSame($before, $this->snapshot(self::ALLOWED), '許していない表の中身が変わっています');
    }

    // ── ヘルパー ────────────────────────────────────────────

    public function test_名前が引けないときは綴りを名前にしない(): void
    {
        /*
         * F-7 — 定義から作った代表なのだから、名前が引けないのは
         * **2 周目の読み取りが 1 周目と違うものを返した**場合だけである。
         * そこで黙って綴りを名前にすると、十年分の分類が
         * `oshirase` のような URL 用の文字列で並ぶ。**名前は後から復元できない。**
         *
         * 定義とファイルは同じものなので、この食い違いは fixture では作れない。
         * だから読み取りの結果そのものを空にして当てる。
         */
        $this->expectException(\RuntimeException::class);

        $method = new \ReflectionMethod(TermImporter::class, 'createOne');

        $method->invoke(
            app(TermImporter::class),
            ['termId' => '1', 'target' => 'news_category', 'storageSlug' => 'oshirase', 'parentTermId' => null],
            [],
            [],
            $this->openBatch(),
            OperationContext::cli(),
        );
    }

    /** 台帳の行を書けるようにするためだけの batch（FK があるので実在が要る） */
    private function openBatch(): string
    {
        $id = (string) Str::ulid();

        DB::table('import_batches')->insert([
            'id' => $id,
            'source' => 'a.xml',
            'file_hash' => str_repeat('0', 64),
            'plan_hash' => str_repeat('0', 64),
            'options' => '{}',
            'counts' => '{}',
            'started_at' => now(),
        ]);

        return $id;
    }

    private function importing(string $body, bool $fresh = false): void
    {
        $path = $this->write($fresh ? 'b.xml' : 'a.xml', $this->wxr($body));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);
    }

    private function apply(string $path, bool $backup = false, ?string $hash = null): PendingCommand
    {
        $options = ['file' => $path];

        if ($backup) {
            $options['--i-have-a-backup'] = true;
        }

        if ($hash !== null) {
            $options['--plan-hash'] = $hash;
        }

        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-apply', $options);
    }

    private function hashFor(string $path): string
    {
        return (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], [], false))
            ->fingerprint();
    }

    private function existingTerm(string $taxonomy, string $slug): string
    {
        $taxonomyId = (string) DB::table('taxonomies')->where('key', $taxonomy)->value('id');
        $id = Str::ulid()->toString();

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

    private function term(string $termId, string $slug, string $name, string $parentSlug = ''): string
    {
        return '<wp:term>'
            ."<wp:term_id>{$termId}</wp:term_id>"
            .'<wp:term_taxonomy>news_category</wp:term_taxonomy>'
            ."<wp:term_slug>{$slug}</wp:term_slug>"
            ."<wp:term_name><![CDATA[{$name}]]></wp:term_name>"
            ."<wp:term_parent>{$parentSlug}</wp:term_parent>"
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

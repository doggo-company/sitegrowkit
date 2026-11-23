<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use App\Console\Commands\ImportTermsCommand;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Phase 9.3B / #23 — Template Import の分類項目 batch（SPEC §U.3）。
 *
 * ここで固定するのは 1 つだけ。
 *
 * **全件成功か、1 件も残らないか。**
 *
 * 途中で失敗したときに「作れたぶんだけ残す」を許すと、
 * 消すには削除（確認と参照調査を伴う別の意味の操作）が要る半端な状態が残る。
 * 補償で消すのではなく、**そもそも作らない**ことで担保する。
 */
final class ImportTermsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomySynchronizer::class)->sync();
    }

    public function test_全件成功すると項目と監査が残る(): void
    {
        $before = $this->auditCount();

        $exit = $this->importTerms([
            ['taxonomyKey' => 'news_category', 'name' => 'お知らせ', 'slug' => 'oshirase'],
            ['taxonomyKey' => 'news_category', 'name' => 'イベント', 'slug' => 'event'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(2, TaxonomyTerm::query()->count());
        $this->assertSame($before + 2, $this->auditCount());
    }

    /**
     * 2 件目が失敗したとき、**1 件目も残らない**。
     *
     * 監査も同じトランザクションに乗っていることを行数で確かめる。
     * 監査だけ別接続で書かれていると、ここで差が出る。
     */
    public function test_2件目が失敗したら1件目も監査も残らない(): void
    {
        $terms = TaxonomyTerm::query()->count();
        $audits = $this->auditCount();

        $exit = $this->importTerms([
            ['taxonomyKey' => 'news_category', 'name' => '正しい項目', 'slug' => 'valid-one'],
            // 存在しない分類 → CreateTerm が not_found を返す
            ['taxonomyKey' => 'no_such_taxonomy', 'name' => 'だめな項目'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame($terms, TaxonomyTerm::query()->count(), '1 件目が残っている');
        $this->assertSame($audits, $this->auditCount(), '監査行が残っている');
        $this->assertNull(TaxonomyTerm::query()->where('slug', 'valid-one')->first());
    }

    /** 同じ slug が 2 回来た場合も、片方だけ作られた状態にしない */
    public function test_重複slugでも部分適用にならない(): void
    {
        $terms = TaxonomyTerm::query()->count();

        $exit = $this->importTerms([
            ['taxonomyKey' => 'news_category', 'name' => '一つ目', 'slug' => 'same'],
            ['taxonomyKey' => 'news_category', 'name' => '二つ目', 'slug' => 'same'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame($terms, TaxonomyTerm::query()->count());
    }

    public function test_未知のキーは入口で拒否される(): void
    {
        $exit = $this->importTerms([
            ['taxonomyKey' => 'news_category', 'name' => 'だめ', 'archived' => true],
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, TaxonomyTerm::query()->count());
        $this->assertStringContainsString('受け付けないキー', $this->commandOutput());
    }

    public function test_空配列は何もせず成功する(): void
    {
        $exit = $this->importTerms([]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, TaxonomyTerm::query()->count());
    }

    /** archived な項目と同じ slug は CreateTerm 側の contract で弾かれる（迂回していない） */
    public function test_archived_slugと衝突する作成は失敗して何も残さない(): void
    {
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $archived = new TaxonomyTerm;
        $archived->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomy->id,
            'name' => '過去の分類',
            'slug' => 'retired',
            'sort_order' => 0,
            'archived_at' => now(),
        ])->save();

        $terms = TaxonomyTerm::query()->count();

        $exit = $this->importTerms([
            ['taxonomyKey' => 'news_category', 'name' => '新しい', 'slug' => 'brand-new'],
            ['taxonomyKey' => 'news_category', 'name' => '再利用', 'slug' => 'retired'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame($terms, TaxonomyTerm::query()->count());
        $this->assertNull(TaxonomyTerm::query()->where('slug', 'brand-new')->first());
    }

    /** AI からは触れない（公開面を増やしていない） */
    public function test_ai_へ公開していない(): void
    {
        $this->assertFalse(OperationCatalog::allows('term.import'));
        $this->assertFalse(OperationCatalog::allows('agentcms:import-terms'));

        $source = (string) file_get_contents(base_path('app/Support/OperationCatalog.php'));
        $this->assertStringNotContainsString('ImportTermsCommand', $source);
    }

    /** raw SQL / model 直書きで Application Service を迂回していない */
    public function test_createterm_を迂回していない(): void
    {
        $source = (string) file_get_contents(base_path('app/Console/Commands/ImportTermsCommand.php'));

        $this->assertStringContainsString('CreateTerm', $source);
        foreach (['DB::insert', 'DB::statement', 'DB::table', '->save()', 'TaxonomyTerm::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * payload を stdin ストリームとして渡してコマンドを実行する。
     *
     * `$this->artisan()` では入力ストリームを差し替えられないので、
     * Symfony の Input を組み立てて実行する。**argv には payload を載せない**
     * という実装の契約はそのまま通る。
     *
     * @param  list<array<string, mixed>>  $terms
     */
    private function importTerms(array $terms): int
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode(['terms' => $terms], JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command = app(ImportTermsCommand::class);
        $command->setLaravel(app());

        $exit = $command->run($input, $output);

        $this->lastOutput = $output->fetch();
        fclose($stream);

        return $exit;
    }

    private function commandOutput(): string
    {
        return $this->lastOutput;
    }

    private string $lastOutput = '';

    private function auditCount(): int
    {
        return DB::table('audit_logs')->count();
    }
}

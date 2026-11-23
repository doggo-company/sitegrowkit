<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\ContentTypes\ContentTypeDraft;
use AgentCms\Core\ContentTypes\ContentTypeGenerator;
use AgentCms\Core\ContentTypes\ContentTypeIdentifier;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Wxr\Status\WxrStatusColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 出自の列が、すべての投稿タイプにあること（Phase 9.14 / STAGE 27C・SPEC §C.8.3）。
 *
 * ## この検査が無いと、誰も気づかない
 *
 * `SchemaTableParityTest` は **schema の field → 列** の一方向しか見ない。
 * この列は field として宣言しない設計なので、**あの検査の視野に入らない**。
 * 列を持たない型が 1 つ紛れても、取り込むまで誰も気づかず、
 * 気づいたときには十年分の出自が落ちている。
 *
 * ## 実行時の門は作らない
 *
 * ゴミ箱（9.8）は毎回の要求で通る道なので実行時の門が要る。
 * こちらは取り込みの最中にしか要らないので、全要求に列の確認を足すのは
 * 得るものに対して重すぎる。**検査で守る。**
 */
final class WxrStatusColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_投稿を持つ型はすべて出自の列を持つ(): void
    {
        $missing = [];

        foreach (app(SchemaRegistry::class)->declared() as $schema) {
            if (! EditorialCollections::isEditorial($schema)) {
                continue;
            }

            if (! Schema::hasTable($schema->table)) {
                continue;
            }

            if (! Schema::hasColumn($schema->table, WxrStatusColumn::NAME)) {
                $missing[] = $schema->table;
            }
        }

        $this->assertSame([], $missing, '出自の列が無い投稿タイプがあります');
    }

    public function test_見る対象が空でないことを確かめる(): void
    {
        /*
         * **0 件を走査して緑になる検査は、何も守っていない。**
         * 上の検査が「対象が 1 つも無いので通った」に化けないよう、
         * 対象が実在することをここで固定する。
         */
        $editorial = array_filter(
            app(SchemaRegistry::class)->declared(),
            static fn (ContentSchema $schema): bool => EditorialCollections::isEditorial($schema),
        );

        $this->assertGreaterThan(1, count($editorial));
    }

    public function test_新しく作る型にも最初から列が入る(): void
    {
        /*
         * 後から migration で追いかける形だと、**追いかける前に取り込みが走った型**
         * だけ出自が残らない。生成の時点で持たせる。
         */
        $migration = $this->generatedMigration();

        $this->assertStringContainsString(WxrStatusColumn::NAME, $migration);
        $this->assertStringContainsString((string) WxrStatusColumn::LENGTH, $migration);
    }

    // ── 同じ名前の field を作らせない ────────────────────────

    public function test_管理画面から同じ名前のフィールドを作れない(): void
    {
        $this->assertFalse(ContentTypeIdentifier::isValidFieldKey(WxrStatusColumn::NAME));
    }

    public function test_手書きのschemaでも同じ名前のフィールドを拒む(): void
    {
        /*
         * **両方で断って初めて「人の編集で消えない」が成り立つ。**
         * 片方だけだと、手書きで宣言した時点で `ContentValues::split()` の
         * 書き込み対象に変わり、保存のたびに出自が消える列になる。
         */
        $this->expectException(InvalidArgumentException::class);

        new ContentSchema(
            id: 'test_type',
            label: 'テスト',
            kind: 'collection',
            fields: [
                new Field(key: 'title', type: 'text', label: 'タイトル'),
                new Field(key: WxrStatusColumn::NAME, type: 'text', label: '横取り'),
            ],
        );
    }

    public function test_断る理由を分類の予約と混ぜない(): void
    {
        /*
         * 「分類の割り当て用に予約」と「CMS が管理する列」は**直す先が違う**。
         * 前者は taxonomies へ書き換える、後者は別の名前を選ぶ。
         * 同じ文面で断ると、片方の手順でもう片方を直そうとする。
         */
        try {
            new ContentSchema(
                id: 'test_type',
                label: 'テスト',
                kind: 'collection',
                fields: [new Field(key: WxrStatusColumn::NAME, type: 'text', label: '横取り')],
            );

            $this->fail('予約された列の名前が通ってしまいました');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('CMS が管理する列', $e->getMessage());
            $this->assertStringNotContainsString('分類', $e->getMessage());
        }
    }

    private function generatedMigration(): string
    {
        $parsed = ContentTypeDraft::fromInput([
            'id' => 'shinkigata',
            'label' => '新しい型',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'タイトル', 'required' => true],
            ],
            'list' => ['title'],
            'search' => ['title'],
        ]);

        $this->assertSame([], $parsed['errors']);
        $this->assertNotNull($parsed['draft']);

        return app(ContentTypeGenerator::class)->migrationSource($parsed['draft']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\ContentTypes\ContentTypeIdentifier;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Wxr\Status\WxrStatusColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 3B CORRECTION — **F-3B-1**。
 *
 * `taxonomies` は content payload / read response が使う予約キーなので、
 * **ContentSchema のトップレベル field key として使えない**。
 *
 * 同梱 schema を検査するだけでは利用者 project を守れないため、
 * **ContentSchema の contract 自体**で拒否することを固定する。
 */
final class ContentSchemaReservedKeyTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/overrides/*.json') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($dir.'/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir.'/overrides');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /** A: トップレベル field key が taxonomies なら schema 読込時に FAIL */
    public function test_a_トップレベルfield_keyのtaxonomiesを拒否する(): void
    {
        $path = $this->schemasWith(static function (array $data): array {
            $data['fields'][] = ['key' => 'taxonomies', 'type' => 'text', 'label' => '分類'];

            return $data;
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/予約されています/');

        (new SchemaRegistry($path))->all();
    }

    /** A: 直接構築でも同じ contract（fromArray だけの検査にしない） */
    public function test_a_直接構築でも予約キーを拒否する(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/taxonomies/');

        new ContentSchema(
            id: 'demo',
            label: 'デモ',
            kind: 'collection',
            fields: [Field::fromArray(['key' => 'taxonomies', 'type' => 'text', 'label' => '分類'])],
        );
    }

    /** 予約キーの単一情報源が公開されている */
    public function test_予約キーの単一情報源がある(): void
    {
        $this->assertSame(['taxonomies'], ContentSchema::RESERVED_FIELD_KEYS);
        $this->assertSame('taxonomies', ContentSchema::TAXONOMIES_KEY);
        $this->assertContains(ContentSchema::TAXONOMIES_KEY, ContentSchema::RESERVED_FIELD_KEYS);
    }

    // ── CMS が管理する列（Phase 9.8 / 9.14）────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function managedColumns(): array
    {
        return [
            '出自' => [WxrStatusColumn::NAME],
            'ゴミ箱の時刻' => [TrashColumns::DELETED_AT],
            'ゴミ箱へ入れた人' => [TrashColumns::TRASHED_BY],
            '楽観ロックの版' => [TrashColumns::LOCK_VERSION],
        ];
    }

    /**
     * **CMS が管理する列は field として宣言できない。**
     *
     * 宣言すると `ContentValues::split()` の書き込み対象に入り、
     * **人が投稿を編集するたびにその列が書き潰される**。
     */
    #[DataProvider('managedColumns')]
    public function test_cmsが管理する列はfieldにできない(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CMS が管理する列/');

        new ContentSchema(
            id: 'demo',
            label: 'デモ',
            kind: 'collection',
            fields: [Field::fromArray(['key' => $column, 'type' => 'text', 'label' => 'だめ'])],
        );
    }

    /** 断り文は**直し方**を言う（別の名前を選ぶ） */
    public function test_断り文が直し方を言う(): void
    {
        $this->expectExceptionMessageMatches('/別の名前へ変更/');
        $this->expectException(InvalidArgumentException::class);

        new ContentSchema(
            id: 'demo',
            label: 'デモ',
            kind: 'collection',
            fields: [Field::fromArray(['key' => TrashColumns::LOCK_VERSION, 'type' => 'text', 'label' => 'だめ'])],
        );
    }

    /**
     * **`RESERVED_COLUMN_KEYS` ⊆ Builder が断る key**（片方向だけ固定する）。
     *
     * ## なぜこの向きだけか
     *
     * CMS が管理する列は、**Builder の入口でも必ず断られなければならない**。
     * 断られないと、`ContentSchema` 側の一覧にだけ足して Builder 側に忘れた列を
     * Builder が通してしまい、**生成された型が schema の構築時に例外で落ちる** —
     * 作った人には「保存できたのに壊れた」に見える。**遅く、汚く壊れる**。
     *
     * 逆向き（Builder の予約 ⊆ 列の予約）は**要らない**。Builder の予約には
     * `editurl` / `publicurl` のように**列ではないもの**が正当に含まれており、
     * それらを `ContentSchema` の列の予約へ足すのは意味が違う。
     */
    #[DataProvider('managedColumns')]
    public function test_cmsが管理する列はbuilderの入口でも断られる(string $column): void
    {
        $this->assertContains($column, ContentSchema::RESERVED_COLUMN_KEYS);
        $this->assertFalse(
            ContentTypeIdentifier::isValidFieldKey($column),
            "{$column} が Builder の入口を通ってしまいます",
        );
    }

    /** 一覧そのものが痩せていないこと（0 件走査で緑にならないように） */
    public function test_管理する列の一覧が空でない(): void
    {
        $this->assertNotSame([], ContentSchema::RESERVED_COLUMN_KEYS);

        foreach (TrashColumns::ALL as $column) {
            $this->assertContains($column, ContentSchema::RESERVED_COLUMN_KEYS, "{$column} が予約されていません");
        }
    }

    /** B: taxonomy 宣言の有無に関係なく拒否する */
    public function test_b_taxonomy宣言の有無に関係なく拒否する(): void
    {
        foreach ([[], [['key' => 'news_category', 'multiple' => true, 'required' => false]]] as $declarations) {
            $path = $this->schemasWith(static function (array $data) use ($declarations): array {
                $data['fields'][] = ['key' => 'taxonomies', 'type' => 'text', 'label' => '分類'];

                if ($declarations !== []) {
                    $data['taxonomies'] = $declarations;
                }

                return $data;
            });

            try {
                (new SchemaRegistry($path))->all();
                $this->fail('宣言 '.count($declarations).' 件のときに予約キーを通した');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('予約されています', $e->getMessage());
            }
        }
    }

    /** C: group の中の子キーは従来どおり使える（トップレベル衝突ではない） */
    public function test_c_nestedな子キーのtaxonomiesは許可する(): void
    {
        $path = $this->schemasWith(static function (array $data): array {
            foreach ($data['fields'] as $index => $field) {
                if (($field['key'] ?? null) === 'specs') {
                    $data['fields'][$index]['fields'][] = [
                        'key' => 'taxonomies', 'type' => 'text', 'label' => '分類メモ',
                    ];
                }
            }

            return $data;
        }, target: 'product');

        $schema = (new SchemaRegistry($path))->get('product');

        $this->assertNotContains('taxonomies', $schema->fieldKeys());
        $this->assertNotNull($schema->fieldByPath('specs.taxonomies'));
    }

    /** C: nested な taxonomies が data JSON として往復できる */
    public function test_c_nestedなtaxonomiesはdataとして保存_読み出しできる(): void
    {
        $path = $this->schemasWith(static function (array $data): array {
            foreach ($data['fields'] as $index => $field) {
                if (($field['key'] ?? null) === 'specs') {
                    $data['fields'][$index]['fields'][] = [
                        'key' => 'taxonomies', 'type' => 'text', 'label' => '分類メモ',
                    ];
                }
            }

            return $data;
        }, target: 'product');

        $this->useSchemaPath($path);

        $admin = OperationContext::admin($this->operatorId(), 'admin');
        $created = app(CreateContent::class)->execute('product', [
            'title' => '入れ子テスト', 'status' => 'draft',
            'specs' => ['weight' => 1, 'taxonomies' => 'これは通常の入れ子フィールド'],
        ], $admin);

        $this->assertTrue($created->isSuccess(), (string) $created->message);

        $item = app(GetContent::class)
            ->execute('product', (int) $created->data['item']['id'], $admin)
            ->data['item'];

        // 入れ子の値は保持され、トップレベルの分類 block とは別物
        $this->assertSame('これは通常の入れ子フィールド', $item['specs']['taxonomies']);
        $this->assertSame([], $item['taxonomies']);
    }

    /** D: project schema に予約キーがあると taxonomy-check が DB 接続前に FAIL する */
    public function test_d_taxonomy_checkが_d_b接続前に_f_a_i_lする(): void
    {
        $path = $this->schemasWith(static function (array $data): array {
            $data['fields'][] = ['key' => 'taxonomies', 'type' => 'text', 'label' => '分類'];

            return $data;
        });

        $this->useSchemaPath($path);

        $before = [
            Taxonomy::query()->count(),
            TaxonomyTerm::query()->count(),
            TaxonomyTermAssignment::query()->count(),
        ];

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $exit = Artisan::call('agentcms:taxonomy-check');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'FAIL していない');
        $this->assertSame([], $statements, 'データベースへ接続した: '.implode(' / ', $statements));
        $this->assertStringContainsString('コンテンツタイプの定義', $output);

        // 秘密情報・SQL・接続情報を画面へ出さない
        foreach (['SQLSTATE', 'mysql:', 'DB_', 'password', 'select ', 'insert '] as $leak) {
            $this->assertStringNotContainsString($leak, $output, "[{$leak}] を表示した");
        }

        $this->assertSame($before, [
            Taxonomy::query()->count(),
            TaxonomyTerm::query()->count(),
            TaxonomyTermAssignment::query()->count(),
        ], 'DB を書き換えた');
    }

    /** E: 同梱 schema には予約キーが無い（既存テストの意図を維持する） */
    public function test_e_同梱schemaに予約キーが無い(): void
    {
        foreach ((new SchemaRegistry(base_path('content/schemas')))->all() as $schema) {
            foreach (ContentSchema::RESERVED_FIELD_KEYS as $reserved) {
                $this->assertNotContains($reserved, $schema->fieldKeys(), "{$schema->id} が予約キーを使っている");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * 同梱 schema を複製し、指定 schema だけ加工した一時ディレクトリを作る。
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function schemasWith(callable $mutate, string $target = 'news'): string
    {
        $source = base_path('content/schemas');
        $path = sys_get_temp_dir().'/agentcms-reserved-key-'.uniqid();
        $this->tempDirs[] = $path;

        mkdir($path.'/overrides', 0777, true);

        foreach (glob($source.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

            if (($data['id'] ?? '') === $target) {
                $data = $mutate($data);
            }

            file_put_contents(
                $path.'/'.basename($file),
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }

        foreach (glob($source.'/overrides/*.json') ?: [] as $file) {
            copy($file, $path.'/overrides/'.basename($file));
        }

        return $path;
    }

    private function useSchemaPath(string $path): void
    {
        config(['agentcms.schema_path' => $path]);
        app()->forgetInstance(SchemaRegistry::class);
    }
}

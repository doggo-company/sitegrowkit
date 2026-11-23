<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Revisions\ManifestIntegrityError;
use AgentCms\Core\Revisions\SchemaManifest;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 項目定義の写しと、その指紋（Phase 9.8 / STAGE 21D・SPEC §F.2.0・§F.2.1）。
 *
 * 規準は **「復元できるかどうかに影響するか」** の一点である。
 * 表示名を直しただけで「schema が変わりました」と出るのは、警告として役に立たない。
 * 逆に、選択肢から値が消えたのに黙っているのでは、復元が失敗してから気づくことになる。
 */
final class SchemaManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_同じschemaからは同じ指紋が出る(): void
    {
        $schema = $this->schema();

        $this->assertSame(
            SchemaManifest::fingerprint($schema),
            SchemaManifest::fingerprint($this->schema()),
        );
    }

    public function test_並び順を変えても指紋は変わらない(): void
    {
        $ordered = $this->schema([
            $this->field('title', 'text'),
            $this->field('body', 'richtext'),
        ]);
        $shuffled = $this->schema([
            $this->field('body', 'richtext'),
            $this->field('title', 'text'),
        ]);

        // 並べ替えただけで「変わった」と言わない（identity の昇順へ正規化する）
        $this->assertSame(
            SchemaManifest::fingerprint($ordered),
            SchemaManifest::fingerprint($shuffled),
        );
    }

    public function test_表示のためだけの属性は指紋に影響しない(): void
    {
        $before = $this->schema([$this->field('title', 'text', ['label' => 'タイトル'])]);
        $after = $this->schema([$this->field('title', 'text', [
            'label' => '見出し', 'help' => '説明を変えた', 'fieldset' => 'basic',
            'searchable' => true, 'filterable' => true, 'sortable' => true,
        ])]);

        $this->assertSame(
            SchemaManifest::fingerprint($before),
            SchemaManifest::fingerprint($after),
        );
    }

    public function test_復元の可否に関わる変更は指紋を変える(): void
    {
        $base = $this->schema([$this->field('pick', 'select', ['choices' => ['a', 'b']])]);

        $cases = [
            '選択肢が減った' => $this->schema([$this->field('pick', 'select', ['choices' => ['a']])]),
            '型が変わった' => $this->schema([$this->field('pick', 'text', ['choices' => ['a', 'b']])]),
            '必須になった' => $this->schema([$this->field('pick', 'select', ['choices' => ['a', 'b'], 'required' => true])]),
            '検証が厳しくなった' => $this->schema([$this->field('pick', 'select', ['choices' => ['a', 'b'], 'validation' => ['max' => 3]])]),
            '参照先が変わった' => $this->schema([$this->field('pick', 'relation', ['choices' => ['a', 'b'], 'target' => 'works'])]),
            '上限が変わった' => $this->schema([$this->field('pick', 'select', ['choices' => ['a', 'b'], 'max' => 10])]),
        ];

        foreach ($cases as $label => $changed) {
            $this->assertNotSame(
                SchemaManifest::fingerprint($base),
                SchemaManifest::fingerprint($changed),
                "{$label}: 指紋が変わっていない",
            );
        }
    }

    public function test_同じ定義は1行しか保存しない(): void
    {
        $repository = app(SchemaManifestRepository::class);
        $schema = $this->schema();

        $first = $repository->materialize('post', $schema);
        $second = $repository->materialize('post', $schema);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_保存済みと食い違えば止める(): void
    {
        $repository = app(SchemaManifestRepository::class);
        $schema = $this->schema();
        $fingerprint = $repository->materialize('post', $schema);

        // 行が壊れた（あるいは hash が衝突した）状態を作る
        DB::table(SchemaManifestRepository::TABLE)
            ->where('fingerprint', $fingerprint)
            ->update(['manifest' => '[{"identity":"別物","key":"別物"}]']);

        /*
         * **気づかずに書き進めない。** 同じ指紋なのに中身が違うなら、
         * その版は正しく復元できない。
         */
        $this->expectException(ManifestIntegrityError::class);
        $repository->materialize('post', $schema);
    }

    public function test_食い違いの説明に内部情報を含めない(): void
    {
        $error = new ManifestIntegrityError('post', str_repeat('a', 64));

        $this->assertStringNotContainsString('SQLSTATE', $error->safeMessage());
        $this->assertStringNotContainsString(str_repeat('a', 64), $error->safeMessage());
    }

    public function test_型が違えば同じ指紋でも別の行になる(): void
    {
        $repository = app(SchemaManifestRepository::class);
        $schema = $this->schema();

        /*
         * 別々の型が**たまたま同じ形**なら指紋も同じになる。主キーが指紋だけだと、
         * 後から入れた型を表現できず、型を取り違えた復元が通ってしまう。
         */
        $repository->materialize('post', $schema);
        $repository->materialize('news', $schema);

        $this->assertSame(2, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_キー順が入れ替わっていても同じ定義として扱う(): void
    {
        $repository = app(SchemaManifestRepository::class);
        $schema = $this->schema();
        $fingerprint = $repository->materialize('post', $schema);

        /*
         * **MySQL の `json` 型は保存時にオブジェクトのキー順を並べ替える。**
         * 読み戻したものをバイト列で比べると、正しい行を「壊れている」と誤判定する。
         * SQLite は文字列のまま持つので、そこでは気づけない。
         */
        $stored = json_decode(
            (string) DB::table(SchemaManifestRepository::TABLE)->where('fingerprint', $fingerprint)->value('manifest'),
            true,
        );

        $shuffled = array_map(static function (array $field): array {
            $keys = array_keys($field);
            shuffle($keys);

            return array_merge(array_flip($keys), $field);
        }, $stored);

        DB::table(SchemaManifestRepository::TABLE)
            ->where('fingerprint', $fingerprint)
            ->update(['manifest' => (string) json_encode($shuffled, JSON_UNESCAPED_UNICODE)]);

        // 中身は同じなので、止めずに通る
        $this->assertSame($fingerprint, $repository->materialize('post', $schema));
    }

    public function test_壊れた行は読めないものとして返す(): void
    {
        $repository = app(SchemaManifestRepository::class);
        $fingerprint = $repository->materialize('post', $this->schema());

        DB::table(SchemaManifestRepository::TABLE)
            ->where('fingerprint', $fingerprint)
            ->update([
                /*
             * **不正なバイト列では壊さない。**
             *
             * SQLite は受けるが、**MySQL は `3140 Invalid JSON text` で拒む** —
             * そして拒むのが正しい。本番でその行は作れない。
             *
             * 読む側は「**JSON として読めて、かつ配列であるか**」で判断している
             * （`RevisionWriter::hydrate()`）。だから**妥当な JSON だが配列でない**もので壊す。
             */
                'manifest' => '"読めない中身"',
            ]);

        // 空の定義として扱わない（そのまま復元すると全項目が「消えた」ことになる）
        $this->assertNull($repository->find('post', $fingerprint));
    }

    /** @param list<array<string, mixed>> $fields */
    private function schema(?array $fields = null): ContentSchema
    {
        return ContentSchema::fromArray([
            'id' => 'sample',
            'label' => '見本',
            'kind' => 'collection',
            'fields' => $fields ?? [
                $this->field('title', 'text'),
                $this->field('body', 'richtext'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function field(string $key, string $type, array $extra = []): array
    {
        return array_merge(['key' => $key, 'type' => $type, 'label' => $key], $extra);
    }
}

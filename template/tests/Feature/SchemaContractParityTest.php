<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Phase 9.3B / #23 — 定義の読み取りが CMS と import で食い違わないこと（SPEC §U.1）。
 *
 * ## なぜ必要か
 *
 * CMS（`SchemaRegistry`）と Template Import（`packages/cli` の `loadProjectSchemas`）は
 * **同じ JSON を別の言語で読む**。片方が受け付けて片方が拒否する形があると、
 *
 * - import は「この分類がある」と判断して項目の作成を提案するのに、CMS は起動しない
 * - あるいは import が壊れた定義を根拠に選択肢を足してしまう
 *
 * という食い違いが起きる。ここでは **PHP 側が拒否する形の一覧**を実データで固定し、
 * 同じ一覧を CLI 側テスト（`packages/cli/tests/import-contract.test.ts`）でも使う。
 * 片側だけ緩めたら、どちらかのテストが落ちる。
 */
final class SchemaContractParityTest extends TestCase
{
    use RefreshDatabase;

    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_dir($this->path)) {
            File::deleteDirectory($this->path);
        }

        parent::tearDown();
    }

    /**
     * **拒否する形の一覧。** CLI 側の `壊れた定義 [...] は無視せず失敗させる` と対になる。
     *
     * @return array<string, array{array<string, string>}>
     */
    /**
     * **CMS と import が共有する表**を読む。
     *
     * 片側だけ条件を緩めたら、もう片側のテストが落ちる。
     * 表は `template/tests/fixtures/` に置く。生成プロジェクトにも同梱されるので、
     * 利用者の環境でも同じ検証が走る（kit の外へは読みに行かない）。
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function rejectedDefinitions(): array
    {
        return self::casesFrom('rejected');
    }

    /** @return array<string, array{array<string, string>}> */
    public static function acceptedDefinitions(): array
    {
        return self::casesFrom('accepted');
    }

    /** @return array<string, array{array<string, string>}> */
    private static function casesFrom(string $bucket): array
    {
        // **データ提供はアプリ起動前に呼ばれる。** base_path() は使えない
        $path = dirname(__DIR__).'/fixtures/schema-contract-cases.json';

        if (! is_file($path)) {
            return ['共有表が無い環境' => [[]]];
        }

        /** @var array<string, list<array{label: string, files: array<string, string>}>> $data */
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($data[$bucket] ?? [] as $case) {
            $cases[$case['label']] = [$case['files']];
        }

        return $cases === [] ? ['共有表が無い環境' => [[]]] : $cases;
    }

    /**
     * @param  array<string, string>  $files
     */
    #[DataProvider('rejectedDefinitions')]
    public function test_壊れた定義はcms側でも拒否される(array $files): void
    {
        if ($files === []) {
            $this->markTestSkipped('共有表が見つかりません');
        }

        $this->writeSchemas($files);

        $failed = false;

        try {
            (new SchemaRegistry($this->path))->all();
        } catch (Throwable $e) {
            $failed = true;
            $this->assertTrue(
                $e instanceof RuntimeException || $e instanceof \InvalidArgumentException,
                '想定外の例外: '.$e::class,
            );
        }

        $this->assertTrue($failed, 'CMS 側が壊れた定義を受け入れてしまっている');
    }

    /**
     * @param  array<string, string>  $files
     */
    #[DataProvider('acceptedDefinitions')]
    public function test_正しい定義はcms側でも受け付ける(array $files): void
    {
        if ($files === []) {
            $this->markTestSkipped('共有表が見つかりません');
        }

        $this->writeSchemas($files);

        $schemas = (new SchemaRegistry($this->path))->all();

        $this->assertNotSame([], $schemas);
    }

    /** @param array<string, string> $files */
    private function writeSchemas(array $files): void
    {
        $this->path = storage_path('framework/testing/schema-parity-'.uniqid());
        File::ensureDirectoryExists($this->path.'/overrides');

        foreach ($files as $name => $content) {
            File::put($this->path.'/'.$name, $content);
        }
    }

    private static function schema(string $id, string $fields = '[]', ?string $taxonomies = null): string
    {
        $json = '{"id":"'.$id.'","label":"'.$id.'","kind":"collection","fields":'.$fields;

        if ($taxonomies !== null) {
            $json .= ',"taxonomies":'.$taxonomies;
        }

        return $json.'}';
    }
}

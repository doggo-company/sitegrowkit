<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use RuntimeException;
use Tests\TestCase;

/**
 * Project Scope Schema Override（ADR-0010 Rev.1）の検証。
 * content/schemas/overrides/<id>.json が kit 標準 schema を書き換えずに
 * フィールド属性（required 等）を上書きできること。
 */
final class SchemaOverrideTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/agentcms-schema-override-'.uniqid();
        mkdir($this->dir.'/overrides', 0o777, true);
        file_put_contents($this->dir.'/works.json', json_encode([
            'id' => 'works',
            'table' => 'works',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'タイトル', 'required' => true],
                ['key' => 'category', 'type' => 'select', 'label' => 'カテゴリ', 'required' => true, 'choices' => ['web']],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/overrides/*') as $f) {
            @unlink((string) $f);
        }
        foreach ((array) glob($this->dir.'/*.json') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir.'/overrides');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_override_makes_field_optional_without_touching_base_schema(): void
    {
        file_put_contents($this->dir.'/overrides/works.json', json_encode([
            'id' => 'works',
            'fields' => ['category' => ['required' => false]],
        ], JSON_THROW_ON_ERROR));

        $registry = new SchemaRegistry($this->dir);
        $schema = $registry->get('works');

        $this->assertFalse($schema->field('category')->required);
        $this->assertTrue($schema->field('title')->required); // 他フィールドは不変
        // base の JSON ファイル自体は書き換えられていない
        $base = json_decode((string) file_get_contents($this->dir.'/works.json'), true);
        $this->assertTrue($base['fields'][1]['required']);
    }

    public function test_override_without_file_keeps_defaults(): void
    {
        $registry = new SchemaRegistry($this->dir);
        $this->assertTrue($registry->get('works')->field('category')->required);
    }

    public function test_override_for_unknown_field_fails_loudly(): void
    {
        file_put_contents($this->dir.'/overrides/works.json', json_encode([
            'id' => 'works',
            'fields' => ['nonexistent' => ['required' => false]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        (new SchemaRegistry($this->dir))->all();
    }
}

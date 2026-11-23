<?php

declare(strict_types=1);

namespace Tests\Unit\Smoke;

use AgentCms\Core\Schema\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * 生成されたサイトが持つ「投稿」型の骨格（Phase 9.7 / STAGE 20F）。
 *
 * **このディレクトリのテストは利用者のサイトへ配られる。**
 * kit の例（news / works / product）に触れず、生成物だけで意味が通ることを守る
 * — 触れた瞬間、利用者の手元で落ちるテストになる。
 *
 * DB を使わない（`Tests\TestCase` ではなく素の PHPUnit）。
 */
final class PostSchemaSmokeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function postSchema(): array
    {
        $path = dirname(__DIR__, 3).'/content/schemas/post.json';

        $this->assertFileExists($path, '「投稿」の定義が見つかりません');

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    public function test_投稿の定義が読める(): void
    {
        $schema = ContentSchema::fromArray($this->postSchema());

        $this->assertSame('post', $schema->id);
        $this->assertSame('collection', $schema->kind);
        $this->assertSame('cms', $schema->managedBy);
    }

    public function test_投稿は基本の項目を持つ(): void
    {
        $schema = ContentSchema::fromArray($this->postSchema());
        $keys = array_map(static fn ($field): string => $field->key, $schema->fields);

        foreach (['title', 'slug', 'body', 'status', 'published_at'] as $key) {
            $this->assertContains($key, $keys, "「投稿」に {$key} がありません");
        }
    }

    public function test_一覧列と検索対象は実在する項目を指す(): void
    {
        $schema = ContentSchema::fromArray($this->postSchema());
        $keys = array_map(static fn ($field): string => $field->key, $schema->fields);

        foreach ([...$schema->adminList, ...$schema->searchable] as $key) {
            $this->assertContains($key, $keys, "存在しない項目 [{$key}] を指しています");
        }
    }
}

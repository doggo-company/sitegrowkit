<?php

declare(strict_types=1);

namespace Tests\Unit;

use AgentCms\Core\Schema\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * 「何で探せるか」の宣言（P1 ④）。
 *
 * ## 直下と `admin.search` のどちらが勝つか
 *
 * 元は `admin.search` にしか無かった。公開側でも同じ列を探すので
 * **`search` を直下へ出した**が、既に配った schema は `admin.search` で
 * 書かれているので**両方を読み続ける**。
 *
 * この優先順は **1 件も schema が持っていない状態**で入った —
 * 逆に書いても全部緑のままで、**宙に浮いていた**。
 * だから**両方を書いた schema を 1 つ**用意して、そこで留める。
 */
final class ContentSchemaSearchTest extends TestCase
{
    /** @param  array<string, mixed>  $extra */
    private function schema(array $extra): ContentSchema
    {
        return ContentSchema::fromArray([
            'id' => 'sample',
            'label' => '見本',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => '見出し'],
                ['key' => 'body', 'type' => 'richtext', 'label' => '本文'],
            ],
            ...$extra,
        ]);
    }

    /**
     * **両方在れば直下が勝つ。**
     *
     * 新しく書いた宣言が、古い場所の宣言に負けてはいけない
     * （直したつもりの型が、直っていないまま動くことになる）。
     */
    public function test_直下のsearchがadminのsearchより優先される(): void
    {
        $schema = $this->schema([
            'search' => ['title'],
            'admin' => ['search' => ['body']],
        ]);

        $this->assertSame(['title'], $schema->searchable);
    }

    /** **直下が無ければ、古い場所を読む**（配布済みの schema を壊さない） */
    public function test_直下が無ければadminのsearchを読む(): void
    {
        $schema = $this->schema(['admin' => ['search' => ['body']]]);

        $this->assertSame(['body'], $schema->searchable);
    }

    /**
     * **どちらも無ければ空**（= 絞られない）。
     *
     * 空のときに何が起きるかは `PublicSearchTest` が持っている
     * （**絞られずに全件が出る** — `BACKLOG.md` B-14）。
     */
    public function test_どちらも無ければ空(): void
    {
        $this->assertSame([], $this->schema([])->searchable);
    }
}

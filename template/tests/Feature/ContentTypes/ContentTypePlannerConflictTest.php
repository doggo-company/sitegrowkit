<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\ContentTypeDraft;
use AgentCms\Core\ContentTypes\ContentTypePlanner;
use Illuminate\Support\Facades\Schema as DbSchema;

/**
 * **在るものを、在ると言って断る**（監査 指摘 38）。
 *
 * R-13 7 件目で `RESERVED_TABLES` から kit の例（news / works / products）を外した。
 * 静的な網を外した以上、**代わりに効くのは実在検査（動的な網）**だが、
 * その 4 つには試験が 1 つも無かった（1 つずつ外しても全緑だった）。
 *
 * ▷ **静的な網を外したら、動的な網に試験を置く。** 片方を外して片方を無試験のまま残すと、
 *   「守りを移した」ではなく「守りを消した」になる。
 *
 * ここでは 4 つの入口を**それぞれ 1 つだけ**踏ませる。同時に踏むと、
 * どれが効いて断ったのか分からない（1 つ外しても他が代わりに赤くする）。
 */
final class ContentTypePlannerConflictTest extends BuilderTestCase
{
    public function test_schemaが登録済みならその理由で断る(): void
    {
        /*
         * sandbox は kit の schemas を写しているので `product` は登録済み。
         * `post` は**管理画面 URL の予約語**でもあるため、そちらで先に断られてしまう
         * （どの門が効いたか分からなくなる）。
         */
        $this->assertContains(
            'Content Type ID [product] はすでに存在します',
            $this->planErrors('product'),
        );
    }

    public function test_schemaファイルが在るならその理由で断る(): void
    {
        $id = $this->typeId.'_schemafile';

        // 登録はされていない（Registry は setUp で読み終えている）が、ファイルは在る
        file_put_contents($this->schemaPath.'/'.$id.'.json', '{"id":"'.$id.'","fields":[]}');

        // sandbox は base_path の外なので、断り文には basename が出る
        $this->assertContains(
            'schema ファイルがすでに存在します（'.$id.'.json）',
            $this->planErrors($id),
        );
    }

    public function test_モジュールが在るならその理由で断る(): void
    {
        $id = $this->typeId.'_module';
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $id)));

        mkdir($this->modulesPath.'/'.$studly, 0o755, true);

        $this->assertContains(
            "モジュール {$studly} はすでに存在します",
            $this->planErrors($id),
        );
    }

    public function test_テーブルが在るならその理由で断る(): void
    {
        $id = $this->typeId.'_table';

        DbSchema::create($id, static function ($table): void {
            $table->id();
        });

        $this->assertContains(
            "テーブル [{$id}] はすでにデータベースに存在します",
            $this->planErrors($id),
        );
    }

    /** 何とも衝突しない ID は通る（**断りが全部に当たっていない**ことの対照） */
    public function test_衝突しなければ計画できる(): void
    {
        $planned = app(ContentTypePlanner::class)->plan(
            $this->draft($this->typeId.'_free'),
            '2026_08_22_000000',
        );

        $this->assertSame([], $planned['errors']);
        $this->assertNotNull($planned['plan']);
    }

    /** @return list<string> */
    private function planErrors(string $id): array
    {
        $planned = app(ContentTypePlanner::class)->plan(
            $this->draft($id),
            '2026_08_22_000000',
        );

        $this->assertNull($planned['plan'], "[{$id}] を断っていない");

        /** @var list<string> */
        return $planned['errors']['id'] ?? [];
    }

    private function draft(string $id): ContentTypeDraft
    {
        $parsed = ContentTypeDraft::fromInput([
            'id' => $id,
            'label' => '検査用',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'タイトル', 'required' => true],
            ],
            'list' => ['title'],
            'search' => ['title'],
        ]);

        $this->assertNotNull($parsed['draft'], (string) json_encode($parsed['errors'], JSON_UNESCAPED_UNICODE));

        return $parsed['draft'];
    }
}

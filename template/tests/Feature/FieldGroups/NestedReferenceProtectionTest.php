<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\Content\ContentReferences;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\MediaUsageInspector;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.5-C — **入れ子の中の参照**も守られること（ADR-0017 §5）。
 *
 * `MediaUsageInspector` は Phase 9.2B から `group` / `repeater` の中まで再帰していたが、
 * `ContentReferences` は `$schema->fields` の **トップレベルしか走査していなかった**。
 * そのため `group` の中へ `relation` を置くと、
 *
 * - 参照先の存在確認が効かない
 * - **参照されている側を削除できてしまう**（RESTRICT が働かない）
 *
 * 削除保護は「消せないこと」で価値が出る。効かない場所があると、
 * 利用者は守られていると思ったまま参照を壊す。
 */
final class NestedReferenceProtectionTest extends FieldGroupTestCase
{
    private function context(): OperationContext
    {
        app()->forgetScopedInstances();

        return OperationContext::cli();
    }

    /** works を 1 件作って ID を返す */
    private function makeWork(string $slug): int
    {
        $result = app(CreateContent::class)->execute('works', [
            'title' => '参照される実績', 'slug' => $slug, 'body' => '本文',
            'category' => 'web', 'status' => 'draft',
        ], $this->context());

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);

        return (int) $result->data['item']['id'];
    }

    /** group の中に relation を持つ束を news へ割り当てる */
    private function groupWithRelation(string $type = 'relation'): void
    {
        $this->makeGroup(
            'spec',
            [[
                'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり',
                'id' => '01JAAAAAAAAAAAAAAAAAAAAAAA',
                'fields' => [[
                    'key' => 'spec_ref', 'type' => $type, 'label' => '参照', 'target' => 'works',
                    'id' => '01JBBBBBBBBBBBBBBBBBBBBBBB',
                ]],
            ]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        app()->forgetScopedInstances();
    }

    public function test_入れ子のrelationの参照を見つけられる(): void
    {
        $workId = $this->makeWork('nested-relation');
        $this->groupWithRelation();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '参照する記事', 'slug' => 'ref-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['spec_ref' => $workId],
        ], $this->context());

        $this->assertSame(OperationStatus::Success, $created->status, (string) $created->message);

        // 値が入れ子の中に入っている
        $data = json_decode((string) DB::table('news')->value('data'), true);
        $this->assertSame($workId, $data['spec_group']['spec_ref']);

        /*
         * **参照が見つかること**を確かめる（Phase 9.8 / STAGE 21C で見る場所が変わった）。
         *
         * 9.8 より前は「参照中なら削除を拒否」で確かめていた。いまは参照中でも
         * ゴミ箱へは移せる（OG4 = (c)。ゴミ箱はデータを失わない）。守りは
         * **完全削除（purge）の側**へ移り、それは backup ができるまで作らない（OG8）。
         *
         * 拒否できるかどうかではなく、**入れ子の中の参照を見つけられるか**が
         * このテストの中身だったので、そこを直接見る。
         */
        $references = app(ContentReferences::class)->referencesTo('works', $workId);

        $this->assertNotSame([], $references, '入れ子の中の参照を見つけられていない');

        // ゴミ箱へは移せる。行は残り、参照も壊れない
        $trashed = $this->confirmTrash('works', $workId);

        $this->assertSame(OperationStatus::Success, $trashed->status);
        $this->assertNotNull(DB::table('works')->find($workId));
    }

    public function test_入れ子のrelationsの参照を見つけられる(): void
    {
        $workId = $this->makeWork('nested-relations');
        $this->groupWithRelation('relations');

        $created = app(CreateContent::class)->execute('news', [
            'title' => '参照する記事', 'slug' => 'refs-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['spec_ref' => [$workId]],
        ], $this->context());

        $this->assertSame(OperationStatus::Success, $created->status, (string) $created->message);

        $this->assertNotSame(
            [],
            app(ContentReferences::class)->referencesTo('works', $workId),
            '入れ子の relations を見つけられていない',
        );

        $this->assertSame(OperationStatus::Success, $this->confirmTrash('works', $workId)->status);
    }

    public function test_repeaterの行の中の参照も見つけられる(): void
    {
        $workId = $this->makeWork('repeater-relation');

        $this->makeGroup(
            'spec',
            [[
                'key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => 10,
                'id' => '01JCCCCCCCCCCCCCCCCCCCCCCC',
                'fields' => [[
                    'key' => 'row_ref', 'type' => 'relation', 'label' => '参照', 'target' => 'works',
                    'id' => '01JDDDDDDDDDDDDDDDDDDDDDDD',
                ]],
            ]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        app()->forgetScopedInstances();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '行から参照', 'slug' => 'row-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_rows' => [['row_ref' => $workId]],
        ], $this->context());

        $this->assertSame(OperationStatus::Success, $created->status, (string) $created->message);

        $this->assertNotSame(
            [],
            app(ContentReferences::class)->referencesTo('works', $workId),
            'repeater の行の中の参照を見つけられていない',
        );

        $this->assertSame(OperationStatus::Success, $this->confirmTrash('works', $workId)->status);
    }

    /** 確認を取ってからゴミ箱へ移す（2 回呼ぶのが正規の経路） */
    private function confirmTrash(string $type, int $id): Result
    {
        $token = app(DeleteContent::class)->execute($type, $id, $this->context())->confirmation?->token;

        return app(DeleteContent::class)->execute(
            $type, $id, $this->context()->withConfirmationToken((string) $token),
        );
    }

    public function test_入れ子のrelationでも存在しない参照は拒否される(): void
    {
        $this->groupWithRelation();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '壊れた参照', 'slug' => 'broken-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['spec_ref' => 999999],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $created->status);
        $this->assertSame(0, DB::table('news')->count());
    }

    public function test_参照が外れれば削除できる(): void
    {
        $workId = $this->makeWork('detached');
        $this->groupWithRelation();

        app(CreateContent::class)->execute('news', [
            'title' => '参照する記事', 'slug' => 'detach-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['spec_ref' => $workId],
        ], $this->context());

        // 参照を外す
        DB::table('news')->update(['data' => json_encode(['spec_group' => ['spec_ref' => null]])]);

        // 削除は Destructive なので確認を挟む（参照が無いので Conflict にはならない）
        $confirm = app(DeleteContent::class)->execute('works', $workId, $this->context());
        $this->assertSame(OperationStatus::ConfirmationRequired, $confirm->status, (string) $confirm->message);

        $deleted = app(DeleteContent::class)->execute(
            'works', $workId, $this->context()->withConfirmationToken($confirm->confirmation->token),
        );

        $this->assertSame(OperationStatus::Success, $deleted->status, (string) $deleted->message);
    }

    /** media の既存の再帰契約を壊さない */
    public function test_入れ子のmedia保護は従来どおり効く(): void
    {
        $mediaId = (int) DB::table('media')->insertGetId([
            'name' => '画像', 'alt' => null, 'path' => 'media/x.png', 'mime' => 'image/png',
            'size' => 10, 'width' => 10, 'height' => 10, 'has_webp' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->makeGroup(
            'spec',
            [[
                'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり',
                'fields' => [['key' => 'row_media', 'type' => 'media', 'label' => '画像']],
            ]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        app()->forgetScopedInstances();

        app(CreateContent::class)->execute('news', [
            'title' => '画像を使う', 'slug' => 'media-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['row_media' => $mediaId],
        ], $this->context());

        $usage = app(MediaUsageInspector::class)->usages($mediaId);

        $this->assertNotSame([], $usage, '入れ子の media が使用中として検出されない');
    }
}

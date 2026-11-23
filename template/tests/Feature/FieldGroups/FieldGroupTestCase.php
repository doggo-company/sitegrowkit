<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\FieldGroups\DatabaseFieldGroupOverlay;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Field Group のテスト基盤（Phase 9.5-A / ADR-0015）。
 *
 * ## 実プロジェクトを壊さない
 *
 * DB は phpunit.xml の **使い捨て in-memory SQLite** だけを使う
 * （`agentcms-mysql` のローカル確認用 DB へは 1 度も接続しない）。
 * schema ファイルは**読むだけ**で、1 バイトも書かない。
 */
abstract class FieldGroupTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * 実プロジェクトの schema ディレクトリ。**読み取り専用**で使う。
     */
    protected function schemaPath(): string
    {
        return (string) config('agentcms.schema_path');
    }

    /** overlay 込みの registry（毎回 new して「新しいリクエスト」を再現する） */
    protected function registryWithOverlay(): SchemaRegistry
    {
        return new SchemaRegistry(
            $this->schemaPath(),
            new DatabaseFieldGroupOverlay($this->repository()),
        );
    }

    /** overlay を渡さない registry（Phase 9.5-A 以前と同じ挙動） */
    protected function registryWithoutOverlay(): SchemaRegistry
    {
        return new SchemaRegistry($this->schemaPath());
    }

    protected function repository(): FieldGroupRepository
    {
        return new FieldGroupRepository(DB::connection());
    }

    /**
     * Field Group を 1 件作る。
     *
     * **production コードに書き込み経路が無い**ので、テストは DB へ直接入れる
     * （9.5-A の service は読み取りだけである、という事実そのものの表明でもある）。
     *
     * @param  list<array<string, mixed>>|string  $fields  生の宣言、または壊れた JSON 文字列
     * @param  list<array{kind: string, value: string}>  $locations
     */
    /**
     * **「読めない定義」の作り方**（MySQL 緑化・段 3）。
     *
     * かつては `'{ not json'` のような**不正なバイト列**を JSON 列へ入れていた。
     * SQLite は受けるが、**MySQL は `3140 Invalid JSON text` で拒む** —
     * そして拒むのが正しい。本番でその行は作れない。
     *
     * 守りたいのは「**読めない定義を落とさず、理由付きで出す**」ことであって、
     * 列に不正な値を入れる自由ではない。だから**JSON としては妥当だが、
     * 期待している形ではない**もので壊す（`fields` は list を期待するのに object）。
     */
    protected const BROKEN_FIELDS = '{"not":"a list"}';

    protected function makeGroup(
        string $key,
        array|string $fields,
        array $locations = [],
        string $status = 'published',
        int $sortOrder = 0,
        string $label = 'テスト用の束',
    ): string {
        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => $key,
            'label' => $label,
            'sort_order' => $sortOrder,
            'status' => $status,
            'fields' => is_string($fields) ? $fields : json_encode($fields, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($locations as $location) {
            DB::table('field_group_locations')->insert([
                'field_group_id' => $id,
                'kind' => $location['kind'],
                'value' => $location['value'],
            ]);
        }

        return $id;
    }

    /** @return list<string> */
    protected function fieldKeys(SchemaRegistry $registry, string $schemaId): array
    {
        return $registry->get($schemaId)->fieldKeys();
    }

    /**
     * 全権限の利用者。カスタムフィールドの画面は作りの画面なので、制作者で開く
     * （オーナーは 403 — UX 一周 2026-09-28）。名前は既存の呼び出しに合わせて残している
     */
    protected function owner(): User
    {
        return User::factory()->create(['role' => Role::Developer->value]);
    }
}

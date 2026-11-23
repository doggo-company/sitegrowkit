<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\ContentTypes\ArchiveContentType;
use AgentCms\Core\Application\ContentTypes\ReorderContentTypes;
use AgentCms\Core\Application\ContentTypes\RestoreContentType;
use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 投稿タイプの編集・無効化・復元・並べ替え（Phase 9.7 / STAGE 20C）。
 *
 * ここで固定するのは 4 つ。
 *
 * 1. **code 管理の型は触れない** — ファイルが正なので、管理画面から変えると git が説明できなくなる
 * 2. **無効化は何も消さない** — 投稿・分類の割り当て・監査はそのまま残り、復元で元に戻る
 * 3. **後勝ちにしない** — 版が食い違えば保存せずに知らせる
 * 4. **監査に中身を入れない** — 対象 ID・結末・変わった項目名まで
 */
final class ContentTypeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actor = null;

    /**
     * 操作する人。**テストの中では 1 人に固定する。**
     *
     * `ConfirmationGate` の指紋は実行者を含むので、呼ぶたびに別人を作ると
     * 「同じ人が確認して適用する」という本来の流れを再現できない。
     */
    private function owner(): User
    {
        return $this->actor ??= User::factory()->create();
    }

    private function context(?User $user = null, ?string $token = null): OperationContext
    {
        $user ??= $this->owner();

        return OperationContext::admin($user->getKey(), (string) $user->name)
            ->withConfirmationToken($token);
    }

    private function lockVersion(string $id): int
    {
        $row = DB::table('content_types')->where('content_type_id', $id)->first();

        return $row === null ? 0 : (int) $row->lock_version;
    }

    /** 確認を通して archive を最後まで実行する */
    private function archive(string $id, ?User $user = null): void
    {
        $user ??= $this->owner();
        $version = $this->lockVersion($id);

        $first = app(ArchiveContentType::class)->execute($id, $version, $this->context($user));
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);

        $second = app(ArchiveContentType::class)->execute(
            $id,
            $version,
            $this->context($user, $first->confirmation?->token),
        );

        $this->assertTrue($second->isSuccess(), '確認を通しても archive できていない');

        $this->nextRequest();
    }

    /**
     * 次のリクエストとして読み直す。
     *
     * 設定の読み側は **リクエスト単位でメモ化**している（`scoped`）。
     * 1 つのテストで「書いてから読む」を続けるときは、その境界を明示しないと
     * 本番では起きない古い値を見てしまう。
     */
    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
    }

    // ── 権限 ─────────────────────────────────────────────────────

    /** @return list<array{0: string}> */
    public static function nonManagingRoles(): array
    {
        return [[Role::Editor->value], [Role::Viewer->value]];
    }

    #[DataProvider('nonManagingRoles')]
    public function test_権限が無ければ何もできない(string $role): void
    {
        $user = User::factory()->role(Role::from($role))->create();
        $context = $this->context($user);

        $this->assertSame(OperationStatus::Forbidden, app(UpdateContentType::class)->execute(['id' => 'post'], $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(ArchiveContentType::class)->execute('post', 0, $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(RestoreContentType::class)->execute('post', 0, $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(ReorderContentTypes::class)->execute([['id' => 'post', 'lock_version' => 0]], $context)->status);

        // 拒否されたリクエストは何も変えない
        $this->assertSame(0, DB::table('content_types')->count());
    }

    // ── code 管理の型は触れない ───────────────────────────────────

    /** @return list<array{0: string}> */
    public static function codeManagedTypes(): array
    {
        return [['news'], ['works'], ['product'], ['media']];
    }

    #[DataProvider('codeManagedTypes')]
    public function test_code管理の型は編集も無効化もできない(string $id): void
    {
        $context = $this->context();

        $this->assertSame(OperationStatus::Forbidden, app(UpdateContentType::class)->execute(['id' => $id], $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(ArchiveContentType::class)->execute($id, 0, $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(RestoreContentType::class)->execute($id, 0, $context)->status);
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_存在しない型は見つからないと答える(): void
    {
        $this->assertSame(
            OperationStatus::NotFound,
            app(ArchiveContentType::class)->execute('ghost_type', 0, $this->context())->status,
        );
    }

    // ── 表示属性の更新 ────────────────────────────────────────────

    public function test_表示属性と登録属性を保存できる(): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 0,
            'display_overrides' => ['label' => 'ブログ', 'icon' => 'news'],
            'registration' => ['public' => true, 'has_archive' => true],
            'public_slug' => 'blog',
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $row = DB::table('content_types')->where('content_type_id', 'post')->first();

        $this->assertNotNull($row);
        $this->assertSame('blog', $row->public_slug);
        $this->assertSame(1, (int) $row->lock_version, '保存で lock_version が上がっていない');
        $this->assertStringContainsString('ブログ', (string) $row->display_overrides);
        // schema は 1 バイトも変わらない
        $this->assertSame(
            ['title', 'slug', 'main_image_id', 'excerpt', 'body', 'status', 'published_at'],
            array_map(static fn ($f): string => $f->key, app(SchemaRegistry::class)->get('post')->fields),
        );
    }

    public function test_内部_i_dやfieldは編集の入力として受け取らない(): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 0,
            'fields' => [['key' => 'evil', 'type' => 'text']],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_allowlist外のキーは保存できない(): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 0,
            'registration' => ['capability_type' => 'post'],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_公開スラッグは型をまたいで重複できない(): void
    {
        DB::table('content_types')->insert([
            'content_type_id' => 'other_type', 'status' => 'active', 'lifecycle' => 'ready',
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'public_slug' => 'blog', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'public_slug' => 'blog',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_公開するならスラッグが要る(): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'registration' => ['public' => true],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('public_slug', $result->errors);
    }

    // ── 楽観ロック ────────────────────────────────────────────────

    public function test_版が食い違えば保存せず知らせる(): void
    {
        app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'display_overrides' => ['label' => '1 回目'],
        ], $this->context());

        // 古い版のまま送る（別のタブで開いていた状態）
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'display_overrides' => ['label' => '2 回目'],
        ], $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);

        $row = DB::table('content_types')->where('content_type_id', 'post')->first();
        $this->assertStringContainsString('1 回目', (string) $row?->display_overrides, '後勝ちになっている');
        $this->assertSame(1, (int) $row?->lock_version);

        // 競合も結末として記録する
        $audit = DB::table('audit_logs')->where('operation', 'content.type.update')->latest('id')->first();
        $this->assertSame('conflict', $audit?->result);
        $metadata = json_decode((string) $audit?->metadata, true);
        $this->assertSame(0, $metadata['expectedVersion'] ?? null);
        $this->assertSame(1, $metadata['actualVersion'] ?? null);
    }

    // ── archive / restore ────────────────────────────────────────

    public function test_無効化は確認を要る(): void
    {
        $result = app(ArchiveContentType::class)->execute('post', 0, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        // **確認の前は 1 行も書かない**
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_確認トークンは1回で使い切る(): void
    {
        $first = app(ArchiveContentType::class)->execute('post', 0, $this->context());
        $token = $first->confirmation?->token;

        $this->assertNotNull($token);
        $this->assertTrue(app(ArchiveContentType::class)->execute('post', 0, $this->context(null, $token))->isSuccess());

        // 2 回目は同じトークンでは通らない（再び確認を求められる）
        $again = app(ArchiveContentType::class)->execute('post', 1, $this->context(null, $token));
        $this->assertNotSame(OperationStatus::Success, $again->status);
    }

    public function test_確認の指紋は版を含む(): void
    {
        $first = app(ArchiveContentType::class)->execute('post', 0, $this->context());
        $token = $first->confirmation?->token;

        // 確認してから適用するまでに他者が更新した = 期待版が変わっている
        $result = app(ArchiveContentType::class)->execute('post', 5, $this->context(null, $token));

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status, '版が違うのにトークンが通った');
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_無効化はデータを1件も消さない(): void
    {
        $entry = DB::table('post')->insertGetId([
            'title' => '残る投稿', 'slug' => 'stays', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $auditBefore = DB::table('audit_logs')->count();

        $this->archive('post');

        $this->assertTrue(Schema::hasTable('post'), 'テーブルが消えている');
        $this->assertSame(1, DB::table('post')->where('id', $entry)->count(), '投稿が消えている');
        $this->assertGreaterThan($auditBefore, DB::table('audit_logs')->count(), '監査が残っていない');
        $this->assertSame('archived', DB::table('content_types')->where('content_type_id', 'post')->value('status'));
    }

    public function test_復元で同じ_i_dとデータへ戻る(): void
    {
        DB::table('post')->insert([
            'title' => '戻る投稿', 'slug' => 'back', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->archive('post');

        $result = app(RestoreContentType::class)->execute('post', $this->lockVersion('post'), $this->context());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('active', DB::table('content_types')->where('content_type_id', 'post')->value('status'));
        $this->assertSame(1, DB::table('post')->where('slug', 'back')->count());
    }

    public function test_二重の無効化と不要な復元は競合として返す(): void
    {
        $this->archive('post');

        $version = $this->lockVersion('post');
        $this->assertSame(
            OperationStatus::Conflict,
            app(ArchiveContentType::class)->execute('post', $version, $this->context())->status,
        );

        app(RestoreContentType::class)->execute('post', $version, $this->context());
        $this->nextRequest();

        $this->assertSame(
            OperationStatus::Conflict,
            app(RestoreContentType::class)->execute('post', $this->lockVersion('post'), $this->context())->status,
        );
    }

    // ── 並べ替え ──────────────────────────────────────────────────

    public function test_並べ替えは全件をまとめて保存する(): void
    {
        $result = app(ReorderContentTypes::class)->execute([
            ['id' => 'works', 'lock_version' => 0],
            ['id' => 'post', 'lock_version' => 0],
            ['id' => 'news', 'lock_version' => 0],
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame(1, (int) DB::table('content_types')->where('content_type_id', 'works')->value('menu_order'));
        $this->assertSame(2, (int) DB::table('content_types')->where('content_type_id', 'post')->value('menu_order'));
        $this->assertSame(3, (int) DB::table('content_types')->where('content_type_id', 'news')->value('menu_order'));
    }

    public function test_1件でも版が違えば並べ替えを全部やめる(): void
    {
        // works だけ先に別の操作で版が進んでいる
        DB::table('content_types')->insert([
            'content_type_id' => 'works', 'status' => 'active', 'lifecycle' => 'ready',
            'menu_order' => 9, 'comments_enabled' => false, 'revisions_enabled' => true,
            'lock_version' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(ReorderContentTypes::class)->execute([
            ['id' => 'post', 'lock_version' => 0],
            ['id' => 'works', 'lock_version' => 0],
        ], $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        // **部分的に並び替わった状態を残さない**
        $this->assertSame(0, DB::table('content_types')->where('content_type_id', 'post')->count());
        $this->assertSame(9, (int) DB::table('content_types')->where('content_type_id', 'works')->value('menu_order'));
    }

    public function test_並べ替えできない対象は拒否する(): void
    {
        foreach ([['id' => 'media', 'lock_version' => 0], ['id' => 'site', 'lock_version' => 0], ['id' => 'ghost', 'lock_version' => 0]] as $item) {
            $result = app(ReorderContentTypes::class)->execute([$item], $this->context());

            $this->assertSame(OperationStatus::ValidationError, $result->status, "{$item['id']} が並べ替え対象になっている");
        }

        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_同じ型を2回送れない(): void
    {
        $result = app(ReorderContentTypes::class)->execute([
            ['id' => 'post', 'lock_version' => 0],
            ['id' => 'post', 'lock_version' => 0],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    // ── 監査 ──────────────────────────────────────────────────────

    public function test_監査には項目名までしか残さない(): void
    {
        app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 0,
            'display_overrides' => ['label' => '秘密の表示名', 'description' => 'admin@example.test'],
            'public_slug' => 'blog',
        ], $this->context());

        $audit = DB::table('audit_logs')->where('operation', 'content.type.update')->latest('id')->first();
        $metadata = (string) $audit?->metadata;

        $this->assertStringContainsString('display_overrides.label', $metadata);
        $this->assertStringContainsString('public_slug', $metadata);
        // **値は入れない**
        $this->assertStringNotContainsString('秘密の表示名', $metadata);
        $this->assertStringNotContainsString('admin@example.test', $metadata);
    }

    public function test_失敗も結末として記録する(): void
    {
        $context = $this->context(User::factory()->role(Role::Viewer)->create());
        app(ArchiveContentType::class)->execute('post', 0, $context);

        $this->assertSame(
            'forbidden',
            DB::table('audit_logs')->where('operation', 'content.type.archive')->latest('id')->value('result'),
        );

        app(UpdateContentType::class)->execute(['id' => 'post', 'lock_version' => 0, 'registration' => ['bogus' => true]], $this->context());

        $this->assertSame(
            'validation_error',
            DB::table('audit_logs')->where('operation', 'content.type.update')->latest('id')->value('result'),
        );
    }

    // ── 設定を読めないとき ────────────────────────────────────────

    public function test_設定表が無ければ書き込みを止める(): void
    {
        Schema::drop('content_types');

        $context = $this->context();

        $this->assertSame(OperationStatus::Conflict, app(UpdateContentType::class)->execute(['id' => 'post', 'lock_version' => 0], $context)->status);
        $this->assertSame(OperationStatus::Conflict, app(ArchiveContentType::class)->execute('post', 0, $context)->status);
        $this->assertSame(OperationStatus::Conflict, app(ReorderContentTypes::class)->execute([['id' => 'post', 'lock_version' => 0]], $context)->status);
    }
}

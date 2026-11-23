<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\ContentTypes\CreateContentType;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Settings\UpdateSettings;
use App\Models\User;
use App\Support\Capability;
use App\Support\OperationCapabilities;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE WP-BASELINE / STAGE #18A — 4 ロールの権限（SPEC §2-2〜4）。
 *
 * **画面で隠すだけでは足りない。** HTTP と Application Service の両方で
 * 同じ結果になることを固定する。
 */
final class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** SPEC §2-2 — 管理画面の到達可否（HTTP） */
    public function test_ロールごとに到達できる画面が変わる(): void
    {
        $matrix = [
            // 作りの画面（投稿タイプ）は制作者だけ（UX 一周 2026-09-28）
            Role::Developer->value => [
                '/admin/news' => 200, '/admin/pages' => 200, '/admin/media' => 200,
                '/admin/contact' => 200, '/admin/site' => 200, '/admin/content-types' => 200,
                '/admin/users' => 200, '/admin/activity' => 200,
            ],
            Role::Owner->value => [
                '/admin/news' => 200, '/admin/pages' => 200, '/admin/media' => 200,
                '/admin/contact' => 200, '/admin/site' => 200, '/admin/content-types' => 403,
                '/admin/users' => 200, '/admin/activity' => 200,
            ],
            Role::Administrator->value => [
                '/admin/news' => 200, '/admin/pages' => 200, '/admin/media' => 200,
                '/admin/contact' => 200, '/admin/site' => 200, '/admin/content-types' => 403,
                '/admin/users' => 403, '/admin/activity' => 200,
            ],
            Role::Editor->value => [
                '/admin/news' => 200, '/admin/pages' => 200, '/admin/media' => 200,
                '/admin/contact' => 403, '/admin/site' => 200, '/admin/content-types' => 403,
                '/admin/users' => 403, '/admin/activity' => 403,
            ],
            Role::Viewer->value => [
                '/admin/news' => 200, '/admin/pages' => 200, '/admin/media' => 200,
                '/admin/contact' => 403, '/admin/site' => 200, '/admin/content-types' => 403,
                '/admin/users' => 403, '/admin/activity' => 403,
            ],
        ];

        foreach ($matrix as $role => $expectations) {
            $user = User::factory()->role(Role::from($role))->create();

            foreach ($expectations as $url => $status) {
                $this->actingAs($user)->get($url)
                    ->assertStatus($status, "{$role} → {$url}");
            }
        }
    }

    /** SPEC §2-2 — 書き込みは Application Service 側でも拒否される */
    public function test_書き込み権限がapplication_serviceで実効する(): void
    {
        $viewer = User::factory()->viewer()->create();
        $editor = User::factory()->editor()->create();
        $administrator = User::factory()->administrator()->create();
        $owner = User::factory()->create();

        $attributes = ['title' => 'テスト', 'slug' => 'test-role', 'body' => '<p>x</p>', 'status' => 'draft'];

        // 閲覧者は読めるが書けない
        $this->assertTrue(app(ListContent::class)->paginate('news', $this->contextFor($viewer))->isNotEmpty() !== null);
        $this->assertSame(
            OperationStatus::Forbidden,
            app(CreateContent::class)->execute('news', $attributes, $this->contextFor($viewer))->status,
        );

        // 編集者は書ける
        $this->assertSame(
            OperationStatus::Success,
            app(CreateContent::class)->execute('news', $attributes, $this->contextFor($editor))->status,
        );

        // 編集者はサイト設定を変えられない
        $this->assertSame(
            OperationStatus::Forbidden,
            app(UpdateSettings::class)->execute(['site_name' => 'x'], $this->contextFor($editor), partial: true)->status,
        );
        $this->assertSame(
            OperationStatus::Success,
            app(UpdateSettings::class)->execute(['site_name' => 'x'], $this->contextFor($administrator), partial: true)->status,
        );

        // 投稿タイプの作成はオーナーだけ
        $this->assertSame(
            OperationStatus::Forbidden,
            app(CreateContentType::class)->execute([], $this->contextFor($administrator))->status,
        );
        // オーナーは権限で止まらない（内容の検証まで進む）
        $this->assertSame(
            OperationStatus::ValidationError,
            app(CreateContentType::class)->execute([], $this->contextFor($owner))->status,
        );
    }

    /** SPEC §2-3 — 権限表に無い operation は利用者に対して拒否される */
    public function test_未知のoperationはdefault_denyになる(): void
    {
        $policy = app(OperationPolicy::class);
        $owner = User::factory()->create();

        $this->assertNull(OperationCapabilities::for('totally.unknown.operation'));

        foreach ([$this->contextFor($owner), OperationContext::agent(OperationInterface::Mcp)] as $context) {
            $this->assertFalse($policy->allows(
                'totally.unknown.operation',
                OperationClassification::Write,
                $context,
            ));
        }

        // CLI / System は従来どおり通す（サーバー管理者の実行）
        $this->assertTrue($policy->allows(
            'totally.unknown.operation',
            OperationClassification::Write,
            OperationContext::cli(),
        ));
    }

    /** AI（MCP）が人より強くならない — agent の上限は管理者ロール */
    public function test_agentは管理者権限を超えない(): void
    {
        $policy = app(OperationPolicy::class);
        $agent = OperationContext::agent(OperationInterface::Mcp);

        // 管理者が持たない権限は agent にも無い
        foreach (['content.type.create', 'user.invite', 'user.role.change'] as $operationId) {
            $this->assertFalse(
                $policy->allows($operationId, OperationClassification::ExternalEffect, $agent),
                "{$operationId} が agent から実行できます",
            );
        }

        // 既存の公開範囲（コンテンツ操作）は従来どおり通る
        $this->assertTrue($policy->allows('content.create', OperationClassification::Write, $agent));
        $this->assertTrue($policy->allows('term.delete', OperationClassification::Destructive, $agent));
    }

    /** SPEC §2-4 — 未認証・未確認・無効の利用者は入れない */
    public function test_未認証と未確認と無効の利用者は管理画面へ入れない(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get('/admin/users')
            ->assertRedirect(route('verification.notice', absolute: false));

        $inactive = User::factory()->inactive()->create();
        $this->actingAs($inactive)->get('/admin/news')->assertRedirect('/login');
        $this->actingAs($inactive)->get('/admin/users')->assertRedirect('/login');
    }

    /** 権限表とロール定義が矛盾していない（表の健全性） */
    public function test_権限表がすべて既知のcapabilityを指す(): void
    {
        $known = array_column(Capability::cases(), 'value');

        foreach (OperationCapabilities::all() as $operationId => $capability) {
            $this->assertContains($capability, $known, "{$operationId} が未知の capability を指しています");
        }

        // 制作者はすべての capability を持つ。オーナーは作りの画面と一括置換以外すべて（2026-09-28）
        $this->assertSame(count(Capability::cases()), count(Role::Developer->capabilities()));
        $this->assertSame(count(Capability::cases()) - 2, count(Role::Owner->capabilities()));
        $this->assertNotContains(Capability::SiteBuild, Role::Owner->capabilities());
        $this->assertNotContains(Capability::ContentReplace, Role::Owner->capabilities());
        // 閲覧者は読み取りだけ
        $this->assertSame([Capability::ContentRead], Role::Viewer->capabilities());
    }

    private function contextFor(User $user): OperationContext
    {
        return OperationContext::admin((int) $user->getKey(), (string) $user->name);
    }
}

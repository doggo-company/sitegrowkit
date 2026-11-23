<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AdminScreenAccess;
use App\Support\Capability;
use App\Support\OperationCapabilities;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_and_registered_admin_screen_uses_the_capability_table(): void
    {
        $checked = [];
        foreach (Role::cases() as $role) {
            $user = User::factory()->make(['role' => $role->value]);
            foreach (app('router')->getRoutes() as $route) {
                $uri = $route->uri();
                if (! str_starts_with($uri, 'admin/') || ! in_array('GET', $route->methods(), true)) {
                    continue;
                }
                if (in_array($uri, ['admin/offline', 'admin/sw.js', 'admin/manifest.webmanifest'], true)) {
                    continue;
                }
                $request = Request::create('/'.str_replace(['{key}', '{id}', '{type}', '{run}'], ['contact', '1', 'post', 'test'], $uri));
                $request->setUserResolver(fn () => $user);
                $request->setRouteResolver(fn () => $route);
                $screen = $request->segment(2);
                // Expectations come from the write operation's canonical authorization,
                // not from AdminScreenAccess::screens().
                $operations = [
                    'site' => 'settings.update', 'menus' => 'menu.save', 'menu-entries' => 'menu.save',
                    'forms' => 'form.save', 'readiness' => 'settings.update',
                    'backups' => 'backup.create', 'exports' => 'backup.download',
                    'users' => 'user.update', 'activity' => 'activity.list',
                    'contact' => 'contact.list', 'comments' => 'comment.status',
                    'content-replace' => 'content.replace', 'content-types' => 'content.type.create',
                    'field-groups' => 'field_group.update', 'reusable-components' => 'component.reusable.update',
                    'redirects' => 'redirect.save', 'not-found' => 'not_found.resolve',
                    'taxonomies' => 'term.update', 'pages' => 'page.update', 'media' => 'media.update',
                ];
                $operation = $operations[$screen] ?? 'content.update';
                if (in_array($route->getName(), ['admin.taxonomies.create', 'admin.taxonomies.edit'], true)) {
                    $operation = 'taxonomy.definition.update';
                }
                if ($screen === 'forms' && $request->segment(4) === 'submissions') {
                    $operation = 'form.submission.status';
                }
                $capability = OperationCapabilities::for($operation);
                $this->assertNotNull($capability, $operation);
                $expected = ! $role->can($capability);
                if (in_array('site.build', $route->gatherMiddleware(), true)) {
                    $expected = $expected || ! $role->can(Capability::SiteBuild);
                }
                if ($screen === 'notifications') {
                    $expected = false; // Current user's preferences; no site settings mutation.
                }
                $this->assertSame($expected, AdminScreenAccess::readOnly($request), $role->value.' '.$uri);
                $checked[] = [$role->value, $route->getName(), $uri, $expected ? 'read-only' : 'interactive'];
            }
        }
        $this->assertNotEmpty($checked);
        $csv = fopen(storage_path('logs/admin-screen-access-matrix.csv'), 'w');
        foreach ($checked as $row) {
            fputcsv($csv, $row, escape: '');
        }
        fclose($csv);
        $this->assertContains(['editor', 'admin.site.edit', 'admin/site', 'read-only'], $checked);
        $this->assertContains(['owner', 'admin.site.edit', 'admin/site', 'interactive'], $checked);
        $this->assertContains(['viewer', 'admin.pages.create', 'admin/pages/create', 'read-only'], $checked);
    }

    public function test_editor_site_and_dashboard_share_the_same_read_only_and_navigation_decision(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/admin/site')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('adminScreen.readOnly', true)
            ->where('adminScreen.navigation./admin/site', false)
            ->where('adminScreen.navigation./admin/menus', false));
        $this->actingAs($editor)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('adminScreen.readOnly', false)
            ->where('adminScreen.navigation./admin/site', false));
    }

    public function test_all_roles_open_actual_admin_screen_families_and_keep_private_denials(): void
    {
        $paths = ['/admin/site', '/admin/notifications', '/admin/menus', '/admin/forms', '/admin/forms/new', '/admin/readiness', '/admin/backups', '/admin/exports', '/admin/users', '/admin/activity', '/admin/contact', '/admin/comments', '/admin/content-replace', '/admin/content-types', '/admin/field-groups', '/admin/reusable-components', '/admin/redirects', '/admin/not-found', '/admin/taxonomies', '/admin/taxonomies/create', '/admin/pages', '/admin/pages/create', '/admin/media', '/admin/news', '/admin/news/create', '/admin/works', '/admin/post', '/settings/profile', '/settings/appearance', '/settings/security'];
        $actual = [];
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role->value]);
            foreach ($paths as $path) {
                $response = $this->actingAs($user)->get($path);
                $private = [
                    '/admin/exports' => Capability::BackupRestore,
                    '/admin/users' => Capability::UserManage,
                    '/admin/activity' => Capability::ActivityRead,
                    '/admin/contact' => Capability::ContactRead,
                    '/admin/comments' => Capability::CommentModerate,
                    '/admin/content-replace' => Capability::ContentReplace,
                    '/admin/content-types' => Capability::SiteBuild,
                    '/admin/field-groups' => Capability::SiteBuild,
                    '/admin/reusable-components' => Capability::SiteBuild,
                    '/admin/redirects' => Capability::SiteBuild,
                    '/admin/not-found' => Capability::SiteBuild,
                    '/admin/readiness' => Capability::SettingsManage,
                ];
                $denied = isset($private[$path]) && ! $role->can($private[$path]);
                $response->assertStatus($path === '/settings/security' ? 302 : ($denied ? 403 : 200));
                if ($denied) {
                    $response->assertInertia(fn (Assert $page) => $page->component('admin/Forbidden')->where('adminScreen.readOnly', false)->where('adminScreen.readOnlyMessage', null));
                }
                $actual[] = [$role->value, $path, (string) $response->status(), in_array($response->status(), [200, 403], true) ? ($response->viewData('page')['component'] ?? 'none') : 'password-confirmation'];
                if ($response->status() === 200 && $path === '/admin/taxonomies/create') {
                    $response->assertInertia(fn (Assert $page) => $page->where('adminScreen.readOnly', ! $role->can(Capability::ContentTypeManage)));
                }
                if ($response->status() === 200 && $path === '/admin/notifications') {
                    $response->assertInertia(fn (Assert $page) => $page->where('adminScreen.readOnly', false));
                }
            }
        }
        $csv = fopen(storage_path('logs/admin-screen-http-matrix.csv'), 'w');
        foreach ($actual as $row) {
            fputcsv($csv, $row, escape: '');
        }
        fclose($csv);
    }

    public function test_role_navigation_matrix_is_explicit_and_does_not_offer_unavailable_settings(): void
    {
        foreach (Role::cases() as $role) {
            $nav = AdminScreenAccess::navigation($role);
            $this->assertSame(in_array($role, [Role::Owner, Role::Developer, Role::Administrator], true), $nav['/admin/site']);
            $this->assertSame(in_array($role, [Role::Owner, Role::Developer, Role::Administrator], true), $nav['/admin/menus']);
            $this->assertSame(in_array($role, [Role::Owner, Role::Developer], true), $nav['/admin/backups']);
            $this->assertSame(in_array($role, [Role::Owner, Role::Developer], true), $nav['/admin/users']);
            $this->assertSame($role === Role::Developer, $nav['/admin/content-types']);
            $this->assertTrue($nav['/admin/pages']);
        }
    }

    public function test_all_five_dashboard_steps_use_the_destination_permission_for_every_role(): void
    {
        $required = ['site' => Capability::SettingsManage, 'content' => Capability::ContentWrite,
            'menu' => Capability::SettingsManage, 'inbox' => Capability::SettingsManage,
            'readiness' => Capability::SettingsManage, 'front' => Capability::ContentWrite];
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role->value]);
            $steps = $this->actingAs($user)->get('/dashboard')->assertOk()->inertiaProps('firstSteps.steps');
            $this->assertCount(6, $steps);
            foreach ($steps as $step) {
                $this->assertSame($role->can($required[$step['key']]), $step['href'] !== '', $role->value.' '.$step['key']);
                if (! $role->can($required[$step['key']])) {
                    $this->assertStringContainsString('権限がありません', $step['description']);
                }
            }
        }
    }

    public function test_read_only_notice_names_the_roles_from_the_capability_table(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        foreach (['/admin/site' => Capability::SettingsManage, '/admin/backups' => Capability::BackupRestore,
            '/admin/pages/create' => Capability::ContentWrite, '/admin/taxonomies/create' => Capability::ContentTypeManage] as $path => $capability) {
            $roles = array_map(static fn (Role $role): string => $role->label(),
                array_filter(Role::cases(), static fn (Role $role): bool => $role->can($capability)));
            $this->actingAs($viewer)->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('adminScreen.readOnly', true)
                ->where('adminScreen.readOnlyMessage', 'この設定は'.implode('・', $roles).'だけが変えられます'));
        }
    }
}

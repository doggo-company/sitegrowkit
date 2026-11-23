<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** Screen affordances use the same capabilities as the operation policy. */
final class AdminScreenAccess
{
    /** @return array<string, Capability> */
    public static function screens(): array
    {
        return [
            'site' => Capability::SettingsManage,
            'notifications' => Capability::SettingsManage,
            'menus' => Capability::SettingsManage,
            'menu-entries' => Capability::SettingsManage,
            'forms' => Capability::SettingsManage,
            'readiness' => Capability::SettingsManage,
            'backups' => Capability::BackupRestore,
            'exports' => Capability::BackupRestore,
            'users' => Capability::UserManage,
            'activity' => Capability::ActivityRead,
            'contact' => Capability::ContactRead,
            'comments' => Capability::CommentModerate,
            'content-replace' => Capability::ContentReplace,
            'content-types' => Capability::SiteBuild,
            'field-groups' => Capability::SiteBuild,
            'reusable-components' => Capability::SiteBuild,
            'redirects' => Capability::SiteBuild,
            'not-found' => Capability::SiteBuild,
            'pages' => Capability::ContentWrite,
            'media' => Capability::ContentWrite,
            'taxonomies' => Capability::ContentWrite,
        ];
    }

    /** @return array<string, bool> */
    public static function navigation(Role $role): array
    {
        $result = [];
        foreach (self::screens() as $screen => $capability) {
            // Content lists remain useful to a viewer. Settings only appear for people who can change them.
            $required = in_array($screen, ['pages', 'media', 'taxonomies'], true)
                ? Capability::ContentRead : $capability;
            $result['/admin/'.$screen] = $screen === 'notifications' || $role->can($required);
        }

        return $result;
    }

    /** The destination screen decides every dashboard step, including the final check. */
    public static function canChangePath(Role $role, string $href): bool
    {
        $screen = explode('/', trim((string) parse_url($href, PHP_URL_PATH), '/'))[1] ?? '';

        return $role->can(self::screens()[$screen] ?? Capability::ContentWrite);
    }

    public static function readOnlyMessage(Request $request): ?string
    {
        if (! self::readOnly($request)) {
            return null;
        }

        $capability = self::capability($request);
        $roles = array_map(
            static fn (Role $role): string => $role->label(),
            array_filter(Role::cases(), static fn (Role $role): bool => $role->can($capability)),
        );

        return 'この設定は'.implode('・', $roles).'だけが変えられます';
    }

    public static function editing(Request $request): bool
    {
        $action = last(explode('.', (string) $request->route()?->getName()));

        return in_array($action, ['create', 'edit', 'editor'], true)
            || $request->routeIs('admin.notifications.show');
    }

    public static function readOnly(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->is('admin/*')) {
            return false;
        }

        if ($request->routeIs('admin.notifications.*')) {
            // These are the signed-in person's own preferences, not site-wide settings.
            return false;
        }

        return ! $user->role()->can(self::capability($request));
    }

    private static function capability(Request $request): Capability
    {
        $screen = (string) $request->segment(2);
        $capability = self::screens()[$screen] ?? Capability::ContentWrite;
        if ($request->routeIs('admin.taxonomies.create', 'admin.taxonomies.edit')) {
            $capability = OperationCapabilities::for('taxonomy.definition.update') ?? Capability::ContentTypeManage;
        }
        if ($screen === 'forms' && $request->segment(4) === 'submissions') {
            $capability = Capability::ContactWrite;
        }

        return $capability;
    }
}

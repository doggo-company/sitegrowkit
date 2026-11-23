<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use App\Support\SiteTemplates\StarterSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StarterPageAdminController
{
    public function __invoke(Request $request, StarterSite $starter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array(Capability::SettingsManage->value, app(RoleOperationPolicy::class)->capabilitiesFor($user), true), 403);
        try {
            $page = $starter->create(OperationContext::admin($user->getKey(), (string) $user->name));
        } catch (Throwable $e) {
            Log::error('初期見本の作成に失敗しました', ['exception' => $e]);

            return back()->withErrors(['starter' => '見本を作成できませんでした。時間をおいてもう一度お試しください。']);
        }

        return redirect()->route('admin.pages.editor', ['id' => $page->id]);
    }
}

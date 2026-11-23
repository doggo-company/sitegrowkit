<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use App\Support\Capability;
use App\Support\Industry\IndustryProfiles;
use App\Support\Industry\IndustrySetup;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 最初に業種を聞く入口（UX 一周 2026-09-28・メンテナの方針）。
 *
 * 選んだ業種に合わせて、お問い合わせの項目・「投稿」の呼び名・見本の文章を、
 * その店の形にする（`IndustrySetup`）。手を入れたものは上書きしない。
 */
final class IndustryAdminController
{
    public function __invoke(Request $request, IndustrySetup $setup): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array(
                Capability::SettingsManage->value,
                app(RoleOperationPolicy::class)->capabilitiesFor($user),
                true,
            ),
            403,
        );

        $key = (string) $request->input('industry', '');
        $request->validate(['name' => ['nullable', 'string', 'max:'.IndustryProfiles::NAME_MAX]]);

        $changed = $setup->apply(
            $key,
            OperationContext::admin($user->getKey(), (string) $user->name),
            // 「その他」のときだけ。打ったお仕事の名前（UX 一周 9 周目 M2）
            (string) $request->input('name', ''),
        );

        if ($changed === null) {
            return back()->withErrors(['industry' => '業種を選んでください']);
        }

        $label = $setup->profile()['label'] ?? $key;
        $notes = array_filter([
            $changed['form'] ? 'お問い合わせの項目' : null,
            $changed['postLabel'] ? '記事の呼び名' : null,
            ($changed['listSlug'] ?? false) ? '記事の一覧の URL' : null,
            ($changed['public'] ?? false) ? '記事を公開サイトに出す設定' : null,
        ]);

        return back()->with('success', "「{$label}」のサイトにしました".($notes === [] ? '' : '（'.implode('・', $notes).'を合わせました）'));
    }
}

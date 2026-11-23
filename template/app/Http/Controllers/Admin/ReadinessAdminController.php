<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Capability;
use App\Support\InboxRecipients;
use App\Support\Maintenance\MaintenanceReport;
use App\Support\Maintenance\ReleaseUpdateReport;
use App\Support\PublicationReadiness;
use App\Support\ReadinessResult;
use App\Support\RoleOperationPolicy;
use App\Support\SamplePages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 公開前チェックの画面（2026-08-30）。
 *
 * ## なぜ画面が要るのか
 *
 * 判定そのもの（`PublicationReadiness`）は**前から在った**が、
 * 触れるのは `php artisan site:readiness` だけだった。**編集担当者はターミナルを開かない。**
 *
 * 🔴 ダッシュボードの「はじめに」は **4 つ目に「公開前チェックをする」を並べていて、
 * そこだけリンクが無かった**。読んだ人はどこへ行けばよいか分からない。
 * **約束を消すか、行き先を作るか**の二択で、行き先を作るほうを選んだ。
 *
 * ## 判定と修正を分ける
 *
 * ここは**数えて見せ、最後の実行結果だけを記録する**。サイトの内容は直さない。直す場所はそれぞれ別の画面に在る
 * （サイト設定・投稿・ページ）。1 画面で全部直せるようにすると、
 * 「どこが正本か」が ぼやける — 判定と修正を同じ場所に置かない。
 */
final class ReadinessAdminController
{
    public function __invoke(Request $request, PublicationReadiness $readiness, MaintenanceReport $maintenance, ReleaseUpdateReport $updates): Response
    {
        /*
         * **サイト全体の状態を見るので、設定を触れる人だけ**。
         * 受信の中身は出さない（通知先が空かどうかしか見ていない）。
         */
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array(
                Capability::SettingsManage->value,
                app(RoleOperationPolicy::class)->capabilitiesFor($user),
                true,
            ),
            403,
        );

        $findings = array_map(
            fn (array $finding): array => $finding + ['fix' => self::fixFor($finding)],
            $readiness->check(),
        );

        // 判定・修正は分けたまま、実行した件数と日時だけ記録する。
        app(ReadinessResult::class)->record(count($findings));

        return Inertia::render('admin/readiness/Index', [
            'checkedAt' => app(ReadinessResult::class)->last()['checkedAt'],
            'deployGuide' => $findings === [] ? ['document' => 'docs/deployment/xserver.md', 'readmeSection' => '§6「6 歩が済んだら、サーバーに出す」'] : null,
            'findings' => $findings,
            // 画面表示では外部確認せず、定期CLIの結果だけを読む。
            'maintenance' => $maintenance->read(),
            'releaseUpdate' => $updates->read(),
        ]);
    }

    /**
     * 指摘から**直す場所へ行く道**（UX 一周 2026-09-27）。
     *
     * 🔴 判定と修正を同じ画面に置かない方針は変えない（上の「判定と修正を分ける」）。
     * ただ、**行き先を言わない指摘は行き止まり**だった — 「見本のまま公開されています。
     * 消してください」と言われても、どこで消すのか分からず、行を押しても何も起きなかった。
     * ここは直す画面への**リンクだけ**を足す。行き先が決まらない指摘は null のまま。
     *
     * @param  array{level: string, where: string, what: string}  $finding
     * @return array{href: string, label: string}|null
     */
    private static function fixFor(array $finding): ?array
    {
        $where = $finding['where'];

        if ($where === 'メール設定') {
            return ['href' => '#mail-settings-guide', 'label' => '設定手順を見る'];
        }

        if (str_starts_with($finding['what'], '同梱の見本のまま')) {
            $anchor = SamplePages::anchorFor($where);

            /*
             * 4 つ以外（kit の開発環境の `/works` など、見本の枠で描かれるページ）は、
             * 行を特定できないので見本ページの一覧の頭へ連れて行く。行き止まりにはしない。
             */
            return [
                'href' => '/admin/pages#'.($anchor ?? 'sample-pages'),
                'label' => $anchor === null ? '見本ページの直し方を見る' : '直す・非公開にする',
            ];
        }

        // 見本の説明文は、その文を持っているフォームへ（無ければ見本ページの直し方へ）
        if (str_starts_with($finding['what'], '見本の説明文のまま')) {
            foreach (PublicationReadiness::SAMPLE_LEADS as $lead) {
                $form = Form::query()->where('description', 'like', '%'.$lead.'%')->first();

                if ($form !== null && str_contains($finding['what'], $lead)) {
                    return ['href' => '/admin/forms/'.rawurlencode((string) $form->key).'#description', 'label' => 'フォームの説明を直す'];
                }
            }

            $anchor = SamplePages::anchorFor($where);

            return ['href' => '/admin/pages#'.($anchor ?? 'sample-pages'), 'label' => '見本ページの直し方を見る'];
        }

        // 通知先はその受け口を直す画面へ（フォームならフォームの画面。InboxRecipients と同じ行き先）
        foreach (app(InboxRecipients::class)->problems() as $problem) {
            if ($problem['where'] === $where) {
                return [
                    'href' => $problem['href'],
                    'label' => str_starts_with($problem['href'], '/admin/forms/') ? 'フォームの通知先を直す' : 'サイト設定で直す',
                ];
            }
        }

        /*
         * 原稿待ちの言葉がサイト設定（所在地・キャッチコピーなど）にあるなら、サイト設定へ
         * （UX 一周 12 周目: 所在地の「〇〇」が全ページのフッターに出て、「このページを直す」が
         * 〇〇の無いページの編集画面へ連れて行っていた）
         */
        if (preg_match('/原稿待ちの言葉が公開面に出ている: 「(.+)」/u', $finding['what'], $m) === 1) {
            $label = self::siteSettingContaining($m[1]);

            if ($label !== null) {
                return ['href' => '/admin/site', 'label' => "サイト設定の「{$label}」を直す"];
            }
        }

        // ページの本文に残っている言葉は、そのページの編集画面へ
        if (str_starts_with($where, '/')) {
            $page = Page::query()->where('slug', trim($where, '/'))->first();

            if ($page !== null) {
                return ['href' => "/admin/pages/{$page->getKey()}/edit", 'label' => 'このページを直す'];
            }

            // 見本のページ（ページの一覧の「見本」）の中の言葉なら、その見本の行へ（UX 一周 15 周目 G3:
            // 「〇〇」の 2 行に押す所が無く、どこを直すのか分からなかった）
            $anchor = SamplePages::anchorFor($where);

            if ($anchor !== null) {
                return ['href' => '/admin/pages#'.$anchor, 'label' => '見本のページを直す・非公開にする'];
            }

            return ['href' => '/admin/pages', 'label' => 'ページの一覧で探す'];
        }

        return null;
    }

    /** その言葉を持っているサイト設定の欄の名前（無ければ null） */
    private static function siteSettingContaining(string $marker): ?string
    {
        $settings = app(SettingsStore::class);

        foreach (app(SchemaRegistry::class)->get('site')->fields as $field) {
            $value = $settings->get($field->key);

            if (is_string($value) && $value !== '' && str_contains($value, $marker)) {
                return $field->label;
            }
        }

        return null;
    }
}

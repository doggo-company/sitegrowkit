<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\NotifyRecipients;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 訪問者から届くものが、**実際にどこへ送られるか**（UX 一周 2026-09-28）。
 *
 * 🔴 **送る側と同じ順で決める。** 公開前チェックとダッシュボードが
 * サイト設定の宛先だけを見ていたので、フォームの画面で通知先を入れても
 * 「開発用の宛先のまま」と言い続けた。送る側は次の順に落ちる:
 *
 * | 受け口 | 宛先の決まり方 | 出どころ |
 * | --- | --- | --- |
 * | 公開中のフォーム | フォームの通知先 → サイト設定 → 既定 | `PublicFormController`（`NotifyRecipients::forForm`） |
 * | 従来のお問い合わせ | サイト設定 → 既定 | `ContactController`（/contact がフォームでないとき） |
 * | コメント | サイト設定 → 既定 | `PublicCommentController`（コメントを受けている型があるとき） |
 *
 * **受け口が 1 つも無ければ、何も言わない**（届くものが無いのに宛先を求めない）。
 */
final class InboxRecipients
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly ContactEntry $contact,
    ) {}

    /**
     * 届かない、または開発用の宛先へ落ちている受け口。**空なら全部どこかへ届く。**
     *
     * @return list<array{where: string, to: string, href: string, fallback: bool}>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->inboxes() as $inbox) {
            $to = implode(', ', $inbox['to']);

            if ($inbox['to'] === [] || str_contains($to, 'example')) {
                $problems[] = ['where' => $inbox['where'], 'to' => $to, 'href' => $inbox['href'], 'fallback' => $inbox['fallback'] ?? false];
            }
        }

        return $problems;
    }

    /**
     * @return list<array{where: string, to: list<string>, href: string, fallback?: bool}>
     */
    private function inboxes(): array
    {
        $site = (string) $this->settings->get('contact_email');
        $siteOnly = NotifyRecipients::listOf(trim($site ?: (string) config('agentcms.mail.admin_to')));
        $inboxes = [];

        foreach (Form::query()->orderBy('id')->get() as $form) {
            if (! $form->status()->isVisible()) {
                continue;
            }

            $inboxes[] = [
                'where' => "フォーム「{$form->name}」の通知先",
                'to' => NotifyRecipients::forForm($form->notify_to, $site),
                // 欄が空で、サイト設定（無ければ MAIL_ADMIN_ADDRESS）へ倒れているか
                'fallback' => NotifyRecipients::listOf($form->notify_to) === [],
                'href' => '/admin/forms/'.rawurlencode((string) $form->key).'#notify_to',
            ];
        }

        if (! $this->contact->usesForm()) {
            $inboxes[] = [
                'where' => '問い合わせの通知先',
                'to' => $siteOnly,
                'href' => '/admin/site#contact_email',
            ];
        }

        if ($this->commentsOpen()) {
            $inboxes[] = [
                'where' => 'コメントの通知先',
                'to' => $siteOnly,
                'href' => '/admin/site#contact_email',
            ];
        }

        return $inboxes;
    }

    /** コメントを受けている投稿タイプがあるか */
    private function commentsOpen(): bool
    {
        $table = ContentTypeSettingsResolver::TABLE;

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'comments_enabled')) {
            return false;
        }

        return DB::table($table)->where('comments_enabled', true)->exists();
    }
}

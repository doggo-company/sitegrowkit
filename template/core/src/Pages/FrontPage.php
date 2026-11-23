<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages;

use AgentCms\Core\Settings\SettingsStore;

/**
 * トップページに出すページ（R-5）。
 *
 * ## 何を保存しているか
 *
 * サイト設定の `front_page_id` に **`pages.id`（整数）だけ**を持つ。
 * URL も題も公開状態もそこから引けるので、**導出できるものは保存しない**。
 * slug で持たない理由も同じで、`MenuTree` が既に書いているとおり
 * 「保存されているのは何を指しているかだけ」——**slug を変えた日にトップが消える**形にしない。
 *
 * ## 未選択なら、何も変わらない
 *
 * キーが無い（あるいは null）ときは `resolve()` が null を返し、
 * 呼ぶ側は**これまでどおりテーマのトップページ**を出す。
 * 設定を触っていないサイトの挙動は 1 バイトも変わらない。
 *
 * ## 選んだ後に状態が変わることは、必ず起きる
 *
 * 保存時は「いま公開されているページ」しか通さない（`SchemaRules` の `page` 型）。
 * それでも**公開を取り消す・ゴミ箱へ入れる**は後からできる。
 * そのとき公開面は **404 でも 500 でもなく既定のトップへ戻す** — トップが消えるのが一番悪い。
 * 代わりに `reason()` が管理画面へ理由を返す（黙って既定に戻ると、
 * 編集者は「設定が効いていない」としか分からない）。
 */
final class FrontPage
{
    public const SETTING_KEY = 'front_page_id';

    public function __construct(private readonly SettingsStore $settings) {}

    /** 設定に保存されている id。**選んでいなければ null** */
    public function selectedId(): ?int
    {
        $value = $this->settings->get(self::SETTING_KEY);

        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit($value))
            ? (int) $value
            : null;
    }

    /**
     * いまトップに出せるページ。**出せないなら null**（呼ぶ側は既定のトップを出す）。
     */
    public function resolve(): ?Page
    {
        $page = $this->selected();

        return $page !== null && $page->isPublicallyVisible() && $page->isDocumentManaged()
            ? $page
            : null;
    }

    /**
     * 出せない理由（管理画面が印を付けるのに使う）。**出せるなら null**。
     *
     * 語彙は `MenuTree::pageReason()` と揃えてある。同じ事情を 2 通りの言い方で
     * 説明すると、読む人は別の問題だと思う。
     */
    public function reason(): ?string
    {
        if ($this->selectedId() === null) {
            return null;
        }

        $page = $this->selected();

        if ($page === null) {
            return 'トップページに指定したページがありません';
        }

        if ($page->trashed_at !== null || $page->status === PageStatus::Trashed) {
            return 'トップページに指定したページがゴミ箱にあります';
        }

        if ($page->isScheduled()) {
            return 'トップページに指定したページは公開予約中です';
        }

        if (! $page->isPublicallyVisible()) {
            return 'トップページに指定したページは下書きです';
        }

        if (! $page->isDocumentManaged()) {
            return 'トップページに指定したページはビジュアルエディタで編集されていません';
        }

        return null;
    }

    private function selected(): ?Page
    {
        $id = $this->selectedId();

        return $id === null ? null : Page::query()->find($id);
    }
}

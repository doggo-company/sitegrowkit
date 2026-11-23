<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use App\Support\Industry\IndustrySetup;
use Illuminate\Support\Facades\Route;

/**
 * ボタンのリンク先に出す候補（UX 一周 2026-09-28）。
 *
 * ボタンの「リンク先」は URL の手入力だけで、`#` のまま置かれていた。
 * 店の人は `/contact` という綴りを知らない。**公開面で開けるものだけ**を候補にする
 * （下書きやゴミ箱のページを選べると、押しても開かないボタンができる）。
 *
 * 候補は入力の助けであって、手で書いた URL も今までどおり使える。
 */
final class LinkTargets
{
    public function __construct(private readonly BundledPages $bundled) {}

    /** @return list<array{href: string, label: string}> */
    public function all(): array
    {
        $targets = [];

        if (Route::has('home')) {
            $targets[] = ['href' => '/', 'label' => 'ホーム'];
        }

        if (Route::has('contact.show') && ! app(ContactEntry::class)->closed()) {
            $targets[] = ['href' => '/contact', 'label' => 'お問い合わせ'];
        }

        $seen = array_column($targets, 'href');

        $pages = Page::query()
            ->whereNull('trashed_at')
            ->where('managed_by', Page::MANAGED_BY_DOCUMENT)
            ->orderBy('title')
            ->get()
            ->filter(static fn (Page $page): bool => $page->isPublicallyVisible());

        // トップに選んだページは「ホーム（/）」と同じなので、二重に出さない
        $front = app(FrontPage::class)->resolve()?->getKey();

        foreach ($pages as $page) {
            if ($front !== null && $page->getKey() === $front) {
                continue;
            }

            $href = '/'.$page->slug;

            if (! in_array($href, $seen, true)) {
                $targets[] = ['href' => $href, 'label' => $page->label()];
                $seen[] = $href;
            }
        }

        foreach (['about' => '私たちについて', 'service' => app(IndustrySetup::class)->serviceNoun()] as $route => $label) {
            $href = '/'.$route;

            if (! in_array($href, $seen, true) && $this->bundled->isAvailable($route)) {
                $targets[] = ['href' => $href, 'label' => $label];
                $seen[] = $href;
            }
        }

        return $targets;
    }
}

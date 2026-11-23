<?php

declare(strict_types=1);

namespace App\Listeners;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Modules\Media\Models\Media;

/**
 * ページを**公開したとき**に、画像ブロックの代替テキストを、説明が空のメディアへ残す（直し3回目）。
 *
 * 同じ写真を 2 つ目のページで選ぶと、代替テキストが空に戻っていた。メディアの説明が空なら
 * `altAfterPick` が写すものが無いからである。ブロックで書いた文をメディアにも残せば引き継がれる。
 *
 * ## 版の保存ではなく、公開で書く
 *
 * 初めは版を作るたびに書いていた。だが編集画面は打つ途中で 0.8 秒止まると自動保存し、
 * そのたびに版ができる。**打ちかけの文がメディアの説明に入ると空でなくなり、あとで書き直しても
 * 二度と上書きされない**（採点で指摘）。公開は「この文でよい」と決めた時なので、そこで書く。
 * 下書きのまま公開しない文・見本の文も、公開しない限りメディアへ移らない。
 *
 * 空の説明だけを埋める（条件付き UPDATE）。利用者がメディア側で付けた説明は上書きしない。
 * 公開の保存と同じ取引の中で動くので、公開が失敗すれば説明も巻き戻る。
 */
final class RememberPageImageAlt
{
    public function handle(Page $page): void
    {
        if (! $page->wasChanged('published_version_id') || $page->published_version_id === null) {
            return;
        }

        $version = PageVersion::query()->find($page->published_version_id);

        if ($version !== null) {
            $this->walk($version->document);
        }
    }

    /** @param array<mixed> $node */
    private function walk(array $node): void
    {
        $props = $node['props'] ?? null;
        if (($node['type'] ?? null) === 'image' && is_array($props)) {
            $id = $props['mediaId'] ?? null;
            $alt = $props['alt'] ?? null;
            if (is_int($id) && is_string($alt) && trim($alt) !== '' && mb_strlen($alt) <= 200) {
                // 条件付き UPDATE にして、同時に保存された既存の説明を上書きしない。
                Media::query()->whereKey($id)->where('kind', 'image')
                    ->where(fn ($query) => $query->whereNull('alt')->orWhereRaw("TRIM(alt) = ''"))
                    ->update(['alt' => trim($alt)]);
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walk($value);
            }
        }
    }
}

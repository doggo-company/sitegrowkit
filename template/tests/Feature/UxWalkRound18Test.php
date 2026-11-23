<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX 一周 第 18 周（工務店の社長の目・業種の入口から・2026-10-04）で見つけたものの回帰。
 *
 * 見つけたのは 1 つ。最初の 5 歩が済んだダッシュボードが「準備はできています」と言うのに、
 * 公開前チェックは「公開前に直したいもの（2 件）」で、どちらを信じるか迷った。
 * 済んだのは 5 歩であって、公開の準備ではない。
 */
final class UxWalkRound18Test extends TestCase
{
    public function test_五歩が済んでも残りの公開前チェックを案内する(): void
    {
        $panel = (string) file_get_contents(resource_path('js/components/GettingStartedPanel.vue'));

        $this->assertStringContainsString('最初の 6 歩のうち 5 歩が済みました。残りの 1 歩は、公開の前に公開前チェックでひととおり見ることです。', $panel);
        $this->assertStringNotContainsString('準備はできています。', $panel);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX 一周 第 21 周（ガラス作家の目・業種の入口から・2026-10-04）で見つけたものの回帰。
 *
 * 見つけたのは 1 つ。メニュー表・営業時間の複数行の欄へ Tab で入って打つと、初期文言
 * 「〇〇（ドリンク）｜〇〇円 …」の後ろへ続いて打たれ、〇〇の行ごと公開された。
 * 欄に入ったとき中身が初期文言のままなら選んだ状態にする（打ち始めで置き換わる）。
 */
final class UxWalkRound21Test extends TestCase
{
    public function test_欄に入ったとき初期文言のままなら選んだ状態にする(): void
    {
        $inspector = (string) file_get_contents(resource_path('js/components/page-editor/InspectorPanel.vue'));

        $this->assertStringContainsString('function placeholderOf(', $inspector);
        // 1 行の欄・複数行の欄・リッチテキストの 3 つに同じ守り
        $this->assertSame(2, substr_count($inspector, '@focus="selectPlaceholder($event, key, propSpec)"'));
        $this->assertStringContainsString('@focusin="selectHtmlPlaceholder($event, key, propSpec)"', $inspector);
        // クリックは focus の後に caret を置き直すので、click でも選ぶ
        $this->assertSame(2, substr_count($inspector, '@click="selectPlaceholder($event, key, propSpec); selectSampleAtCaret($event)"'));
        $this->assertStringContainsString('@click="selectHtmlPlaceholder($event, key, propSpec)"', $inspector);
    }
}

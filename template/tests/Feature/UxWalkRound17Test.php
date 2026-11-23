<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX 一周 第 17 周（整体院の院長の目・「その他」の入口から・2026-10-04）で見つけたものの回帰。
 *
 * 見つけたのは 1 つ。ブロック編集で欄 A を打って Tab で欄 B へ移り、すぐ打つと
 * **B の文字が消えた**（A の保存中は欄が disabled になって焦点が外れ、保存中に来た B の変更は
 * 黙って捨てられ、応答の再描画が B の値を打つ前へ戻していた）。
 *
 * 画面の振る舞いは Playwright で実測した（Tab 直後に delay 0 で打っても残る）。
 * ここでは、その守りが**消えていない**ことを文字で固定する。
 */
final class UxWalkRound17Test extends TestCase
{
    private function editor(): string
    {
        return (string) file_get_contents(resource_path('js/pages/admin/pages/Editor.vue'));
    }

    private function inspector(): string
    {
        return (string) file_get_contents(resource_path('js/components/page-editor/InspectorPanel.vue'));
    }

    /** 🔴 保存中に来た欄の変更を捨てない — 待ち行列に積んで順に送る */
    public function test_欄の変更は捨てずに順に送る(): void
    {
        $editor = $this->editor();

        $this->assertStringContainsString('updates = updates.then(', $editor);
        // 送る直前に読む（前の応答で進んだ版を使う）
        $this->assertMatchesRegularExpression('/updates = updates\.then\(async \(\) => \{.*?expectedVersion: version\.value/s', $editor);
        // 「保存中なら捨てる」は残っていない
        $this->assertStringNotContainsString("function update(changes: Record<string, unknown>): void {\n    if (!selected.value || blocked.value) {", $editor);
    }

    /** 🔴 入力欄を止めるのは競合のときだけ。保存中に disabled にすると焦点が外れて文字が落ちる */
    public function test_入力欄を止めるのは競合のときだけ(): void
    {
        $editor = $this->editor();

        $this->assertStringContainsString('const locked = computed(() => conflict.value !== null);', $editor);
        $this->assertStringContainsString(':blocked="locked"', $editor);
    }

    /** 🔴 スマホ幅で、営業時間の曜日が時間の横で「月曜日〜土曜 10:00〜20:00 日」と割れていた — 縦に積む */
    public function test_狭い画面ではメニュー表と営業時間を縦に積む(): void
    {
        $css = (string) file_get_contents(resource_path('css/document.css'));
        $narrow = substr($css, (int) strpos($css, '@media (max-width: 30rem)'));

        $this->assertStringContainsString('.doc-pairs__table th,', $narrow);
        $this->assertStringContainsString('display: block;', $narrow);
        $this->assertStringContainsString('white-space: normal;', $narrow);
    }

    /** 🔴 打ちかけの文字は、document が入れ替わっても欄に残る */
    public function test_打ちかけの文字は再描画で消えない(): void
    {
        $inspector = $this->inspector();

        $this->assertStringContainsString('const textDraft = ref<Record<string, string>>({});', $inspector);
        $this->assertStringContainsString(':value="textValue(key)"', $inspector);
        $this->assertStringContainsString(':model-value="textValue(key)"', $inspector);
    }
}

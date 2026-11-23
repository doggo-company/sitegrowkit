<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX 一周 第 19 周（トリミングサロンの店主の目・業種の入口から・2026-10-04）で見つけたものの回帰。
 *
 * 見つけたのは 1 つ。お問い合わせの受信一覧で、中身へ入る口が日付のリンクだけ（下線は hover まで無い）で、
 * 「どこを押せば読める？」で止まった。内容の列も開く口にし、下線を常に出す。
 */
final class UxWalkRound19Test extends TestCase
{
    public function test_受信一覧は内容の列からも開ける(): void
    {
        $vue = (string) file_get_contents(resource_path('js/pages/admin/forms/Submissions.vue'));

        $this->assertStringContainsString('data-testid="submission-open"', $vue);
        // 下線は常に出す（hover まで隠さない）
        $this->assertStringNotContainsString('hover:underline', $vue);
        $this->assertSame(2, substr_count($vue, 'class="underline underline-offset-4"'), '日付と内容の 2 か所が開く口');
    }
}

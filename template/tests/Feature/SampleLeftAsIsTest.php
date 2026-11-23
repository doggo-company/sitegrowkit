<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 見本のまま公開されるのを止める（WP 一周 2026-09-03・2〜4 周目で毎回書いた 🟡）。
 *
 * ## 何が起きるか
 *
 * 会社名と通知先だけ変えて、見本サイトの文章が丸ごと残っていても、
 * 公開前チェックは「公開できる状態です」と出す。原稿待ちの印
 * （「準備中」「ダミー」など）は**見本の文章には入っていない**からである。
 *
 * **一番ありがちな事故がこれ。** 納品したサイトに
 * 「本サイトは SiteGrowKit に同梱のサンプルサイトです」が残る。
 *
 * ## 文章の中身は見ない、は変えない
 *
 * 見るのは**見本だと名乗っている印**だけである。文章がその案件にふさわしいかは
 * 相変わらず見ない（機械には決められない）。画面の限界表記もそのまま残す。
 */
final class SampleLeftAsIsTest extends TestCase
{
    use RefreshDatabase;

    /** 見本の脚注は、同梱の layout が実際に描いている文である */
    private const SAMPLE_NOTE = '本サイトは SiteGrowKit に同梱のサンプルサイトです';

    public function test_見本の断り書きを見つける(): void
    {
        $found = PublicationReadiness::sampleMarkersIn(
            '<footer><p class="footer-note">'.self::SAMPLE_NOTE.'（コンテンツはデモ用）。</p></footer>',
        );

        $this->assertNotSame([], $found, '見本の断り書きを見落としている');
    }

    public function test_見本でないページでは何も言わない(): void
    {
        $this->assertSame(
            [],
            PublicationReadiness::sampleMarkersIn('<p>剪定と落ち葉の片づけを承っています。</p>'),
        );
    }

    /** **原稿待ちの印とは別に数える**（直す場所が違うので混ぜない） */
    public function test_原稿待ちの印とは別の理由で出す(): void
    {
        $findings = app(PublicationReadiness::class)->check();

        $sample = array_values(array_filter(
            $findings,
            static fn (array $f): bool => str_contains($f['what'], '見本'),
        ));

        $this->assertNotSame([], $sample, '見本のままが指摘に出ていない');
        $this->assertSame('blocker', $sample[0]['level']);
        $this->assertStringContainsString('同梱の見本', $sample[0]['what']);
    }

    /**
     * **限界の表記は残す。** ここが消えると「緑＝中身も正しい」と読まれる。
     */
    public function test_中身は見ないという断りを消さない(): void
    {
        $src = (string) file_get_contents(
            base_path('resources/js/pages/admin/readiness/Index.vue'),
        );

        $this->assertStringContainsString('文章の中身', $src);
    }
}

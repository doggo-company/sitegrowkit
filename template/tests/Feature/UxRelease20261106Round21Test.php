<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Reading\TitleReading;
use AgentCms\Core\Pages\SlugFromTitle;
use Tests\TestCase;

/**
 * 公開前の UX 一周 21 周目（2026-11-06・rc26 の採点 round21-grade.md）の回帰。
 *
 * - Y1: 定番名＋ありふれた付け足し（のご案内・方法…）が全部読みになった（料金のご案内 → ryoukin-no-go-annai）。
 *   決まりは 1 つ: 題名が「定番名＋付け足し（表）」だけなら定番名の英語、それ以外は全部読む
 * - Y2: 英語でない外来語は英語の訳語を使わず音（パン → pan・アルバイト → arubaito）。の＋お・の＋ご は区切る
 */
final class UxRelease20261106Round21Test extends TestCase
{
    public function test_定番名とありふれた付け足しだけなら定番名の英語(): void
    {
        $this->assertSame('price', TitleReading::slug('料金のご案内'));
        $this->assertSame('access', TitleReading::slug('アクセス方法'));
        $this->assertSame('staff', TitleReading::slug('スタッフ紹介'));
        $this->assertSame('service', TitleReading::slug('サービス一覧'));
        // それ以外（店名・地名・人名が付く・表に無い語）は全部読む
        $this->assertSame('kamakura-staff-shoukai', TitleReading::slug('鎌倉スタッフ紹介'));
        $this->assertSame('instructor-shoukai', TitleReading::slug('インストラクター紹介'));
        $this->assertSame('ryoukin-no-meyasu', TitleReading::slug('料金のめやす'));
        $this->assertSame('kichijouji-takahashi-trimming-salon', TitleReading::slug('吉祥寺高橋トリミングサロン'));
    }

    public function test_付け足しの表は定番名の表の側ではなく付け足しの側に持つ(): void
    {
        $this->assertContains('のご案内', SlugFromTitle::COMMON_SUFFIXES);
        $this->assertContains('方法', SlugFromTitle::COMMON_SUFFIXES);
        $this->assertArrayNotHasKey('料金のご案内', SlugFromTitle::COMMON);
        $this->assertArrayNotHasKey('アクセス方法', SlugFromTitle::COMMON);
    }

    public function test_英語でない外来語は音で_の_お_ご_は区切る(): void
    {
        $this->assertSame('pan-no-shurui', TitleReading::slug('パンの種類'));
        $this->assertSame('arubaito-boshuu', TitleReading::slug('アルバイト募集'));
        $this->assertSame('taiken-lesson-no-o-moushikomi', TitleReading::slug('体験レッスンのお申し込み'));
        $this->assertSame('pan-to-coffee-no-go-annai', TitleReading::slug('パンとコーヒーのご案内'));
        $this->assertSame('pan-no-go', TitleReading::slug('パンのご'));
        // 英語の外来語は今までどおり英語
        $this->assertSame('kamakura-takahashi-yoga-studio', TitleReading::slug('鎌倉高橋ヨガスタジオ'));
    }
}

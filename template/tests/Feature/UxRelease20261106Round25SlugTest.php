<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Reading\TitleReading;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UxRelease20261106Round25SlugTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function titles(): array
    {
        return [
            'visa' => ['ビザ', 'visa'],
            'center' => ['センター', 'center'],
            'visa in title' => ['ビザ・在留資格のサポート', 'visa-zairyuu-shikaku-no-support'],
            'center in title' => ['阿佐ヶ谷パールセンターからの道順', 'asagaya-pearl-center-karano-michijun'],
            'unknown long vowels' => ['ピャーピョー', 'pyaapyoo'],
        ];
    }

    #[DataProvider('titles')]
    public function test_カタカナの英語表記と未知語の長音を残す(string $title, string $expected): void
    {
        $this->assertSame($expected, TitleReading::slug($title));
    }
}

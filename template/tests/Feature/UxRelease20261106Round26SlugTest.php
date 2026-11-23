<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Reading\TitleReading;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UxRelease20261106Round26SlugTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function titles(): array
    {
        return [
            'place before surname' => ['西荻窪木村青果店', 'nishiogi-kubo-kimura-seika-ten'],
            'surname without place' => ['木村青果店のご案内', 'kimura-seika-ten-no-go-annai'],
            'smoothie in title' => ['スムージーセット', 'smoothie-set'],
            'juicer in title' => ['サラダバーとジューサー', 'salad-bar-to-juicer'],
        ];
    }

    #[DataProvider('titles')]
    public function test_人名と長音の外来語を途中で切らず読む(string $title, string $expected): void
    {
        $this->assertSame($expected, TitleReading::slug($title));
    }
}

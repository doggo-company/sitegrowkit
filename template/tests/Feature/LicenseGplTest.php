<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * 本キットは GPL-3.0-or-later で公開する（2026-11-23）。
 *
 * - `LICENSE` は FSF の GPLv3 本文そのもの。1 文字でも変わると「GPL の本文」ではなくなる。
 *   sha256 は https://www.gnu.org/licenses/gpl-3.0.txt を 2026-11-23 に取得して測った値。
 * - 著作権の表示と「or later」の宣言は `COPYRIGHT` に置く。
 * - first-party の composer.json が LICENSE と同じ表記であること。
 *
 * 以前ここにあった LicensePlaceholderTest（以前の独自ライセンスの未確定の印の検査）は、
 * GPL-3.0-or-later にしたので外した。
 */
final class LicenseGplTest extends TestCase
{
    private const GPL3_SHA256 = '3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986';

    public function test_license_は_gplv3_の本文そのもの(): void
    {
        $path = $this->repoRoot().'/LICENSE';

        $this->assertFileExists($path);
        $this->assertSame(self::GPL3_SHA256, hash_file('sha256', $path));
    }

    public function test_copyright_に会社の著作権表示と_or_later_がある(): void
    {
        $path = $this->repoRoot().'/COPYRIGHT';

        $this->assertFileExists($path);
        $text = (string) file_get_contents($path);

        $this->assertStringContainsString('Copyright (C) 2026 DOGGO Company株式会社', $text);
        $this->assertStringContainsString('either version 3 of the License, or', $text);
    }

    public function test_first_party_の_composer_json_は_gpl_3_0_or_later(): void
    {
        foreach (['template/composer.json', 'template/core/composer.json'] as $rel) {
            $manifest = json_decode((string) file_get_contents($this->repoRoot().'/'.$rel), true);

            $this->assertSame('GPL-3.0-or-later', $manifest['license'] ?? null, $rel);
        }
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}

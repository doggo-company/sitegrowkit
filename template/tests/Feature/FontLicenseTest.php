<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 8 D-3(LEGAL-REQUIRED CHANGE): Instrument Sans は利用者の production build
 * (public/build/assets/instrument-sans-*.woff{,2})に self-host font として
 * 埋め込まれ再配布されるため、OFL-1.1 license 全文 + copyright notice を
 * deploy 対象の public/ 配下に同梱する。upstream 公式 OFL.txt の verbatim copy
 * であること(独自文面へ書き換えないこと)を regression で固定する。
 */
final class FontLicenseTest extends TestCase
{
    private const OFL_PATH = 'licenses/instrument-sans/OFL.txt';

    /**
     * `THIRD-PARTY-LICENSES.md` に記録した取得時の SHA-256。
     *
     * **記録が正本で、ここはその写しである。** 片方だけ変えない。
     */
    private const OFL_SHA256 = '9e27a72ed30eb49a08678f6a5d6ed98ec7ba5368f541637ee0683ec9134ef966';

    public function test_instrument_sans_ofl_license_is_shipped_in_public(): void
    {
        $this->assertFileExists(public_path(self::OFL_PATH));
    }

    public function test_built_fonts_and_license_coexist_in_deploy_artifact(): void
    {
        // Phase 8.5-A: build 実行場所を node コンテナへ移しても、
        // deploy 対象（public/）に font と OFL が同居する状態は変わらない
        $fonts = glob(public_path('build/assets/instrument-sans-*.woff*')) ?: [];

        if ($fonts === []) {
            $this->markTestSkipped('production build 未実行（public/build がありません）');
        }

        $this->assertGreaterThanOrEqual(3, count($fonts), 'weights 400/500/600 が生成されていること');
        $this->assertFileExists(public_path(self::OFL_PATH));
    }

    /**
     * **verbatim と主張しているなら、verbatim を検査する。**
     *
     * 構造マーカーだけでは「全文らしさ」しか見ておらず、
     * **本文の途中を書き換えてもマーカーが残っていれば通る**。
     * `THIRD-PARTY-LICENSES.md` は取得時の SHA-256 を記録しているので、
     * **記録した値そのもの**と突き合わせる。
     *
     * ▷ この値が変わったら、**同梱物が上流と違う**か、**上流が変わった**かである。
     *   どちらも人が確かめる話なので、**黙って通さない**。
     */
    public function test_同梱ofl_は記録した通りの全文である(): void
    {
        $this->assertSame(
            self::OFL_SHA256,
            hash_file('sha256', public_path(self::OFL_PATH)),
            'OFL.txt が THIRD-PARTY-LICENSES.md の記録と一致しない',
        );
    }

    public function test_ofl_license_is_the_official_unmodified_text(): void
    {
        $text = (string) file_get_contents(public_path(self::OFL_PATH));

        // upstream 公式 OFL.txt の copyright header(1 行目)
        $this->assertStringStartsWith(
            'Copyright 2022 The Instrument Sans Project Authors (https://github.com/Instrument/instrument-sans)',
            $text,
        );

        // SIL OFL-1.1 全文の構造マーカー(全文が保持されていること)
        foreach ([
            'SIL OPEN FONT LICENSE Version 1.1',
            'PREAMBLE',
            'DEFINITIONS',
            'PERMISSION & CONDITIONS',
            'TERMINATION',
            'DISCLAIMER',
            'https://scripts.sil.org/OFL',
        ] as $marker) {
            $this->assertStringContainsString($marker, $text, $marker);
        }
    }
}

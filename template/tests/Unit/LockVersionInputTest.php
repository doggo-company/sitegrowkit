<?php

declare(strict_types=1);

namespace Tests\Unit;

use AgentCms\Core\Application\Content\LockVersionInput;
use PHPUnit\Framework\TestCase;

/**
 * 版の入力を読み解く規則そのもの（Phase 9.8 / STAGE 21C CORRECTION #2）。
 *
 * ここは DB を使わない。**入力の形をどう解釈するか**だけを固定する。
 *
 * 一番危ないのは「送ったのに未指定として扱われる」形である。送った側は
 * 上書き検出が働いていると思っているのに、実際には素通りする。
 * だから **キーが在るなら、空でも壊れていても「送った」として扱う**。
 */
final class LockVersionInputTest extends TestCase
{
    public function test_キーが無ければ未指定(): void
    {
        $input = LockVersionInput::fromRequest(['data' => []]);

        $this->assertNull($input->version);
        $this->assertFalse($input->rejected, '移行期間中は通す');
    }

    public function test_キーが在って値がnullなら拒否(): void
    {
        /*
         * **「送っていない」ではない。** 送る側は版の欄を持っていて、
         * そこへ null を入れて送ってきた。読めない値を黙って無視すると、
         * 上書き検出が働いていると思ったまま上書きが通る。
         */
        $input = LockVersionInput::fromRequest(['lock_version' => null]);

        $this->assertTrue($input->rejected);
        $this->assertNull($input->version);
    }

    public function test_キーが在って値が空文字なら拒否(): void
    {
        $input = LockVersionInput::fromRequest(['lock_version' => '']);

        $this->assertTrue($input->rejected);
    }

    public function test_0は実在する版として通る(): void
    {
        foreach ([0, '0'] as $value) {
            $input = LockVersionInput::of($value);

            $this->assertFalse($input->rejected, var_export($value, true));
            $this->assertSame(0, $input->version);
        }
    }

    public function test_正の整数と数字文字列は通る(): void
    {
        foreach ([1, 42, '1', '42', '0007'] as $value) {
            $input = LockVersionInput::of($value);

            $this->assertFalse($input->rejected, var_export($value, true));
            $this->assertIsInt($input->version);
        }
    }

    public function test_形が合わない値は拒否(): void
    {
        foreach ([-1, 1.5, '1.5', true, false, ['1'], new \stdClass, 'abc', ' 1', '1 ', '+1', '-0'] as $value) {
            $this->assertTrue(
                LockVersionInput::of($value)->rejected,
                var_export($value, true).' が通ってしまった',
            );
        }
    }

    public function test_安全に扱えない大きさは拒否(): void
    {
        /*
         * **cast してから判定しない。** `(int) "9223372036854775808"` は
         * PHP_INT_MAX へ丸められるので、cast したあとでは「範囲内」に見えてしまう。
         * 丸められた値で版を比べると、**別の版なのに一致してしまう**ことが起き得る。
         */
        foreach ([
            '9223372036854775808',                 // PHP_INT_MAX + 1
            (string) PHP_INT_MAX,                  // PHP では表せるが JSON では危うい
            '9007199254740992',                    // Number.MAX_SAFE_INTEGER + 1
            str_repeat('9', 40),                   // 極端に長い
        ] as $value) {
            $input = LockVersionInput::of($value);

            $this->assertTrue($input->rejected, $value.' が通ってしまった');
            $this->assertNotSame(PHP_INT_MAX, $input->version, $value.' が丸められている');
            $this->assertNull($input->version);
        }
    }

    public function test_安全な上限そのものは通る(): void
    {
        // JavaScript が整数として正確に扱える上限（MCP の相手は JSON である）
        $input = LockVersionInput::of('9007199254740991');

        $this->assertFalse($input->rejected);
        $this->assertSame(9007199254740991, $input->version);
    }

    public function test_内部からの省略は未指定のまま(): void
    {
        /*
         * 引数を書かなかった内部呼び出し（`normalize(null)`）と、
         * 外から明示された `null` を混同しない。前者は「送っていない」である。
         */
        $this->assertFalse(LockVersionInput::normalize(null)->rejected);
        $this->assertTrue(LockVersionInput::fromRequest(['lock_version' => null])->rejected);
    }

    public function test_拒否の理由に内部情報を含めない(): void
    {
        $errors = LockVersionInput::of('abc')->errors();

        $this->assertArrayHasKey('lock_version', $errors);

        foreach ($errors['lock_version'] as $message) {
            foreach (['SQLSTATE', '/var/www', 'PHP_INT_MAX', 'preg_'] as $leak) {
                $this->assertStringNotContainsString($leak, $message);
            }
        }
    }
}

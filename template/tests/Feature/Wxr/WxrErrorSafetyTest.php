<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\WxrError;
use Tests\TestCase;

/**
 * 止める理由を**そのまま端末と JSON へ出せる**ことを保証する
 * （Phase 9.14 / STAGE 27B・SPEC §F・§G）。
 *
 * ## なぜここで守るのか
 *
 * 取り込みを止める理由には、**外から受け取ったファイルの中身**が入る。
 * 分類の slug、meta の名前、名乗られた版。どれも利用者が書いたものではない。
 *
 * これが端末へ出ると、不可視文字や書字方向の上書きで**別の文章に見せられる**。
 * ログや JSON へ入れば、その先で読む仕組みも巻き込む。
 *
 * 呼ぶ側それぞれで escape すると、**1 か所書き忘れたところだけが穴になる**。
 * だから通り道を 1 本にして、そこで守る。
 */
final class WxrErrorSafetyTest extends TestCase
{
    public function test_制御文字を持ち込ませない(): void
    {
        $error = new WxrError("読めません\x07\x1b[31m", "詳細\x00です");

        $this->assertStringNotContainsString("\x07", $error->getMessage());
        $this->assertStringNotContainsString("\x1b", $error->getMessage());
        $this->assertStringNotContainsString("\x00", $error->hint);
    }

    public function test_書字方向を上書きする文字を持ち込ませない(): void
    {
        /*
         * **見えない文字で文章を書き換えられる。**
         * 右横書きの上書きを混ぜると、端末では別の順序で表示され、
         * 「危険です」を「安全です」のように見せることができる。
         */
        $error = new WxrError("分類 [\u{202E}gnitset\u{202C}] が読めません");

        $this->assertStringNotContainsString("\u{202E}", $error->getMessage());
        $this->assertStringNotContainsString("\u{202C}", $error->getMessage());
        // **消さずに見える形にする**（何が入っていたかを読めるようにする）
        $this->assertStringContainsString('\u{202E}', $error->getMessage());
        $this->assertStringContainsString('\u{202C}', $error->getMessage());
    }

    public function test_改行になる別の文字も通さない(): void
    {
        /*
         * **行を作れるのは `\n` だけではない。**
         * U+0085 NEL、U+2028 LINE SEPARATOR、U+2029 PARAGRAPH SEPARATOR も
         * 端末やビューアで改行になる。`Cc` / `Cf` を落とすだけでは残るので
         * （U+2028/2029 は `Zl` / `Zp`）、ここで固定する。
         */
        $error = new WxrError("読めません\u{0085}偽\u{2028}の\u{2029}行");

        foreach (['0085', '2028', '2029'] as $codepoint) {
            $raw = mb_chr((int) hexdec($codepoint), 'UTF-8');

            $this->assertStringNotContainsString(
                $raw,
                $error->getMessage(),
                "U+{$codepoint} が生のまま残っている",
            );

            // **消すのではなく見える形にする**（診断としての情報を残す）
            $this->assertStringContainsString('\u{'.$codepoint.'}', $error->getMessage());
        }

        // 元の文字は残る（何が書かれていたかは読める）
        $this->assertStringContainsString('偽', $error->getMessage());
    }

    public function test_幅の無い文字を持ち込ませない(): void
    {
        // 同じに見える別の文字列を作れる（見分けが付かない偽装になる）
        $error = new WxrError("slug [ne\u{200B}ws] が読めません");

        $this->assertStringNotContainsString("\u{200B}", $error->getMessage());
        $this->assertStringContainsString('\u{200B}', $error->getMessage());
    }

    public function test_見えない差を診断が言えること(): void
    {
        /*
         * **落としてしまうと診断が自己矛盾する。**
         * 幅の無い文字を消すと `1.2` と `1.2` が同じ文字列になり、
         * 「名前空間は 1.2、本文は 1.2 で食い違っています」という
         * それ自体が読めない診断になる。
         */
        $plain = new WxrError('版は 1.2 です');
        $hidden = new WxrError("版は 1.2\u{200B} です");

        $this->assertNotSame($plain->getMessage(), $hidden->getMessage());
    }

    public function test_本物の改行を通さない(): void
    {
        /*
         * **通す・通さないを中で決められない。** その改行がこちらの定型文なのか、
         * ファイルから来た値なのかは、ここからは区別が付かない。
         * 1 つでも通せば、外から来た値が**次の行を偽装できる**
         * （「エラー」と書いた次の行に「成功しました」を足す、など）。
         *
         * 消すのではなく**見える形**にする。診断としての情報は失わない。
         */
        $error = new WxrError("読めません\n偽の行です", "詳細\r\tです");

        $this->assertStringNotContainsString("\n", $error->getMessage());
        $this->assertStringContainsString('\n', $error->getMessage());
        $this->assertStringContainsString('偽の行です', $error->getMessage());

        $this->assertStringNotContainsString("\r", $error->hint);
        $this->assertStringNotContainsString("\t", $error->hint);
        $this->assertStringContainsString('\r', $error->hint);
        $this->assertStringContainsString('\t', $error->hint);
    }

    public function test_長すぎる文章を切り詰める(): void
    {
        $error = new WxrError(str_repeat('あ', 5000), str_repeat('い', 5000));

        $this->assertLessThanOrEqual(WxrError::MESSAGE_MAX_BYTES, strlen($error->getMessage()));
        $this->assertLessThanOrEqual(WxrError::HINT_MAX_BYTES, strlen($error->hint));
        // 切ったことを言う（全部読んだと思わせない）
        $this->assertStringEndsWith('…', $error->getMessage());
    }

    public function test_切り詰めても文字の途中で切らない(): void
    {
        $error = new WxrError(str_repeat('あ', 5000));

        $this->assertTrue(mb_check_encoding($error->getMessage(), 'UTF-8'));
    }

    public function test_こちらが書いた説明は切られない(): void
    {
        /*
         * 上限が厳しすぎると、**手順の途中で切れて読めなくなる**。
         * 実際に使っている一番長い説明が丸ごと収まることを確かめる。
         */
        $longest = '次の順に用意してください: (1) content/taxonomies/example_category.json を作る'
            .' → (2) php artisan agentcms:taxonomy-sync'
            .' → (3) --taxonomy=<WXR 側の分類>:example_category を付けて、もう一度 plan を実行する';

        $error = new WxrError('分類の受け皿がありません。', $longest);

        $this->assertSame($longest, $error->hint);
    }

    // ── 外から来た値を出すとき ──────────────────────────────

    public function test_外から来た値は短く畳んで出す(): void
    {
        /*
         * 本文級の長さの slug や meta 名が来ることがある。
         * **そのまま補間してから WxrError へ渡すと、中央の上限まで
         * 説明が押し出される。** 呼ぶ側でも畳んでおく。
         */
        $folded = WxrError::token(str_repeat('あ', 500));

        $this->assertLessThanOrEqual(WxrError::TOKEN_MAX_BYTES, strlen($folded));
        $this->assertTrue(mb_check_encoding($folded, 'UTF-8'));
        // 畳んだことを言う（全部が出ていると思わせない）
        $this->assertStringEndsWith('…', $folded);
    }

    public function test_短い値はそのまま読める(): void
    {
        $this->assertSame('subtitle', WxrError::token('subtitle'));
    }

    public function test_外から来た値の不可視文字も落とす(): void
    {
        $folded = WxrError::token("ne\u{202E}ws");

        $this->assertStringNotContainsString("\u{202E}", $folded);
        $this->assertStringContainsString('\u{202E}', $folded);
    }

    public function test_to_arrayも同じ保証を持つ(): void
    {
        $error = new WxrError("読めません\x1b[31m", "詳細\u{200B}です");

        $encoded = (string) json_encode($error->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString("\x1b", $encoded);
        $this->assertStringNotContainsString("\u{200B}", $encoded);
    }
}

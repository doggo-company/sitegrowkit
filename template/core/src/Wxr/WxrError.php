<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

use RuntimeException;

/**
 * 取り込みを止める理由（Phase 9.14 / STAGE 27B・SPEC §F・§G）。
 *
 * **止めるときは、どこで何が起きたかを必ず言う。**
 * 数百 MB のファイルを渡されて「読めません」とだけ返されても、
 * 利用者には次の手が無い。
 *
 * ## そのまま出せることを、ここで保証する
 *
 * 理由には**外から受け取ったファイルの中身**が入る。分類の slug、meta の名前、
 * 名乗られた版。どれも利用者が書いたものではない。
 *
 * それが端末へ出ると、書字方向の上書きや幅の無い文字で**別の文章に見せられる**。
 * ログや JSON へ入れば、その先で読む仕組みも巻き込む。
 *
 * 呼ぶ側それぞれで守ると、**1 か所書き忘れたところだけが穴になる**。
 * だから通り道を 1 本にして、ここで守る。呼ぶ側は `token()` で
 * 外から来た値を畳んでから渡す（中央の上限まで説明が押し出されないように）。
 */
final class WxrError extends RuntimeException
{
    /**
     * 理由の上限。
     *
     * **こちらが書く説明が切れない長さにする。** 厳しすぎると手順の途中で切れ、
     * 「次に何をすればよいか」が読めなくなる。
     */
    public const MESSAGE_MAX_BYTES = 1024;

    /** 説明の上限（手順は複数行になるので理由より広く取る） */
    public const HINT_MAX_BYTES = 2048;

    /** 外から来た 1 つの値を出すときの上限 */
    public const TOKEN_MAX_BYTES = 96;

    public readonly string $hint;

    public function __construct(
        string $message,
        string $hint = '',
        /** **WXR の何行目か**（PHP の `Exception::$line` とは別のもの） */
        public readonly ?int $sourceLine = null,
    ) {
        $this->hint = self::safe($hint, self::HINT_MAX_BYTES);

        parent::__construct(self::safe($message, self::MESSAGE_MAX_BYTES));
    }

    /**
     * 外から来た 1 つの値を、**そのまま出せる短い形**にする。
     *
     * 本文級の長さの slug や meta 名が来ることがある。補間してから渡すと、
     * 中央の上限まで**こちらの説明が押し出される**ので、呼ぶ側でも畳んでおく。
     */
    public static function token(string $value): string
    {
        return self::safe($value, self::TOKEN_MAX_BYTES);
    }

    /**
     * 画面や JSON へそのまま出せる形。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'hint' => $this->hint === '' ? null : $this->hint,
            'line' => $this->sourceLine,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * 見えない文字を落とし、長さを収める。
     *
     * - 改行・復帰・タブは**見える形へ置き換える**（`\n` のような文字の並びにする）。
     *   **ここで本物の改行を通さない。** 通す・通さないを中で決めようとしても、
     *   その改行がこちらの定型文なのか、ファイルから来た値なのかは区別できない。
     *   1 つでも通せば、外から来た値が**次の行を偽装できる**
     * - それ以外の制御文字（`Cc`）と書式文字（`Cf`）は落とす。
     *   端末の escape、書字方向の上書き、幅の無い文字が該当する
     * - 切るときは**文字の途中で切らない**（壊れたバイト列を出さない）
     */
    private static function safe(string $value, int $maxBytes): string
    {
        // 不正なバイト列はここで落とす（出せない形のまま先へ流さない）
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        /*
         * 診断を失わないよう、消さずに見える形にする。
         *
         * **行を作れる文字は `\n` だけではない。** U+0085 NEL、
         * U+2028 LINE SEPARATOR、U+2029 PARAGRAPH SEPARATOR も端末や
         * ビューアで改行になる。`Cc` / `Cf` の分類には入らない
         * （U+2028/2029 は `Zl` / `Zp`）ので、名指しで潰す。
         */
        $value = strtr($value, [
            "\n" => '\n',
            "\r" => '\r',
            "\t" => '\t',
            "\u{0085}" => '\u{0085}',
            "\u{2028}" => '\u{2028}',
            "\u{2029}" => '\u{2029}',
        ]);

        /*
         * **消さずに見える形にする。**
         *
         * 落としてしまうと、`1.2\u{200B}` と `1.2` が同じ文字列に見え、
         * 「名前空間は 1.2、本文は 1.2 で食い違っています」という
         * **それ自体が矛盾した診断**になる。何が違うのかを読めるようにする。
         */
        $value = (string) preg_replace_callback(
            '/\p{Cc}|\p{Cf}|\p{Zl}|\p{Zp}/u',
            static fn (array $m): string => sprintf('\u{%04X}', mb_ord($m[0], 'UTF-8')),
            $value,
        );

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        // 切ったことを言う（全部読んだと思わせない）
        return mb_strcut($value, 0, $maxBytes - strlen('…'), 'UTF-8').'…';
    }
}

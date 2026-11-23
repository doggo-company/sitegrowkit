<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * stdin から JSON を受け、stdout へ JSON 1 行を返すコマンドの作法（D-7）。
 *
 * ## なぜ 1 か所に寄せるのか
 *
 * 🔴 **6 つのコマンドが同じ作法を別々に持っていて、既に答えが違っていた**
 * （2026-08-28 実測）:
 *
 * ```
 * emit()       2 通り — 書き出せないとき `{"status":"conflict"}` と `{"status":"error"}`
 * readStdin()  3 通り — 1 つだけ `StreamableInputInterface` を見ない
 * ```
 *
 * **呼ぶ側は JSON を読む。**status が違えば、**同じ失敗を別の失敗として扱う。**
 * しかも**どちらも「動いて」いる**ので、片方だけ直しても気づけない。
 *
 * ▷ `readStdin()` の違いはもっと直接的で、**`StreamableInputInterface` を見ない実装は
 * 試験から入力を流し込めない**。つまり**その口だけ、試験が書けなかった。**
 */
trait SpeaksJson
{
    /**
     * 標準入力を読み切る。
     *
     * 🔴 **`StreamableInputInterface` を先に見る。**試験はここへ入力を流し込むので、
     * 見ないと**その口だけ試験が書けない**（実際に 1 つそうなっていた）。
     */
    private function readStdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface
            ? $this->input->getStream()
            : null;

        if ($stream !== null) {
            stream_set_blocking($stream, true);

            return trim(stream_get_contents($stream) ?: '');
        }

        $handle = fopen('php://stdin', 'rb');

        if ($handle === false) {
            return '';
        }

        // 対話端末で誤って実行されたときにブロックしない
        stream_set_blocking($handle, true);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return trim($contents === false ? '' : $contents);
    }

    /**
     * 受け取ったものが通らないと分かったときの断り方。
     *
     * 🔴 **呼ぶ側は JSON を読む。**断り方が口ごとに違うと、
     * **同じ失敗を別の失敗として扱う。**しかもどれも「動いて」いるので、
     * 片方だけ直しても気づけない（D-7 の emit と同じ形）。
     *
     * ▷ ImportForm / ImportMenu / ImportPage の 3 か所に
     * **一字一句同じものが置かれていた**（2026-08-29 実測）ので 1 つへ寄せた。
     *
     * 中身に触れずに断るとき用。**Result を持っているときは使わない**
     * （ImportPage の `refuseResult()` が理由と errors まで返す）。
     */
    private function refuse(string $message): int
    {
        return $this->emit([
            'status' => 'validation_error',
            'action' => 'none',
            'message' => $message,
        ], failure: true);
    }

    /**
     * 結果を JSON 1 行で返す。
     *
     * 🔴 **書き出せなかったときの返事は 1 つだけ。**
     * `conflict` と `error` に割れていたが、**JSON にできなかったのは衝突ではない**。
     * 呼ぶ側が「衝突が起きた」と読むと、**やり直せば直ると思ってしまう。**
     *
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, bool $failure): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->line($json === false ? '{"status":"error"}' : $json);

        return $failure ? self::FAILURE : self::SUCCESS;
    }
}

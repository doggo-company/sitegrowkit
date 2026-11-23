<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 資格情報に触れる処理の失敗を、**中身を漏らさずに**記録する（STAGE #18B）。
 *
 * ## `report()` をそのまま使えない場所がある
 *
 * `report($e)` は例外のメッセージとスタックを書く。ところが
 *
 * - `QueryException` のメッセージには **SQL と bindings** が入る。
 *   `password_reset_tokens` への insert なら、そこに
 *   **メールアドレスとハッシュ化された token** が並ぶ。
 * - メール送信の例外は、transport によっては **本文**を抱える。
 *   招待メールの本文にはパスワード設定 URL がそのまま載っている。
 *
 * つまり「ログには残さない」と決めた値が、例外を報告した瞬間に
 * ログファイルへ複製される。ログを読める範囲は本番では広い。
 *
 * ## だから種別だけ残す
 *
 * 運用で最初に要るのは「どこで・どの種類の失敗が・いつ起きたか」であり、
 * 値そのものではない。ここでは **固定の文言 + 例外クラス名 + コード**だけを書く。
 * それ以上の調査は、値を持たない再現手順で行う。
 *
 * 資格情報に触れない失敗（画像処理・外部 API など）は
 * これまでどおり `report()` でよい。使い分けを狭く保つ。
 */
final class SafeFailureLog
{
    /**
     * @param  string  $what  何をしようとして失敗したか（固定文言。値を入れない）
     */
    public static function record(string $what, Throwable $e): void
    {
        Log::error($what, [
            // クラス名とコードは値を含まない。message / trace / previous は載せない
            'exception' => $e::class,
            'code' => (string) $e->getCode(),
        ]);
    }
}

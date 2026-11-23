<?php

declare(strict_types=1);

namespace Modules\Notifications\Push;

/**
 * 1 件の Push 送信結果。一時的エラー（Failed）と恒久的エラー（Invalid = 404/410 等で
 * subscription 自体が無効）を区別する — Invalid は再試行せず subscription を削除する。
 */
enum PushResult
{
    case Sent;
    case Invalid;
    case Failed;
}

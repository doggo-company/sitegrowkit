<?php

declare(strict_types=1);

namespace AgentCms\Core\Mail;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 送信結果を必ず呼び出し元へ返すメール送信（Contact pipeline 用）。
 * 例外を握りつぶさず記録し、送信失敗が上位のデータ保存を巻き戻さないようにする。
 */
final class RecordedMailer
{
    /**
     * @param  string|list<string>  $to  1 通を複数の宛先へ（R-11 ⑤）
     * @return string|null 失敗時はエラーメッセージ、成功時は null
     */
    public function send(Mailable $mailable, string|array $to): ?string
    {
        try {
            Mail::to($to)->send($mailable);

            return null;
        } catch (Throwable $e) {
            Log::error('Mail send failed', ['to' => is_array($to) ? implode(',', $to) : $to, 'mailable' => $mailable::class, 'error' => $e->getMessage()]);

            return mb_substr($e->getMessage(), 0, 500);
        }
    }
}

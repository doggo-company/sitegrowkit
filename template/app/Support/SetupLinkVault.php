<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use JsonException;

/**
 * パスワード設定 URL を **1 回だけ**渡すための預かり所（STAGE #18B）。
 *
 * ## なぜ session へ素で入れないのか
 *
 * このプロジェクトの既定は `SESSION_DRIVER=database` である。
 * つまり session へ入れた値は **`sessions` テーブルへそのまま保存される**。
 * 設定 URL は「一時的な鍵」なので、平文で DB に置いた時点で
 * 「DB には残さない」という約束が崩れる。
 * Inertia の flash に載せると、さらに **ページの props** として
 * ブラウザの DOM と DevTools の履歴にも複製される。
 *
 * ## だから封筒ごと暗号化する
 *
 * URL・表示名・期限をまとめて JSON にし、`Crypt` で暗号化した
 * **文字列 1 本だけ**を session へ置く。DB に載るのは暗号文で、
 * 鍵（`APP_KEY`）を持たない者には読めない。
 * 受け取り側は取り出すと同時に session から消すので、
 * 同じ封筒を二度開けることはできない。
 *
 * ## 何を守れて、何を守れないか
 *
 * - 守れる: `sessions` テーブルの平文、Inertia props、ブラウザ履歴、
 *   リダイレクト URL の path / query
 * - 守れない: `APP_KEY` を持つ者（= アプリ自身）と、
 *   表示された画面そのもの（だから 1 回だけにして警告を出す）
 */
class SetupLinkVault
{
    /*
     * `final` を付けていないのは、**渡す途中で落ちたときの挙動**を
     * テストで実際に再現するため（差し替えて例外を起こす）。
     * 本番でここを継承して振る舞いを変える想定は無い。
     */

    /** session に置く鍵。Inertia の flash とは別枠にして props へ載せない */
    private const SESSION_KEY = 'agentcms.setup_link';

    /**
     * 次の 1 リクエストにだけ渡す（`flash` なのでその後は自動で消える）。
     *
     * @param  array{url: string, expiresInMinutes: int, userName: string, userId: int}  $envelope
     */
    public function stash(array $envelope): void
    {
        Session::flash(self::SESSION_KEY, Crypt::encryptString(
            json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ));
    }

    /**
     * 取り出して **同時に消す**（再読み込み・戻るで二度目は出ない）。
     *
     * 封筒が無い / 復号できない / 形が違うときは null。
     * 復号の失敗は鍵の入れ替えなどで普通に起こるため、
     * 例外にせず「表示できない」として扱う（値はログへ出さない）。
     *
     * @return array{url: string, expiresInMinutes: int, userName: string, userId: int}|null
     */
    public function pull(): ?array
    {
        $sealed = Session::pull(self::SESSION_KEY);

        if (! is_string($sealed) || $sealed === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode(Crypt::decryptString($sealed), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $e) {
            SafeFailureLog::record('パスワード設定 URL の封筒を開けませんでした', $e);

            return null;
        }

        $url = $envelope['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return [
            'url' => $url,
            'expiresInMinutes' => (int) ($envelope['expiresInMinutes'] ?? 0),
            'userName' => (string) ($envelope['userName'] ?? ''),
            'userId' => (int) ($envelope['userId'] ?? 0),
        ];
    }
}

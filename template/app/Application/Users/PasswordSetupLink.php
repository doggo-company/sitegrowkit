<?php

declare(strict_types=1);

namespace App\Application\Users;

use App\Models\User;
use App\Support\SafeFailureLog;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * 本人がパスワードを決めるための URL（PHASE WP-BASELINE / STAGE #18B）。
 *
 * ## メールに頼らず渡すため
 *
 * 制作会社が顧客の利用者を代理で作るとき、相手のメールが使えない
 * （まだ用意していない・届かない・確認できない）ことがある。
 * そこで **同じ URL を管理者が受け取り、LINE など別の経路で本人へ渡す**。
 *
 * ## 仮パスワードを作らない理由
 *
 * 「管理者が決めたパスワードを伝える」方式にすると、その文字列は
 * 画面・メモ・チャットのどこかに必ず残り、変更されるまで誰でも本人になれる。
 * ここで渡すのは **1 回で使い切る・期限つきの設定 URL** であり、
 * パスワードそのものは最後まで本人しか知らない。
 *
 * ## 保管しない
 *
 * token は Laravel 標準のブローカーが `password_reset_tokens` へ
 * **ハッシュで**保存する。こちらは平文の URL をどこにも書かない
 * （監査ログ・アプリログ・例外メッセージ・users のいずれにも残さない）。
 * 新しい token を発行すると、ブローカーが同じ利用者の古い token を消すので、
 * **前に渡した URL は自動的に使えなくなる**。
 */
class PasswordSetupLink
{
    /*
     * `final` を付けていないのは、**失効に失敗したときの案内**を
     * テストで実際に再現するため（差し替えて失敗を返させる）。
     * 本番でここを継承して振る舞いを変える想定は無い。
     */

    /**
     * 新しい設定 URL を発行する（古い URL はこの時点で無効になる）。
     *
     * 失敗（DB へ書けない等）は例外のまま呼び出し側へ返す。
     * ここで握りつぶすと「URL を渡したつもりで渡せていない」状態を作る。
     */
    public function issueFor(User $user): string
    {
        return route('password.reset', [
            // 既定 broker への操作は公開 Facade API を使い、環境ごとの型推論差をなくす。
            'token' => Password::createToken($user),
            'email' => $user->getEmailForPasswordReset(),
        ]);
    }

    /**
     * **まだ誰にも渡していない** URL をその場で使えなくする（STAGE #18B）。
     *
     * 発行そのものは成功したのに、画面へ渡す途中で落ちることがある。
     * そのまま放置すると「誰も知らないが有効な鍵」が期限まで残る。
     * 渡せていないと分かっているなら、待たずに消すほうが安全。
     *
     * ## 消せたかどうかを返す
     *
     * 失敗しても呼び出し側の流れは止めない（既に失敗処理の途中）。
     * ただし **黙って成功を装わない**。画面が「使えなくしました」と
     * 断言してよいかはこの戻り値で決まる。嘘の案内は、
     * 「もう安全だ」と思わせたぶんだけ危険になる。
     *
     * 利用者ではなく ID で受けるのは、呼び出し側（Controller）に
     * DB を引かせないため。**利用者の解決もここに閉じる**。
     *
     * @return bool 使えなくできたか
     */
    public function revokeFor(int $userId): bool
    {
        try {
            $user = User::query()->find($userId);

            if (! $user instanceof User) {
                return false;
            }

            Password::deleteToken($user);

            return true;
        } catch (Throwable $e) {
            SafeFailureLog::record('未使用のパスワード設定 URL を失効できませんでした', $e);

            return false;
        }
    }

    /** URL が使える時間（分）。画面に出して「いつまでに渡すか」を判断させる */
    public function expiresInMinutes(): int
    {
        return (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
    }
}

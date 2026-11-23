<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;

/**
 * 利用者の既存 session を失効させる（STAGE #18A / Correction 3）。
 *
 * 無効化とロール変更は **その場で効かないと意味がない**。
 * 開いたままのタブが古い権限で動き続ける状態を残さない。
 *
 * ## driver に依存しない失効
 *
 * 中心は `users.session_epoch` の **世代番号**である。
 * 認証済みの最初のリクエストでその時点の番号を session へ入れ
 * （`EnsureUserIsActive`）、以降は毎リクエストで突き合わせる。
 * 番号を進めれば **どの session driver でも**次のリクエストで確実に弾ける。
 *
 * `database` driver のときは行そのものも消し、`remember_token` も断つ。
 * どれか 1 つに頼らない多重の防御。
 */
final class SessionInvalidator
{
    /** session に持たせる世代番号のキー */
    public const SESSION_KEY = 'agentcms.session_epoch';

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * ログインした瞬間の世代を session へ刻む（Correction 2-2）。
     *
     * ## なぜ middleware では遅いのか
     *
     * 以前は「最初の認証済みリクエストで、まだ記録が無ければ今の世代を入れる」
     * という後追いだった。すると **ログインしてから最初のリクエストまでの間に**
     * 権限が変わった session は、変更後の世代を初期値として受け入れてしまう。
     * 攻撃者が session を確立したまま静かに待てば、降格や無効化を素通りできる。
     *
     * ログインの瞬間に刻めばその窓が無くなる。以降、`users.session_epoch` が
     * 1 でも進めば必ず不一致になる。
     */
    public function remember(Session $session, User $user): void
    {
        $session->put(self::SESSION_KEY, (int) $user->session_epoch);
    }

    /**
     * @param  string|null  $exceptSessionId  database driver のときだけ意味を持つ
     * @return int 進めたあとの世代番号
     */
    public function forgetUser(int $userId, ?string $exceptSessionId = null): int
    {
        // 世代を進める（driver に関係なく次のリクエストで弾ける）
        $this->connection->table('users')->where('id', $userId)->update([
            'session_epoch' => $this->connection->raw('session_epoch + 1'),
            // remember-me による自動再ログインも同時に断つ
            'remember_token' => null,
        ]);

        if ($this->usesDatabaseSessions()) {
            $this->connection->table((string) config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
                ->delete();
        }

        return (int) User::query()->whereKey($userId)->value('session_epoch');
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database'
            && Schema::hasTable((string) config('session.table', 'sessions'));
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * 管理者はここ（または CLI）でのみ作成する。公開登録は無効。
 * 認証情報は .env の ADMIN_EMAIL / ADMIN_PASSWORD（agentcms create / dev が
 * project ごとに安全なランダム値を生成する）。共有既定パスワードは存在しない。
 * 値の参照先: .agentcms/local/admin-credentials.json（Git 対象外）。
 */
class AdminUserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = (string) config('agentcms.admin.email');
        $password = (string) config('agentcms.admin.password');

        if ($password === '') {
            throw new RuntimeException(
                'ADMIN_PASSWORD が設定されていません。`agentcms dev` を実行すると安全なランダム値が自動生成されます'
                .'（手動の場合は .env の ADMIN_PASSWORD を設定してください）。'
            );
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        // 既存の表示名は変えない（運用中に付け替えた名前を seeder が戻さない）
        if ($isNew) {
            $user->name = 'Administrator';
        }

        $user->password = Hash::make($password);
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        /*
         * **owner が 1 人も居ない状態を作らない**（STAGE #18A）。
         * 既存環境で有効な owner が居なくなっている場合も、この管理者アカウントを owner へ戻す。
         * role / is_active は fillable ではないので forceFill で明示的に書く。
         *
         * **新しく作る管理者は制作者**（UX 一周 2026-09-28）。`agentcms create` を打つのは
         * サイトを作る人で、店主はこの人が招待する。作りの画面（投稿タイプなど）は制作者だけが
         * 開けるので、owner で作ると、生成直後のサイトで誰も作りの画面を開けなかった。
         * 制作者は「最後のオーナー」の数にも入る（`UserGuard`）。既に居る利用者のロールは変えない。
         */
        if ($isNew) {
            $user->forceFill(['role' => Role::Developer->value, 'is_active' => true]);
        } elseif (! $this->hasActiveOwner($user)) {
            $keep = $user->role === Role::Developer->value ? Role::Developer : Role::Owner;
            $user->forceFill(['role' => $keep->value, 'is_active' => true]);
        }

        $user->save();
    }

    /** この利用者以外に、有効な owner（制作者を含む）が居るか */
    private function hasActiveOwner(User $current): bool
    {
        return User::query()
            ->whereIn('role', [Role::Owner->value, Role::Developer->value])
            ->where('is_active', true)
            ->when($current->exists, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->exists();
    }
}

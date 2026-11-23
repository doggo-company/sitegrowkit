<?php
// ローカルDocker内でだけ呼び出す。生パスワード・トークンをログへ出力しない。
ob_start();
try {
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = config('database.connections.mysql');
    if (! $app->environment('local') || config('database.default') !== 'mysql'
        || ! is_array($db) || ($db['host'] ?? '') !== 'mysql' || ! empty($db['url'])
        || ! empty($db['unix_socket']) || ! empty($db['read']) || ! empty($db['write'])) {
        throw new RuntimeException('local_only');
    }
    $admins = App\Models\User::query()->where('is_active', true)->whereIn('role', ['owner', 'administrator']);
    if (($input['action'] ?? '') === 'info') {
        $accounts = (clone $admins)->orderBy('id')->limit(100)->get(['id', 'name', 'email', 'role']);
        $initial = $input['initial'] ?? null;
        $initialValid = false;
        if (is_array($initial) && is_string($initial['email'] ?? null) && is_string($initial['password'] ?? null)) {
            $user = (clone $admins)->where('email', $initial['email'])->first();
            $initialValid = $user !== null && Illuminate\Support\Facades\Hash::check($initial['password'], $user->password);
        }
        $result = ['accounts' => $accounts->toArray(), 'initialValid' => $initialValid];
    } elseif (($input['action'] ?? '') === 'reset' && is_string($input['email'] ?? null)) {
        $user = $admins->where('email', $input['email'])->firstOrFail();
        $broker = Illuminate\Support\Facades\Password::broker();
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            throw new RuntimeException('reset_throttled');
        }
        // 標準トークンの発行だけを行う。パスワード・権限・2FAは変更しない。
        $result = ['token' => $broker->createToken($user), 'email' => $user->email,
            'expiresMinutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60)];
    } else {
        throw new RuntimeException('invalid_action');
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    ob_end_clean();
    // 例外に含まれ得るDB接続情報等を返さない。
    $code = in_array($error->getMessage(), ['local_only', 'reset_throttled'], true) ? $error->getMessage() : 'recovery_failed';
    echo json_encode(['ok' => false, 'error' => $code]);
    exit(1);
}

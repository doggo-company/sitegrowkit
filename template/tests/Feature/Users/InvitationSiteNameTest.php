<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 外へ出る文面は、**案件の名前**で名乗る（WP 一周 10 周目 2026-09-04 / 効くもの ①）。
 *
 * ## 何が起きていたか
 *
 * サイト名を「沼津どうぶつ病院」にしたあと受付の人を招待すると、
 * 届く件名は **「【SiteGrowKit】管理画面へ招待されました」** だった。
 * 同じ受信箱の 1 行下では、問い合わせが「【沼津どうぶつ病院】」で届いている。
 *
 * 招待メールだけが `config('app.name')`（**製品名**）を直に読んでいた。
 * 件名・本文・署名で **4 回**出るので、心当たりの無い人はフィッシングだと思う。
 *
 * 🔴 **5 歩の 1 歩目が、そう約束している。**
 * 「案件の名前に変えると、**画面にもメールにも反映されます**」（`App\Support\FirstSteps`）。
 * 守られていない約束は、動かない機能より危ない。
 */
final class InvitationSiteNameTest extends TestCase
{
    use RefreshDatabase;

    /** 🔴 これが本体 */
    public function test_招待メールは案件の名前で名乗る(): void
    {
        app(SettingsStore::class)->put(['site_name' => '沼津どうぶつ病院']);

        $mail = $this->invitation();

        $this->assertStringContainsString('沼津どうぶつ病院', (string) $mail->subject);
        $this->assertStringNotContainsString('SiteGrowKit', (string) $mail->subject);
    }

    /**
     * 件名だけ直しても足りない。本文と署名にも出る。
     *
     * ⚠️ **見ているのはこの通知が書く文だけ**である。`render()` した HTML には
     * Laravel 標準のメール枠（ヘッダーの 1 行と footer の著作権表示）が付き、
     * そこは `config('app.name')` を出す。差し替えるにはテンプレートの publish が要るので、
     * この試験は**そこを見ていない**。見ていないことを隠さないために書いておく。
     */
    public function test_本文と署名も案件の名前(): void
    {
        app(SettingsStore::class)->put(['site_name' => '沼津どうぶつ病院']);

        $mail = $this->invitation();
        $written = implode("\n", [
            ...$mail->introLines,
            ...$mail->outroLines,
            (string) $mail->salutation,
            (string) $mail->greeting,
        ]);

        $this->assertStringContainsString('沼津どうぶつ病院', $written);
        $this->assertStringNotContainsString('SiteGrowKit', $written);
    }

    /** まだ決めていなければ製品名のまま（空欄で名乗らせない） */
    public function test_サイト名を決めていなければ製品名に戻る(): void
    {
        $mail = $this->invitation();

        $this->assertStringContainsString((string) config('app.name'), (string) $mail->subject);
    }

    /**
     * **次に足すメールで、同じことを起こさない。**
     *
     * 外へ出る文面（`app/Mail`・`app/Notifications`・module 配下の `Mail` と `Notifications`）が
     * `config('app.name')` を**直に**読んでいないことを固定する。
     * 名乗りは 1 か所（`SettingsStore` の `site_name`）から取る。
     *
     * 既定値として `$settings->get('site_name', config('app.name'))` の形で
     * 使うのは構わない — その形は `get(` と同じ行に現れる。
     */
    public function test_外へ出る文面は製品名を直に読まない(): void
    {
        $offenders = [];

        foreach (['app/Mail', 'app/Notifications', 'modules'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(base_path().'/', '', $file->getPathname());

                if ($dir === 'modules' && ! str_contains($relative, '/Mail/') && ! str_contains($relative, '/Notifications/')) {
                    continue;
                }

                foreach (explode("\n", File::get($file->getPathname())) as $number => $line) {
                    if (! str_contains($line, "config('app.name')")) {
                        continue;
                    }

                    // 既定値として渡す形（`get('site_name', config('app.name'))`）は許す
                    if (str_contains($line, 'site_name')) {
                        continue;
                    }

                    /*
                     * 説明の文章では拾わない（10 周目に 1 度これで落ちた）。
                     * 数えるのは**呼び出し**だけである。
                     */
                    $trimmed = ltrim($line);

                    if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                        continue;
                    }

                    $offenders[] = $relative.':'.($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "外へ出る文面が製品名を直に読んでいる。名乗りは SettingsStore の site_name から取る:\n".implode("\n", $offenders),
        );
    }

    private function invitation(): MailMessage
    {
        $user = User::factory()->create(['name' => '佐々木 受付']);

        return (new UserInvitation('probe-token', '管理者', '編集者'))->toMail($user);
    }
}

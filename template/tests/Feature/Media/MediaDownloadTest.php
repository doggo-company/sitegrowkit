<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Media\Models\Media;
use Tests\TestCase;

/**
 * 一般ファイルの配信（Phase 9.5-D / SPEC §3.2）。
 *
 * ここで守るのは 2 つ。**必ず保存として扱わせる**ことと、
 * **「無い」と「見せない」を同じ 404 にする**ことである。
 */
final class MediaDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /** @param array<string, mixed> $overrides */
    private function file(array $overrides = []): Media
    {
        $path = 'files/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.7\n%%EOF\n");

        return Media::query()->create(array_merge([
            'kind' => 'file',
            'visibility' => 'public',
            'disk' => 'local',
            'name' => '会社案内',
            'original_name' => '会社案内.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 16,
            'width' => null,
            'height' => null,
        ], $overrides));
    }

    private function image(): Media
    {
        Storage::disk('public')->put('media/photo.png', 'not really a png');

        return Media::query()->create([
            'name' => '写真',
            'path' => 'media/photo.png',
            'mime' => 'image/png',
            'size' => 16,
            'width' => 10,
            'height' => 10,
        ]);
    }

    private function assertHardenedHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringStartsWith(
            'attachment;',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    // --- public route ---------------------------------------------------

    public function test_a_public_file_downloads_as_an_attachment(): void
    {
        $media = $this->file();

        // ブラウザは必ず percent-encode して送る。生のまま入れると URI として弾かれる
        $response = $this->get("/media/file/{$media->id}/".rawurlencode('会社案内.pdf'));

        $response->assertOk();
        $this->assertHardenedHeaders($response);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->assertSame("%PDF-1.7\n%%EOF\n", $response->streamedContent());
    }

    public function test_a_japanese_name_survives_as_utf8_and_still_has_an_ascii_fallback(): void
    {
        $media = $this->file();

        $disposition = (string) $this->get("/media/file/{$media->id}/x.pdf")
            ->headers->get('Content-Disposition');

        // filename= だけだと日本語が壊れるので、両方を出す
        $this->assertStringContainsString(
            strtolower("filename*=UTF-8''".rawurlencode('会社案内.pdf')),
            strtolower($disposition),
        );
        // ASCII 版は代替名。日本語だけの名前からは何も残らないので media-<id> になる
        $this->assertMatchesRegularExpression('/filename="?media-\d+\.pdf"?/', $disposition);
    }

    public function test_the_name_in_the_url_is_decoration_and_the_id_decides(): void
    {
        $media = $this->file();

        $this->get("/media/file/{$media->id}/".rawurlencode('まったく違う名前.pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_extension_comes_from_what_we_stored_not_from_what_was_declared(): void
    {
        // 申告された名前は `.exe` だが、保存時に確定したのは `.pdf`
        $media = $this->file(['original_name' => 'invoice.exe']);

        $this->assertStringContainsString(
            'filename=invoice.pdf',
            (string) $this->get("/media/file/{$media->id}/x")->headers->get('Content-Disposition'),
        );
    }

    // --- 404 の形 --------------------------------------------------------

    /**
     * status も body も header も同じにする。
     *
     * 「無い」と「見せない」が見分けられると、ID を総当たりして
     * **どの ID が存在するか**を数え上げられてしまう。
     *
     * 所要時間までは揃えられない（DB を引くかどうかで必ず差が出る）ので、
     * そこは約束しない。代わりに **未認可は DB を引く前に落とす**（下のテスト）。
     */
    public function test_every_refusal_looks_exactly_the_same(): void
    {
        $private = $this->file(['visibility' => 'private']);
        $image = $this->image();
        $verified = $this->admin();
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $inactive = User::factory()->create(['email_verified_at' => now(), 'is_active' => false]);

        $refusals = [
            'public: 存在しない ID' => fn () => $this->get('/media/file/999999/x.pdf'),
            'public: 画像を要求' => fn () => $this->get("/media/file/{$image->id}/x.png"),
            'public: private を要求' => fn () => $this->get("/media/file/{$private->id}/x.pdf"),
            'admin: 未ログイン' => fn () => $this->get("/admin/media/file/{$private->id}"),
            'admin: メール未確認' => fn () => $this->actingAs($unverified)->get("/admin/media/file/{$private->id}"),
            'admin: 無効な利用者' => fn () => $this->actingAs($inactive)->get("/admin/media/file/{$private->id}"),
            'admin: 存在しない ID' => fn () => $this->actingAs($verified)->get('/admin/media/file/999999'),
            'admin: 画像を要求' => fn () => $this->actingAs($verified)->get("/admin/media/file/{$image->id}"),
        ];

        $reference = null;

        foreach ($refusals as $label => $call) {
            $fingerprint = $this->fingerprint($call());
            $reference ??= $fingerprint;

            $this->assertSame($reference, $fingerprint, "{$label} の 404 が他と区別できてしまう");
        }
    }

    /**
     * 未認可の呼び出しで **DB を引かない**。
     *
     * 引いてしまうと「存在する ID」と「しない ID」で所要時間が変わり、
     * 揃えたはずの 404 が意味を失う。
     */
    public function test_an_unauthorised_admin_request_never_touches_the_database(): void
    {
        $media = $this->file(['visibility' => 'private']);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get("/admin/media/file/{$media->id}")->assertNotFound();
        $this->get('/admin/media/file/999999')->assertNotFound();

        $this->assertSame(0, $queries, '未認可なのに DB を引いている');
    }

    /**
     * 応答の指紋。**揺れるものだけ**を落とす。
     *
     * `date` は秒で変わり、`set-cookie` は session ID を、`x-request-id` は
     * 相関 ID を含むので、値を比べても意味が無い
     * （値ではなく **在ることの有無**を見る）。
     *
     * @return array<string, mixed>
     */
    private function fingerprint(TestResponse $response): array
    {
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            if (in_array($name, ['date', 'set-cookie', 'x-request-id'], true)) {
                $headers[$name] = '<変わるので値は見ない>';

                continue;
            }

            /*
             * **CSP の nonce は応答ごとに変わる**（変わらなければ nonce の意味が無い）。
             * ここで見たいのは「**断り方が資源によって違わないか**」なので、
             * **政策は比べたまま、乱数のところだけ伏せる**。
             *
             * ヘッダーごと除くと、**政策が変わっても気づかなくなる**。
             */
            $headers[$name] = array_map(
                static fn (string $value): string => (string) preg_replace(
                    "/'nonce-[A-Za-z0-9+\/=_-]+'/",
                    "'nonce-<応答ごとに変わる>'",
                    $value,
                ),
                $values,
            );
        }

        ksort($headers);

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
            'headers' => $headers,
        ];
    }

    public function test_a_file_whose_bytes_are_gone_is_a_plain_404(): void
    {
        $media = $this->file();
        Storage::disk('local')->delete($media->path);

        $this->get("/media/file/{$media->id}/x.pdf")->assertNotFound();
    }

    // --- admin route -----------------------------------------------------

    public function test_the_admin_route_answers_a_guest_with_404_not_a_login_redirect(): void
    {
        $media = $this->file(['visibility' => 'private']);

        // redirect すると「この URL は在る」と教えてしまう
        $this->get("/admin/media/file/{$media->id}")->assertNotFound();
    }

    public function test_an_admin_can_open_a_private_file(): void
    {
        $media = $this->file(['visibility' => 'private']);

        $response = $this->actingAs($this->admin())->get("/admin/media/file/{$media->id}");

        $response->assertOk();
        $this->assertHardenedHeaders($response);
        // 共有キャッシュに残さない
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 世代の突き合わせが**空約束でない**ことを固定する。
     *
     * `actingAs` は本番のログインと同じく「その時点の世代」を session へ刻む
     * （`Tests\TestCase`）。権限が動いたあとに入り直していない session は、
     * `EnsureUserIsActive` と同じ理由でここでも通してはいけない。
     */
    public function test_a_session_from_before_a_permission_change_is_refused(): void
    {
        $media = $this->file(['visibility' => 'private']);
        $admin = $this->admin();

        $this->actingAs($admin);
        $this->get("/admin/media/file/{$media->id}")->assertOk();

        // 権限が動いた。**入り直していない**ので session の世代は古いまま
        $admin->forceFill(['session_epoch' => (int) $admin->session_epoch + 1])->save();

        $this->get("/admin/media/file/{$media->id}")->assertNotFound();
    }

    public function test_the_admin_route_still_refuses_an_image(): void
    {
        $image = $this->image();

        $this->actingAs($this->admin())
            ->get("/admin/media/file/{$image->id}")
            ->assertNotFound();
    }

    // --- picker ----------------------------------------------------------

    public function test_a_file_has_a_download_url_and_no_direct_url(): void
    {
        $picker = $this->file()->toPickerArray();

        $this->assertSame('file', $picker['kind']);
        $this->assertNull($picker['url'], 'local disk に url() を呼んではいけない');
        $this->assertStringContainsString('/media/file/', (string) $picker['download_url']);
        $this->assertSame([], $picker['variants']);
    }

    public function test_a_private_file_points_at_the_admin_route(): void
    {
        $picker = $this->file(['visibility' => 'private'])->toPickerArray();

        $this->assertStringContainsString('/admin/media/file/', (string) $picker['download_url']);
    }

    public function test_an_image_keeps_its_direct_url_and_gains_no_download_url(): void
    {
        $picker = $this->image()->toPickerArray();

        $this->assertSame('image', $picker['kind']);
        $this->assertNotNull($picker['url']);
        $this->assertNull($picker['download_url']);
    }
}

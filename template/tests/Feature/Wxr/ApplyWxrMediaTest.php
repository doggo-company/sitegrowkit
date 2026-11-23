<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Contracts\MediaIntake;
use AgentCms\Core\Wxr\Media\AddressGuard;
use AgentCms\Core\Wxr\Media\HostResolver;
use AgentCms\Core\Wxr\Media\MediaFailure;
use AgentCms\Core\Wxr\Media\RemoteFile;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * メディアを取り込む（Phase 9.14 / STAGE 27E-3・SPEC §C.6・ADR-0020）。
 *
 * ## 既定では外へ出ない
 *
 * `--media` を付けたときだけ取りに行く（OG5）。付けていない実行が
 * **1 度も接続しない**ことは、ここで固定する（DoD 12）。
 *
 * ## 名前ではなく、繋ぐ先で判定する
 *
 * 公開のホスト名がプライベート IP を返す形（DNS rebinding）は、
 * 名前で弾く実装では止まらない。**解決器を差し替えて**それを作る（DoD 13）。
 */
final class ApplyWxrMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-media-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active',
                'public_slug' => 'news',
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    // ── 既定では外へ出ない（DoD 12）───────────────────────

    public function test_mediaを付けなければ外へ1度も繋がない(): void
    {
        Http::fake();
        $this->resolveTo('93.184.216.34');

        $this->apply($this->wxrWithAttachment(), media: false);

        Http::assertNothingSent();

        // 添付は Media にも台帳にもならない
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame(0, DB::table('import_records')->where('kind', 'media')->count());

        // 本文の URL も元のまま
        $this->assertStringContainsString('https://example.com/wp/uploads/e.png', $this->body());
    }

    // ── 繋ぐ先の判定（DoD 13）─────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function blockedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private' => ['10.0.0.1'],
            'private 172' => ['172.16.0.1'],
            'private 192' => ['192.168.1.1'],
            'link-local（メタデータ）' => ['169.254.169.254'],
            'CGNAT' => ['100.64.0.1'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unique-local' => ['fc00::1'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv4-mapped IPv6' => ['::ffff:10.0.0.1'],
            /*
             * **IPv4 を埋め込む書き方は 1 つではない**（F-13）。
             *
             * どちらも中に `10.0.0.1` が入っている。攻撃側のゾーンが AAAA だけを
             * この形で返すと、**突き合わせる A が無い**まま内部へ届く。
             */
            'NAT64 に埋めた private' => ['64:ff9b::a00:1'],
            '6to4 に埋めた private' => ['2002:a00:1::1'],
            /*
             * **並べ方を増やしても追いつかない**（F-13b）。
             *
             * NAT64 の prefix は運用者が選べる（RFC 8215）ので、
             * 「危ない prefix を挙げる」形では**挙げ切れない**。
             * だから通す側を `2000::/3` に狭めた — 下 4 つはその外側か、
             * 内側に在って埋め込みだと分かっているものである。
             */
            'IPv4 互換 (private)' => ['::a00:1'],
            'IPv4 互換 (loopback)' => ['::7f00:1'],
            'NAT64 local-use' => ['64:ff9b:1::a00:1'],
            'Teredo' => ['2001:0:1:2:3:4:5:6'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_繋いではいけない相手には繋がない(string $address): void
    {
        Http::fake();

        [$pinned, $failure] = $this->guardFor($address)->allow('https://example.com/a.png');

        $this->assertNull($pinned, "{$address} へ繋ごうとしています");
        $this->assertSame(MediaFailure::Address, $failure);
    }

    /** @return array<string, array{0: string}> */
    public static function allowedAddresses(): array
    {
        return [
            '公開 IPv4' => ['93.184.216.34'],
            // `2000::/3` の中の、埋め込みでない普通の IPv6
            '公開 IPv6' => ['2606:2800:220:1::'],
            /*
             * **Teredo の隣にある、普通の公開アドレス。**
             *
             * これが無いと `2001::/32` を `2001::/16` と書き間違えても
             * **どのテストも気づかない** — 塞ぎすぎは静かに起きるからである。
             */
            'Teredo の隣の公開 IPv6' => ['2001:4860:4860::8888'],
            /*
             * **公開 IPv4 を IPv6 の書き方で渡された場合。**
             * 剥がしてから IPv4 として見るので通る — 狭めた結果ここまで
             * 落ちていないことを、明示的に押さえておく。
             */
            'IPv4-mapped な公開アドレス' => ['::ffff:93.184.216.34'],
        ];
    }

    #[DataProvider('allowedAddresses')]
    public function test_公開のアドレスには繋ぐ(string $address): void
    {
        [$pinned, $failure] = $this->guardFor($address)->allow('https://example.com/a.png');

        $this->assertSame($address, $pinned);
        $this->assertNull($failure);
    }

    /**
     * **1 つでも駄目なら全部駄目。**
     *
     * 良い IP が 1 つあればそこへ繋ぐ形は、公開 IP と内部 IP を同じ名前へ並べるだけで
     * 内部へ繋げる余地を残す（どちらへ繋ぐかは実装の都合で決まってしまう）。
     */
    public function test_公開と内部が並んでいたら繋がない(): void
    {
        [$pinned, $failure] = $this->guardFor('93.184.216.34', '10.0.0.1')->allow('https://example.com/a.png');

        $this->assertNull($pinned);
        $this->assertSame(MediaFailure::Address, $failure);
    }

    /** @return array<string, array{0: string}> */
    public static function badSchemes(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'data' => ['data:image/png;base64,AAAA'],
            'gopher' => ['gopher://example.com/1'],
        ];
    }

    #[DataProvider('badSchemes')]
    public function test_httpとhttps以外は入口ごと無い(string $url): void
    {
        [$pinned, $failure] = $this->guardFor('93.184.216.34')->allow($url);

        $this->assertNull($pinned);
        $this->assertSame(MediaFailure::Scheme, $failure);
    }

    /**
     * **接続 IP を固定していることの証明。**
     *
     * 判定の後にもう一度名前を引く実装なら、2 度目の答え（内部 IP）へ繋いでしまう。
     * ここでは 1 度目に公開 IP、2 度目以降に内部 IP を返す解決器を当てる。
     * **繋ぐ先は 1 度目の答えでなければならない。**
     */
    public function test_名前解決が後から変わっても最初に判定した_i_pへ繋ぐ(): void
    {
        Http::fake(['*' => Http::response('PNGDATA', 200)]);

        $resolver = new class implements HostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                // 2 度目からは内部を返す（rebinding）
                return [$this->calls === 1 ? '93.184.216.34' : '10.0.0.1'];
            }
        };

        $this->app->instance(HostResolver::class, $resolver);

        [$file, $failure] = app(RemoteFile::class)->fetch('https://example.com/a.png');

        $this->assertNull($failure);
        $this->assertNotNull($file);
        $file?->discard();

        // **1 度しか引いていない**（引き直せば rebinding が通る）
        $this->assertSame(1, $resolver->calls);

        Http::assertSent(function (Request $request): bool {
            $resolve = $request->toPsrRequest()->getUri()->getHost();

            return $resolve === 'example.com';
        });

        // 固定に使う 1 行そのものを押さえる
        $this->assertSame(
            'example.com:443:93.184.216.34',
            RemoteFile::pin('https://example.com/a.png', '93.184.216.34'),
        );
    }

    /**
     * **繋ぎ方の指定が、実際に curl へ渡る形で組み立てられている**（F-14）。
     *
     * `pin()` が正しい文字列を作ることは別に見ているが、**それが渡っていることは
     * 誰も見ていなかった**。部品は正しいのに繋がりを見ていない、という穴である。
     *
     * ここで押さえられるのは `options()` の中身までで、**`withOptions()` の呼び出しごと
     * 消せばこの試験は緑のままである**（呼ぶ場所は 1 か所なので穴は狭い）。
     */
    public function test_繋ぎ方の指定に固定と非追従が入る(): void
    {
        $options = RemoteFile::options('https://example.com/a.png', '93.184.216.34', '/tmp/x', 1024);

        // リダイレクトを追わない
        $this->assertFalse($options['allow_redirects']);

        // **判定した IP へ固定する**行がそのまま入っている
        $this->assertSame(
            ['example.com:443:93.184.216.34'],
            $options['curl'][CURLOPT_RESOLVE],
        );
    }

    /**
     * **大きさは、載せる前に止める**（SPEC §C.6）。
     *
     * かつては全部メモリへ載せてから大きさを見ていた。上限は 10 MB なのに、
     * **相手が 5 GB 返せば 5 GB 載ってから断っていた**。
     *
     * 止め方は 2 つ要る。`Content-Length` を宣言してこない相手には
     * `CURLOPT_MAXFILESIZE` が効かないので、**そこは進捗 callback でしか止まらない**。
     */
    public function test_大きさの守りが繋ぎ方の指定に入る(): void
    {
        $options = RemoteFile::options('https://example.com/a.png', '93.184.216.34', '/tmp/x', 4096);

        // 受け取った端からファイルへ流す（メモリに載せない）
        $this->assertSame('/tmp/x', $options['sink']);

        // 宣言してきた相手は、落とす前に断る
        $this->assertSame(4096, $options['curl'][CURLOPT_MAXFILESIZE]);

        // 宣言してこない相手は、流れている途中で断る
        $this->assertFalse($options['curl'][CURLOPT_NOPROGRESS]);
        $this->assertIsCallable($options['curl'][CURLOPT_XFERINFOFUNCTION]);
    }

    /**
     * **中止の判定そのもの**（curl を通さずに直接叩く）。
     *
     * 0 以外を返すと curl は転送を中止する。上限ちょうどでは止めない。
     */
    public function test_上限を超えたら中止を返す(): void
    {
        $abort = RemoteFile::options('https://example.com/a.png', '93.184.216.34', '/tmp/x', 100)['curl'][CURLOPT_XFERINFOFUNCTION];

        $this->assertSame(0, $abort(null, 0, 100), '上限ちょうどで止めています');
        $this->assertSame(1, $abort(null, 0, 101), '上限を超えても止めていません');

        // 宣言された大きさ（第 2 引数）ではなく、**実際に受け取った量**で見る
        $this->assertSame(0, $abort(null, 999_999, 1));
    }

    /**
     * **二段目の判定を直に当てる**（F-21 の後始末）。
     *
     * 一次は curl の `errno` で、実機ではそちらが必ず先に立つ。二段目の大きさ判定は
     * 「`errno` を取れない環境の備え」なので、**実機の経路からは踏めない**。
     * 純粋な関数なので、ここで直接呼んで固定する。
     *
     * 境界が `>=` なのは、宣言の無い応答が**上限ちょうどで止まる**からである
     * （`>` にしていたために「大きすぎた」が一度も出ていなかった）。
     */
    public function test_大きさの二段目は上限ちょうどで断る(): void
    {
        $this->assertTrue(RemoteFile::tooLarge(null, 1024, 1024), '上限ちょうどを通しています');
        $this->assertFalse(RemoteFile::tooLarge(null, 1023, 1024), '上限内を断っています');
    }

    public function test_大きすぎるものは断る(): void
    {
        config(['agentcms.uploads.max_kilobytes' => 1]);

        Http::fake(['*' => Http::response(str_repeat('x', 2048), 200)]);

        [$file, $failure] = app(RemoteFile::class)->fetch('https://example.com/big.png');

        $this->assertNull($file);
        $this->assertSame(MediaFailure::TooLarge, $failure);
    }

    /**
     * **使わない書きかけを置き去りにしない。**
     *
     * `sink` は 3xx や 404 の本文も書く。消さないと、取れなかった数だけ
     * 一時ファイルが積み上がる。
     */
    public function test_取れなかったときに書きかけを残さない(): void
    {
        $before = $this->tempMediaFiles();

        Http::fake(['*' => Http::response('not found', 404)]);
        app(RemoteFile::class)->fetch('https://example.com/missing.png');

        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/a.png'])]);
        app(RemoteFile::class)->fetch('https://example.com/moved.png');

        config(['agentcms.uploads.max_kilobytes' => 1]);
        Http::fake(['*' => Http::response(str_repeat('x', 2048), 200)]);
        app(RemoteFile::class)->fetch('https://example.com/big.png');

        $this->assertSame($before, $this->tempMediaFiles(), '書きかけの一時ファイルが残っています');
    }

    /** @return list<string> */
    private function tempMediaFiles(): array
    {
        $found = glob(sys_get_temp_dir().'/wxr-media-*') ?: [];

        sort($found);

        return $found;
    }

    public function test_リダイレクトは追わない(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/a.png'])]);
        $this->resolveTo('93.184.216.34');

        [$file, $failure] = app(RemoteFile::class)->fetch('https://example.com/a.png');

        $this->assertNull($file);
        $this->assertSame(MediaFailure::Redirected, $failure);
    }

    // ── 取り込みと本文の置換 ────────────────────────────────

    public function test_取り込めた画像は本文の_ur_lが差し替わる(): void
    {
        $this->fakePng();

        $this->apply($this->wxrWithAttachment());

        $media = DB::table('media')->first();
        $this->assertNotNull($media);

        $body = $this->body();
        $this->assertStringNotContainsString('https://example.com/wp/uploads/e.png', $body);
        $this->assertStringContainsString((string) $media->path, $body);

        // 台帳に所有として残る
        $record = DB::table('import_records')->where('kind', 'media')->first();
        $this->assertNotNull($record);
        $this->assertSame('501', $record->external_id);
        $this->assertTrue((bool) $record->created_by_import);
    }

    public function test_取り込めなかった_ur_lは元のまま残る(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->resolveTo('93.184.216.34');

        $this->apply($this->wxrWithAttachment());

        $this->assertSame(0, DB::table('media')->count());
        // **壊れたリンクを別の壊れたリンクに変えない**
        $this->assertStringContainsString('https://example.com/wp/uploads/e.png', $this->body());
    }

    public function test_繋いではいけない相手は符号で数えて本編は入る(): void
    {
        Http::fake();
        $this->resolveTo('10.0.0.1');

        $this->apply($this->wxrWithAttachment());

        // 記事は入っている（1 件の失敗で本編を止めない）
        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(0, DB::table('media')->count());

        $counts = (string) DB::table('import_batches')->value('counts');
        $this->assertStringContainsString(MediaFailure::Address->value, $counts);
    }

    /**
     * **中身と拡張子が食い違うものは入らない。**
     *
     * 名前は取得先が決めた文字列で、こちらが信じるのは実体である。
     */
    public function test_拡張子を偽った添付は入らない(): void
    {
        Http::fake(['*' => Http::response('<?php echo 1;', 200)]);
        $this->resolveTo('93.184.216.34');

        $this->apply($this->wxrWithAttachment());

        $this->assertSame(0, DB::table('media')->count());

        $counts = (string) DB::table('import_batches')->value('counts');
        $this->assertStringContainsString(MediaFailure::Rejected->value, $counts);
    }

    /**
     * **RED 8（2周方式）** — 取り込んだ直後の記事が取り消せること。
     *
     * 記事を作った後で本文を差し替える実装だと `UpdateContent` を通って
     * 投稿の行の `updated_at` が動き、取り消しの (a) が「人が触った」と読む。
     * **その実装ではここが赤くなる。**
     */
    public function test_メディアを取り込んだ直後の記事も取り消せる(): void
    {
        $this->fakePng();

        $this->apply($this->wxrWithAttachment());

        $id = (string) DB::table('import_batches')->value('id');

        $this->artisan('agentcms:wxr-rollback', ['batch' => $id, '--i-have-a-backup' => true])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame(0, DB::table('import_records')->count());
    }

    public function test_口が無ければ黙って飛ばさずに止まる(): void
    {
        $this->fakePng();

        // module を配っていない生成サイトを模す
        $this->app->offsetUnset(MediaIntake::class);
        $this->app->bind(MediaIntake::class, fn () => null);

        $this->applyCommand($this->wxrWithAttachment(), media: true)->assertExitCode(1);

        $this->assertSame(0, DB::table('media')->count());
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function guardFor(string ...$addresses): AddressGuard
    {
        return new AddressGuard(new class($addresses) implements HostResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        });
    }

    private function resolveTo(string ...$addresses): void
    {
        $this->app->instance(HostResolver::class, new class($addresses) implements HostResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        });
    }

    /** 1x1 の PNG を返す偽の相手 */
    private function fakePng(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        Http::fake(['*' => Http::response((string) $png, 200, ['Content-Type' => 'image/png'])]);
        $this->resolveTo('93.184.216.34');
    }

    private function body(): string
    {
        return (string) DB::table('news')->value('body');
    }

    private function wxrWithAttachment(): string
    {
        $item = '<item><title>記事 11</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>kiji-11</wp:post_name>'
            .'<content:encoded><![CDATA[本文 <img src="https://example.com/wp/uploads/e.png"> 続き]]></content:encoded>'
            .'</item>';

        $attachment = '<item><title>e.png</title><wp:post_id>501</wp:post_id>'
            .'<wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status>'
            .'<wp:attachment_url>https://example.com/wp/uploads/e.png</wp:attachment_url>'
            .'</item>';

        return $this->write('a.xml', $this->wxr($item.$attachment));
    }

    private function apply(string $path, bool $media = true): void
    {
        $this->applyCommand($path, $media)->assertExitCode(0);
    }

    private function applyCommand(string $path, bool $media): PendingCommand
    {
        $options = [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => (string) app(PlanBuilder::class)
                ->build($path, ImportOptions::fromCli([], [], $media))
                ->fingerprint(),
        ];

        if ($media) {
            $options['--media'] = true;
        }

        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-apply', $options);
    }

    private function wxr(string $body = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}

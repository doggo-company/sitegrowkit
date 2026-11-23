<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Media\AddressGuard;
use AgentCms\Core\Wxr\Media\FetchedFile;
use AgentCms\Core\Wxr\Media\HostResolver;
use AgentCms\Core\Wxr\Media\MediaFailure;
use AgentCms\Core\Wxr\Media\RemoteFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 大きさの守りが**本物の curl で効く**こと（Phase 9.14 / SPEC §C.6）。
 *
 * ## なぜ別の試験が要るのか
 *
 * `Http::fake()` は curl を通らない。`CURLOPT_MAXFILESIZE` も進捗 callback も
 * **一度も呼ばれない**ので、これまでは「指定が入っている」までしか確かめられなかった。
 * 今日 F-14 で起きたのがまさにこの形である — `options()` は正しいのに、
 * それが curl へ渡って**効いている**ことを誰も見ていなかった。
 *
 * ## `fetch()` を通す（**2 つの半分を出会わせる**）
 *
 * 最初はここで `options()` だけを本物の curl へ当て、`fetch()` は通していなかった。
 * その結果、**「curl が止まること」と「利用者に何が返るか」が別々の試験に分かれ**、
 * その隙間に「上限超過が `TooLarge` にならない」が丸ごと入っていた（F-21）。
 * fake 側は例外を通らないので `filesize` の再検査で `TooLarge` に届き、
 * 本物側は `MediaFailure` を見ていなかった。**どちらの半分も緑だった。**
 *
 * だから `fetch()` を通す。`AddressGuard` が `127.0.0.1` を塞ぐ壁は、
 * **`HostResolver` と同じ constructor 注入の seam** で越える
 * （`allowPrivate` は既定 `false` のまま・`final` も保つ）。
 *
 * ## 既定では走らない
 *
 * PHP の組み込みサーバーを立てるので、`WXR_MEDIA_LIMIT=1` を明示したときだけ走る
 * （`WXR_BENCHMARK` と同じ作法）。ポートが塞がっている環境で**本編のゲートを
 * 落とさない**ためである。
 *
 * ```
 * WXR_MEDIA_LIMIT=1 ./vendor/bin/pest tests/Feature/Wxr/RemoteFileLimitTest.php
 * ```
 */
final class RemoteFileLimitTest extends TestCase
{
    /** 1 MB。fixture は 12.5 MB を流してくる */
    private const MAX_BYTES = 1024 * 1024;

    private ?Process $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('WXR_MEDIA_LIMIT') !== '1') {
            $this->markTestSkipped('WXR_MEDIA_LIMIT=1 のときだけ走ります（本物の curl で当てる）');
        }

        $this->port = $this->freePort();

        $this->server = new Process([
            PHP_BINARY, '-S', '127.0.0.1:'.$this->port,
            base_path('tests/fixtures/media-server/router.php'),
        ]);

        $this->server->start();
        $this->waitForServer();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();

        parent::tearDown();
    }

    /**
     * `Content-Length` を宣言してくる相手は、**落とす前に**切れる。
     *
     * そして利用者には**「大きすぎた」と伝わる**。ここが `Fetch`（取れなかった）に
     * 落ちていたのが F-21 である。
     */
    public function test_宣言のある巨大な応答は落とす前に切れる(): void
    {
        [$file, $failure] = $this->fetchThrough('declared');

        $this->assertNull($file);
        $this->assertSame(MediaFailure::TooLarge, $failure, '大きすぎたことが伝わっていません');
    }

    /**
     * **大きさを宣言してこない相手も止まる。**
     *
     * 当初は「ここは進捗 callback でしか止まらない」と考えていたが、実測すると
     * この libcurl（8.7.1）では `CURLOPT_MAXFILESIZE` だけでも止まった。
     * 違うのは**落ちる量**で、宣言があれば 0 バイト、無ければ上限ちょうどまで落ちる。
     *
     * だからこの試験は「どちらの守りが効いたか」を区別しない。見ているのは
     * **12.5 MB が丸ごと落ちてこないこと**である（`RemoteFile` の docblock 参照）。
     */
    public function test_宣言のない巨大な応答も途中で切れる(): void
    {
        [$file, $failure] = $this->fetchThrough('chunked');

        $this->assertNull($file);
        $this->assertSame(MediaFailure::TooLarge, $failure, '大きすぎたことが伝わっていません');
    }

    /** **止めてはいけない側。** 上限内のものは普通に取れる */
    public function test_上限内の応答は取れる(): void
    {
        [$file, $failure] = $this->fetchThrough('small');

        $this->assertNull($failure, '上限内の応答を止めています');
        $this->assertNotNull($file);
        $this->assertSame(1024, filesize($file->path));

        $file->discard();
    }

    /** **書きかけを残さない**（本物の中止でも） */
    public function test_中止しても書きかけを残さない(): void
    {
        $before = glob(sys_get_temp_dir().'/wxr-media-*') ?: [];

        $this->fetchThrough('declared');
        $this->fetchThrough('chunked');

        $this->assertSame($before, glob(sys_get_temp_dir().'/wxr-media-*') ?: []);
    }

    /**
     * **`fetch()` を通して**取りに行く。
     *
     * @return array{0: FetchedFile|null, 1: MediaFailure|null}
     */
    private function fetchThrough(string $path): array
    {
        config(['agentcms.uploads.max_kilobytes' => self::MAX_BYTES / 1024]);

        /*
         * 組み込みサーバーは `127.0.0.1` にしか立たない。**住所の守りは緩めず**、
         * 既定 `false` の seam を試験からだけ開ける（`AddressGuardSeamTest` が
         * 「開けているのは試験だけ」を固定している）。
         */
        $guard = new AddressGuard(new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        }, allowPrivate: true);

        return (new RemoteFile($guard))->fetch("http://127.0.0.1:{$this->port}/{$path}");
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        $this->assertNotFalse($socket, "空きポートが取れません: {$error}");

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitForServer(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        $this->fail('組み込みサーバーが立ち上がりません');
    }
}

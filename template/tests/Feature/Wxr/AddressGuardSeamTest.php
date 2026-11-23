<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Media\AddressGuard;
use AgentCms\Core\Wxr\Media\HostResolver;
use AgentCms\Core\Wxr\Media\MediaFailure;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 試験のために開けた穴が、**本番の経路から開かないこと**（Phase 9.14 / F-21）。
 *
 * `AddressGuard` に `allowPrivate` を足したのは、取得の守りを**本物の curl で
 * 当てる**ためである。組み込みサーバーは `127.0.0.1` にしか立たないので、
 * ここを通れないと「curl の指定が効くか」と「利用者に何が返るか」が
 * 別々の試験に分かれたままになる（それで F-21 を取り逃した）。
 *
 * **穴を開けた以上、開けっぱなしにならないことを固定する。**
 */
final class AddressGuardSeamTest extends TestCase
{
    /** 既定は塞がったまま */
    public function test_既定では内部アドレスへ繋がない(): void
    {
        $guard = new AddressGuard($this->resolvingTo('127.0.0.1'));

        [$address, $failure] = $guard->allow('http://localhost/a.png');

        $this->assertNull($address);
        $this->assertSame(MediaFailure::Address, $failure);
    }

    /** 開けたときだけ通る（試験がこれに頼っている） */
    public function test_明示したときだけ内部アドレスへ繋ぐ(): void
    {
        $guard = new AddressGuard($this->resolvingTo('127.0.0.1'), allowPrivate: true);

        [$address, $failure] = $guard->allow('http://localhost/a.png');

        $this->assertSame('127.0.0.1', $address);
        $this->assertNull($failure);
    }

    /**
     * **開けているのは試験だけ。**
     *
     * 本番のコードが `allowPrivate: true` を渡し始めたら、SSRF の守りが
     * 黙って外れる。名前で探せるうちに固定しておく。
     */
    public function test_本番の経路は穴を開けていない(): void
    {
        $files = Finder::create()
            ->files()
            ->in([base_path('core/src'), base_path('app'), base_path('modules')])
            ->name('*.php');

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                'allowPrivate: true',
                $file->getContents(),
                "{$file->getRelativePathname()} が住所の守りを外しています",
            );
        }
    }

    private function resolvingTo(string $address): HostResolver
    {
        return new class($address) implements HostResolver
        {
            public function __construct(private readonly string $address) {}

            /** @return list<string> */
            public function resolve(string $host): array
            {
                return [$this->address];
            }
        };
    }
}

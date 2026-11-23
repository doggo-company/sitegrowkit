<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Media;

/**
 * ホスト名を IP へ解いた結果（Phase 9.14 / STAGE 27E-3・SPEC §C.6）。
 *
 * ## 差し替えられる形にしておく
 *
 * SSRF の要は「**検査した IP へ接続する**」ことで、それが働いているかは
 * **名前解決が検査の後で別の答えを返す**状況（DNS rebinding）でしか確かめられない。
 * 本物の DNS ではその状況を作れないので、解決だけを差し替えられるようにしてある。
 *
 * 差し替え点を用意しないと、**ホスト名で弾いているだけの実装でも
 * プライベート IP を拒む試験は緑になる**。
 */
interface HostResolver
{
    /**
     * そのホストの IP。引けなければ空。
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}

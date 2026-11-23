<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use Illuminate\Contracts\Routing\Registrar;

/**
 * その投稿タイプに **公開面があるか**（Phase 9.8 / STAGE 21C・SPEC §A.1.1）。
 *
 * 公開状態を持たない型を復元してよいかは、これで決まる。公開面が無ければ
 * 戻しても誰にも見えないので安全だが、公開面があれば**戻した瞬間に公開される**。
 *
 * 公開面は 2 通りある。**両方見ないと判断を誤る。**
 *
 * | 経路 | 例 |
 * | --- | --- |
 * | 型の設定で公開している | 9.7 の公開スラッグ（`/blog/...`）。判定は `ContentTypeLifecyclePolicy` |
 * | module が自前の route を持つ | `news.show` / `works.show` のような同梱の公開面 |
 *
 * **型名で分岐しない。** 後者は「`<型>.show` という route が在るか」で見る。
 */
final class PublicSurface
{
    public function __construct(
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly Registrar $router,
    ) {}

    public function exists(string $type): bool
    {
        return $this->lifecycle->isPublic($type) || $this->router->has($type.'.show');
    }
}

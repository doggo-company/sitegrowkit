<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

/**
 * 項目が指している先の**いまの状態**（Phase 9.9 / STAGE 22C）。
 *
 * ページと投稿では列も公開条件も違うが、メニューから見て要るのは
 * 「URL を作れるか」「名前は何か」「出してよいか」の 3 つだけである。
 * 生の行をそのまま持ち回すと、呼ぶ側が列の有無を毎回気にすることになるので、
 * **引いた場所で 1 度だけ**この形へ寄せる。
 */
final readonly class MenuLinkTarget
{
    public function __construct(
        public string $slug,
        public string $title,
        /** ゴミ箱にあるか（「無い」とは違う。戻せば戻る） */
        public bool $trashed,
        /** 公開面から見えるか */
        public bool $published,
        /** 公開予約中か（`published` は false のまま。理由の文言だけが変わる） */
        public bool $scheduled = false,
    ) {}
}

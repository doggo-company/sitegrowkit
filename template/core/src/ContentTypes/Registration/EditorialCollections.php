<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\Schema\ContentSchema;

/**
 * 「投稿タイプ」の判定（Phase 9.7 / SPEC §B.1・§B.2）。
 *
 * **ここが唯一の判定である。** 画面ごとに `media` を除く条件を書かない。
 *
 * `kind === 'collection'` だけでは足りない。`media` は保存方式の都合で collection だが、
 * 実体は**メディアライブラリ**であって、利用者が増減させる投稿タイプではない
 * （SPEC §B.1 の ① system facility）。ここを混ぜると
 * 「メディアライブラリを archive する」が実装可能に見えてしまう。
 */
final class EditorialCollections
{
    /**
     * CMS そのものの機能であって、投稿タイプではない schema。
     *
     * `contact`（受信箱）と `site`（サイト設定）は `kind` で既に外れるが、
     * **意図を 1 か所に残すために並べておく**。`media` だけが `kind` で外れない。
     *
     * 将来 schema 側に印を持たせるなら、この定数を置き換える（判定は 1 か所のままにする）。
     *
     * @var list<string>
     */
    public const SYSTEM_FACILITY = ['media', 'contact', 'site'];

    public static function isEditorial(ContentSchema $schema): bool
    {
        return $schema->kind === 'collection'
            && ! in_array($schema->id, self::SYSTEM_FACILITY, true);
    }

    /** 管理画面から構造と lifecycle を変えられるのは cms 管理の投稿タイプだけ（SPEC §B.3） */
    public static function isManageable(ContentSchema $schema): bool
    {
        return self::isEditorial($schema) && $schema->managedBy === ContentSchema::MANAGED_BY_CMS;
    }

    /**
     * 並べ替えの対象（SPEC §J.2）。
     *
     * **code 管理も含めて 1 本の order で並べる** — 利用者から見て「型は型」であり、
     * 2 系統に割ると説明できない。並び順は schema の構造を 1 バイトも変えない。
     */
    public static function isReorderable(ContentSchema $schema): bool
    {
        return self::isEditorial($schema);
    }
}

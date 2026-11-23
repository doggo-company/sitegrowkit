<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\WxrItem;

/**
 * その item をどこへ入れるか（Phase 9.14 / STAGE 27D-3・SPEC §C.1・§C.2・§C.6）。
 *
 * ## 判定を 2 か所に持たない
 *
 * `plan` と `apply` は**同じ item を同じ先へ**入れなければならない。
 * それぞれが自前で決めると、片方だけ直したときに
 * **plan が言ったのと違う場所へ入る**。plan の照合はその食い違いを見つけられない
 * （hash は plan 側の判断から作られるので、両方が同じ誤りをすれば一致してしまう）。
 *
 * だから決めるのはここ 1 か所で、両方がここを呼ぶ。
 *
 * ## 理由は数えない
 *
 * ここは「どこへ入るか」だけを答える。入らない理由を数えるのは `plan` の仕事で、
 * `apply` はもう数えない（plan が全部出したうえで利用者が承認している）。
 * 数える側をここへ入れると、**apply が同じ理由を二度数える**ことになる。
 */
final class ItemTarget
{
    /**
     * **仕様として入れないと決まっている** post_type（§A.2・§C.6）。
     *
     * @var array<string, PlanIssue>
     */
    public const EXCLUDED_TYPES = [
        'nav_menu_item' => PlanIssue::SkippedNavMenuItem,
    ];

    /** 添付は投稿タイプではなく Media として扱う（§C.1） */
    public const ATTACHMENT_TYPE = 'attachment';

    /**
     * ページは投稿タイプではなく Page として扱う（release-20260909 / wxr-pages）。
     *
     * `--type=page:xxx` で別の型へ向けることはできない — ページの入る先は
     * `pages` / `page_versions` に固定で、記事の型には入らない。
     */
    public const PAGE_TYPE = 'page';

    /** 台帳での Media の行き先（`target_type`） */
    public const MEDIA_TARGET = 'media';

    /** 台帳での Page の行き先（`target_type`） */
    public const PAGE_TARGET = 'page';

    /** 台帳の桁（`import_records.external_id` / `target_type`） */
    public const LEDGER_COLUMN_BYTES = 64;

    public function __construct(private readonly ContentTypeResolver $types) {}

    /**
     * 入る先。**入らないなら `null`。**
     *
     * `null` の理由は返さない — 理由を数えるのは `plan` の仕事だからである
     * （`PlanBuilder::scanItem()` が同じ順で確かめて数える）。
     *
     * @return array{kind: ImportKind, target: string}|null
     */
    public function of(WxrItem $item, ImportOptions $options): ?array
    {
        $sourceType = $item->postType;

        if ($sourceType === self::ATTACHMENT_TYPE) {
            // `--media` を付けたときだけ取りに行く（§C.6）
            return $options->media
                ? ['kind' => ImportKind::Media, 'target' => self::MEDIA_TARGET]
                : null;
        }

        if (array_key_exists($sourceType, self::EXCLUDED_TYPES)) {
            return null;
        }

        if ($sourceType === self::PAGE_TYPE) {
            // ページは型を探さない。入る先は 1 つに固定（`--type` の指定は効かない）
            return strlen($item->postId) > self::LEDGER_COLUMN_BYTES
                ? null
                : ['kind' => ImportKind::Page, 'target' => self::PAGE_TARGET];
        }

        // 明示の対応が無ければ**同じ名前の型**を探す（§C.2）
        $target = $options->types[$sourceType] ?? $sourceType;

        if (! $this->types->knows($target)) {
            return null;
        }

        if (strlen($item->postId) > self::LEDGER_COLUMN_BYTES) {
            return null;
        }

        if (strlen($target) > self::LEDGER_COLUMN_BYTES) {
            return null;
        }

        return ['kind' => ImportKind::Content, 'target' => $target];
    }
}

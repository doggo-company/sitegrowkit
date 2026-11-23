<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

/**
 * その版が生まれた理由（Phase 9.8 / STAGE 21D・SPEC §F.1）。
 *
 * **`baseline` を `create` と書かない。** その版は実際の作成操作ではないので、
 * `create` と書くと「この日時に作られた」と履歴が嘘をつく。
 * `baseline` は「導入した時点の姿を記録しただけ」という意味である。
 */
enum RevisionReason: string
{
    /** 版の仕組みを入れる前から在った投稿の初版 */
    case Baseline = 'baseline';

    case Create = 'create';

    case Update = 'update';

    /** 下書き → 公開 */
    case Publish = 'publish';

    /** 公開 → 下書き（ゴミ箱からの復元で下書きへ戻す場合を含む） */
    case Unpublish = 'unpublish';

    /** 過去の版へ戻した結果の版 */
    case RevisionRestore = 'revision_restore';
}

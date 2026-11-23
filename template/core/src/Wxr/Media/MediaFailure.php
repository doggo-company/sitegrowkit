<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Media;

/**
 * メディアを取り込めなかった理由（Phase 9.14 / STAGE 27E-3・SPEC §C.6・§A.3）。
 *
 * ## 理由は固定の符号で数える
 *
 * **例外の文面を数えない。** 他人のサイトの URL やサーバーの応答本文が
 * 報告と監査へ流れ込む経路になる。符号だけなら、何が起きたかは伝わり、
 * 中身は流れない（§E.2 と同じ作法）。
 *
 * ## 1 件の失敗で本編を止めない
 *
 * 記事は既に入っている（§C.6）。落ちたものは数えて続け、
 * **本文の URL は元のまま残す** — 壊れたリンクを別の壊れたリンクに変えない。
 */
enum MediaFailure: string
{
    /** 取得先が http/https ではない（`file://` `data:` など） */
    case Scheme = 'media_bad_scheme';

    /** 解決した IP が loopback / private / link-local などだった（SSRF） */
    case Address = 'media_blocked_address';

    /** 3xx が返った。**追わない**（追えば追った先で再検査が要る） */
    case Redirected = 'media_redirected';

    /** 上限を超える大きさだった */
    case TooLarge = 'media_too_large';

    /** 繋がらない・時間切れ・2xx 以外 */
    case Fetch = 'media_fetch_failed';

    /** 取れたが、既存の upload 経路が受け付けなかった（拡張子と中身の食い違い・SVG など） */
    case Rejected = 'media_rejected_by_upload';
}

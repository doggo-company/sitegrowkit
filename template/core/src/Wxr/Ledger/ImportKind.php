<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

/**
 * 台帳が覚えている記録の種類（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 「何の記録か」だけを足す。所有権は足さない
 *
 * 「重なった定義」「使い回した対象」を種類として足したくなるが、
 * それは**何の記録かではなく、その対象を誰が作ったか**の話で軸が違う。
 * ここへ混ぜると `unique(kind, target_type, external_id)` の意味まで変わり、
 * 冪等性の鍵が別物になる。所有権は `import_records.created_by_import` が持つ。
 *
 * `Page`（release-20260909）は**記事と別の表（`pages` / `page_versions`）へ入る
 * 別の記録**なので、この軸に沿う。`Content` に `target_type = 'page'` で混ぜると、
 * 利用者が `page` という名前の型を作った日に鍵がぶつかる。
 *
 * ## WordPress の id は種類ごとに振られる
 *
 * 記事の 101 と分類項目の 101 は別物である。だから鍵には必ず種類が要る。
 * ページと記事は同じ `wp:post_id` の空間だが、こちらの入る先が違うので
 * 種類を分けて持つ。
 */
enum ImportKind: string
{
    /** 記事（`wp:post_id`） */
    case Content = 'content';

    /** ページ（`wp:post_id`・`post_type = page`）。`pages` / `page_versions` へ入る */
    case Page = 'page';

    /** 分類項目（`wp:term_id`） */
    case Term = 'term';

    /** コメント（`wp:comment_id`） */
    case Comment = 'comment';

    /** 添付（`--media` を付けたときだけ） */
    case Media = 'media';
}

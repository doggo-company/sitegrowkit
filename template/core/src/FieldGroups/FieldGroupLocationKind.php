<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

/**
 * Field Group の割当先の種類（Phase 9.5-A / ADR-0015 G4・G11）。
 *
 * **9.5-A はこの 2 値だけ。** `page_template` は enum に入れない。
 * binding の型整合を Visual Page Editor（P0-C5 / `docs/PHASE9_6_SPEC.md` §4.9）と
 * 同時に決めるほうが安全なため、予約語として文書に書くだけで
 * 実行時にも UI にも出さない。
 *
 * enum に無い kind（`page_template` を含む）は
 * **どの割当先にも一致しない**（fail closed）。推測して当てない。
 */
enum FieldGroupLocationKind: string
{
    /** その投稿タイプの編集画面に Field が出る */
    case ContentType = 'content_type';

    /**
     * そのフォームの入力項目になる。
     *
     * 9.5-A では **受理して一覧に表示するだけ**で、実行面（保存・検証・通知）を作らない。
     * content と form は Field 定義だけを共用する（G9）。
     */
    case Form = 'form';

    public function label(): string
    {
        return match ($this) {
            self::ContentType => '投稿タイプ',
            self::Form => 'フォーム',
        };
    }
}

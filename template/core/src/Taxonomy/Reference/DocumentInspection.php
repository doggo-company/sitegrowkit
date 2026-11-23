<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

/**
 * PageVersion 1 件分の構造確認結果（Phase 9.3B / STAGE 2A CORRECTION #2 B）。
 *
 * **一致を見つけても走査を止めない**ため、
 * 「参照が見つかった」と「document 全体を確定できたか」を**独立に**持つ。
 *
 * 同一 document の前半に正常な参照があり、後半が壊れている場合は
 * `matched = true` かつ `malformed !== null` になる。
 * このとき参照は数えつつ、report 全体は未完了（complete = false）にする。
 */
final class DocumentInspection
{
    public bool $matched = false;

    /** 構造確認を完了できなかった理由（日本語）。null なら全体を確定できた */
    public ?string $malformed = null;

    /** 最初の 1 件だけ残す（利用者向けメッセージは件数で十分なため） */
    public function markMalformed(string $reason): void
    {
        $this->malformed ??= $reason;
    }
}

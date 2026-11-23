<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Trash\TrashColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * 楽観ロック（Phase 9.8 / STAGE 21C・Owner Decision OG11）。
 *
 * ## なぜ「未指定を許す」のか
 *
 * 版を即座に必須にすると、**既存の管理画面と MCP が同時に落ちる**。
 * 移行の期間だけ未指定を通し、**指定されていれば必ず検証する**。
 * 「送ったのに見られていない」が起きないことが要点で、
 * 送らない側は 21F の受入まで猶予する（そのあと必須化）。
 *
 * ## 何を返さないか
 *
 * 競合したことは伝えるが、**誰が更新したかは返さない**。
 * 「田中さんが更新しました」は、その人が何を見ているかを漏らす。
 */
final class EntryLockVersion
{
    /** 競合したときの文言（OG11 で確定。誰が、は出さない） */
    public const CONFLICT_MESSAGE = '他の人がこのコンテンツを更新しました。最新の内容を読み込み直してください';

    /** 版を指定せずに書いたときの監査上の印 */
    public const UNSPECIFIED_AUDIT = '版の指定なし';

    /**
     * 入力から版を読む。**数値でなければ「指定なし」として扱う**。
     *
     * 空文字や null を 0 に丸めない — 0 は「初版」という**実在する値**で、
     * 「指定していない」とは意味が違う。
     */
    public static function fromInput(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** その行のいまの版（列がまだ無い型では 0 として扱う） */
    public static function of(Model $model): int
    {
        $value = $model->getAttribute(TrashColumns::LOCK_VERSION);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * 指定された版が現在と一致するか。
     *
     * **未指定は通す**（移行期間中）。指定されていれば必ず見る。
     */
    public static function matches(Model $model, ?int $expected): bool
    {
        return $expected === null || $expected === self::of($model);
    }

    /**
     * 次の版へ進める。
     *
     * **同じ transaction の中で、ロックした行に対して呼ぶこと。**
     * 読んでから書くまでのあいだに他が入ると、版が飛ぶ。
     */
    public static function advance(Model $model): int
    {
        $next = self::of($model) + 1;

        $model->setAttribute(TrashColumns::LOCK_VERSION, $next);

        return $next;
    }

    /**
     * 監査へ残す最小限。
     *
     * 版そのものと、指定されていなかったという事実だけ。本文も個人情報も入れない。
     *
     * @return array<string, mixed>
     */
    public static function auditMetadata(?int $requested, int $current): array
    {
        $meta = ['lock_version' => $current];

        if ($requested === null) {
            // 移行の進み具合はここでしか測れない
            $meta['lock_version_note'] = self::UNSPECIFIED_AUDIT;
        }

        return $meta;
    }
}

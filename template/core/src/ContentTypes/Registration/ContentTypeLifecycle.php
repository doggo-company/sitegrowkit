<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

/**
 * 投稿タイプの**登録の進行状態**（Phase 9.7 / SPEC §F.1 / §F.3.2 / §I.3）。
 *
 * システムが動かす軸である。`DROP TABLE` も `Schema::create` も暗黙 commit を起こすので、
 * 作成と撤去は「1 つの transaction」では書けない。代わりに**段階を持たせて冪等にする**。
 * この enum はその段階を表す。
 */
enum ContentTypeLifecycle: string
{
    /** 使える。作成は完了し、撤去は始まっていない */
    case Ready = 'ready';

    /** 作成が完了していない（登録行はあるがテーブルがまだ無いかもしれない）。やり直せる */
    case Provisioning = 'provisioning';

    /** 撤去の途中。**読み書きとも不可で、復元できない**。続きから再開する */
    case Deprovisioning = 'deprovisioning';

    /** 撤去が完了した。定義もテーブルも無い。**監査のための痕跡だけが残る** */
    case Tombstoned = 'tombstoned';

    /**
     * 保存された値を読む。**未知の値は `Deprovisioning` として扱う**（fail-closed / SPEC §J.4）。
     *
     * 未知の状態を `Ready` に倒すと、途中で落ちた型へ書けてしまう。
     */
    public static function fromStorage(string $raw): self
    {
        return self::tryFrom($raw) ?? self::Deprovisioning;
    }

    /** 保存された値が既知か（診断用） */
    public static function recognizes(string $raw): bool
    {
        return self::tryFrom($raw) !== null;
    }

    /**
     * 使える状態か。
     *
     * **`Ready` 以外は、`status` が何であっても全能力を false にする**（SPEC §J.4）。
     */
    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

/**
 * 一般ファイルの公開範囲（Phase 9.5-D / ADR-0018・owner 決定 D4）。
 *
 * 既定は `public`（WordPress 相当の使い勝手）。ただし **public でも直リンクにしない** —
 * 配信は必ず controller を通り、`Content-Disposition: attachment` と
 * `X-Content-Type-Options: nosniff` が付く。
 *
 * `private` は認可済みだけが開ける。**未認可には 404 を返す**
 * （403 だと「その ID は存在する」と教えてしまう）。
 */
enum MediaVisibility: string
{
    case Public = 'public';

    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => '公開',
            self::Private => '管理者のみ',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'このファイルは URL を知っていれば誰でも開けます',
            self::Private => 'このファイルは管理者だけが開けます',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\Result;
use RuntimeException;

/**
 * `agentcms:import-page` のトランザクションの中で「断られた」ことを外へ運ぶ。
 *
 * 作成 → ブロック → 公開は 1 つの取引で行う（Codex 指摘 ④）。途中で断られたら
 * この例外で取引を巻き戻し、**半端なページを 1 行も残さない**。
 * 断りの中身（`Result`）はそのまま持ち帰り、呼び出し側が人に見せる形で出す。
 */
final class ImportPageRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?Result $result = null,
    ) {
        parent::__construct($reason);
    }
}

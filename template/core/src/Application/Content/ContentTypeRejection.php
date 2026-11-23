<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;

/**
 * `ContentTypeResolver::knows()` が false のときに返す `Result`
 * （Phase 9.3B / STAGE 3B CORRECTION・F-3B-2）。
 *
 * ## 2 つの contract を両立させる
 *
 * | 呼び出し | status | 根拠 |
 * | --- | --- | --- |
 * | **taxonomy payload あり** | **`validationError`**（key = `type`） | SPEC §G.3 CT-8 / DoD D-6 |
 * | taxonomy payload なし（従来の content 操作） | `notFound` | 既存の generic content contract |
 *
 * CT-8 は **taxonomy assignment の入力経路**に対する規則であり、
 * `GetContent` / `ListContent` / `DeleteContent` など通常の lookup semantics
 * （未知 type = 404）までは変えない。generic content API 全体を 422 にしない。
 *
 * どちらの分岐でも **`modelClass()` へ入力を渡さず、DB へ書かず、Audit も残さない**。
 * taxonomy payload の raw 値は**解析すらしない**（任意 class / table へ到達しない）。
 */
final class ContentTypeRejection
{
    /** 判定に使ったキー（Admin / API / MCP が同じキーでエラーを描画できるように固定する） */
    public const KEY = 'type';

    public static function of(string $type, ?TaxonomyPayload $taxonomies): Result
    {
        $message = "コンテンツタイプ [{$type}] は存在しません";

        if ($taxonomies === null) {
            return Result::notFound($message);
        }

        return Result::validationError(
            [self::KEY => [$message.'（分類を設定できるコンテンツタイプではありません）']],
            $message,
        );
    }
}

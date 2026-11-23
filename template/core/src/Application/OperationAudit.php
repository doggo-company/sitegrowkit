<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

use Throwable;

/**
 * 操作の結末を **1 か所で・ちょうど 1 回** 記録する（STAGE #18A / Correction 7）。
 *
 * ## なぜ helper を挟むのか
 *
 * これまで各 Application Service は「成功したときだけ」監査していた。
 * 入力エラー・権限なし・競合・確認待ちは記録されず、
 * 「誰が何を試みて止められたか」が残らなかった。
 *
 * かといって記録を増やすと、**二重記録**が起きやすい
 * （policy 側でも拒否を書き、service 側でも書く、など）。
 * そこで **結末を返す直前にここを必ず通す**という 1 本の規約にした。
 *
 * ```php
 * return $this->outcome->record($this, $context, Result::validationError($errors));
 * ```
 *
 * - `Read` は記録しない（量が多く、個人情報も増える — 既存方針のまま）
 * - **結末ごとに ちょうど 1 行**。確認待ちで返した試行と、確認トークン付きで
 *   成功した次の試行は **別の結末**なので 2 行になる（同じ結末を
 *   policy と service の両方から書かない、という意味の exactly once）
 * - 記録するのは **結果の種別と安全な要約だけ**。入力値・本文・秘密は渡さない
 * - `Result` をそのまま返すので、呼び出し側の分岐が増えない
 */
final class OperationAudit
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  OperationClassification|null  $classification  同じ operation の中で
     *                                                        危険度が枝分かれする場合だけ渡す
     *                                                        （例: `page.document.patch` の delete）
     */
    public function record(
        Operation $operation,
        OperationContext $context,
        Result $result,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
        ?OperationClassification $classification = null,
    ): Result {
        $this->recorder->record(
            $operation->operationId(),
            $classification ?? $operation->classification(),
            $context,
            $result,
            $resourceType,
            $resourceId,
            // **結末は `result` 列が持つ**。metadata へ重ねて書かない（Correction 7 / Q2）。
            // 失敗理由を残したい場合は、呼び出し側が PII を含まない固定コードを渡す
            $metadata,
        );

        return $result;
    }

    /**
     * 監査に失敗しても **巻き戻せない成果物を捨てない**ための記録（Correction 2-5）。
     *
     * テーブル作成やファイル生成のように、既に外の世界が変わってしまった操作では、
     * 「監査ログが書けなかった」を理由に成果物を消すほうが実害が大きい。
     * かといって黙って成功にもしない。**監査が欠けたという事実を meta へ添えて**
     * 返し、画面が警告として見せられるようにする。
     *
     * 巻き戻せる操作では使わないこと（既定は `record()`）。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordOrFlag(
        Operation $operation,
        OperationContext $context,
        Result $result,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
    ): Result {
        try {
            return $this->record($operation, $context, $result, $resourceType, $resourceId, $metadata);
        } catch (Throwable $e) {
            report($e);

            return $result->withMeta(['auditRecorded' => false]);
        }
    }
}

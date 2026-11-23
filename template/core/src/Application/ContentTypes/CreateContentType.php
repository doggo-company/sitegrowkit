<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\ContentTypeDraft;
use AgentCms\Core\ContentTypes\ContentTypeEnvironment;
use AgentCms\Core\ContentTypes\ContentTypeInstaller;
use AgentCms\Core\ContentTypes\ContentTypeInstallFailed;
use AgentCms\Core\ContentTypes\ContentTypePlanner;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Date;

/**
 * Content Type の新規作成（Phase 9.4 / STAGE #17A）。
 *
 * ## Preview → Confirm → Apply
 *
 * 1 回目（確認トークンなし）は**検証と計画だけ**を行い、
 * `ConfirmationRequired` と計画の中身を返す。**この時点でファイルも DB も変わらない**。
 * 2 回目に正しいトークンが提示されて初めて適用する。
 * トークンは `ConfirmationGate` が 1 回で使い切る（使い回し不可）。
 *
 * ## 今回できるのは「新規追加」だけ
 *
 * 既存 Content Type の編集・フィールド削除・型変更・削除は**実装しない**。
 * 破壊的スキーマ変更の既定動作は **拒否**（Owner Gate G4）。
 *
 * ## 経路の制限
 *
 * 分類は `ExternalEffect` で、`OperationCatalog` にも載せていない。
 * つまり **MCP / WebMCP からは到達できない**。ここではさらに
 * インターフェイスを Admin / CLI に限って二重に閉じる。
 *
 * ## 監査の落ち方
 *
 * この operation だけは全ての結末を `recordOrFlag()` で記録する（Correction 2-5）。
 * 監査ログが書けないことを理由に例外へ倒すと、**作成済みのテーブルと
 * ファイルが残ったまま「失敗しました」と表示される**という、
 * いちばん復旧しにくい見え方になる。記録できなければ
 * `meta.auditRecorded = false` を添えて返し、画面が警告として見せる。
 */
final class CreateContentType implements Operation
{
    /** 適用中の排他ロックの保持時間（秒）。migration を含むので短すぎない値にする */
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly ContentTypePlanner $planner,
        private readonly ContentTypeEnvironment $environment,
        private readonly ContentTypeInstaller $installer,
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly CacheRepository $cache,
    ) {}

    public function operationId(): string
    {
        return 'content.type.create';
    }

    public function classification(): OperationClassification
    {
        // 不可逆な構造変更（テーブル作成・ファイル生成）なので、
        // 既存の「人間の確認を要する」分類を使う。新しい case は増やさない
        return OperationClassification::ExternalEffect;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->recordOrFlag($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        if (! in_array($context->interface, [OperationInterface::Admin, OperationInterface::Cli], true)) {
            return $this->outcome->recordOrFlag($this, $context, Result::forbidden('Content Type の作成は管理画面からのみ実行できます'));
        }

        $parsed = ContentTypeDraft::fromInput($input);

        if ($parsed['draft'] === null) {
            return $this->outcome->recordOrFlag($this, $context, Result::validationError($parsed['errors'], '入力内容を確認してください'));
        }

        $planned = $this->planner->plan($parsed['draft'], Date::now()->format('Y_m_d_His'));

        if ($planned['plan'] === null) {
            return $this->outcome->recordOrFlag($this, $context, Result::validationError($planned['errors'], '入力内容を確認してください'));
        }

        $plan = $planned['plan'];

        $blockers = $this->environment->blockers();

        if ($blockers !== []) {
            // **変更を始める前に**止める（SPEC §1C）
            return $this->outcome->recordOrFlag($this, $context, Result::conflict(
                'この環境では Content Type を作成できません：'.implode(' / ', $blockers),
                ['blockers' => $blockers],
            ));
        }

        $lock = $this->lock($plan->draft->identifier->value);

        if ($lock !== null && ! $lock->get()) {
            return $this->outcome->recordOrFlag($this, $context, Result::conflict('ほかの Content Type 作成処理が実行中です。完了後にもう一度お試しください。'));
        }

        try {
            // ロックを取ってから確認・適用へ入る。計画の再確認から rollback 完了までを
            // 同じロックの中で行うので、競合した側の rollback が
            // もう一方の生成物を巻き添えにできない
            $confirmation = $this->confirmations->require(
                $this->operationId(),
                $this->classification(),
                $context,
                $plan->confirmationArguments(),
                summary: "投稿タイプ「{$plan->draft->label}」を作成します",
                impact: [
                    "データベースにテーブル [{$plan->table()}] を作成します",
                    '定義ファイル・モデル・マイグレーションを 3 件生成します',
                    "管理画面に {$plan->adminUrl} が追加されます",
                    '作成後にフィールドを削除・改名・型変更することはできません',
                ],
            );

            if ($confirmation !== null) {
                return $this->outcome->recordOrFlag(
                    $this,
                    $context,
                    Result::confirmationRequired($confirmation, ['plan' => $plan->toArray()]),
                    resourceType: 'content_type',
                    resourceId: $plan->draft->identifier->value,
                );
            }

            try {
                $summary = $this->installer->install($plan);
            } catch (ContentTypeInstallFailed $e) {
                report($e);

                return $this->outcome->recordOrFlag(
                    $this,
                    $context,
                    Result::conflict($e->getMessage(), [
                        'rollback' => [
                            'completed' => $e->rollbackNotes === [],
                            'notes' => $e->rollbackNotes,
                        ],
                    ]),
                    resourceType: 'content_type',
                    resourceId: $plan->draft->identifier->value,
                );
            }
        } finally {
            $lock?->release();
        }

        /*
         * 監査の失敗で **作成済みの成果物を巻き戻さない**。
         *
         * ここへ来た時点で schema / モデル / migration / テーブルは
         * すべて正常に作られている。監査ログが書けなかったという理由で
         * それを消すのは、実害の大きい方を選ぶことになる。
         *
         * かといって黙って成功にもしない。`recordOrFlag()` が
         * **監査が欠けたこと自体を meta へ添えて**返すので、
         * 画面はそれを警告として見せられる（例外にして 500 にすると、
         * 「作成されているのに失敗と表示される」という最悪の見え方になる）。
         */
        return $this->outcome->recordOrFlag(
            $this,
            $context,
            Result::success(['contentType' => $summary]),
            resourceType: 'content_type',
            resourceId: $plan->draft->identifier->value,
            metadata: [
                'table' => $plan->table(),
                'fieldCount' => count($plan->draft->fields),
                // 値そのものではなく「どんな項目を作ったか」だけを残す
                'fieldKeys' => $plan->draft->fieldKeys(),
            ],
        );
    }

    /**
     * 同一 ID の同時作成を止めるロック。
     *
     * lock を提供しない cache ストア構成では null を返すが、
     * その場合も `ContentTypeInstaller::write()` の排他生成
     * （`fopen($path, 'xb')`）が二重生成を防ぐ。
     */
    private function lock(string $id): ?Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        return $store->lock('agentcms:content-type:create:'.$id, self::LOCK_SECONDS);
    }
}

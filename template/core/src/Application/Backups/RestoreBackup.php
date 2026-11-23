<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Backups;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\RestoreImpact;

/**
 * backup から戻す（Phase 9.11 / STAGE 24C・SPEC §D.2）。
 *
 * **本書で最も危険な操作である。** 順序を固定する。
 *
 *   1. `OperationPolicy`（**Owner 限定** — `Capability::BackupRestore`）
 *   2. 検証（落ちたらそこで終わり）
 *   3. `ConfirmationGate`。impact に「いま何件 → 戻すと何件」を**表ごとに**出す
 *   4. 復元前 backup を取る（`BackupRestorer` が行う。取れなければ復元しない）
 *   5. DB とメディアを入れ替え、**同じ transaction ですべての session を切る**
 *   6. 監査に残す
 *
 * ## なぜ session を切るのか（OG12）
 *
 * 利用者の表を戻すので、**いまログインしている人の前提が変わる**。
 * 権限を落とされた人が、戻す前の権限のまま操作できてしまう状態を作らない。
 *
 * 切るのは `BackupRestorer` が transaction の中で行う（release R09）。以前はここで
 * commit の後に `session_epoch + 1` していたが、それだと (a) commit と失効の間に
 * 隙間がある、(b) CLI から直接戻したときは切れない、(c) backup の世代 + 1 が
 * いまの世代と一致すると切れない、の 3 つが残っていた。
 */
final class RestoreBackup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly BackupRestorer $restorer,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly RestoreImpact $impact,
    ) {}

    public function operationId(): string
    {
        return 'backup.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! BackupPaths::isValidId($id)) {
            return Result::notFound('その backup はありません');
        }

        $check = $this->restorer->verify($id);

        if (! $check['ok'] || $check['manifest'] === null) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('この backup は復元できません: '.implode(' / ', $check['problems'])),
                resourceType: 'backup',
                resourceId: $id,
            );
        }

        $manifest = $check['manifest'];

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            /*
             * 見出しも人の形で（WP 一周 10 周目 2026-09-04）。
             * ここは `2026-09-04T11:37:09+09:00 の backup へ戻します` と出ていた。
             * 読みの形は `RestoreImpact` と同じ 1 か所から取る。
             */
            $this->impact->readable($manifest->createdAt).' の backup へ戻します',
            [
                /*
                 * 🔴 **戻せない操作の前に、失われるものを見せる**
                 * （WP 一周 9 周目 2026-09-04）。
                 *
                 * ここは表ごとの行数の増減だけを出していた。実測で出たのは
                 * `audit_logs: 13 件 → 9 件` と `not_found_logs: 1 件 → 0 件` の 2 行 —
                 * 機械の名前で、いちばん気にならないものだけである。
                 *
                 * もっと悪いのは出ないほうで、**行数が変わらない変更は 1 行も出なかった**。
                 * 誤字を直した・公開の状態を変えた・サイト名を入れ替えた、はどれも
                 * 行数が同じなので黙っていた。それでも戻せば中身は置き換わる。
                 */
                ...$this->impact->since($manifest->createdAt, array_keys($manifest->tables)),
                'いまの内容は、戻す直前に自動で backup を取ってから置き換えます',
                '全員のログインが切れます（もう一度ログインが必要になります）',
                '取得時に無かったメディアは公開面から消えます',
            ],
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation);
        }

        try {
            $result = $this->restorer->restore($id);
        } catch (BackupFailed $e) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict($e->getMessage()),
                resourceType: 'backup',
                resourceId: $id,
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['restored' => $result['restored'], 'pre_restore' => $result['preRestore']],
                ['id' => $id],
            ),
            resourceType: 'backup',
            resourceId: $id,
        );
    }
}

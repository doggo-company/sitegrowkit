/**
 * Application Service（core）が返す確認要求（Phase 9.1B / STEP 7）。
 * 破壊的操作は Admin も AI も同じ ConfirmationGate を通る。
 */
export interface OperationConfirmation {
    token: string;
    summary: string;
    impact: string[];
    expiresAt: string;
}

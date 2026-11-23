// 利用者と操作履歴の管理画面向け表現 — PHASE WP-BASELINE / STAGE #18A。
// core / app の UserPresenter・ActivityPresenter と対応する。

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isActive: boolean;
    /** 「有効 / 無効 / 招待中」を色だけでなく文字でも示すためのラベル */
    statusLabel: string;
    emailVerified: boolean;
    invitedAt: string | null;
    lastLoginAt: string | null;
    pendingInvitation: boolean;
    createdAt: string | null;
    canDeactivate: boolean;
    canReactivate: boolean;
    canChangeRole: boolean;
    isSelf: boolean;
    isLastActiveOwner: boolean;
}

export interface SelectOption {
    value: string;
    label: string;
    description?: string;
}

export interface UserFilterOptions {
    roles: SelectOption[];
    statuses: SelectOption[];
}

/** 操作履歴の 1 行 */
export interface ActivityEntry {
    id: number;
    occurredAt: string | null;
    actorId: number | null;
    actorLabel: string;
    actorType: string;
    operation: string;
    operationLabel: string;
    result: string;
    resultLabel: string;
    interface: string;
    interfaceLabel: string;
    resourceType: string | null;
    resourceId: string | null;
    summary: string;
    /** オーナーにだけ返る */
    ipAddress?: string | null;
    userAgent?: string | null;
    anonymized?: boolean;
}

export interface ActivityFilterState {
    actorId: number | null;
    operation: string | null;
    result: string | null;
    interface: string | null;
    resourceType: string | null;
    from: string | null;
    until: string | null;
}

export interface ActivityFilterOptions {
    operations: SelectOption[];
    results: SelectOption[];
    interfaces: SelectOption[];
}

export interface PageMeta {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
    includesNetworkContext?: boolean;
}

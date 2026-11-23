<script setup lang="ts">
/**
 * backup の管理（Phase 9.11 / STAGE 24D・SPEC §H）。
 *
 * ## 取得はボタンで始められる。進みは実測だけを出す（OG3 改訂・2026-09-09）
 *
 * 押すとサーバーが**別プロセス**を起こし、この画面は状態ファイルを 2 秒ごとに読む。
 * 出すのは段階（DB → メディア → 秘密の走査 → まとめ → 確認）と、その中で実際に数えた数。
 * 架空のパーセントは出さない。止まれば「止まった」と出て、CLI の手順に戻れる。
 *
 * 別プロセスを起動できない環境では理由と**コマンド**を出す。コマンド表示は無くさない。
 *
 * ## 戻す前に確かめられる
 *
 * 復元は取り返しがつかない。押す前に「戻せるか」を見られるようにする。
 * 取り終えた backup からも同じ確認を通してしか復元へ進めない。
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import { formatDateTime } from '@/lib/admin-datetime';
import { useAdminTimezone } from '@/composables/useAdminTimezone';

interface Backup {
    id: string;
    created_at: string;
    size_bytes: number;
    rows: number;
    media_files: number;
    pre_restore: boolean;
    excluded: string[];
}

interface RunProgress {
    done: number;
    total: number | null;
}

/** サーバーの `BackupRunState::toArray()` と同じ形 */
interface Run {
    run_id: string;
    status: 'queued' | 'running' | 'done' | 'failed';
    stage: string;
    stage_label: string;
    progress: RunProgress | null;
    started_at: string;
    heartbeat_at: string;
    finished_at: string | null;
    backup_id: string | null;
    error: string | null;
}

interface Stage {
    key: string;
    label: string;
}

/** サーバーの `AutoBackupStatus::read()` と同じ形（日時は ISO8601） */
interface AutoBackup {
    state:
        | 'off'
        | 'never'
        | 'running'
        | 'ok'
        | 'local-only'
        | 'failed'
        | 'interrupted'
        | 'stale'
        | 'unknown';
    attention: boolean;
    message: string;
    schedule: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    lastSuccessAt: string | null;
    backup: string | null;
    reason: string | null;
}

const props = defineProps<{
    backups: Backup[];
    command: string;
    canCreate?: boolean;
    createUnavailableReason?: string | null;
    run?: Run | null;
    stages?: Stage[];
    canRestore?: boolean;
    canDelete?: boolean;
    autoBackup?: AutoBackup | null;
}>();

/** 最後の自動バックアップ。使っていない（off）なら何も出さない */
const autoBackup = computed<AutoBackup | null>(() =>
    props.autoBackup && props.autoBackup.state !== 'off'
        ? props.autoBackup
        : null,
);

const restorable = props.canRestore === true;
const removable = props.canDelete === true;
const creatable = props.canCreate === true;
const stages = props.stages ?? [];

// ── 画面からの取得（OG3 改訂） ──────────────────────────
const run = ref<Run | null>(props.run ?? null);
const starting = ref(false);
/** 取り終えた直後の backup。一覧で目立たせ、復元へ進む導線にする */
const fresh = ref<string | null>(null);

const active = computed(
    () =>
        run.value !== null &&
        (run.value.status === 'queued' || run.value.status === 'running'),
);

/** 進みの中で、その段階がどこにあるか */
function stageState(key: string): 'done' | 'current' | 'pending' {
    const current = run.value;

    if (!current) {
        return 'pending';
    }

    const index = stages.findIndex((s) => s.key === key);
    const at = stages.findIndex((s) => s.key === current.stage);

    if (current.status === 'done') {
        return 'done';
    }

    if (at === -1) {
        return 'pending';
    }

    if (index < at) {
        return 'done';
    }

    return index === at ? 'current' : 'pending';
}

/** 数えたものだけを言葉にする。数えていなければ何も出さない */
function measured(progress: RunProgress | null): string {
    if (!progress) {
        return '';
    }

    return progress.total === null
        ? `${progress.done} 件`
        : `${progress.done} / ${progress.total}`;
}

let timer: number | null = null;

function stopPolling() {
    if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
    }
}

async function poll() {
    const current = run.value;

    if (!current) {
        stopPolling();

        return;
    }

    try {
        const response = await fetch(`/admin/backups/runs/${current.run_id}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            // 記録が消えた（世代整理）か、権限が変わった。読めないものを「処理中」のままにしない
            run.value = {
                ...current,
                status: 'failed',
                error: '取得の記録を読めませんでした。画面を開き直してください',
            };
            stopPolling();

            return;
        }

        const body = (await response.json()) as { run?: Run | null };

        if (body.run) {
            run.value = body.run;
        }
    } catch {
        // 通信の一時的な失敗は次の周で拾う（サーバー側の鮮度判定が「止まった」を決める）
    }

    if (run.value && !active.value) {
        stopPolling();

        if (run.value.status === 'done' && run.value.backup_id) {
            fresh.value = run.value.backup_id;
            // 一覧に出す。取れた backup は zip の manifest を読み直した結果である
            router.reload({ only: ['backups', 'run'] });
        }
    }
}

function startPolling() {
    if (timer !== null) {
        return;
    }

    timer = window.setInterval(() => {
        void poll();
    }, 2000);
}

watch(
    () => props.run,
    (next) => {
        run.value = next ?? null;
    },
);

watch(
    active,
    (isActive) => {
        if (isActive) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);

function create() {
    if (starting.value || active.value) {
        return;
    }

    starting.value = true;
    fresh.value = null;

    router.post(
        '/admin/backups',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                starting.value = false;
            },
        },
    );
}

/** 取り終えた backup の行（一覧に載っていれば） */
const freshBackup = computed(
    () => props.backups.find((b) => b.id === fresh.value) ?? null,
);

function proceedToRestore(backup: Backup) {
    void verify(backup);
    document
        .querySelector(`[data-backup-id="${backup.id}"]`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

interface Verdict {
    ok: boolean;
    problems: string[];
}

const checked = ref<Record<string, Verdict>>({});
const checking = ref<Record<string, boolean>>({});

/** その backup を確かめた結果（まだなら null） */
function verdict(id: string): Verdict | null {
    return checked.value[id] ?? null;
}
const copied = ref(false);

function size(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(1)} ${units[unit]}`;
}

async function copyCommand() {
    try {
        await navigator.clipboard.writeText(props.command);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
    }
}

/** **何も変えない。** 戻せるかどうかだけ聞く */
async function verify(backup: Backup) {
    checking.value = { ...checking.value, [backup.id]: true };

    try {
        const response = await fetch(`/admin/backups/${backup.id}/verify`, {
            headers: { Accept: 'application/json' },
        });
        const body = (await response.json()) as {
            ok?: boolean;
            problems?: string[];
        };

        checked.value = {
            ...checked.value,
            [backup.id]: {
                ok: body.ok === true,
                problems: body.problems ?? [],
            },
        };
    } catch {
        checked.value = {
            ...checked.value,
            [backup.id]: {
                ok: false,
                problems: [
                    '確かめられませんでした。通信状況を確認してください。',
                ],
            },
        };
    } finally {
        checking.value = { ...checking.value, [backup.id]: false };
    }
}

// ── 復元と削除（どちらもサーバーの確認を通す） ──────────────
const pending = ref<{ action: 'restore' | 'delete'; backup: Backup } | null>(
    null,
);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const target = pending.value;

        if (!target) {
            return;
        }

        router.post(
            `/admin/backups/${target.backup.id}/${target.action}`,
            { ...(token ? { confirmation_token: token } : {}) },
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

function start(action: 'restore' | 'delete', backup: Backup) {
    pending.value = { action, backup };
    request();
}

/*
 * 人が読む日時は `admin-datetime` を通す（WP 一周 10 周目 2026-09-04）。
 * #10 で 1 か所へまとめたが、この画面は通っていなかった
 * （`2026-09-04T12:16:56+09:00` がそのまま出ていた）。
 */
const timezone = useAdminTimezone();
</script>

<template>
    <Head title="バックアップ" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">バックアップ</h1>
            <p class="text-sm text-muted-foreground">
                投稿・設定・メディアをまとめて保存します。パスワードや API
                キーなどの 秘密情報は含まれません。
            </p>
        </div>

        <!--
            最後の自動バックアップ。cron の schedule:run は失敗しても成功で終わるので、
            夜中の失敗はここ（とサイドバーの印）でしか分からない。
        -->
        <section
            v-if="autoBackup && autoBackup.attention"
            class="rounded-lg border border-destructive bg-destructive/5 p-4"
            role="alert"
            data-testid="auto-backup-alert"
            :data-state="autoBackup.state"
        >
            <h2 class="text-sm font-semibold text-destructive">
                自動バックアップを確かめてください
            </h2>
            <p class="mt-1 text-sm">{{ autoBackup.message }}</p>
            <p class="mt-2 text-xs text-muted-foreground">
                <template v-if="autoBackup.lastSuccessAt">
                    最後に取れたのは
                    {{ formatDateTime(autoBackup.lastSuccessAt, timezone) }}
                    です。
                </template>
                <template v-else>自動ではまだ一度も取れていません。</template>
                サーバーで
                <code class="font-mono"
                    >php artisan agentcms:backup-offsite --list</code
                >
                を実行して置き場所と設定を確かめ、直したら
                <code class="font-mono">php artisan agentcms:backup-auto</code>
                で取り直してください。成功すると、この表示とサイドバーの印は消えます。
            </p>
        </section>
        <p
            v-else-if="autoBackup"
            class="text-xs text-muted-foreground"
            data-testid="auto-backup-status"
            :data-state="autoBackup.state"
        >
            {{ autoBackup.message }}
        </p>

        <!-- サイト全体の復元と、投稿内容だけを移す用途を選べるようにする。 -->
        <a
            v-if="creatable"
            href="/admin/exports"
            class="text-sm text-primary underline underline-offset-4"
        >
            記事・ページだけを書き出す（WordPress交換用XML）
        </a>

        <!-- 取得（OG3 改訂）: 画面から始め、別プロセスが進める -->
        <section class="rounded-lg border p-4" aria-labelledby="take-heading">
            <h2 id="take-heading" class="text-sm font-medium">
                バックアップを取る
            </h2>

            <template v-if="creatable && !createUnavailableReason">
                <p class="mt-1 text-xs text-muted-foreground">
                    押すとサーバーの別プロセスが取得を進めます。この画面を開いたままで進みが分かります。
                    大きなサイトでも途中で切れません。
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        :disabled="starting || active"
                        data-testid="backup-create"
                        @click="create"
                    >
                        {{
                            active
                                ? '取得しています…'
                                : starting
                                  ? '始めています…'
                                  : 'いま取る'
                        }}
                    </Button>
                </div>
            </template>

            <p
                v-else-if="creatable && createUnavailableReason"
                class="mt-1 text-xs text-destructive"
                data-testid="backup-create-unavailable"
            >
                {{ createUnavailableReason }}
            </p>

            <!-- 進み・結末（Owner だけ）。実測の段階と数だけを出す -->
            <div
                v-if="run"
                class="mt-4 rounded-md border bg-muted/30 p-3 text-sm"
                data-testid="backup-run"
                :data-run-status="run.status"
                aria-live="polite"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <span class="font-medium">
                        <template v-if="run.status === 'done'"
                            >取り終えました</template
                        >
                        <template v-else-if="run.status === 'failed'"
                            >取れませんでした</template
                        >
                        <template v-else>{{ run.stage_label }}</template>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        開始 {{ formatDateTime(run.started_at, timezone) }}
                    </span>
                </div>

                <ol
                    v-if="run.status !== 'failed'"
                    class="mt-2 space-y-1 text-xs"
                    data-testid="backup-run-stages"
                >
                    <li
                        v-for="stage in stages"
                        :key="stage.key"
                        :data-stage="stage.key"
                        :data-stage-state="stageState(stage.key)"
                        :class="{
                            'text-muted-foreground':
                                stageState(stage.key) === 'pending',
                            'font-medium': stageState(stage.key) === 'current',
                        }"
                    >
                        <span aria-hidden="true">{{
                            stageState(stage.key) === 'done'
                                ? '✓'
                                : stageState(stage.key) === 'current'
                                  ? '●'
                                  : '○'
                        }}</span>
                        {{ stage.label }}
                        <span
                            v-if="
                                stageState(stage.key) === 'current' &&
                                run.progress
                            "
                            class="text-muted-foreground"
                            data-testid="backup-run-progress"
                        >
                            {{ measured(run.progress) }}
                        </span>
                    </li>
                </ol>

                <p
                    v-if="run.status === 'failed'"
                    class="mt-2 text-destructive"
                    data-testid="backup-run-error"
                >
                    {{ run.error }}
                </p>
                <p
                    v-if="run.status === 'failed'"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    サーバーにログインして次のコマンドで取ることもできます。
                </p>

                <div
                    v-if="run.status === 'done' && run.backup_id"
                    class="mt-3 flex flex-wrap items-center gap-2"
                >
                    <span class="text-xs text-muted-foreground">
                        backup
                        <code class="font-mono">{{ run.backup_id }}</code>
                        が一覧に入りました。
                    </span>
                    <Button
                        v-if="freshBackup"
                        variant="secondary"
                        size="sm"
                        data-testid="backup-run-proceed"
                        @click="proceedToRestore(freshBackup)"
                    >
                        確かめて復元へ進む
                    </Button>
                </div>
            </div>

            <!-- コマンド表示は無くさない: 画面から始められない環境と、止まったときの代替 -->
            <div class="mt-4 border-t pt-3 text-xs">
                <p class="text-muted-foreground">
                    コマンドで取る場合は、サーバーにログインして次を実行してください。
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code
                        class="rounded bg-muted px-3 py-2 font-mono text-sm"
                        data-testid="backup-command"
                        >{{ command }}</code
                    >
                    <Button
                        variant="secondary"
                        size="sm"
                        data-testid="backup-copy"
                        @click="copyCommand"
                    >
                        {{ copied ? 'コピーしました' : 'コピー' }}
                    </Button>
                </div>
            </div>
        </section>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">取得日時</th>
                        <th class="px-4 py-2 font-medium">大きさ</th>
                        <th class="px-4 py-2 font-medium">中身</th>
                        <th class="px-4 py-2 font-medium">状態</th>
                        <th class="px-4 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="backup in backups"
                        :key="backup.id"
                        data-testid="backup-row"
                        :data-backup-id="backup.id"
                        :class="{ 'bg-primary/5': backup.id === fresh }"
                    >
                        <td class="px-4 py-3">
                            {{ formatDateTime(backup.created_at, timezone) }}
                            <Badge
                                v-if="backup.pre_restore"
                                variant="secondary"
                                class="ml-1"
                                data-testid="backup-pre-restore"
                            >
                                復元前に自動取得
                            </Badge>
                        </td>
                        <td class="px-4 py-3">{{ size(backup.size_bytes) }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            記録 {{ backup.rows }} 行 / メディア
                            {{ backup.media_files }} 件
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <template v-if="checking[backup.id]"
                                >確かめています…</template
                            >
                            <template v-else-if="verdict(backup.id)">
                                <span
                                    v-if="verdict(backup.id)?.ok"
                                    class="text-muted-foreground"
                                    data-testid="backup-verified"
                                >
                                    戻せます
                                </span>
                                <span
                                    v-else
                                    class="text-destructive"
                                    data-testid="backup-problem"
                                >
                                    {{
                                        verdict(backup.id)?.problems.join(' / ')
                                    }}
                                </span>
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                data-testid="backup-verify"
                                @click="verify(backup)"
                            >
                                確かめる
                            </Button>
                            <Button
                                v-if="restorable"
                                variant="ghost"
                                size="sm"
                                as-child
                                data-testid="backup-download"
                            >
                                <a
                                    :href="`/admin/backups/${backup.id}/download`"
                                    >持ち出す</a
                                >
                            </Button>
                            <Button
                                v-if="restorable"
                                variant="ghost"
                                size="sm"
                                data-testid="backup-restore"
                                @click="start('restore', backup)"
                            >
                                これへ戻す
                            </Button>
                            <Button
                                v-if="removable"
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                data-testid="backup-delete"
                                @click="start('delete', backup)"
                            >
                                削除
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="backups.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="backup-empty"
                        >
                            バックアップはまだありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="backups.length > 0"
            class="text-xs text-muted-foreground"
            data-testid="backup-excluded"
        >
            含まれないもの: {{ backups[0].excluded.join(' / ') }}
        </p>
    </div>

    <ConfirmationDialog
        :confirmation="confirmation"
        :confirm-label="
            pending?.action === 'restore' ? 'これへ戻す' : '削除する'
        "
        @confirm="accept"
        @cancel="dismiss"
    />
</template>

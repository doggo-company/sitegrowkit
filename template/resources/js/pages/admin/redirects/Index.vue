<script setup lang="ts">
/**
 * 転送ルールの管理（Phase 9.10 / STAGE 23D・SPEC §G）。
 *
 * ## 踏まずに確かめられること
 *
 * 301 はブラウザが強く覚える。試しに踏むと、ルールを直したあとも
 * **自分のブラウザだけ古い転送先へ飛び続ける**。だからシミュレータを画面に置く。
 *
 * ## 301 を選んだら注意を出す
 *
 * 「あとで変えられる」と思って 301 を選ぶ人がいる。実際には戻しにくい。
 * 選んだ瞬間に、その場で伝える。
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';

interface Rule {
    id: number;
    match_type: string;
    source_path: string;
    target_url: string;
    status_code: number;
    status_label: string;
    is_sticky: boolean;
    is_enabled: boolean;
    keep_query: boolean;
    created_reason: string;
    note: string | null;
    hit_count: number;
    last_hit_at: string | null;
    lock_version: number;
}

interface StatusChoice {
    value: number;
    label: string;
    sticky: boolean;
}

const props = defineProps<{
    rules: Rule[];
    meta: { total: number; page: number; lastPage: number; search: string };
    statuses: StatusChoice[];
    canWrite?: boolean;
}>();

const writable = props.canWrite !== false;
const editing = ref<Rule | null>(null);
const pending = ref<Rule | null>(null);

const form = useForm({
    match_type: 'exact',
    source_path: '',
    target_url: '',
    status_code: 301,
    is_enabled: true,
    keep_query: true,
    note: '',
    lock_version: 0,
});

/** いま選んでいる転送の種類が、ブラウザに覚えられるものか */
const sticky = computed(
    () =>
        props.statuses.find((s) => s.value === Number(form.status_code))
            ?.sticky ?? false,
);

function startNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function startEdit(rule: Rule) {
    editing.value = rule;
    form.clearErrors();
    form.match_type = rule.match_type;
    form.source_path = rule.source_path;
    form.target_url = rule.target_url;
    form.status_code = rule.status_code;
    form.is_enabled = rule.is_enabled;
    form.keep_query = rule.keep_query;
    form.note = rule.note ?? '';
    form.lock_version = rule.lock_version;
}

function submit() {
    if (editing.value) {
        form.patch(`/admin/redirects/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: () => startNew(),
        });

        return;
    }

    form.post('/admin/redirects', {
        preserveScroll: true,
        onSuccess: () => startNew(),
    });
}

/** 有効・無効の切り替えは 1 操作で済ませる（消さずに止められる） */
function toggle(rule: Rule) {
    router.patch(
        `/admin/redirects/${rule.id}`,
        {
            match_type: rule.match_type,
            source_path: rule.source_path,
            target_url: rule.target_url,
            status_code: rule.status_code,
            is_enabled: !rule.is_enabled,
            keep_query: rule.keep_query,
            note: rule.note ?? '',
            lock_version: rule.lock_version,
        },
        { preserveScroll: true },
    );
}

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const rule = pending.value;

        if (!rule) {
            return;
        }

        router.post(
            `/admin/redirects/${rule.id}/delete`,
            { ...(token ? { confirmation_token: token } : {}) },
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

function remove(rule: Rule) {
    pending.value = rule;
    request();
}

// ── シミュレータ（踏まずに確かめる） ──────────────────────
const probe = ref('');
const probeResult = ref<Record<string, unknown> | null>(null);
const probing = ref(false);

async function simulate() {
    probing.value = true;
    probeResult.value = null;

    try {
        const response = await fetch(
            `/admin/redirects/simulate?url=${encodeURIComponent(probe.value)}`,
            { headers: { Accept: 'application/json' } },
        );

        probeResult.value = (await response.json()) as Record<string, unknown>;
    } catch {
        probeResult.value = {
            error: '確かめられませんでした。通信状況を確認してください。',
        };
    } finally {
        probing.value = false;
    }
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="リダイレクト" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">リダイレクト</h1>
            <p class="text-sm text-muted-foreground">
                無くなった URL を、いまのページへ転送します。転送されるのは
                <strong>そのページが表示できないとき</strong>だけです。
            </p>
        </div>

        <!-- 踏まずに確かめる -->
        <section
            class="rounded-lg border p-4"
            aria-labelledby="simulator-heading"
        >
            <h2 id="simulator-heading" class="text-sm font-medium">
                動きを確かめる
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                実際には転送しません。301
                はブラウザに記憶されるため、試しに開いて確かめると
                あとで直しても自分の画面だけ古い転送先へ飛び続けます。
            </p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <div class="grid gap-1.5">
                    <Label for="probe-url">確かめる URL</Label>
                    <Input
                        id="probe-url"
                        v-model="probe"
                        class="w-72"
                        placeholder="/old-page"
                        @keyup.enter="simulate"
                    />
                </div>
                <Button
                    variant="secondary"
                    data-testid="redirect-simulate"
                    @click="simulate"
                >
                    確かめる
                </Button>
            </div>

            <p
                v-if="probing"
                class="mt-3 text-sm text-muted-foreground"
                data-testid="redirect-simulating"
            >
                確かめています…
            </p>

            <div
                v-else-if="probeResult"
                class="mt-3 rounded-md bg-muted/40 p-3 text-sm"
                role="status"
                aria-live="polite"
                data-testid="redirect-simulate-result"
            >
                <template v-if="probeResult.protected">
                    このパスは管理画面や API
                    のものです。ルールがあっても転送されません。
                </template>
                <template v-else-if="probeResult.matched">
                    <strong>{{ probeResult.path }}</strong> →
                    <strong>{{ probeResult.target }}</strong>
                    （{{ probeResult.status_code }} /
                    {{ probeResult.hops }} 段）
                </template>
                <template v-else-if="probeResult.error">{{
                    probeResult.error
                }}</template>
                <template v-else>
                    <strong>{{ probeResult.path }}</strong>
                    に当たるルールはありません。
                </template>
            </div>
        </section>

        <!-- ルールを作る / 直す -->
        <section
            v-if="writable"
            class="rounded-lg border p-4"
            aria-labelledby="form-heading"
        >
            <h2 id="form-heading" class="text-sm font-medium">
                {{
                    editing
                        ? `ルールを直す（${editing.source_path}）`
                        : 'ルールを追加'
                }}
            </h2>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="source-path">転送元のパス</Label>
                    <Input
                        id="source-path"
                        v-model="form.source_path"
                        placeholder="/old-page"
                    />
                    <p
                        v-if="form.errors.source_path"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.source_path }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="target-url">転送先</Label>
                    <Input
                        id="target-url"
                        v-model="form.target_url"
                        placeholder="/new-page"
                    />
                    <p
                        v-if="form.errors.target_url"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.target_url }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="match-type">照合の方法</Label>
                    <select
                        id="match-type"
                        v-model="form.match_type"
                        class="h-9 rounded-md border px-2 text-sm"
                    >
                        <option value="exact">完全一致</option>
                        <option value="prefix">
                            前方一致（配下をまとめて）
                        </option>
                    </select>
                </div>

                <div class="grid gap-1.5">
                    <Label for="status-code">転送の種類</Label>
                    <select
                        id="status-code"
                        v-model.number="form.status_code"
                        class="h-9 rounded-md border px-2 text-sm"
                    >
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                    <p
                        v-if="sticky"
                        class="text-xs text-muted-foreground"
                        data-testid="redirect-sticky-warning"
                    >
                        ブラウザがこの転送を記憶します。あとで変えても、一度訪れた人には
                        しばらく古い転送先が使われます。
                    </p>
                    <p
                        v-if="form.errors.status_code"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.status_code }}
                    </p>
                </div>

                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="note">メモ</Label>
                    <Input
                        id="note"
                        v-model="form.note"
                        placeholder="なぜ作ったか"
                    />
                </div>

                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_enabled" type="checkbox" />
                        有効にする
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.keep_query" type="checkbox" />
                        ?以降をそのまま引き継ぐ
                    </label>
                </div>
            </div>

            <p
                v-if="form.errors.lock_version"
                class="mt-2 text-sm text-destructive"
                role="alert"
                aria-live="polite"
                data-testid="redirect-conflict"
            >
                {{ form.errors.lock_version }}
            </p>

            <div class="mt-3 flex gap-2">
                <Button data-testid="redirect-save" @click="submit">
                    {{ editing ? '保存' : '追加' }}
                </Button>
                <Button
                    v-if="editing"
                    variant="secondary"
                    data-testid="redirect-cancel"
                    @click="startNew"
                >
                    やめる
                </Button>
            </div>
        </section>

        <p
            v-else
            class="rounded-md border border-dashed px-4 py-3 text-sm text-muted-foreground"
            data-testid="redirect-readonly"
        >
            転送ルールを編集する権限がありません。
        </p>

        <!-- 一覧 -->
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">転送元</th>
                        <th class="px-4 py-2 font-medium">転送先</th>
                        <th class="px-4 py-2 font-medium">種類</th>
                        <th class="px-4 py-2 font-medium">回数</th>
                        <th class="px-4 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="rule in rules"
                        :key="rule.id"
                        data-testid="redirect-row"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ rule.source_path }}
                            <Badge
                                v-if="rule.match_type === 'prefix'"
                                variant="secondary"
                                class="ml-1"
                            >
                                配下も
                            </Badge>
                            <Badge
                                v-if="!rule.is_enabled"
                                variant="outline"
                                class="ml-1"
                                >停止中</Badge
                            >
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ rule.target_url }}
                        </td>
                        <td class="px-4 py-3">{{ rule.status_code }}</td>
                        <td class="px-4 py-3">{{ rule.hit_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="writable">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    data-testid="redirect-toggle"
                                    @click="toggle(rule)"
                                >
                                    {{ rule.is_enabled ? '止める' : '動かす' }}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    data-testid="redirect-edit"
                                    @click="startEdit(rule)"
                                >
                                    直す
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                    data-testid="redirect-delete"
                                    @click="remove(rule)"
                                >
                                    削除
                                </Button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="rules.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="redirect-empty"
                        >
                            転送ルールはありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmationDialog
        :confirmation="confirmation"
        @confirm="accept"
        @cancel="dismiss"
    />
</template>

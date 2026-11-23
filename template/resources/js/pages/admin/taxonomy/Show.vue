<script setup lang="ts">
/**
 * 分類の項目管理（Phase 9.3B / #16・SPEC §R / §I.5 / §I.7）。
 *
 * - mutation はすべて **Admin route → Application Service**（API を呼ばない）
 * - 管理 tree は `includeArchived=true` で受け取る（HR-3）
 * - 一覧の既定は active のみ。「アーカイブ済みを表示」で切り替える（AR-1 / AR-2）
 * - 並べ替えは drag に依存せず、**上へ / 下へのボタン**で行う（§AB）。
 *   送るのは **同じ親の兄弟全件（archived を含む）**（ReorderTerms の full sibling-set contract）
 * - 削除できないときは **理由**を出し、**アーカイブを代替として提示**する（§I.5）
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Plus } from '@lucide/vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import InputError from '@/components/InputError.vue';
import TermTree from '@/components/taxonomy/TermTree.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type {
    AdminTaxonomy,
    AdminTerm,
    TermDeleteConflict,
    TermNode,
} from '@/types/taxonomy';
import { useAdminCapability } from '@/composables/useAdminCapability';

const canWrite = useAdminCapability('content.write');

const props = defineProps<{
    taxonomy: AdminTaxonomy;
    /** includeArchived=true の全件（HR-3） */
    terms: AdminTerm[];
    usage: Record<string, number>;
    treeComplete: boolean;
}>();

const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const showArchived = ref(false);
const busy = ref(false);

const storeUrl = computed(
    () => `/admin/taxonomies/${props.taxonomy.key}/terms`,
);
const reorderUrl = computed(() => `${storeUrl.value}/reorder`);

// ── tree の組み立て ─────────────────────────────────────────────

/** 同じ親の兄弟を **全件**（archived を含む）順序どおりに返す */
function siblingsOf(parentId: string | null): AdminTerm[] {
    return props.terms.filter((term) => (term.parentId ?? null) === parentId);
}

function isVisible(term: AdminTerm): boolean {
    return !term.archived || showArchived.value;
}

/**
 * 表示する tree。**archived な親の配下の active な子を孤児 root にしない**（HR-4）。
 * 自身がフィルタ対象でも、表示すべき子孫があれば階層を保つために出す。
 */
function buildNodes(parentId: string | null): TermNode[] {
    const nodes: TermNode[] = [];

    for (const term of siblingsOf(parentId)) {
        const children = buildNodes(term.id);
        const visible = isVisible(term);

        if (!visible && children.length === 0) {
            continue;
        }

        nodes.push({
            term,
            children,
            keptForHierarchy: !visible,
            canMoveUp: false,
            canMoveDown: false,
        });
    }

    return nodes.map((node, index) => ({
        ...node,
        canMoveUp: index > 0,
        canMoveDown: index < nodes.length - 1,
    }));
}

const tree = computed(() => buildNodes(null));

/** 親の候補。**archived は新しい親に選べない**（AR-11）。編集時だけ現在の親を残す */
function parentChoices(currentParentId: string | null): AdminTerm[] {
    return props.terms.filter(
        (term) => !term.archived || term.id === currentParentId,
    );
}

function labelFor(term: AdminTerm): string {
    return term.archived ? `${term.name}（アーカイブ済み）` : term.name;
}

// ── 追加 ────────────────────────────────────────────────────────

const addForm = useForm({ name: '', slug: '', parentId: '' });
const addOpen = ref(false);

function submitAdd() {
    // 未指定と明示 null を潰さないため、空の slug は**キーごと送らない**
    addForm
        .transform((data) => {
            const payload: Record<string, unknown> = {
                name: data.name,
                parentId: data.parentId === '' ? null : data.parentId,
            };

            if (data.slug.trim() !== '') {
                payload.slug = data.slug.trim();
            }

            return payload;
        })
        .post(storeUrl.value, {
            preserveScroll: true,
            onSuccess: () => {
                addForm.reset();
                addOpen.value = false;
            },
        });
}

// ── 編集 ────────────────────────────────────────────────────────

const editingId = ref<string | null>(null);
const editForm = useForm({ name: '', slug: '', parentId: '' });

function startEdit(term: AdminTerm) {
    editingId.value = term.id;
    editForm.clearErrors();
    editForm.name = term.name;
    editForm.slug = term.slug;
    editForm.parentId = term.parentId ?? '';
}

function cancelEdit() {
    editingId.value = null;
    editForm.reset();
}

function submitEdit() {
    const id = editingId.value;

    if (id === null) {
        return;
    }

    editForm
        .transform((data) => ({
            name: data.name,
            slug: data.slug,
            parentId: data.parentId === '' ? null : data.parentId,
        }))
        .patch(`/admin/terms/${id}`, {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
        });
}

const editingTerm = computed(
    () => props.terms.find((term) => term.id === editingId.value) ?? null,
);

// ── アーカイブ / 並べ替え ────────────────────────────────────────

function setArchived(term: AdminTerm, archived: boolean) {
    busy.value = true;
    router.patch(
        `/admin/terms/${term.id}`,
        { archived },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

/** 表示上の隣（archived を飛ばした位置）と入れ替えつつ、**兄弟全件**を送る */
function move(term: AdminTerm, direction: 'up' | 'down') {
    const parentId = term.parentId ?? null;
    const rendered = renderedSiblingIds(parentId);
    const index = rendered.indexOf(term.id);
    const neighbor =
        direction === 'up' ? rendered[index - 1] : rendered[index + 1];

    if (neighbor === undefined) {
        return;
    }

    // 兄弟**全件**の並びを保ったまま、対象と「表示上の隣」の **2 箇所だけを交換**する。
    // 抜き出して挿し直すと、間に挟まった非表示（アーカイブ済み）の兄弟まで動いてしまい、
    // 「上へ」と「下へ」で結果が非対称になる。
    const ordered = siblingsOf(parentId).map((sibling) => sibling.id);
    const from = ordered.indexOf(term.id);
    const to = ordered.indexOf(neighbor);

    if (from === -1 || to === -1) {
        return;
    }

    ordered[from] = neighbor;
    ordered[to] = term.id;

    busy.value = true;
    router.post(
        reorderUrl.value,
        { parentId, orderedTermIds: ordered },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function renderedSiblingIds(parentId: string | null): string[] {
    const find = (nodes: TermNode[]): TermNode[] | null => {
        for (const node of nodes) {
            if (node.term.id === parentId) {
                return node.children;
            }

            const found = find(node.children);

            if (found !== null) {
                return found;
            }
        }

        return null;
    };

    const nodes = parentId === null ? tree.value : (find(tree.value) ?? []);

    return nodes.map((node) => node.term.id);
}

// ── 削除（確認 / 拒否理由） ──────────────────────────────────────

const pendingTerm = ref<AdminTerm | null>(null);
const conflict = ref<TermDeleteConflict | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const term = pendingTerm.value;

        if (term === null) {
            return;
        }

        router.post(
            `/admin/terms/${term.id}/delete`,
            token ? { confirmation_token: token } : {},
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

function destroyTerm(term: AdminTerm) {
    pendingTerm.value = term;
    conflict.value = null;
    request();
}

const conflictTerm = computed(
    () =>
        props.terms.find((term) => term.id === conflict.value?.termId) ?? null,
);

onMounted(() => {
    const stop = router.on('flash', (event) => {
        const data = (event as CustomEvent).detail?.flash
            ?.termDeleteConflict as TermDeleteConflict | undefined;

        if (data) {
            conflict.value = data;
        }
    });

    onUnmounted(stop);
});

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(
    () =>
        (addForm.isDirty || editForm.isDirty) &&
        !(addForm.processing || editForm.processing),
);
</script>

<template>
    <Head :title="`${taxonomy.label} — 分類管理`" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex flex-col gap-1">
            <Link
                href="/admin/taxonomies"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                ← 分類管理へ戻る
            </Link>
            <!-- managed_by = code の分類は label / key を read-only 表示する（§R） -->
            <h1 class="text-xl font-semibold">{{ taxonomy.label }}</h1>
            <p class="text-sm text-muted-foreground">
                キー <code class="font-mono">{{ taxonomy.key }}</code>
                <span v-if="taxonomy.managedBy === 'code'">
                    ／ コード管理のため分類名とキーは変更できません
                </span>
            </p>
        </div>

        <div
            v-if="pageErrors.conflict || pageErrors.orderedTermIds"
            class="rounded-md border p-3"
        >
            <p
                class="text-sm text-destructive"
                data-testid="taxonomy-page-error"
            >
                {{ pageErrors.conflict ?? pageErrors.orderedTermIds }}
            </p>
        </div>

        <!-- 削除できなかった理由。**確認ダイアログとは別物**（conflict を確認へ変換しない） -->
        <section
            v-if="conflict"
            class="flex flex-col gap-2 rounded-xl border border-destructive/40 p-4"
            data-testid="term-delete-conflict"
        >
            <h2 class="font-medium">この項目は削除できません</h2>
            <p class="text-sm">{{ conflict.message }}</p>
            <ul
                v-if="conflict.references?.references.length"
                class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
            >
                <li
                    v-for="reference in conflict.references.references"
                    :key="reference.kind"
                >
                    {{ reference.label }} {{ reference.count }} 件
                </li>
            </ul>
            <ul
                v-if="conflict.references?.undetermined.length"
                class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
            >
                <li
                    v-for="(reason, i) in conflict.references.undetermined"
                    :key="i"
                >
                    {{ reason }}
                </li>
            </ul>
            <div class="flex flex-wrap gap-2">
                <!-- 「削除できません」で終わらせず、その場から archive できるようにする（§I.5） -->
                <Button
                    v-if="conflictTerm && !conflictTerm.archived"
                    size="sm"
                    :disabled="busy"
                    data-testid="term-archive-instead"
                    @click="setArchived(conflictTerm, true)"
                >
                    アーカイブして一覧から外す
                </Button>
                <Button size="sm" variant="outline" @click="conflict = null"
                    >閉じる</Button
                >
            </div>
        </section>

        <!-- 追加 -->
        <section class="flex flex-col gap-3 rounded-xl border p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-medium">項目を追加</h2>
                <Button
                    v-if="!addOpen && canWrite"
                    size="sm"
                    data-testid="term-add-open"
                    @click="addOpen = true"
                >
                    <Plus class="size-3.5" aria-hidden="true" />
                    追加する
                </Button>
            </div>

            <form
                v-if="addOpen"
                class="flex flex-col gap-3"
                @submit.prevent="submitAdd"
            >
                <div class="grid gap-2">
                    <Label for="term-name"
                        >名前 <span class="text-destructive">*</span></Label
                    >
                    <Input
                        id="term-name"
                        v-model="addForm.name"
                        data-testid="term-name-input"
                    />
                    <InputError :message="addForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="term-slug">スラッグ</Label>
                    <Input
                        id="term-slug"
                        v-model="addForm.slug"
                        placeholder="空のままで自動生成"
                    />
                    <InputError :message="addForm.errors.slug" />
                </div>
                <div v-if="taxonomy.hierarchical" class="grid gap-2">
                    <Label for="term-parent">親の項目</Label>
                    <select
                        id="term-parent"
                        v-model="addForm.parentId"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        data-testid="term-parent-select"
                    >
                        <option value="">なし（最上位）</option>
                        <!-- アーカイブ済みは新しい親に選べない（AR-11） -->
                        <option
                            v-for="term in parentChoices(null)"
                            :key="term.id"
                            :value="term.id"
                        >
                            {{ term.name }}
                        </option>
                    </select>
                    <InputError :message="addForm.errors.parentId" />
                </div>
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="addForm.processing"
                        >保存</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="
                            addOpen = false;
                            addForm.reset();
                        "
                    >
                        キャンセル
                    </Button>
                </div>
            </form>
        </section>

        <!-- 編集 -->
        <section
            v-if="editingTerm"
            class="flex flex-col gap-3 rounded-xl border p-4"
            data-testid="term-edit-form"
        >
            <h2 class="font-medium">
                「{{ editingTerm.name }}」を編集
                <Badge v-if="editingTerm.archived" variant="secondary"
                    >アーカイブ済み</Badge
                >
            </h2>
            <form class="flex flex-col gap-3" @submit.prevent="submitEdit">
                <div class="grid gap-2">
                    <Label for="term-edit-name"
                        >名前 <span class="text-destructive">*</span></Label
                    >
                    <Input
                        id="term-edit-name"
                        v-model="editForm.name"
                        data-testid="term-edit-name-input"
                    />
                    <InputError :message="editForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="term-edit-slug">スラッグ</Label>
                    <Input id="term-edit-slug" v-model="editForm.slug" />
                    <InputError :message="editForm.errors.slug" />
                </div>
                <div v-if="taxonomy.hierarchical" class="grid gap-2">
                    <Label for="term-edit-parent">親の項目</Label>
                    <select
                        id="term-edit-parent"
                        v-model="editForm.parentId"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        data-testid="term-edit-parent-select"
                    >
                        <option value="">なし（最上位）</option>
                        <!-- 既存のアーカイブ済みの親は維持できる（AR-11） -->
                        <option
                            v-for="term in parentChoices(editingTerm.parentId)"
                            :key="term.id"
                            :value="term.id"
                            :disabled="term.id === editingTerm.id"
                        >
                            {{ labelFor(term) }}
                        </option>
                    </select>
                    <InputError :message="editForm.errors.parentId" />
                </div>
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="editForm.processing"
                        >更新</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="cancelEdit"
                    >
                        キャンセル
                    </Button>
                </div>
            </form>
        </section>

        <!-- 一覧 -->
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-medium">項目</h2>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="show-archived"
                        :model-value="showArchived"
                        data-testid="show-archived-toggle"
                        @update:model-value="showArchived = $event === true"
                    />
                    <Label for="show-archived" class="text-sm font-normal">
                        アーカイブ済みを表示
                    </Label>
                </div>
            </div>

            <TermTree
                v-if="tree.length"
                :nodes="tree"
                :usage="usage"
                :busy="busy"
                :read-only="!canWrite"
                @edit="startEdit"
                @archive="setArchived($event, true)"
                @unarchive="setArchived($event, false)"
                @delete="destroyTerm"
                @move-up="move($event, 'up')"
                @move-down="move($event, 'down')"
            />
            <p
                v-else
                class="rounded-xl border px-4 py-8 text-center text-sm text-muted-foreground"
            >
                項目がまだありません。「追加する」から作成してください。
            </p>
        </section>

        <ConfirmationDialog
            :confirmation="confirmation"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>

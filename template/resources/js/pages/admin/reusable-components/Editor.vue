<script setup lang="ts">
/**
 * 再利用ブロックの編集（Phase 9.6 / P0-C7・`PHASE9_6_SPEC.md` §4.5）。
 *
 * ## 部品は流用し、書き込みだけ別にする
 *
 * Canvas / Layers / Inspector はページの編集画面と同じものを使う。
 * **`usePageDocument` は流用しない** — あれは `pageId` から URL を組み立てており、
 * 「送信先は全部 `/api/v1/pages/`」を `PageAdminTest` が固定している。
 * 流用するとその守りを緩めることになる。
 *
 * ## ここでの変更は、まだどのページにも出ない
 *
 * 保存するのは下書きである。**公開したときに**使っているページへ反映される。
 * だから画面は「いくつのページに関わるか」を**押す前に**出し、
 * 公開の確認はサーバーが出した文面をそのまま見せる。
 *
 * ## 版も取り消しも無い
 *
 * 共有物の版は「誰の操作を戻すか」が未定義になるので持たない（§4.5）。
 * 画面にも取り消しは置かない — **無いものを押せるように見せない**。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import CanvasFrame from '@/components/page-editor/CanvasFrame.vue';
import InspectorPanel from '@/components/page-editor/InspectorPanel.vue';
import LayersTree from '@/components/page-editor/LayersTree.vue';
import { Button } from '@/components/ui/button';
import { impactSummary } from '@/lib/reusable';
import { useReusableDocument } from '@/composables/useReusableDocument';
import type {
    ComponentSpec,
    DocumentNode,
    StylePropertySpec,
} from '@/types/document';

const props = defineProps<{
    component: {
        id: string;
        key: string;
        label: string;
        document: DocumentNode;
        published: boolean;
        archived: boolean;
    };
    impact: { publicPages: number; draftPages: number };
    specs: ComponentSpec[];
    styles: Record<string, StylePropertySpec>;
    backUrl: string;
}>();

const { document, error, saving, confirmation, save, publish } =
    useReusableDocument(props.component.id, props.component.document);

const selectedId = ref<string | null>(null);
const canvas = ref<InstanceType<typeof CanvasFrame> | null>(null);

const specs = computed<Record<string, ComponentSpec>>(() =>
    Object.fromEntries(props.specs.map((spec) => [spec.type, spec])),
);

const labels = computed<Record<string, string>>(() =>
    Object.fromEntries(props.specs.map((spec) => [spec.type, spec.label])),
);

/** 木を平らにする（選べる id を Canvas へ渡すため） */
const flattened = computed<DocumentNode[]>(() => {
    const out: DocumentNode[] = [];

    const walk = (node: DocumentNode): void => {
        out.push(node);
        (node.children ?? []).forEach(walk);
    };

    walk(document.value);

    return out;
});

const selectableIds = computed(() => flattened.value.map((node) => node.id));

const selected = computed<DocumentNode | null>(
    () => flattened.value.find((node) => node.id === selectedId.value) ?? null,
);

const summary = computed(() =>
    impactSummary(props.impact.publicPages, props.impact.draftPages),
);

/**
 * Inspector からの変更。
 *
 * **木をそのまま作り直して送る。** 実体は版を持たないので、差分の patch も
 * 楽観ロックも無い（§4.5）。送るのは「いまの木」である。
 */
async function update(changes: Record<string, unknown>): Promise<void> {
    if (selected.value === null) {
        return;
    }

    const next = applyTo(document.value, selected.value.id, changes);

    if (await save(next)) {
        canvas.value?.reload();
    }
}

/** その節点だけを差し替えた木を返す（**元の木は変えない**） */
function applyTo(
    node: DocumentNode,
    id: string,
    changes: Record<string, unknown>,
): DocumentNode {
    if (node.id === id) {
        const props_ = changes.props;
        const style = changes.style;

        return {
            ...node,
            props: { ...(node.props ?? {}), ...(props_ as object) },
            style: { ...(node.style ?? {}), ...(style as object) },
        };
    }

    return {
        ...node,
        children: (node.children ?? []).map((child) =>
            applyTo(child, id, changes),
        ),
    };
}

async function publishNow(): Promise<void> {
    // 1 度目で確認の文面が返る。押し直すとその token で通る
    if (await publish(confirmation.value?.token ?? null)) {
        /*
         * **画面に結果を反映する。**
         *
         * 公開の状態と関わるページの数はサーバーから渡されているので、
         * 引き直さないと「まだ公開していません」と言い続ける
         * （e2e で見つけた — 押した人には失敗したように見える）。
         */
        router.reload();
    }
}
</script>

<template>
    <Head :title="`再利用ブロック: ${component.label}`" />

    <div class="flex flex-col gap-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ component.label }}</h1>
                <p class="text-xs text-muted-foreground">{{ component.key }}</p>
            </div>

            <div class="flex items-center gap-2">
                <Button variant="outline" as-child>
                    <Link :href="backUrl">一覧へ</Link>
                </Button>
                <Button
                    :disabled="saving || component.archived"
                    data-testid="reusable-publish"
                    @click="publishNow"
                >
                    公開する
                </Button>
            </div>
        </div>

        <!--
            **押す前に、関わる数を出す。** 公開の確認はサーバーが出すが、
            直している人は「いくつのページに関わっているか」を先に知りたい
        -->
        <p class="text-sm" data-testid="reusable-impact">{{ summary }}</p>

        <p
            v-if="!component.published"
            class="rounded-md border border-amber-500/60 bg-amber-500/10 px-2 py-1.5 text-xs"
            data-testid="reusable-unpublished"
        >
            まだ公開していません。ここでの変更は、公開するまでページに出ません。
        </p>

        <!-- 公開の確認。**文面はサーバーが出したものをそのまま見せる** -->
        <div
            v-if="confirmation"
            class="rounded-md border border-amber-500/60 bg-amber-500/10 p-3 text-xs"
            data-testid="reusable-confirmation"
        >
            <p class="font-medium">{{ confirmation.summary }}</p>
            <ul class="mt-1 list-disc pl-4">
                <li v-for="line in confirmation.impact" :key="line">
                    {{ line }}
                </li>
            </ul>
            <Button
                class="mt-2"
                size="sm"
                data-testid="reusable-publish-confirm"
                @click="publishNow"
            >
                このまま公開する
            </Button>
        </div>

        <p
            v-if="error"
            class="text-xs text-destructive"
            data-testid="reusable-error"
        >
            {{ error }}
        </p>

        <div class="grid gap-3 lg:grid-cols-[240px_1fr_280px]">
            <section class="rounded-md border p-2">
                <h2 class="mb-2 text-sm font-medium">構造</h2>
                <LayersTree
                    :nodes="document.children ?? []"
                    :parent-id="document.id"
                    :selected-id="selectedId"
                    :labels="labels"
                    :dragging="null"
                    :can-drop-into="() => false"
                    :blocked="saving"
                    @select="selectedId = $event"
                />
            </section>

            <CanvasFrame
                ref="canvas"
                :src="`${backUrl}/${component.id}/preview?canvas=1`"
                :selected-id="selectedId"
                :selectable-ids="selectableIds"
                :width="null"
                @select="selectedId = $event"
            />

            <section class="rounded-md border p-2">
                <h2 class="mb-2 text-sm font-medium">設定</h2>
                <InspectorPanel
                    v-if="selected && specs[selected.type]"
                    :node="selected"
                    :spec="specs[selected.type]"
                    :styles="styles"
                    :tokens="{}"
                    :breakpoint="null"
                    :sources="{ entry: [], collection: [], page: [] }"
                    :reusables="[]"
                    :hidden-by-ancestor="false"
                    :blocked="saving"
                    @change="update"
                />
                <p v-else class="text-xs text-muted-foreground">
                    「構造」からブロックを選んでください。
                </p>
            </section>
        </div>
    </div>
</template>

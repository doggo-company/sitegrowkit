<script setup lang="ts">
/**
 * Layers（Phase 9.6 / P0-C2・`PHASE9_6_SPEC.md` §4.2）。
 *
 * document の木をそのまま出す。**選択状態も配置の可否も親が持つ**ので、
 * ここは表示と「どこへ落とそうとしているか」の通知だけを担当する。
 *
 * ## 置ける場所しか受け付けない
 *
 * drop を受けるかどうかは `canDropInto()`（Registry の `allowedParents` /
 * `allowedChildren` と循環参照の判定）に必ず訊く。**受けられない場所では
 * `dragover` を許可しない**ので、そもそも drop が起きない。validator は最後の砦であって、
 * ここで止めるのが本来の順序（§4.1）。
 *
 * ## キーボードでも同じことができる
 *
 * drag は指せる人にしか使えない。`Alt` + 矢印で同じ操作を割り当てる:
 *
 * | キー | 動き |
 * | --- | --- |
 * | `Alt` + `↑` / `↓` | 同じ親の中で前後へ |
 * | `Alt` + `→` | 直前の兄弟の**中へ**（入れ子にする） |
 * | `Alt` + `←` | 親の**外へ**（親の直後へ出す） |
 */
import type { DocumentNode } from '@/types/document';

defineOptions({ name: 'LayersTree' });

const props = defineProps<{
    nodes: DocumentNode[];
    /** この一覧を保持している節点の id */
    parentId: string;
    selectedId: string | null;
    /** type → 日本語の表示名（Registry 由来） */
    labels: Record<string, string>;
    /** いま掴んでいるブロック。掴んでいなければ null */
    dragging: string | null;
    /** その節点の中へ、いま掴んでいるものを置けるか */
    canDropInto: (parentId: string) => boolean;
    /** 操作を受け付けない状態（保存中・競合中） */
    blocked: boolean;
    /** ボタン・キーボードとも同じ配置規則で可否を決める。 */
    canNudge?: (id: string, direction: 'up' | 'down' | 'in' | 'out') => boolean;
}>();

const emit = defineEmits<{
    (e: 'select', id: string): void;
    (e: 'dragging', id: string | null): void;
    (e: 'place', payload: { parentId: string; afterId: string | null }): void;
    (
        e: 'nudge',
        payload: { id: string; direction: 'up' | 'down' | 'in' | 'out' },
    ): void;
}>();

/** 落とし込み線を受け付けるか（この一覧の親へ入れられるか） */
function acceptsLine(): boolean {
    return (
        !props.blocked &&
        props.dragging !== null &&
        props.canDropInto(props.parentId)
    );
}

/** 行そのものへ落とす＝その節点の中へ入れる */
function acceptsRow(node: DocumentNode): boolean {
    return (
        !props.blocked && props.dragging !== null && props.canDropInto(node.id)
    );
}

function allowLine(event: DragEvent): void {
    if (acceptsLine()) {
        event.preventDefault();
    }
}

function allowRow(event: DragEvent, node: DocumentNode): void {
    if (acceptsRow(node)) {
        event.preventDefault();
    }
}

function dropOnLine(event: DragEvent, afterId: string | null): void {
    if (!acceptsLine()) {
        return;
    }

    event.preventDefault();
    emit('place', { parentId: props.parentId, afterId });
}

function dropOnRow(event: DragEvent, node: DocumentNode): void {
    if (!acceptsRow(node)) {
        return;
    }

    event.preventDefault();
    // 中へ入れるときは末尾へ付ける
    const last = node.children?.[(node.children?.length ?? 0) - 1] ?? null;
    emit('place', { parentId: node.id, afterId: last?.id ?? null });
}

function onKeydown(event: KeyboardEvent, node: DocumentNode): void {
    if (!event.altKey || props.blocked) {
        return;
    }

    const direction = {
        ArrowUp: 'up',
        ArrowDown: 'down',
        ArrowRight: 'in',
        ArrowLeft: 'out',
    }[event.key] as 'up' | 'down' | 'in' | 'out' | undefined;

    if (direction === undefined) {
        return;
    }

    event.preventDefault();
    emit('nudge', { id: node.id, direction });
}

/**
 * 自動で付いた識別名か（`section`・複製で付く `image-2` など）。
 * 英語の型名に番号が付いただけなので、階層には出さない（UX 一周 2026-09-28）。
 */
function autoKey(node: DocumentNode): boolean {
    return new RegExp(`^${node.type}(-\\d+)?$`).test(node.key ?? '');
}
</script>

<template>
    <ul class="space-y-0.5">
        <li v-for="(node, index) in nodes" :key="node.id">
            <!-- この位置へ入れるための線。掴んでいるあいだだけ反応する -->
            <div
                class="h-1 rounded transition-colors"
                :class="
                    dragging && acceptsLine() ? 'bg-muted hover:bg-primary' : ''
                "
                :data-testid="`layer-line-${node.id}`"
                @dragover="allowLine"
                @drop="dropOnLine($event, nodes[index - 1]?.id ?? null)"
            />

            <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-muted"
                :class="[
                    node.id === selectedId
                        ? 'bg-muted font-medium ring-1 ring-primary'
                        : '',
                    dragging && dragging !== node.id
                        ? acceptsRow(node)
                            ? 'outline-1 outline-primary outline-dashed'
                            : 'opacity-60'
                        : '',
                ]"
                :draggable="!blocked"
                :aria-current="node.id === selectedId ? 'true' : undefined"
                :aria-grabbed="dragging === node.id ? 'true' : undefined"
                :data-testid="`layer-${node.type}`"
                :data-node-id="node.id"
                @click="emit('select', node.id)"
                @keydown="onKeydown($event, node)"
                @dragstart="
                    (emit('select', node.id), emit('dragging', node.id))
                "
                @dragend="emit('dragging', null)"
                @dragover="allowRow($event, node)"
                @drop="dropOnRow($event, node)"
            >
                <span>{{ labels[node.type] ?? node.type }}</span>
                <!-- 選択行の淡い背景でも部品の識別名を読めるコントラストにする。 -->
                <!-- 型名と同じ識別名（`section` など）は出さない。英語の型名が並ぶだけになる -->
                <span
                    v-if="node.key && !autoKey(node)"
                    class="text-xs text-foreground"
                >
                    {{ node.key }}
                </span>
            </button>

            <!-- タッチでも選んだ行のすぐ下で移動でき、長い画面の末尾へ往復しない。 -->
            <div
                v-if="node.id === selectedId && canNudge"
                class="my-1 grid grid-cols-2 gap-1 lg:hidden"
                aria-label="選択したブロックの移動"
            >
                <button
                    v-for="action in [
                        ['up', '上へ'],
                        ['down', '下へ'],
                        ['in', '中へ'],
                        ['out', '外へ'],
                    ] as const"
                    :key="action[0]"
                    type="button"
                    class="min-h-11 rounded border px-2 text-xs hover:bg-muted disabled:opacity-40"
                    :disabled="blocked || !canNudge?.(node.id, action[0])"
                    :data-testid="`layer-move-${action[0]}`"
                    @click="
                        emit('nudge', { id: node.id, direction: action[0] })
                    "
                >
                    {{ action[1] }}
                </button>
            </div>

            <LayersTree
                v-if="node.children && node.children.length > 0"
                class="ml-3 border-l pl-2"
                :nodes="node.children"
                :parent-id="node.id"
                :selected-id="selectedId"
                :labels="labels"
                :dragging="dragging"
                :can-drop-into="canDropInto"
                :blocked="blocked"
                :can-nudge="canNudge"
                @select="emit('select', $event)"
                @dragging="emit('dragging', $event)"
                @place="emit('place', $event)"
                @nudge="emit('nudge', $event)"
            />
        </li>

        <!-- 末尾へ入れるための線 -->
        <li v-if="nodes.length > 0">
            <div
                class="h-1 rounded transition-colors"
                :class="
                    dragging && acceptsLine() ? 'bg-muted hover:bg-primary' : ''
                "
                :data-testid="`layer-line-end-${parentId}`"
                @dragover="allowLine"
                @drop="dropOnLine($event, nodes[nodes.length - 1].id)"
            />
        </li>
    </ul>
</template>

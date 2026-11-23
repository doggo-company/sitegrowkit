<script setup lang="ts">
/**
 * 分類の項目 tree（Phase 9.3B / #16・SPEC §I.7）。
 *
 * **表示だけを担当する。** mutation は親（Show.vue）→ Admin Controller →
 * Application Service へ流し、ここに業務ロジックを持たない。
 *
 * hierarchy の規則:
 * - archived な親の配下にある active な子を **孤児 root として描かない**（HR-4）
 * - 「アーカイブ済みを表示」が off でも、**表示すべき子孫を持つ archived な親は
 *   階層を保つために出す**。その行は「アーカイブ済み」と明示する
 * - 状態を色だけで伝えない（必ず文字ラベルを併記する）
 */
import {
    ArchiveRestore,
    Archive,
    ChevronDown,
    ChevronUp,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AdminTerm, TermNode } from '@/types/taxonomy';

defineProps<{
    nodes: TermNode[];
    /** 使用件数（導出値）。term ID → 件数 */
    usage: Record<string, number>;
    busy: boolean;
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    (
        e:
            | 'edit'
            | 'archive'
            | 'unarchive'
            | 'delete'
            | 'move-up'
            | 'move-down',
        term: AdminTerm,
    ): void;
}>();
</script>

<template>
    <ul class="flex flex-col gap-1">
        <li v-for="node in nodes" :key="node.term.id">
            <div
                class="flex flex-wrap items-center gap-2 rounded-md border px-3 py-2"
                :class="node.term.archived ? 'bg-muted/40' : ''"
                :data-testid="`term-row-${node.term.slug}`"
            >
                <div
                    class="flex min-w-0 flex-1 basis-full flex-col gap-0.5 sm:basis-auto"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ node.term.name }}</span>
                        <!-- 状態は色ではなく文字で伝える -->
                        <Badge v-if="node.term.archived" variant="secondary">
                            アーカイブ済み
                        </Badge>
                        <Badge v-if="node.keptForHierarchy" variant="outline">
                            階層のため表示中
                        </Badge>
                    </div>
                    <span
                        class="font-mono text-xs break-all text-muted-foreground"
                    >
                        {{ node.term.slug }}
                        <span v-if="node.term.generatedSlug">（自動生成）</span>
                    </span>
                </div>

                <!--
                    この件数は **コンテンツからの割り当て**だけ（§R の使用件数）。
                    子の項目・ページ・コンテンツタイプ定義からの参照は含まないので、
                    「0 件なら必ず削除できる」と読めない文言にする。削除可否の判定者は
                    常に Reference Inspector であり、この数値ではない。
                -->
                <span class="text-xs whitespace-nowrap text-muted-foreground">
                    コンテンツ {{ usage[node.term.id] ?? 0 }} 件
                </span>

                <!-- 狭い画面では折り返す（ボタンが画面外へ出て操作不能にならないように） -->
                <div v-if="!readOnly" class="flex flex-wrap items-center gap-1">
                    <!-- 並べ替えは drag だけに依存しない。キーボードで操作できる（§AB） -->
                    <Button
                        variant="ghost"
                        size="icon"
                        :disabled="busy || !node.canMoveUp"
                        :aria-label="`${node.term.name}を上へ移動`"
                        :data-testid="`term-move-up-${node.term.slug}`"
                        @click="emit('move-up', node.term)"
                    >
                        <ChevronUp class="size-4" aria-hidden="true" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :disabled="busy || !node.canMoveDown"
                        :aria-label="`${node.term.name}を下へ移動`"
                        :data-testid="`term-move-down-${node.term.slug}`"
                        @click="emit('move-down', node.term)"
                    >
                        <ChevronDown class="size-4" aria-hidden="true" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="busy"
                        :data-testid="`term-edit-${node.term.slug}`"
                        @click="emit('edit', node.term)"
                    >
                        <Pencil class="size-3.5" aria-hidden="true" />
                        編集
                    </Button>
                    <Button
                        v-if="node.term.archived"
                        variant="ghost"
                        size="sm"
                        :disabled="busy"
                        :data-testid="`term-unarchive-${node.term.slug}`"
                        @click="emit('unarchive', node.term)"
                    >
                        <ArchiveRestore class="size-3.5" aria-hidden="true" />
                        アーカイブ解除
                    </Button>
                    <Button
                        v-else
                        variant="ghost"
                        size="sm"
                        :disabled="busy"
                        :data-testid="`term-archive-${node.term.slug}`"
                        @click="emit('archive', node.term)"
                    >
                        <Archive class="size-3.5" aria-hidden="true" />
                        アーカイブ
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        :disabled="busy"
                        :data-testid="`term-delete-${node.term.slug}`"
                        @click="emit('delete', node.term)"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />
                        削除
                    </Button>
                </div>
            </div>

            <!-- 子は親の配下に置く。archived な親でも階層を潰さない（HR-4 / HR-6） -->
            <div v-if="node.children.length" class="mt-1 border-l pl-4 sm:pl-6">
                <TermTree
                    :nodes="node.children"
                    :usage="usage"
                    :busy="busy"
                    :read-only="readOnly"
                    @edit="emit('edit', $event)"
                    @archive="emit('archive', $event)"
                    @unarchive="emit('unarchive', $event)"
                    @delete="emit('delete', $event)"
                    @move-up="emit('move-up', $event)"
                    @move-down="emit('move-down', $event)"
                />
            </div>
        </li>
    </ul>
</template>

<script setup lang="ts">
/**
 * 操作履歴の表 — PHASE WP-BASELINE / STAGE #18A・SPEC §1C.2。
 *
 * 全体履歴とユーザー別履歴で同じ表を使う。
 * **すべてテキストとして描画する**（`v-html` は使わない）。
 * IP と User-Agent はサーバーがオーナーにだけ渡すので、
 * ここでは「来ていれば出す」だけにする。
 */
import { Badge } from '@/components/ui/badge';
import type { ActivityEntry } from '@/types/user';

defineProps<{ entries: ActivityEntry[]; showNetwork?: boolean }>();
</script>

<template>
    <!-- 横スクロールする領域はキーボードでも操作できるようにする（axe: scrollable-region-focusable） -->
    <div
        class="overflow-x-auto rounded-xl border"
        tabindex="0"
        role="region"
        aria-label="操作履歴の表"
    >
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left">
                    <th class="px-4 py-3 font-medium text-muted-foreground">
                        日時
                    </th>
                    <th class="px-4 py-3 font-medium text-muted-foreground">
                        実行者
                    </th>
                    <th class="px-4 py-3 font-medium text-muted-foreground">
                        操作
                    </th>
                    <th class="px-4 py-3 font-medium text-muted-foreground">
                        対象
                    </th>
                    <th class="px-4 py-3 font-medium text-muted-foreground">
                        結果
                    </th>
                    <th class="px-4 py-3 font-medium text-muted-foreground">
                        経路
                    </th>
                    <th
                        v-if="showNetwork"
                        class="px-4 py-3 font-medium text-muted-foreground"
                    >
                        接続元
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="entry in entries"
                    :key="entry.id"
                    class="border-b last:border-0"
                    data-testid="activity-row"
                >
                    <td class="px-4 py-3 whitespace-nowrap">
                        {{ entry.occurredAt }}
                    </td>
                    <td class="px-4 py-3">{{ entry.actorLabel }}</td>
                    <td class="px-4 py-3">
                        <div>{{ entry.operationLabel }}</div>
                        <div
                            v-if="entry.summary"
                            class="text-xs text-muted-foreground"
                        >
                            {{ entry.summary }}
                        </div>
                    </td>
                    <td class="px-4 py-3 text-xs text-muted-foreground">
                        {{ entry.resourceType ?? '—'
                        }}{{ entry.resourceId ? ` #${entry.resourceId}` : '' }}
                    </td>
                    <td class="px-4 py-3">
                        <Badge
                            :variant="
                                entry.result === 'success'
                                    ? 'outline'
                                    : 'destructive'
                            "
                        >
                            {{ entry.resultLabel }}
                        </Badge>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                        {{ entry.interfaceLabel }}
                    </td>
                    <td
                        v-if="showNetwork"
                        class="px-4 py-3 text-xs text-muted-foreground"
                    >
                        <span v-if="entry.anonymized">（匿名化済み）</span>
                        <span v-else>{{ entry.ipAddress ?? '—' }}</span>
                    </td>
                </tr>
                <tr v-if="entries.length === 0">
                    <td
                        class="px-4 py-6 text-center text-muted-foreground"
                        :colspan="showNetwork ? 7 : 6"
                    >
                        条件に合う履歴がありません。
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup lang="ts">
/**
 * AI 連携のはじめかた（Stage 1）。
 *
 * ## 見せるのは「その場で打てること」だけ
 *
 * 設定を書き出す仕組みは `agentcms setup`（セットアップ画面）に**もう在る**。
 * ここが足すのは**そこへ辿る道**と、**打てば動く 1 行**である。
 * 説明は書かない — 詳細は `docs/AI_AGENTS.md` が持つ（**正本を 2 つ作らない**）。
 */
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    AGENT_TABS,
    FIRST_PROMPT,
    MCP_SETUP_COMMAND,
    OTHER_AGENTS_NOTE,
} from '@/lib/ai-setup';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const active = ref(AGENT_TABS[0]?.id ?? '');
const copied = ref('');

/** **押したら中身が入る。** 表示だけあって空、を作らない */
async function copy(what: string, text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
    } catch {
        // クリップボードを使えない環境（権限・非 https）では**黙って失敗しない**
        copied.value = 'failed';
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-2xl" data-testid="ai-setup-panel">
            <DialogHeader>
                <DialogTitle>AI でカスタマイズする</DialogTitle>
                <DialogDescription>
                    お使いの AI
                    エージェントをプロジェクトのフォルダで開き、やりたいことを日本語で伝えてください。
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex flex-wrap gap-1"
                role="tablist"
                aria-label="エージェントを選ぶ"
            >
                <Button
                    v-for="tab in AGENT_TABS"
                    :key="tab.id"
                    :variant="active === tab.id ? 'default' : 'ghost'"
                    size="sm"
                    role="tab"
                    :aria-selected="active === tab.id"
                    :data-testid="`ai-setup-tab-${tab.id}`"
                    @click="active = tab.id"
                >
                    {{ tab.label }}
                </Button>
            </div>

            <div
                v-for="tab in AGENT_TABS"
                :key="tab.id"
                v-show="active === tab.id"
                class="space-y-4"
            >
                <div class="space-y-1.5">
                    <p class="text-sm font-medium">
                        1. プロジェクトのフォルダで起動する
                    </p>
                    <div class="flex items-center gap-2">
                        <code
                            class="flex-1 rounded-md border bg-muted px-3 py-2 text-sm"
                            >{{ tab.launch }}</code
                        >
                        <Button
                            size="sm"
                            variant="secondary"
                            @click="copy('launch', tab.launch)"
                        >
                            コピー
                        </Button>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-sm font-medium">
                        2. この CMS につなぐ（MCP）
                    </p>
                    <div class="flex items-center gap-2">
                        <code
                            class="flex-1 rounded-md border bg-muted px-3 py-2 text-sm"
                            >{{ MCP_SETUP_COMMAND }}</code
                        >
                        <Button
                            size="sm"
                            variant="secondary"
                            @click="copy('mcp', MCP_SETUP_COMMAND)"
                        >
                            コピー
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        セットアップ画面の「AI
                        エージェント連携」から設定を書き出せます。
                    </p>
                    <!-- **差を正直に書く。** 黙っていると、手動側の人は自分のやり方を疑う -->
                    <p v-if="tab.note" class="text-xs text-muted-foreground">
                        {{ tab.note }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <p class="text-sm font-medium">3. 最初のプロンプト</p>
                    <pre
                        class="overflow-x-auto rounded-md border bg-muted px-3 py-2 text-xs whitespace-pre-wrap"
                        >{{ FIRST_PROMPT }}</pre>
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="copy('prompt', FIRST_PROMPT)"
                    >
                        プロンプトをコピー
                    </Button>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">{{ OTHER_AGENTS_NOTE }}</p>

            <p
                v-if="copied"
                class="text-xs"
                :class="
                    copied === 'failed'
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{
                    copied === 'failed'
                        ? 'コピーできませんでした。手で選んでコピーしてください。'
                        : 'コピーしました。'
                }}
            </p>
        </DialogContent>
    </Dialog>
</template>

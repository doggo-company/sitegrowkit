<script setup lang="ts">
/**
 * 本文へ画像を挿すボタン（WP 一周 7 周目 2026-09-03 / 効くもの ①）。
 *
 * ## なぜ `MediaPicker` を使わないか
 *
 * あちらは「1 つ選んで**保持する**」欄の部品で、サムネイルと現在値を抱えている。
 * ここが要るのは「選んで**その場で挿して忘れる**」動きなので、形が違う。
 * 無理に流用すると、書式ボタンの列にサムネイルが並ぶことになる。
 *
 * 一覧そのものは `useMediaLibrary` を共有するので、**取得は 1 回**に畳まれる。
 *
 * ## 挿す形は決めない
 *
 * ここは選ばれたものを渡すだけで、`<img>` の組み立ては `@/lib/richtext` が持つ。
 * 出す属性が allowlist の中にあることは `RichtextToolbarTest` が見ている。
 */
import { ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useMediaLibrary } from '@/composables/useMediaLibrary';
import type { MediaItem } from '@/types/media';

const props = withDefaults(defineProps<{ label?: string }>(), {
    label: '画像',
});

const emit = defineEmits<{ select: [image: MediaItem] }>();

const open = ref(false);
const { media, loading, load } = useMediaLibrary('image');

watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

function choose(item: MediaItem): void {
    emit('select', item);
    open.value = false;
}
</script>

<template>
    <Button
        type="button"
        variant="outline"
        size="sm"
        data-testid="richtext-image-open"
        @click="open = true"
    >
        {{ props.label }}
    </Button>

    <Dialog v-model:open="open">
        <DialogContent data-testid="richtext-image-dialog">
            <DialogHeader>
                <DialogTitle>本文に入れる画像を選ぶ</DialogTitle>
            </DialogHeader>

            <p v-if="loading" class="text-sm text-muted-foreground">
                読み込んでいます…
            </p>

            <!-- **「まだ無い」と「読み込めなかった」を混ぜない** -->
            <p
                v-else-if="media.length === 0"
                class="text-sm text-muted-foreground"
                data-testid="richtext-image-empty"
            >
                まだ画像がありません。メディアの画面からアップロードしてください。
            </p>

            <ul v-else class="grid max-h-96 grid-cols-3 gap-3 overflow-y-auto">
                <li v-for="item in media" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full flex-col gap-1 rounded-md border p-2 text-left hover:bg-muted"
                        data-testid="richtext-image-choice"
                        @click="choose(item)"
                    >
                        <img
                            v-if="item.url"
                            :src="item.url"
                            :alt="item.alt ?? ''"
                            class="h-20 w-full rounded object-cover"
                        />
                        <span class="truncate text-xs">{{ item.name }}</span>
                        <!-- 説明が無いことを、選ぶ前に見せる（読み上げに使われる） -->
                        <span
                            v-if="!item.alt"
                            class="text-xs text-muted-foreground"
                            >説明なし</span
                        >
                    </button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
/**
 * `images` — 複数の画像を **順序を保って**持つ（Phase 9.5-C / ADR-0017 §7）。
 *
 * 選ぶ操作そのものは既存の `MediaPicker` に任せる。ここが持つのは
 * 「並び」と「重複を作らないこと」だけで、選択の UI を二重に作らない。
 *
 * 値は Media ID の list。path / disk / URL は持たない（ADR-0006）。
 */
import { computed, onMounted, ref } from 'vue';
import MediaPicker from '@/components/schema/MediaPicker.vue';
import { Button } from '@/components/ui/button';
import { useMediaLibrary } from '@/composables/useMediaLibrary';

const model = defineModel<number[]>({ default: () => [] });

withDefaults(defineProps<{ disabled?: boolean; labelledBy?: string }>(), {
    disabled: false,
    labelledBy: undefined,
});

/**
 * 選択済みの表示は **共有の一覧から引く**。
 *
 * 1 件ごとに MediaPicker を置くと、各 instance が同じ一覧を取りに行って
 * N 件で N 回の要求になる。ここは読むだけなので、共有の取得を 1 度呼ぶ。
 *
 * `images` が指すのは画像だけなので、下のピッカーと**同じ**画像の一覧を使う
 * （種別を渡さないと別の要求になり、上限 200 件の効き方も変わる）。
 */
const { load, find } = useMediaLibrary('image');

onMounted(() => void load());

/** 追加用のピッカーの値。選ばれたらリストへ移して空へ戻す */
const picking = ref<number | null>(null);

const items = computed<number[]>(() => model.value ?? []);

/** 重複は作らない。同じ画像を 2 回並べても意味が無い */
const duplicate = ref(false);

function add(id: number | null | undefined): void {
    if (id === null || id === undefined) {
        return;
    }

    duplicate.value = items.value.includes(id);

    if (!duplicate.value) {
        model.value = [...items.value, id];
    }

    picking.value = null;
}

function remove(index: number): void {
    duplicate.value = false;
    model.value = items.value.filter((_, i) => i !== index);
}

function move(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= items.value.length) {
        return;
    }

    duplicate.value = false;
    model.value = items.value.map((id, i) => {
        if (i === index) {
            return items.value[target];
        }

        if (i === target) {
            return items.value[index];
        }

        return id;
    });
}
</script>

<template>
    <div class="flex flex-col gap-3" :aria-labelledby="labelledBy">
        <ul v-if="items.length" class="flex flex-col gap-2">
            <li
                v-for="(id, index) in items"
                :key="`${id}-${index}`"
                class="flex items-center gap-2 rounded-lg border p-2"
                data-testid="images-item"
            >
                <span class="text-sm text-muted-foreground"
                    >{{ index + 1 }}.</span
                >
                <div
                    class="grid h-16 w-20 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted"
                >
                    <!-- images は画像だけを指すが、url が無いものを <img src=null> にしない -->
                    <img
                        v-if="find(id)?.url"
                        :src="find(id)?.url ?? undefined"
                        :alt="find(id)?.alt ?? ''"
                        class="h-full w-full object-cover"
                        data-testid="images-thumbnail"
                    />
                    <span v-else class="text-xs text-muted-foreground">…</span>
                </div>
                <span class="flex-1 text-sm">{{
                    find(id)?.name ?? `ID ${id}`
                }}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="disabled || index === 0"
                    data-testid="images-move-up"
                    @click="move(index, -1)"
                >
                    上へ
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="disabled || index === items.length - 1"
                    data-testid="images-move-down"
                    @click="move(index, 1)"
                >
                    下へ
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="disabled"
                    data-testid="images-remove"
                    @click="remove(index)"
                >
                    外す
                </Button>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">
            画像はまだ選ばれていません。
        </p>

        <div class="flex flex-col gap-1">
            <MediaPicker
                :model-value="picking"
                :disabled="disabled"
                data-testid="images-add"
                @update:model-value="add"
            />
            <p
                v-if="duplicate"
                class="text-xs text-destructive"
                data-testid="images-duplicate"
            >
                その画像はすでに選ばれています。
            </p>
        </div>
    </div>
</template>

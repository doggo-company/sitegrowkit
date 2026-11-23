<script setup lang="ts">
/**
 * `relation` / `relations` — 他の投稿タイプへの参照（Phase 9.5-C / ADR-0017 §6）。
 *
 * 候補は **初期 HTML に全件入れない**。投稿数だけ増えるので、
 * 管理画面の内部 route から検索付きで少しずつ取りに来る。
 * 実在確認・target 違い・重複・順序は **サーバーでも**検証される。
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface RelationOption {
    id: number;
    title: string;
    /**
     * 参照先がゴミ箱にあるか（Phase 9.8 / STAGE 21B）。
     *
     * 候補にはゴミ箱の中身が出てこないので、これが真になるのは
     * **既に選ばれているもの**だけである。表示の仕方は STAGE 21E。
     */
    trashed?: boolean;
}

const props = withDefaults(
    defineProps<{
        target: string;
        multiple?: boolean;
        disabled?: boolean;
        labelledBy?: string;
    }>(),
    { multiple: false, disabled: false, labelledBy: undefined },
);

const model = defineModel<number | number[] | null>();

const options = ref<RelationOption[]>([]);
const loading = ref(false);
const failed = ref(false);
const search = ref('');
const duplicate = ref(false);

/**
 * 選択中の ID の表示名。候補一覧とは **別に**覚えておく。
 *
 * 候補は先頭 20 件しか返らないので、それより後ろにある既存の選択は
 * 一覧から名前を引けない。選んだ本人が何を選んだのか読めないのは不親切なので、
 * 選択中の ID だけを名前解決して保持する。
 */
const resolved = ref<Record<number, string>>({});

/** 表示名は候補 → 解決済みの順に重ねる。どちらにも無ければ ID を出す（嘘をつかない） */
const titles = computed<Record<number, string>>(() => ({
    ...Object.fromEntries(options.value.map((o) => [o.id, o.title])),
    ...resolved.value,
}));

/**
 * 競合の防止。
 *
 * 検索は打つたびに走るので、**古い応答が新しい結果を上書きし得る**。
 * 打鍵をまとめてから投げ（debounce）、投げ直すときは前の要求を捨てる（abort）。
 */
let debounce: ReturnType<typeof setTimeout> | null = null;
let inflight: AbortController | null = null;

const selected = computed<number[]>(() => {
    if (props.multiple) {
        return Array.isArray(model.value) ? model.value : [];
    }

    return typeof model.value === 'number' ? [model.value] : [];
});

/** 打ち切りか、本当に失敗したか */
function isAbort(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

async function load(): Promise<void> {
    if (props.disabled) {
        return;
    }

    loading.value = true;
    failed.value = false;

    inflight?.abort();
    const controller = new AbortController();
    inflight = controller;

    try {
        const url = `/admin/relations/${props.target}/options?q=${encodeURIComponent(search.value)}`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        options.value = res.ok
            ? ((await res.json()) as { options: RelationOption[] }).options
            : [];
        failed.value = !res.ok;
        loading.value = false;
    } catch (error) {
        /*
         * **打ち切りと本当の失敗を分ける。**
         *
         * 打ち切り（新しい検索で投げ直した）なら、後から来る要求が状態を
         * 引き継ぐので何も書き換えない。ネットワーク断などの実エラーは、
         * `loading` を必ず解除する — 解除しないと永久に「読み込み中」のまま
         * 何も操作できなくなる。もう一度打てば再試行できる状態へ戻す。
         */
        if (!isAbort(error)) {
            options.value = [];
            failed.value = true;
            loading.value = false;
        }
    }
}

/** サーバーが 1 度に返す件数。これを超える選択は分けて引く */
const CHUNK = 20;

/**
 * 選択中の ID の名前を引く。候補の取得とは別の要求にする。
 *
 * `relations` の選択数に上限は無いので、**20 件ずつに分けて**全部引く。
 * 分けないと 21 件目以降がいつまでも「ID 42」のままになる。
 */
async function resolveSelected(): Promise<void> {
    const missing = selected.value.filter(
        (id) => resolved.value[id] === undefined,
    );

    if (missing.length === 0 || props.disabled) {
        return;
    }

    const found: Record<number, string> = {};

    try {
        for (let i = 0; i < missing.length; i += CHUNK) {
            const chunk = missing.slice(i, i + CHUNK);

            const res = await fetch(
                `/admin/relations/${props.target}/options?ids=${chunk.join(',')}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!res.ok) {
                break;
            }

            const json = (await res.json()) as { options: RelationOption[] };

            for (const option of json.options) {
                found[option.id] = option.title;
            }
        }
    } catch {
        // 名前が引けなくても ID で出せる。ここで未処理の rejection を出さない
    }

    resolved.value = { ...resolved.value, ...found };
}

onMounted(() => {
    void load();
    void resolveSelected();
});

watch(search, () => {
    if (debounce !== null) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(() => void load(), 250);
});

watch(selected, () => void resolveSelected());

onUnmounted(() => {
    if (debounce !== null) {
        clearTimeout(debounce);
    }

    inflight?.abort();
});

function pick(id: number): void {
    duplicate.value = false;

    if (!props.multiple) {
        model.value = id;

        return;
    }

    if (selected.value.includes(id)) {
        duplicate.value = true;

        return;
    }

    model.value = [...selected.value, id];
}

function remove(index: number): void {
    duplicate.value = false;

    if (!props.multiple) {
        model.value = null;

        return;
    }

    model.value = selected.value.filter((_, i) => i !== index);
}

function move(index: number, delta: number): void {
    const target = index + delta;

    if (!props.multiple || target < 0 || target >= selected.value.length) {
        return;
    }

    model.value = selected.value.map((id, i) => {
        if (i === index) {
            return selected.value[target];
        }

        if (i === target) {
            return selected.value[index];
        }

        return id;
    });
}
</script>

<template>
    <div class="flex flex-col gap-3" :aria-labelledby="labelledBy">
        <ul v-if="selected.length" class="flex flex-col gap-2">
            <li
                v-for="(id, index) in selected"
                :key="`${id}-${index}`"
                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                data-testid="relation-selected"
            >
                <span class="flex-1">{{ titles[id] ?? `ID ${id}` }}</span>
                <template v-if="multiple">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled || index === 0"
                        data-testid="relation-move-up"
                        @click="move(index, -1)"
                    >
                        上へ
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled || index === selected.length - 1"
                        data-testid="relation-move-down"
                        @click="move(index, 1)"
                    >
                        下へ
                    </Button>
                </template>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="disabled"
                    data-testid="relation-remove"
                    @click="remove(index)"
                >
                    外す
                </Button>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">
            まだ選ばれていません。
        </p>

        <div class="flex flex-col gap-2">
            <Input
                v-model="search"
                :disabled="disabled"
                placeholder="候補を検索"
                data-testid="relation-search"
            />
            <!-- 読み込み後の候補で後続欄が画面外へ飛ばないよう、検索結果の高さを保つ。 -->
            <div class="h-40 overflow-y-auto rounded-md border p-2" role="region" aria-label="参照候補" :aria-busy="loading" data-testid="relation-results">
                <p v-if="loading" class="text-xs text-muted-foreground" role="status">読み込み中…</p>
                <div v-else-if="failed" class="text-sm" role="status">
                    <p>候補を読み込めませんでした。</p>
                    <Button type="button" variant="outline" size="sm" class="mt-2" @click="load">再読み込み</Button>
                </div>
                <p v-else-if="options.length === 0" class="text-xs text-muted-foreground" role="status">一致する候補はありません。</p>
                <ul v-else class="flex flex-wrap content-start gap-2">
                    <li v-for="option in options" :key="option.id" class="max-w-full">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-auto min-h-8 max-w-full whitespace-normal break-words text-left"
                            :disabled="disabled"
                            data-testid="relation-option"
                            @click="pick(option.id)"
                        >
                            {{ option.title }}
                        </Button>
                    </li>
                </ul>
            </div>
            <p
                v-if="duplicate"
                class="text-xs text-destructive"
                data-testid="relation-duplicate"
            >
                すでに選ばれています。
            </p>
        </div>
    </div>
</template>

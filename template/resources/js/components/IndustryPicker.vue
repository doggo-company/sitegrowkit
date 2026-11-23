<script setup lang="ts">
/**
 * 最初に業種を聞く入口（UX 一周 2026-09-28・メンテナの方針）。
 *
 * 選ぶと、お問い合わせの項目・「投稿」の呼び名・見本の文章がその業種の形になる。
 * 手を入れたものは上書きしないので、あとから選び直しても書いた内容は消えない。
 */
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    current: string | null;
    /** 「その他」で打ったお仕事の名前（UX 一周 9 周目 M2） */
    name?: string;
    options: { key: string; label: string; hint?: string }[];
}>();

const open = ref(props.current === null);
const sending = ref(false);

/**
 * 「その他」は押すとすぐ決めず、お仕事の名前を打つ欄を出す（UX 一周 9 周目 M2・メンテナの決定）。
 * 業種を増やすのをやめ、名前を見本の文とお問い合わせの説明に使う。空のままでも決められる。
 */
const askingName = ref(false);
const otherName = ref(props.name ?? '');

function pick(key: string): void {
    if (key === 'other') {
        askingName.value = true;

        return;
    }

    choose(key);
}

function choose(key: string): void {
    if (sending.value) {
        return;
    }

    sending.value = true;
    router.post(
        '/admin/industry',
        key === 'other'
            ? { industry: key, name: otherName.value.trim() }
            : { industry: key },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

const currentLabel = (): string =>
    props.current === 'other' && (props.name ?? '') !== ''
        ? (props.name ?? '')
        : (props.options.find((o) => o.key === props.current)?.label ?? '');
</script>

<template>
    <section
        v-if="open"
        class="rounded-xl border border-primary/30 bg-primary/5 p-4"
        aria-labelledby="industry-heading"
        data-testid="industry-picker"
    >
        <h2 id="industry-heading" class="font-semibold">
            どんなお店・お仕事のサイトですか？
        </h2>
        <!-- 薄い背景の上なので、薄い文字色にしない（axe color-contrast 4.27:1 で落ちていた） -->
        <p class="mt-1 text-sm text-foreground/80">
            選ぶと、お問い合わせの項目や記事の呼び名、見本の文章が、そのお仕事に合った形になります。
            自分で書いた内容は変わりません。あとから選び直せます。
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            <Button
                v-for="option in options"
                :key="option.key"
                type="button"
                :variant="option.key === current ? 'default' : 'outline'"
                :disabled="sending"
                :data-testid="`industry-${option.key}`"
                class="h-auto flex-col items-start py-2 text-left whitespace-normal"
                @click="pick(option.key)"
            >
                <span>{{ option.label }}</span>
                <!-- 選ぶと何が替わるか（12 周目 R12-1） -->
                <span
                    v-if="option.hint"
                    class="text-xs font-normal opacity-80"
                    :data-testid="`industry-hint-${option.key}`"
                    >{{ option.hint }}</span
                >
            </Button>
        </div>
        <form
            v-if="askingName"
            class="mt-3 flex flex-wrap items-end gap-2"
            data-testid="industry-other-form"
            @submit.prevent="choose('other')"
        >
            <div class="flex min-w-60 flex-1 flex-col gap-1">
                <label for="industry-name" class="text-sm font-medium"
                    >お仕事の名前</label
                >
                <input
                    id="industry-name"
                    v-model="otherName"
                    maxlength="40"
                    class="h-10 border bg-background px-3 text-sm"
                    placeholder="例: 宿・民宿"
                    data-testid="industry-name"
                />
                <p class="text-xs text-muted-foreground">
                    見本の文章とお問い合わせの説明に使います。空のままでも決められます。
                </p>
            </div>
            <Button
                type="submit"
                :disabled="sending"
                data-testid="industry-other-submit"
                >これで決める</Button
            >
        </form>
    </section>
    <p
        v-else
        class="text-sm text-muted-foreground"
        data-testid="industry-current"
    >
        お仕事: {{ currentLabel() }}
        <button type="button" class="underline" @click="open = true">
            選び直す
        </button>
    </p>
</template>

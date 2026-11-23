<script setup lang="ts">
/**
 * 分類の作成（Phase 9.7 / STAGE 20D・SPEC §H.1）。
 *
 * **分類キーは作ったあと変えられない。** 投稿への割り当ても、定義ファイルの宣言も、
 * 索引もそこを指しているので、変えると指し先が消える。
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { suggestTaxonomyKey } from '@/lib/taxonomy-key';
import type { TaxonomyDefinitionOptions } from '@/types/taxonomy';

defineProps<{ options: TaxonomyDefinitionOptions }>();

const form = useForm({
    key: '',
    label: '',
    description: '',
    hierarchical: false,
});

/**
 * 名前を打つと、キーの下書きが付いてくる（WP 一周 6 周目 2026-09-03）。
 *
 * **手で直したあとは、二度と上書きしない。** 書いたものが名前を打つたびに
 * 消えるのは、書き換えられているのと同じである。
 *
 * 日本語だけの名前からはキーを作れないので、空のまま送る。
 * その場合サーバーが `taxonomy_1` のような通し番号を付ける
 * — **ここで番号を作らない**（既に使われているキーを知らないので必ずずれる）。
 */
const keyTouched = ref(false);

watch(
    () => form.label,
    (label) => {
        if (!keyTouched.value) {
            form.key = suggestTaxonomyKey(label);
        }
    },
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        description: data.description === '' ? null : data.description,
    })).post('/admin/taxonomies');
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="分類を追加" />

    <form class="flex max-w-2xl flex-col gap-6 p-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">分類を追加</h1>
            <p class="text-sm text-muted-foreground">
                カテゴリやタグのような分類を作ります。作ったあとで投稿タイプへ関連付けます。
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="taxonomy-label">表示名</Label>
            <Input
                id="taxonomy-label"
                v-model="form.label"
                maxlength="60"
                required
                data-testid="taxonomy-label"
            />
            <p class="text-xs text-muted-foreground">
                画面に出る名前です。日本語のままで大丈夫です。
            </p>
            <p
                v-if="form.errors.label"
                class="text-sm text-destructive"
                data-testid="taxonomy-label-error"
            >
                {{ form.errors.label }}
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="taxonomy-key">分類キー（任意）</Label>
            <Input
                id="taxonomy-key"
                v-model="form.key"
                :pattern="options.keyPattern"
                maxlength="64"
                autocomplete="off"
                placeholder="空のままで大丈夫です"
                data-testid="taxonomy-key"
                aria-describedby="taxonomy-key-help"
                @input="keyTouched = true"
            />
            <p id="taxonomy-key-help" class="text-xs text-muted-foreground">
                公開ページの URL
                に使われます（<code>/blog/このキー/項目</code>）。
                空のままにすると名前から作ります。
                <strong>作成後は変更できません</strong
                >（投稿に付けた分類がどこを指すか
                分からなくなるため）。いま直せます。
                {{ options.keyRequirement }}
            </p>
            <p
                v-if="form.errors.key"
                class="text-sm text-destructive"
                data-testid="taxonomy-key-error"
            >
                {{ form.errors.key }}
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="taxonomy-description">説明</Label>
            <Input
                id="taxonomy-description"
                v-model="form.description"
                maxlength="200"
                data-testid="taxonomy-description"
            />
            <p
                v-if="form.errors.description"
                class="text-sm text-destructive"
                data-testid="taxonomy-description-error"
            >
                {{ form.errors.description }}
            </p>
        </div>

        <div class="flex items-start gap-2">
            <input
                id="taxonomy-hierarchical"
                v-model="form.hierarchical"
                type="checkbox"
                class="mt-1 size-4"
                data-testid="taxonomy-hierarchical"
            />
            <div class="flex flex-col">
                <Label for="taxonomy-hierarchical">親子関係を持たせる</Label>
                <span class="text-xs text-muted-foreground">
                    カテゴリのように入れ子にできます。あとから外すには、先に親子関係を解消する必要があります。
                </span>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">作成する</Button>
            <Button variant="ghost" as-child>
                <Link href="/admin/taxonomies">やめる</Link>
            </Button>
        </div>
    </form>
</template>

<script setup lang="ts">
/**
 * 分類の設定（Phase 9.7 / STAGE 20D・SPEC §H.2 / §H.3）。
 *
 * 変えられるのは **表示名・説明・親子関係の有無**と、**投稿タイプとの関連付け**だけ。
 * 分類キーは変えられない（表示だけ）。
 *
 * **関連付けを外しても、投稿に付いている分類は消えない。** 付け直せば元どおり見える。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type {
    TaxonomyDefinition,
    TaxonomyDefinitionOptions,
} from '@/types/taxonomy';

const props = defineProps<{
    taxonomy: TaxonomyDefinition;
    options: TaxonomyDefinitionOptions;
}>();

const form = useForm({
    lock_version: props.taxonomy.lockVersion,
    label: props.taxonomy.label,
    description: props.taxonomy.description ?? '',
    hierarchical: props.taxonomy.hierarchical,
});

const attachForm = useForm({
    content_type: '',
    multiple: true,
    required: false,
});

const attached = computed(
    () => new Set(props.taxonomy.contentTypes.map((row) => row.contentType)),
);

const available = computed(() =>
    props.options.contentTypes.filter((type) => !attached.value.has(type.id)),
);

const announcement = ref('');

function submit(): void {
    form.transform((data) => ({
        ...data,
        description: data.description === '' ? null : data.description,
    })).patch(`/admin/taxonomies/${props.taxonomy.key}`, {
        preserveScroll: true,
    });
}

function attach(): void {
    attachForm.post(`/admin/taxonomies/${props.taxonomy.key}/attach`, {
        preserveScroll: true,
        onSuccess: () => {
            announcement.value = '投稿タイプへ関連付けました';
            attachForm.reset('content_type');
        },
    });
}

/** 外すのは設定だけ。付いている分類は消えないが、影響が読めるよう確認を挟む */
const pending = ref<string | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const contentType = pending.value;

        if (contentType === null) {
            return;
        }

        router.post(
            `/admin/taxonomies/${props.taxonomy.key}/detach`,
            {
                content_type: contentType,
                ...(token ? { confirmation_token: token } : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    if (token === null) {
                        return;
                    }

                    announcement.value = `${contentType} との関連付けを外しました`;
                    pending.value = null;
                },
            },
        );
    },
);

function detach(contentType: string): void {
    pending.value = contentType;
    request();
}

function cancel(): void {
    pending.value = null;
    dismiss();
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="`${taxonomy.label} の設定`" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">{{ taxonomy.label }} の設定</h1>
            <p class="text-sm text-muted-foreground">
                表示のしかたと、どの投稿タイプで使うかを決めます。
            </p>
        </div>

        <p aria-live="polite" class="sr-only" data-testid="taxonomy-live">
            {{ announcement }}
        </p>

        <section class="rounded-xl border p-4">
            <h2 class="text-sm font-semibold">変えられないもの</h2>
            <dl class="mt-3 text-sm">
                <dt class="text-muted-foreground">分類キー</dt>
                <dd class="font-mono" data-testid="taxonomy-key">
                    {{ taxonomy.key }}
                </dd>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground">
                分類キーは、投稿に付けた分類・定義ファイルの宣言・索引がすべて指している名前です。変えると指し先が分からなくなるため、変更できません。
            </p>
        </section>

        <form
            class="flex flex-col gap-4 rounded-xl border p-4"
            @submit.prevent="submit"
        >
            <h2 class="text-sm font-semibold">表示</h2>

            <div class="flex flex-col gap-1.5">
                <Label for="taxonomy-label">表示名</Label>
                <Input
                    id="taxonomy-label"
                    v-model="form.label"
                    maxlength="60"
                    required
                    data-testid="taxonomy-label"
                />
                <p
                    v-if="form.errors.label"
                    class="text-sm text-destructive"
                    data-testid="taxonomy-label-error"
                >
                    {{ form.errors.label }}
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
                    <Label for="taxonomy-hierarchical"
                        >親子関係を持たせる</Label
                    >
                    <span class="text-xs text-muted-foreground">
                        親を持つ項目があるときは、外せません（先に親子関係を解消してください）。
                    </span>
                </div>
            </div>

            <p
                v-if="form.errors.lock_version"
                class="text-sm text-destructive"
                data-testid="taxonomy-conflict"
            >
                {{ form.errors.lock_version }}
            </p>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing"
                    >保存する</Button
                >
                <Button variant="ghost" as-child>
                    <Link href="/admin/taxonomies">やめる</Link>
                </Button>
            </div>
        </form>

        <section class="flex flex-col gap-4 rounded-xl border p-4">
            <h2 class="text-sm font-semibold">使う投稿タイプ</h2>

            <ul
                v-if="taxonomy.contentTypes.length > 0"
                class="flex flex-col gap-2"
            >
                <li
                    v-for="row in taxonomy.contentTypes"
                    :key="row.contentType"
                    class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                    data-testid="taxonomy-association"
                    :data-content-type="row.contentType"
                >
                    <span class="font-mono">
                        {{ row.contentType }}
                        <Badge variant="outline" class="ml-2">{{
                            row.multiple ? '複数選択' : '単一選択'
                        }}</Badge>
                        <Badge
                            v-if="row.required"
                            variant="outline"
                            class="ml-1"
                            >必須</Badge
                        >
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        data-testid="taxonomy-detach"
                        @click="detach(row.contentType)"
                    >
                        外す
                    </Button>
                </li>
            </ul>
            <p
                v-else
                class="text-sm text-muted-foreground"
                data-testid="taxonomy-associations-empty"
            >
                まだどの投稿タイプにも関連付けられていません。
            </p>

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="attach"
            >
                <div class="flex flex-col gap-1.5">
                    <Label for="attach-content-type">投稿タイプ</Label>
                    <select
                        id="attach-content-type"
                        v-model="attachForm.content_type"
                        class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        data-testid="taxonomy-attach-select"
                        required
                    >
                        <option value="">選んでください</option>
                        <option
                            v-for="type in available"
                            :key="type.id"
                            :value="type.id"
                        >
                            {{ type.label }}（{{ type.id }}）
                        </option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input
                        id="attach-multiple"
                        v-model="attachForm.multiple"
                        type="checkbox"
                        class="size-4"
                    />
                    <Label for="attach-multiple">複数選べる</Label>
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input
                        id="attach-required"
                        v-model="attachForm.required"
                        type="checkbox"
                        class="size-4"
                    />
                    <Label for="attach-required">必須にする</Label>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="attachForm.processing || available.length === 0"
                    data-testid="taxonomy-attach"
                >
                    関連付ける
                </Button>
            </form>

            <p class="text-xs text-muted-foreground">
                関連付けを外しても、投稿に付いている分類は削除されません。付け直せば、そのまま元どおり見えます。
            </p>
        </section>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="外す"
            @confirm="accept"
            @cancel="cancel"
        />
    </div>
</template>

<script setup lang="ts">
/**
 * 再利用ブロックの一覧（Phase 9.6 / P0-C7・`PHASE9_6_SPEC.md` §4.5）。
 *
 * **中身は出さない。** 一覧が答えるのは「どれが在るか・公開されているか」で、
 * 部分木は 1 件ずつ引く（版の一覧が document を返さないのと同じ理由）。
 *
 * **しまったものは既定で出さない。** 出すと「使えるもの」と混ざる。
 * 状態は色だけでなく**文字で**示す。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { adminApi } from '@/lib/adminApi';
import type { ReusableSummary } from '@/lib/reusable';

const props = defineProps<{
    components: ReusableSummary[];
    includeArchived: boolean;
    editUrlBase: string;
}>();

const form = reactive({ key: '', label: '' });
const error = ref<string | null>(null);
const busy = ref(false);

function show(archived: boolean): void {
    router.get(props.editUrlBase, archived ? { archived: 1 } : {}, {
        preserveState: true,
    });
}

/**
 * 作る。**中身は空の section 1 つから始める。**
 *
 * 起点を空で作れないと「まず何かを置く」ができない。空の section は
 * `propsSchema` の required を持たないので、そのまま検証を通る。
 */
async function create(): Promise<void> {
    error.value = null;
    busy.value = true;

    try {
        const result = await adminApi.send<{ id?: string } | null>(
            'POST',
            '/api/v1/components/reusable',
            {
                key: form.key,
                label: form.label,
                document: { type: 'section', props: {}, children: [] },
            },
        );

        if (result.status === 'invalid') {
            // **項目ごとに来る。** まとめて 1 行で見せる（押した人が直せる形に）
            const details = Object.values(result.errors).flat().join(' / ');

            error.value =
                details !== ''
                    ? details
                    : (result.message ?? '作成できませんでした');

            return;
        }

        if (result.status !== 'success') {
            error.value =
                result.status === 'unreachable'
                    ? '通信に失敗しました'
                    : ((result as { message?: string | null }).message ??
                      '作成できませんでした');

            return;
        }

        const id = result.data?.id;

        if (typeof id === 'string') {
            router.visit(`${props.editUrlBase}/${id}`);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="再利用ブロック" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">
                {{
                    includeArchived
                        ? 'しまった再利用ブロック'
                        : '再利用ブロック'
                }}
            </h1>

            <Button variant="outline" @click="show(!includeArchived)">
                {{
                    includeArchived ? '使えるものを見る' : 'しまったものを見る'
                }}
            </Button>
        </div>

        <p class="text-sm text-muted-foreground">
            複数のページで同じ中身を使い回します。ここでの変更は
            <strong>公開したときに</strong>、使っているページへ反映されます。
        </p>

        <!-- 作る -->
        <form
            v-if="!includeArchived"
            class="flex flex-wrap items-end gap-3 rounded-md border p-3"
            @submit.prevent="create"
        >
            <div class="grid gap-1">
                <Label class="text-xs" for="reusable-key">キー</Label>
                <Input
                    id="reusable-key"
                    v-model="form.key"
                    placeholder="site-header"
                    data-testid="reusable-key"
                />
            </div>

            <div class="grid gap-1">
                <Label class="text-xs" for="reusable-label">名前</Label>
                <Input
                    id="reusable-label"
                    v-model="form.label"
                    placeholder="サイトヘッダー"
                    data-testid="reusable-label"
                />
            </div>

            <Button
                type="submit"
                :disabled="busy"
                data-testid="reusable-create"
            >
                作る
            </Button>

            <p
                v-if="error"
                class="text-xs text-destructive"
                data-testid="reusable-error"
            >
                {{ error }}
            </p>
        </form>

        <table v-if="components.length > 0" class="w-full text-sm">
            <thead class="text-left text-xs text-muted-foreground">
                <tr>
                    <th class="py-2">名前</th>
                    <th class="py-2">キー</th>
                    <th class="py-2">状態</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="component in components"
                    :key="component.id"
                    class="border-t"
                    :data-testid="`reusable-row-${component.key}`"
                >
                    <td class="py-2">
                        <Link
                            :href="`${editUrlBase}/${component.id}`"
                            class="underline underline-offset-2"
                        >
                            {{ component.label }}
                        </Link>
                    </td>
                    <td class="py-2 text-muted-foreground">
                        {{ component.key }}
                    </td>
                    <td class="py-2">
                        <!-- 状態は色だけに頼らず、文字でも言う -->
                        <Badge v-if="component.archived" variant="outline">
                            しまった
                        </Badge>
                        <Badge v-else-if="component.published">公開中</Badge>
                        <Badge v-else variant="secondary">未公開</Badge>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-else class="text-sm text-muted-foreground">
            {{
                includeArchived
                    ? 'しまった再利用ブロックはありません。'
                    : 'まだありません。上のフォームから作れます。'
            }}
        </p>
    </div>
</template>

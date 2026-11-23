<script setup lang="ts">
/**
 * 「公開にしても、サイトには出ない」ことを言い、その場で出せるようにする（UX 一周 2026-09-28）。
 *
 * 新しいプロジェクトの「投稿」は、公開サイトに出さない設定で始まる。
 * 以前はここに開発者向けの設定画面（内部 ID・保存先テーブル・公開スラッグ）への
 * リンクしか無く、記事を「公開」にした人が、サイトに出ていないことに気づけなかった。
 *
 * ボタンはダッシュボードの 1 クリック（`quick-start`）と同じ口を通す。
 * `stay` を付けて、書いている画面に戻す（書きかけを捨てさせない）。
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{ schemaId: string; label: string }>();

const page = usePage();
const capabilities = computed(
    () =>
        (page.props.auth as { capabilities?: string[] } | undefined)
            ?.capabilities ?? [],
);
const canManageType = computed(() =>
    capabilities.value.includes('content_type.manage'),
);
/** 細かい設定（投稿タイプの画面）は制作者だけが開ける */
const canBuild = computed(() => capabilities.value.includes('site.build'));

const starting = ref(false);

function publishType(): void {
    if (starting.value) {
        return;
    }

    starting.value = true;
    router.post(
        `/admin/content-types/${props.schemaId}/quick-start`,
        { stay: 1 },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                starting.value = false;
            },
        },
    );
}
</script>

<template>
    <aside
        class="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
        data-testid="public-type-notice"
    >
        <p class="font-medium">
            いまは「{{
                label
            }}」を公開サイトに出さない設定です。記事を「公開」にしても、サイトには表示されません。
        </p>
        <div
            v-if="canManageType"
            class="mt-2 flex flex-wrap items-center gap-3"
        >
            <Button
                type="button"
                size="sm"
                :disabled="starting"
                data-testid="public-type-quick-start"
                @click="publishType"
            >
                公開サイトに出せるようにする
            </Button>
            <Link
                v-if="canBuild"
                :href="`/admin/content-types/${schemaId}/edit`"
                class="text-xs underline"
                >細かい設定を開く</Link
            >
        </div>
        <p v-else class="mt-2">
            公開する場合は、管理者に「{{
                label
            }}」を公開サイトに出せるようにしてもらってください。
        </p>
    </aside>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { adminApi } from '@/lib/adminApi';

interface Run {
    id: string;
    status: 'queued' | 'running' | 'done' | 'failed' | 'stalled';
    count: number;
    updated: number;
    message: string;
}

const props = defineProps<{ run: Run | null }>();
const run = ref(props.run);
const starting = ref(false);
const error = ref('');
const active = computed(
    () => run.value?.status === 'queued' || run.value?.status === 'running',
);
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;

// 通信失敗を「処理中」に隠さず、画面を開き直してもサーバーの状態を読み直す。
async function poll() {
    if (!run.value || disposed) {
return;
}

    try {
        const response = await fetch(`/admin/exports/${run.value.id}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
throw new Error('unavailable');
}

        const body = (await response.json()) as { run: Run };
        run.value = body.run;
        error.value = '';
    } catch {
        error.value =
            '状態を読み取れませんでした。接続を確認してください。書き出しはサーバー側で続いている可能性があります。';
    }

    if (active.value && !disposed) {
timer = setTimeout(poll, 2000);
}
}

async function start() {
    starting.value = true;
    error.value = '';
    const response = await adminApi.send<Run>('POST', '/admin/exports', {});
    starting.value = false;

    if (response.status === 'success') {
        run.value = response.data;
    } else {
        error.value =
            '開始できませんでした。ログインと接続を確認し、少し待ってから再実行してください。';
    }
}

watch(
    active,
    (value) => {
        clearTimeout(timer);

        if (value) {
timer = setTimeout(poll, 1000);
}
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    disposed = true;
    clearTimeout(timer);
});
</script>

<template>
    <Head title="内容の書き出し" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <div>
            <a
                href="/admin/backups"
                class="text-sm text-primary underline underline-offset-4"
                >バックアップへ戻る</a
            >
            <h1 class="mt-3 text-2xl font-semibold">内容の書き出し</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                記事・ページを、WordPressで読み込めるXML（WXR
                1.2）にまとめます。
            </p>
        </div>
        <section
            class="space-y-3 rounded-xl border p-5"
            aria-labelledby="scope"
        >
            <h2 id="scope" class="font-semibold">含まれるもの</h2>
            <p class="text-sm">
                ゴミ箱以外の記事とページ、本文、公開状態、公開日時、分類、カスタムフィールドの値。
            </p>
            <p class="text-sm text-muted-foreground">
                投稿タイプはWordPressの「投稿」に変換し、元の種類と追加項目をメタデータに残します。追加項目の表示には移行先での設定が必要です。
            </p>
            <p class="text-sm text-muted-foreground">
                画像はURL参照です。画像ファイル自体、デザイン、編集履歴、フォーム、ユーザー、サイト設定は含みません。サイトを丸ごと引き継ぐ場合はバックアップをご利用ください。
            </p>
            <p class="text-sm text-muted-foreground">
                公開中のページは公開版を、下書きのページは下書き版を書き出します。処理中もこの画面を閉じられます。
            </p>
            <Button :disabled="starting || active" @click="start">{{
                starting
                    ? '開始しています…'
                    : active
                      ? '書き出し中…'
                      : 'XMLを書き出す'
            }}</Button>
        </section>
        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive p-4 text-sm text-destructive"
        >
            {{ error }}
        </p>
        <section
            v-if="run"
            aria-live="polite"
            class="space-y-3 rounded-xl border p-5"
        >
            <h2 class="font-semibold">{{ run.message }}</h2>
            <p>
                {{ run.count }} 件{{
                    run.status === 'done' ? 'を書き出しました' : 'を処理済み'
                }}
            </p>
            <a
                v-if="run.status === 'done'"
                :href="`/admin/exports/${run.id}/download`"
                class="inline-flex min-h-11 items-center rounded-md bg-primary px-4 py-2 text-primary-foreground"
                >XMLをダウンロード</a
            >
            <p
                v-if="run.status === 'stalled' || run.status === 'failed'"
                class="text-sm text-muted-foreground"
            >
                サーバーで
                <code class="break-all"
                    >php artisan agentcms:content-export</code
                >
                を実行する方法もあります。
            </p>
        </section>
    </div>
</template>

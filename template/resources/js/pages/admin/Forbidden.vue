<script setup lang="ts">
/**
 * 権限の無い管理画面を開いたとき（UX 一周 23 周目 #6）。管理画面の枠（メニュー）の中で、
 * 誰なら見られるかと、戻る道を出す。以前は白地の 403 だけだった。
 */
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    message: string;
    needed: string;
    backUrl: string;
}>();

function goBack(): void {
    window.history.back();
}
</script>

<template>
    <Head title="この画面は見られません" />

    <div class="mx-auto max-w-xl space-y-4 p-6" data-testid="admin-forbidden">
        <h1 class="text-xl font-semibold">この画面は見られません</h1>
        <p>{{ message }}</p>
        <p class="text-sm text-muted-foreground">
            必要な権限:
            {{ needed }}
        </p>
        <div class="flex flex-wrap gap-3">
            <button
                type="button"
                class="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium hover:bg-muted"
                data-testid="admin-forbidden-back"
                @click="goBack"
            >
                前の画面に戻る
            </button>
            <Link
                :href="backUrl"
                class="inline-flex min-h-11 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90"
                data-testid="admin-forbidden-dashboard"
            >
                ダッシュボードへ
            </Link>
        </div>
    </div>
</template>

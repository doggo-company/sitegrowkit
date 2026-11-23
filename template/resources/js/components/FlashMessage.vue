<script setup lang="ts">
/**
 * 「保存できた」を画面が言う場所（R-8A 監査 指摘 24）。
 *
 * ## なぜ 1 か所か
 *
 * `with('success', …)` は管理画面の 7 か所以上で使われていたのに、
 * **共有も描画も無く、どこにも出ていなかった**。画面ごとに書くと
 * また同じことが起きるので、layout の 1 か所だけが描く。
 *
 * ## なぜ toast にしないか
 *
 * layout には `Toaster` が在るが、**消える通知**にはしない。
 * 指摘の実体は「保存したのに画面が何も言わない」で、
 * **数秒で消える言葉は、見落とした人にとって無いのと同じ**である。
 * `role="status"` を付けて、読み上げにも載せる。
 *
 * ## なぜ画面に固定するか（UX 一周 3 周目 M11・N4）
 *
 * 以前はページの頭に帯として差し込んでいた。保存は「下までスクロールして押す」ことが多く、
 * 保存後もスクロールの位置は残る（`preserveScroll`）ので、**帯は画面の外（上）に出て見えず**、
 * そのうえ帯の高さのぶん中身が下へずれて、ボタンの横の「保存しました」まで画面の下へ押し出された
 * （サイト設定で top=896／画面 900、フォームで知らせが top=-1672）。
 * だから**中身の流れから外して画面の右上に固定**する。中身はずれず、どこまで下にいても見える。
 * 消えはしない（上の理由）。邪魔なら「閉じる」で消せる。下の画面の操作は塞がない。
 */
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const page = usePage();

const flash = computed(
    () => page.props.flash as { success?: unknown; error?: unknown } | undefined,
);

const read = (value: unknown): string | null =>
    typeof value === 'string' && value !== '' ? value : null;

const message = computed<string | null>(() => read(flash.value?.success));

/**
 * できなかったことも、ここが言う（WP 一周 8 周目 2026-09-04 / 効くもの ③）。
 *
 * `role="alert"` なのは、**読み飛ばされると次の操作を間違える**からである
 * （success は「済んだ」なので後から気づいても困らない）。
 */
const error = computed<string | null>(() => read(flash.value?.error));

/** 「閉じる」を押したか。次に知らせが来たら（保存し直したら）また出す */
const dismissed = ref(false);

watch(flash, () => {
    dismissed.value = false;
});
</script>

<template>
    <!-- 画面に固定（中身の流れに入れない）。押せるのは「閉じる」だけで、下の画面の操作は塞がない -->
    <div
        v-if="(message || error) && !dismissed"
        class="pointer-events-none fixed top-3 right-3 left-3 z-50 flex flex-col items-end gap-2 sm:left-auto sm:w-96"
        data-testid="flash-region"
    >
        <div
            v-if="message"
            class="flex w-full items-start gap-3 border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 shadow-lg dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100"
        >
            <p role="status" data-testid="flash-success" class="flex-1">
                {{ message }}
            </p>
            <button
                type="button"
                class="pointer-events-auto shrink-0 text-xs underline underline-offset-2"
                data-testid="flash-close"
                @click="dismissed = true"
            >
                閉じる
            </button>
        </div>

        <div
            v-if="error"
            class="flex w-full items-start gap-3 border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-lg dark:border-red-800 dark:bg-red-950 dark:text-red-100"
        >
            <p role="alert" data-testid="flash-error" class="flex-1">
                {{ error }}
            </p>
            <button
                type="button"
                class="pointer-events-auto shrink-0 text-xs underline underline-offset-2"
                @click="dismissed = true"
            >
                閉じる
            </button>
        </div>
    </div>
</template>

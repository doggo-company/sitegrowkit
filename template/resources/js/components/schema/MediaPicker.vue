<script setup lang="ts">
// media / file フィールド用ピッカー。ユーザーは「選ぶ」だけ — path / UUID / disk は見せない（ADR-0006）。
import { computed, ref, watch } from 'vue';
import { useMediaLibrary } from '@/composables/useMediaLibrary';
import { adminApi } from '@/lib/adminApi';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { extensionLabel } from '@/types/media';
import type { MediaItem, MediaKind } from '@/types/media';

const model = defineModel<number | null>();

/**
 * 触らせない状態（保存中・競合中など）。
 *
 * 使う側が「押せないはず」と思っている間に選べてしまうと、
 * 止めたはずの変更がここからだけ通る。**見た目だけでなく実際に止める**。
 *
 * `kind` は「何を選ばせるか」（Phase 9.5-D）。既定は画像 — `media` / `images` の
 * 既存の使い方を変えないため。**絞り込みは画面の親切さであって、防御ではない**。
 * 種別の食い違いは server validation が拒否する。
 */
const props = withDefaults(
    defineProps<{
        disabled?: boolean;
        kind?: MediaKind;
        /** 狭い欄（編集画面の右の操作欄）では縦に積む。横に並べると欄からはみ出す */
        compact?: boolean;
    }>(),
    { disabled: false, kind: 'image', compact: false },
);

/** 選んだもの（外したときは null）。代替テキストを写すなど、中身が要る側のため */
const emit = defineEmits<{
    (e: 'picked', item: MediaItem | null, previous: MediaItem | null): void;
}>();

const open = ref(false);
let opener: HTMLElement | null = null;

/**
 * 一覧は **同じ種別の全 instance で共有する**（Phase 9.5-C / 9.5-D）。
 * images のように同じ画面へ何個も置かれると、各自が取りに行って
 * 同じ要求が N 回飛ぶ。取得は種別ごとに 1 回へ畳む。
 * 絞り込みはサーバー（`?kind=`）で行う — 上限 200 件を種別の中で効かせるため。
 */
const {
    media,
    loading,
    load: loadLibrary,
    refresh,
    find,
} = useMediaLibrary(props.kind);

const current = computed(() => find(model.value));
const isImage = computed(() => props.kind === 'image');

const labels = computed(() =>
    isImage.value
        ? {
              choose: '画像を選択',
              title: '画像を選択',
              empty: '画像はまだありません。下の「この端末から追加」で、スマホやパソコンの画像をここからアップロードできます。',
          }
        : {
              choose: 'ファイルを選択',
              title: 'ファイルを選択',
              empty: 'ファイルはまだ 1 つもありません。「アップロード」から追加できます。',
          },
);

/**
 * 一覧を読む。
 *
 * **`disabled` でも読む。** disabled は「変更させない」であって
 * 「見せない」ではない。ここで止めると現在値のサムネイルも名前も引けず、
 * 選ばれているのに「未設定」と表示してしまう（Phase 9.5-C レビュー指摘 1）。
 * 変更の入口（開く・選ぶ・外す）は下で止めてある。
 */
async function load() {
    await loadLibrary();
}

// 現在値のサムネイル表示のために、値があれば初回から一覧を読む
watch(
    model,
    (v) => {
        if (v) {
            void load();
        }
    },
    { immediate: true },
);

function openPicker(event: MouseEvent) {
    if (props.disabled) {
        return;
    }

    opener = event.currentTarget as HTMLElement;
    // 別ページの保存で補われた画像の説明も、選ぶ直前に読み直す。
    void refresh();
    open.value = true;
}

function restorePickerFocus(event: Event) {
    // 閉じるアニメーションの後にフォーカスを奪い、次の入力欄を画面外へ戻さない。
    event.preventDefault();
    const active = document.activeElement;

    if (
        active === document.body ||
        active === opener ||
        active?.closest('[data-testid="media-picker-dialog"]')
    ) {
        opener?.focus({ preventScroll: true });
    }
}

function choose(item: MediaItem) {
    if (props.disabled) {
        return;
    }

    const previous = current.value;

    model.value = item.id;
    emit('picked', item, previous);
    open.value = false;
}

/*
 * この場でアップロードする（UX 一周 2026-09-28）。
 *
 * 以前は「先に『メディア』からアップロードしてください」で、編集中のページを離れ、
 * 戻ってきてブロックを選び直す必要があった。上げたものはそのまま選ぶ。
 */
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const uploadAlt = ref('');

async function upload(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file || props.disabled) {
        return;
    }

    uploadError.value = null;

    // PHP のファイル上限と同じ20MiB。送信を始める前に止め、回線の待ち時間を作らない。
    if (isImage.value && file.size > 20 * 1024 * 1024) {
        const megabytes = Math.round(file.size / 1_000_000);
        uploadError.value = `20MBまで。この画像は${megabytes}MBです。小さくしてから選び直してください。`;
        input.value = '';

        return;
    }

    uploading.value = true;

    const body = new FormData();
    body.append('file', file);

    if (uploadAlt.value.trim() !== '') {
        body.append('alt', uploadAlt.value.trim());
    }

    try {
        const result = await adminApi.send<{ media?: MediaItem }>(
            'POST',
            '/admin/media',
            body,
        );

        if (result.status !== 'success' || !result.data?.media) {
            uploadError.value =
                isImage.value &&
                result.status === 'failed' &&
                result.httpStatus === 413
                    ? '画像が大きすぎて送れませんでした。20MBまでの画像を選んでください。'
                    : result.status === 'invalid'
                      ? (Object.values(result.errors)[0]?.[0] ??
                        result.message ??
                        'この画像はアップロードできませんでした。')
                      : result.status === 'unreachable'
                        ? 'アップロードできませんでした。通信の状態を確かめてください。'
                        : 'アップロードできませんでした。もう一度お試しください。';

            return;
        }

        await refresh();
        uploadAlt.value = '';
        choose(result.data.media);
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

function clear() {
    if (props.disabled) {
        return;
    }

    const previous = current.value;

    model.value = null;
    emit('picked', null, previous);
}
</script>

<template>
    <div
        class="flex gap-3"
        :class="compact ? 'flex-col items-stretch' : 'items-start'"
        data-testid="media-picker"
        :data-kind="kind"
    >
        <div
            class="grid h-24 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted"
            :class="compact ? 'w-full' : 'w-32'"
        >
            <!-- 画像だけがサムネイルを持つ。file に <img src=null> を描かない -->
            <img
                v-if="current && current.url"
                :src="current.url"
                :alt="current.alt ?? ''"
                class="h-full w-full object-cover"
                data-testid="media-picker-current"
            />
            <span
                v-else-if="current"
                class="text-sm font-medium"
                data-testid="media-picker-current-file"
                >{{ extensionLabel(current) || 'FILE' }}</span
            >
            <!-- bg-muted の上の muted 文字は 4.5:1 に届かない（axe color-contrast） -->
            <span v-else class="text-xs text-foreground/80">未設定</span>
        </div>
        <div class="flex min-w-0 flex-col gap-2">
            <p v-if="current" class="truncate text-sm">
                {{ current.name || current.original_name }}
            </p>
            <a
                v-if="current && current.download_url"
                :href="current.download_url"
                class="text-xs underline underline-offset-4"
                data-testid="media-picker-download"
                >ダウンロード</a
            >
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="disabled"
                    data-testid="media-picker-open"
                    @click="openPicker"
                >
                    {{ labels.choose }}
                </Button>
                <Button
                    v-if="model"
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="disabled"
                    data-testid="media-picker-clear"
                    @click="clear"
                >
                    解除
                </Button>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent
                class="max-h-[80vh] max-w-3xl overflow-y-auto"
                data-testid="media-picker-dialog"
                @close-auto-focus="restorePickerFocus"
            >
                <DialogHeader>
                    <DialogTitle>{{ labels.title }}</DialogTitle>
                </DialogHeader>
                <p
                    v-if="loading"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    読み込み中...
                </p>
                <p
                    v-else-if="media.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {{ labels.empty }}
                </p>
                <ul v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <li v-for="item in media" :key="item.id">
                        <button
                            type="button"
                            class="w-full overflow-hidden rounded-md border text-left transition hover:ring-2 hover:ring-ring"
                            :class="{ 'ring-2 ring-ring': item.id === model }"
                            :disabled="disabled"
                            :data-testid="`media-choice-${item.id}`"
                            @click="choose(item)"
                        >
                            <img
                                v-if="item.url"
                                :src="item.url"
                                :alt="item.alt ?? ''"
                                loading="lazy"
                                class="aspect-[4/3] w-full bg-muted object-cover"
                            />
                            <span
                                v-else
                                class="grid aspect-[4/3] w-full place-items-center bg-muted text-sm font-medium"
                                >{{ extensionLabel(item) || 'FILE' }}</span
                            >
                            <!-- 登録した表示名で探せるようにする。元ファイル名で上書きしない。 -->
                            <span class="block truncate p-1.5 text-xs">{{
                                item.name || item.original_name
                            }}</span>
                        </button>
                    </li>
                </ul>

                <div
                    v-if="isImage"
                    class="mt-2 space-y-2 border-t pt-3"
                    data-testid="media-picker-upload"
                >
                    <p class="text-sm font-medium">この端末から追加</p>
                    <p class="text-xs text-muted-foreground">
                        画像は20MBまでアップロードできます。
                    </p>
                    <label class="grid gap-1 text-xs text-muted-foreground">
                        画像の説明（目の不自由な方の読み上げに使われます）
                        <input
                            v-model="uploadAlt"
                            type="text"
                            maxlength="200"
                            class="h-9 rounded-md border bg-transparent px-2 text-sm text-foreground"
                            data-testid="media-picker-upload-alt"
                        />
                    </label>
                    <label
                        class="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                        :class="{
                            'pointer-events-none opacity-50':
                                uploading || disabled,
                        }"
                    >
                        <input
                            type="file"
                            accept="image/*"
                            class="sr-only"
                            :disabled="uploading || disabled"
                            data-testid="media-picker-upload-file"
                            @change="upload"
                        />
                        {{
                            uploading
                                ? 'アップロード中…'
                                : '画像を選んでアップロード'
                        }}
                    </label>
                    <p
                        v-if="uploadError"
                        role="alert"
                        class="text-sm text-destructive"
                        data-testid="media-picker-upload-error"
                    >
                        {{ uploadError }}
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>

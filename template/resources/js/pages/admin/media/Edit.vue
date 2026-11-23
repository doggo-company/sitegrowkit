<script setup lang="ts">
// メディアの専用編集ページ（Phase 8.5-F / F-UX7）。modal ではなく 1 ページで完結させる。
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft } from '@lucide/vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import InputError from '@/components/InputError.vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { extensionLabel } from '@/types/media';
import { formatDateTime } from '@/lib/admin-datetime';
import { useAdminTimezone } from '@/composables/useAdminTimezone';
import type { MediaItem } from '@/types/media';

/** 使用箇所の 1 件。`url` は編集画面へ、`trashed` はゴミ箱に在るか */
type MediaUsageItem = {
    id: number;
    title: string;
    url: string | null;
    trashed: boolean;
};
type MediaUsage = {
    schema: string;
    label: string;
    field: string;
    items: MediaUsageItem[];
};

const props = defineProps<{
    media: MediaItem & {
        has_webp: boolean;
        used: boolean;
        usages: MediaUsage[];
    };
}>();

/** 種別で出し分けるもの（SPEC §8.1.1）。値が無い項目は「—」も出さず、項目ごと消す */
const isImage = computed(() => props.media.kind === 'image');
const noun = computed(() => (isImage.value ? '画像' : 'ファイル'));

const form = useForm({
    name: props.media.name,
    alt: props.media.alt ?? '',
});

/**
 * 公開範囲の切り替え（一般ファイルだけ）。
 *
 * どちらの向きも**外向きの変化**なので、サーバーの ConfirmationGate が
 * 確認を挟む。ここは token を運ぶだけで、判断はサーバーが持つ。
 */
const nextVisibility = computed(() =>
    props.media.visibility === 'public' ? 'private' : 'public',
);

/**
 * 確認は **この画面で 1 つだけ**にする。
 *
 * `useOperationConfirmation` は Inertia の flash を購読するので、2 つ置くと
 * **どちらの確認でも両方が開く**。削除を押したのに公開範囲のダイアログまで出て、
 * しかも承認したほうと違う操作が飛び得る。
 * 一覧画面が `pendingItem` で 1 つに束ねているのと同じ形にする。
 */
const pending = ref<'delete' | 'visibility' | null>(null);

/**
 * どの操作か **分かっているときだけ**送る。
 *
 * 「visibility 以外は削除」にすると、pending が null や未知の値のときに
 * **押していない削除が飛ぶ**。分からなければ何もしない、が正しい既定である。
 */
const {
    confirmation,
    request,
    accept: acceptConfirmation,
    dismiss: dismissConfirmation,
} = useOperationConfirmation((token) => {
    if (pending.value === 'visibility') {
        router.patch(
            `/admin/media/${props.media.id}`,
            {
                name: props.media.name,
                visibility: nextVisibility.value,
                ...(token ? { confirmation_token: token } : {}),
            },
            { preserveScroll: true, preserveState: token === null },
        );

        return;
    }

    if (pending.value === 'delete') {
        router.delete(`/admin/media/${props.media.id}`, {
            data: token ? { confirmation_token: token } : {},
            preserveState: token === null,
        });
    }
});

/** 承認したら送り、**待ちを必ず空に戻す**（次の操作へ持ち越さない） */
function accept() {
    acceptConfirmation();
    pending.value = null;
}

function dismiss() {
    dismissConfirmation();
    pending.value = null;
}

function requestVisibility() {
    pending.value = 'visibility';
    request();
}

function destroy() {
    pending.value = 'delete';
    request();
}

const replaceForm = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);

function submit() {
    form.patch(`/admin/media/${props.media.id}`, { preserveScroll: true });
}

function pickReplacement(event: Event) {
    const target = event.target as HTMLInputElement;
    replaceForm.file = target.files?.[0] ?? null;
}

function submitReplacement() {
    replaceForm.post(`/admin/media/${props.media.id}/replace`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            replaceForm.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

const humanSize = (bytes: number): string =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

/*
 * 人が読む日時は `admin-datetime` を通す（WP 一周 10 周目 2026-09-04）。
 * #10 で 1 か所へまとめたが、この画面は通っていなかった
 * （`2026-09-04T12:16:56+09:00` がそのまま出ていた）。
 */
const timezone = useAdminTimezone();

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="`メディア — ${media.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button variant="ghost" size="sm" as-child>
                    <Link href="/admin/media">
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        メディア一覧へ戻る
                    </Link>
                </Button>
            </div>
        </div>

        <h1 class="text-xl font-semibold">{{ media.name }}</h1>

        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="flex flex-col gap-3"
                aria-labelledby="preview-heading"
            >
                <h2 id="preview-heading" class="text-sm font-medium">
                    プレビュー
                </h2>
                <div
                    class="flex items-center justify-center rounded-xl border bg-muted/40 p-4"
                >
                    <img
                        v-if="media.url"
                        :src="media.url"
                        :alt="media.alt ?? ''"
                        class="max-h-80 w-auto max-w-full"
                        data-testid="media-preview"
                    />
                    <!-- ファイルにサムネイルは作らない -->
                    <span
                        v-else
                        class="grid h-32 w-32 place-items-center rounded-lg bg-muted text-2xl font-semibold text-muted-foreground"
                        data-testid="media-preview-badge"
                        >{{ extensionLabel(media) || 'FILE' }}</span
                    >
                </div>
                <dl
                    class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-muted-foreground"
                >
                    <dt>形式</dt>
                    <dd class="text-foreground">{{ media.mime }}</dd>
                    <dt>サイズ</dt>
                    <dd class="text-foreground">{{ humanSize(media.size) }}</dd>
                    <template v-if="media.width && media.height">
                        <dt>寸法</dt>
                        <dd class="text-foreground">
                            {{ media.width }} × {{ media.height }} px
                        </dd>
                    </template>
                    <template v-if="media.original_name">
                        <dt>元のファイル名</dt>
                        <dd class="truncate text-foreground">
                            {{ media.original_name }}
                        </dd>
                    </template>
                    <dt>登録日</dt>
                    <dd class="text-foreground">
                        {{ formatDateTime(media.created_at, timezone) || '—' }}
                    </dd>
                </dl>

                <p v-if="media.download_url" class="text-sm">
                    <a
                        :href="media.download_url"
                        class="underline underline-offset-4"
                        data-testid="media-download-link"
                        >ダウンロード</a
                    >
                    <span class="text-muted-foreground">
                        —
                        ブラウザで開かず、必ずダウンロードとして扱われます</span
                    >
                </p>
            </section>

            <div class="flex flex-col gap-6">
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">ファイル名</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div v-if="isImage" class="grid gap-2">
                        <Label for="alt">代替テキスト（alt）</Label>
                        <Input
                            id="alt"
                            v-model="form.alt"
                            aria-describedby="alt-help"
                        />
                        <p id="alt-help" class="text-sm text-muted-foreground">
                            画像の内容を短い文で説明します。装飾目的で内容に意味がない画像は、空のままにしてください。
                        </p>
                        <InputError :message="form.errors.alt" />
                    </div>

                    <div>
                        <Button type="submit" :disabled="form.processing">
                            保存する
                        </Button>
                    </div>
                </form>

                <!-- 公開範囲。一般ファイルだけが持つ（画像は常に公開 — §8.1.1） -->
                <section
                    v-if="!isImage"
                    class="flex flex-col gap-3 rounded-xl border p-4"
                    aria-labelledby="visibility-heading"
                    data-testid="media-visibility-section"
                >
                    <h2 id="visibility-heading" class="text-sm font-medium">
                        公開範囲
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            media.visibility === 'private'
                                ? 'このファイルは管理者だけが開けます'
                                : 'このファイルは URL を知っていれば誰でも開けます'
                        }}
                    </p>
                    <div>
                        <Button
                            variant="secondary"
                            data-testid="media-visibility-toggle"
                            @click="requestVisibility"
                        >
                            {{
                                nextVisibility === 'private'
                                    ? '管理者のみに変更する'
                                    : '公開に変更する'
                            }}
                        </Button>
                    </div>
                </section>

                <!-- メディアの差し替え。id は維持されるので参照は壊れない（F-UX6） -->
                <section
                    class="flex flex-col gap-3 rounded-xl border p-4"
                    aria-labelledby="replace-heading"
                >
                    <h2 id="replace-heading" class="text-sm font-medium">
                        {{ noun }}を差し替える
                    </h2>
                    <p id="replace-help" class="text-sm text-muted-foreground">
                        この{{
                            noun
                        }}を使っているページの参照はそのまま保たれ、中身だけが新しいものに切り替わります。
                        種別（画像 / ファイル）は変えられません。
                    </p>
                    <form
                        class="flex flex-col gap-3"
                        @submit.prevent="submitReplacement"
                    >
                        <Label for="replace-file">新しい{{ noun }}</Label>
                        <Input
                            id="replace-file"
                            ref="fileInput"
                            type="file"
                            :accept="
                                isImage
                                    ? 'image/*,.svg'
                                    : '.pdf,.txt,.csv,.docx,.xlsx,.pptx'
                            "
                            aria-describedby="replace-help"
                            data-testid="media-replace-input"
                            @change="pickReplacement"
                        />
                        <InputError :message="replaceForm.errors.file" />
                        <div>
                            <Button
                                type="submit"
                                variant="secondary"
                                :disabled="
                                    !replaceForm.file || replaceForm.processing
                                "
                                data-testid="media-replace-submit"
                            >
                                差し替える
                            </Button>
                        </div>
                    </form>
                </section>

                <section
                    class="flex flex-col gap-3 rounded-xl border p-4"
                    aria-labelledby="danger-heading"
                >
                    <h2 id="danger-heading" class="text-sm font-medium">
                        削除
                    </h2>
                    <!--
                        「消せません」で終わらせない（WP 一周 9 周目 / 効くもの ③）。
                        どこで使われているかへ**行けるようにする**。
                        ゴミ箱に入っているものも使用中なので、そう言う —
                        書かないと「消したのにまだ使用中」で二度止まる。
                    -->
                    <div v-if="media.used" class="flex flex-col gap-2 text-sm">
                        <p class="text-muted-foreground">
                            この{{
                                noun
                            }}は、次の場所で使われているため削除できません。
                            本文や項目から外して保存すると、削除できるようになります。
                        </p>
                        <ul class="flex flex-col gap-1">
                            <li
                                v-for="usage in media.usages"
                                :key="`${usage.schema}-${usage.field}`"
                            >
                                <span class="text-muted-foreground"
                                    >{{ usage.label }}:</span
                                >
                                <template
                                    v-for="(entry, index) in usage.items"
                                    :key="entry.id"
                                >
                                    <span v-if="index > 0">、</span>
                                    <Link
                                        v-if="entry.url"
                                        :href="entry.url"
                                        class="underline underline-offset-2"
                                        data-testid="media-usage-link"
                                        >{{ entry.title }}</Link
                                    >
                                    <span v-else>{{ entry.title }}</span>
                                    <span
                                        v-if="entry.trashed"
                                        class="text-muted-foreground"
                                        >（ゴミ箱にあります。戻すときに要るので、
                                        ゴミ箱にある間は使用中のままです）</span
                                    >
                                </template>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <Button
                            variant="outline"
                            class="text-destructive"
                            :disabled="media.used"
                            data-testid="media-delete"
                            @click="destroy"
                        >
                            この{{ noun }}を削除する
                        </Button>
                    </div>
                </section>
            </div>
        </div>

        <!-- 確認はこの画面で 1 つだけ。どの操作の確認かは pending が持つ -->
        <ConfirmationDialog
            :confirmation="confirmation"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>

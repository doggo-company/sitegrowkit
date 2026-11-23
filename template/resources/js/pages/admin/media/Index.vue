<script setup lang="ts">
// メディア管理。クライアント操作は「アップロード → alt 入力 → 選択」のみのシンプル UX（ADR-0006）。
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import { extensionLabel } from '@/types/media';
import type { MediaItem, MediaKind } from '@/types/media';
import { useAdminCapability } from '@/composables/useAdminCapability';

interface MediaListItem extends MediaItem {
    used: boolean;
}

const canWrite = useAdminCapability('content.write');

const props = defineProps<{
    items: {
        data: MediaListItem[];
        last_page: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    /** タブの現在地。`null` は「すべて」 */
    kind: MediaKind | null;
}>();

/**
 * 種別のタブ（Phase 9.5-D / SPEC §8.1）。
 *
 * 画面は 1 つのまま。`?kind=` で絞るだけなので、URL を共有すれば同じ見え方になる。
 */
const tabs: { value: MediaKind | null; label: string }[] = [
    { value: null, label: 'すべて' },
    { value: 'image', label: '画像' },
    { value: 'file', label: 'ファイル' },
];

function tabHref(value: MediaKind | null): string {
    return value === null ? '/admin/media' : `/admin/media?kind=${value}`;
}

/** 空状態の文言は種別ごとに変える（§8.3 正本） */
const emptyMessage = computed(() =>
    props.kind === 'file'
        ? 'ファイルはまだ 1 つもありません。「アップロード」から追加できます。'
        : 'まだ画像がありません。上のフォームからアップロードしてください。',
);

const page = usePage();

/**
 * validation error（shared props 由来）を読むための read-only な入口。
 *
 * **template 側で型アサーションを書かない。** `errors as Record<string, string>` を
 * template に置くと、Prettier の Vue パーサが `<string, string>` をタグの開始と読み、
 * 以降の閉じタグが解決できずフォーマットが失敗する。
 * 型の確定はここで 1 度だけ行い、template は `pageErrors.media` のように参照する。
 */
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

// --- アップロード ---
const uploadFile = ref<File | null>(null);
const uploadAlt = ref('');
const uploadName = ref('');
const uploading = ref(false);

function onFileChange(event: Event) {
    uploadFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload() {
    if (!uploadFile.value) {
        return;
    }

    uploading.value = true;
    router.post(
        '/admin/media',
        {
            file: uploadFile.value,
            name: uploadName.value,
            alt: uploadAlt.value,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                uploading.value = false;
            },
            onSuccess: () => {
                uploadFile.value = null;
                uploadName.value = '';
                uploadAlt.value = '';
                (
                    document.getElementById('media-file') as HTMLInputElement
                ).value = '';
            },
        },
    );
}

// 編集は専用ページ（/admin/media/{id}）へ移した（F-UX7）。modal は持たない。

// --- 削除（未使用のみ。使用中はサーバー側で拒否され使用箇所が表示される） ---
// 確認は ConfirmationGate（サーバー）が発行する。AI からの削除も同じ経路（STEP 7）
const pendingItem = ref<MediaListItem | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const item = pendingItem.value;

        if (!item) {
            return;
        }

        router.delete(`/admin/media/${item.id}`, {
            data: token ? { confirmation_token: token } : {},
            preserveScroll: true,
            preserveState: token === null,
        });
    },
);

/**
 * 使用中のものは削除ボタンが押せない。
 *
 * 🔴 ここにはブラウザの警告を出す分岐が在ったが、**押せないボタンの `@click` は発火しない**ので
 * 1 度も出ていなかった（WP 一周 9 周目 2026-09-04）。消したい人には、
 * 押せない理由と**次に行く先**を画面に出す（下の `media-used-reason`）。
 */
function destroyItem(item: MediaListItem) {
    pendingItem.value = item;
    request();
}

function formatSize(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.round(bytes / 1024)} KB`;
}
</script>

<template>
    <Head title="メディア" />

    <div class="flex flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">メディア</h1>

        <nav
            class="flex gap-1"
            aria-label="メディアの種別"
            data-testid="media-tabs"
        >
            <Button
                v-for="tab in tabs"
                :key="tab.label"
                :variant="kind === tab.value ? 'default' : 'outline'"
                size="sm"
                as-child
            >
                <Link
                    :href="tabHref(tab.value)"
                    :aria-current="kind === tab.value ? 'page' : undefined"
                    :data-testid="`media-tab-${tab.value ?? 'all'}`"
                    >{{ tab.label }}</Link
                >
            </Button>
        </nav>

        <p
            v-if="pageErrors.media"
            class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-destructive dark:border-red-900 dark:bg-red-950"
            role="alert"
        >
            {{ pageErrors.media }}
        </p>

        <form
            v-if="canWrite"
            class="grid max-w-xl gap-4 rounded-xl border p-4"
            data-testid="media-upload-form"
            @submit.prevent="upload"
        >
            <h2 class="text-sm font-medium">アップロード</h2>
            <div class="grid gap-2">
                <Label for="media-file">ファイル</Label>
                <!-- 欄は 1 つ。受け取ったものの種別はサーバーが決める（§8.1） -->
                <Input
                    id="media-file"
                    type="file"
                    accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.svg,.pdf,.txt,.csv,.docx,.xlsx,.pptx"
                    @change="onFileChange"
                />
                <p class="text-xs text-muted-foreground">
                    画像（jpg / png / gif / webp / avif / svg、最大 10MB）と
                    PDF・テキスト・CSV・Word・Excel・PowerPoint（最大 20MB）
                </p>
                <InputError :message="pageErrors.file" />
            </div>
            <div class="grid gap-2">
                <Label for="media-name">管理用名称（空欄ならファイル名）</Label>
                <Input id="media-name" v-model="uploadName" maxlength="120" />
            </div>
            <div class="grid gap-2">
                <Label for="media-alt"
                    >画像の説明（目の不自由な方の読み上げに使われます）</Label
                >
                <Input id="media-alt" v-model="uploadAlt" maxlength="200" />
                <InputError :message="pageErrors.alt" />
            </div>
            <div>
                <Button type="submit" :disabled="!uploadFile || uploading"
                    >アップロード</Button
                >
            </div>
        </form>

        <ul
            class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
            data-testid="media-grid"
        >
            <li
                v-for="item in items.data"
                :key="item.id"
                class="flex flex-col overflow-hidden rounded-xl border"
                :data-testid="`media-item-${item.id}`"
            >
                <!-- サムネイル / ファイル名から専用編集ページへ（F-UX6 / F-UX7） -->
                <Link
                    :href="`/admin/media/${item.id}`"
                    class="block focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :data-testid="`media-thumb-link-${item.id}`"
                    :aria-label="`${item.name} を編集`"
                >
                    <img
                        v-if="item.url"
                        :src="item.url"
                        :alt="item.alt ?? ''"
                        :width="item.width ?? undefined"
                        :height="item.height ?? undefined"
                        loading="lazy"
                        class="aspect-[4/3] w-full bg-muted object-cover"
                    />
                    <!-- ファイルにサムネイルは作らない。拡張子で見分けられるようにする -->
                    <span
                        v-else
                        class="grid aspect-[4/3] w-full place-items-center bg-muted text-lg font-semibold text-muted-foreground"
                        :data-testid="`media-badge-${item.id}`"
                        >{{ extensionLabel(item) || 'FILE' }}</span
                    >
                </Link>
                <div class="flex flex-1 flex-col gap-1 p-3 text-sm">
                    <Link
                        :href="`/admin/media/${item.id}`"
                        class="truncate font-medium underline-offset-4 hover:underline"
                        data-testid="media-name-link"
                    >
                        {{ item.name }}
                    </Link>
                    <span
                        v-if="item.kind === 'file'"
                        class="truncate text-xs text-muted-foreground"
                        :data-testid="`media-original-name-${item.id}`"
                    >
                        {{ item.original_name }}
                    </span>
                    <!-- 寸法は画像だけ。値が無い項目は「—」も出さず、項目ごと消す -->
                    <span class="text-xs text-muted-foreground">
                        <template v-if="item.width && item.height">
                            {{ item.width }}×{{ item.height }} ・
                        </template>
                        {{ formatSize(item.size) }}
                    </span>
                    <span class="flex flex-wrap gap-1">
                        <Badge :variant="item.used ? 'default' : 'secondary'">
                            {{ item.used ? '使用中' : '未使用' }}
                        </Badge>
                        <Badge
                            v-if="item.kind === 'file'"
                            variant="outline"
                            :data-testid="`media-visibility-${item.id}`"
                        >
                            {{
                                item.visibility === 'private'
                                    ? '管理者のみ'
                                    : '公開'
                            }}
                        </Badge>
                    </span>
                    <span class="mt-2 flex gap-1">
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="`/admin/media/${item.id}`">編集</Link>
                        </Button>
                        <Button
                            v-if="item.download_url"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a
                                :href="item.download_url"
                                :data-testid="`media-download-${item.id}`"
                                >ダウンロード</a
                            >
                        </Button>
                        <Button
                            v-if="!item.used && canWrite"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            @click="destroyItem(item)"
                        >
                            削除
                        </Button>
                    </span>
                    <!--
                        押せないボタンだけを置かない。消したい人が次に何をすれば
                        よいかを、その場で言う（WP 一周 9 周目 / 効くもの ③）
                    -->
                    <span
                        v-if="item.used"
                        class="mt-1 text-xs text-muted-foreground"
                        :data-testid="`media-used-reason-${item.id}`"
                    >
                        使用中のため削除できません。
                        <Link
                            :href="`/admin/media/${item.id}`"
                            class="underline underline-offset-2"
                            >どこで使われているかを見る</Link
                        >
                    </span>
                </div>
            </li>
            <li
                v-if="items.data.length === 0"
                class="col-span-full py-10 text-center text-muted-foreground"
            >
                {{ emptyMessage }}
            </li>
        </ul>

        <nav v-if="items.last_page > 1" class="flex gap-1">
            <template v-for="(link, i) in items.links" :key="i">
                <Button
                    v-if="link.url"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    @click="router.get(link.url!, {}, { preserveScroll: true })"
                >
                    {{
                        link.label
                            .replace('&laquo;', '«')
                            .replace('&raquo;', '»')
                    }}
                </Button>
            </template>
        </nav>

        <ConfirmationDialog
            :confirmation="confirmation"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>

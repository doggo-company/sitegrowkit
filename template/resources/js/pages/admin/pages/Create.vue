<script setup lang="ts">
/**
 * ページの新規作成（Phase 9.3B / #19・SPEC §W）。
 *
 * 受け取るのは **タイトルと URL だけ**。管理方式や公開状態は入力させない
 * （作成されるページは常に下書き / ブロック管理 — Phase 9.3B / #17）。
 */
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import InputError from '@/components/InputError.vue';
import { adminApi } from '@/lib/adminApi';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    /** 見本ページの一覧から来たときに埋めておく値（無ければ空） */
    initial?: { title: string; slug: string };
    /** ページで置き換えられる見本（同じ名前で作ると、見本の URL で作る。UX 一周 4 周目 R4-5） */
    samples?: { title: string; path: string }[];
}>();

const form = useForm({
    title: props.initial?.title ?? '',
    slug: props.initial?.slug ?? '',
});

const page = usePage();
/** 競合（同じ slug が使われている等）は form の項目ではなく画面全体のエラーとして返る */
const conflict = computed(
    () => ((page.props.errors ?? {}) as Record<string, string>).conflict,
);

const compact = (text: string): string => text.replace(/[\s　]+/g, '');

/** 打った題が見本と同じ名前で、URL を空にしているとき、その見本 */
const replacingSample = computed(() =>
    form.slug.trim() === '' && compact(form.title) !== ''
        ? (props.samples ?? []).find(
              (sample) => compact(sample.title) === compact(form.title),
          )
        : undefined,
);

/*
 * URL の候補（UX 一周 17 周目 G1）。打った題を形態素解析で読み、欄の下に「このまま作ると /… 」と出す。
 * 作成のときは URL の欄が空なら、この候補を `slug_draft` として送る（サーバーは重複に番号を付ける）。
 * 読めない環境では出さず、サーバーの下書きに任せる。
 */
const draft = ref('');
let reading = 0;

/*
 * URL の候補（UX 一周 19 周目）。読みはサーバーの形態素解析（IPADIC）で出す。ブラウザに辞書を送らない。
 * 使われている URL は、候補に出す前に -2 を付けてくる。作成は候補を待たない（作るときもサーバーが同じ読みで付ける）。
 */
async function readTitle(): Promise<void> {
    const ticket = ++reading;
    const title = form.title.trim();

    if (title === '') {
        draft.value = '';

        return;
    }

    const result = await adminApi.send<{ slug: string }>(
        'GET',
        `/admin/pages/slug-preview?title=${encodeURIComponent(title)}`,
    );

    if (ticket === reading) {
        draft.value =
            result.status === 'success' ? (result.data.slug ?? '') : '';
    }
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => form.title,
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => void readTitle(), 120);
    },
);

function submit() {
    // 画面に出していた候補を添える（作った URL と違えば、サーバーが正直に言う）
    const shown = form.slug.trim() === '' ? draft.value : '';

    form.transform((data) => ({ ...data, slug_preview: shown })).post(
        '/admin/pages',
    );
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="ページを作成" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <div class="flex flex-col gap-1">
            <Link
                href="/admin/pages"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                ← ページ一覧へ戻る
            </Link>
            <h1 class="text-xl font-semibold">ページを作成</h1>
            <p class="text-sm text-muted-foreground">
                作成すると下書きになります。中身を組み立ててから公開してください。
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="page-title">
                    タイトル <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="page-title"
                    v-model="form.title"
                    data-testid="page-title-input"
                />
                <InputError :message="form.errors.title" />
                <p
                    v-if="replacingSample"
                    class="text-sm text-muted-foreground"
                    data-testid="page-replaces-sample"
                >
                    同じ名前の見本ページ（{{
                        replacingSample.path
                    }}）があります。このページは
                    {{ replacingSample.path }}
                    で作り、公開すると見本の代わりにこちらが出ます（メニューもこのページを指します）。
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="page-slug">
                    URL
                    <span class="text-xs text-muted-foreground"
                        >（空のままでも作れます）</span
                    >
                </Label>
                <Input
                    id="page-slug"
                    v-model="form.slug"
                    placeholder="company-profile"
                    data-testid="page-slug-input"
                />
                <p
                    class="text-xs text-muted-foreground"
                    data-testid="page-slug-help"
                >
                    空のままで大丈夫です。タイトルから付けます（会社概要 →
                    /company、施術のご案内 → /shijutsu-no-go-annai など）。同じ
                    URL があるときは、後ろに番号を付けます。<br />
                    変えたいときだけ、英小文字・数字・ハイフンで書いてください（例:
                    /company-profile）。付けた URL
                    は作成後の画面に出て、あとから変えられます。
                </p>
                <p
                    v-if="
                        form.slug.trim() === '' &&
                        draft !== '' &&
                        !replacingSample
                    "
                    class="text-sm"
                    data-testid="page-slug-draft"
                >
                    このまま作ると URL は <strong>/{{ draft }}</strong>
                    になります（同じ URL があれば後ろに番号を付けます）。
                </p>
                <InputError :message="form.errors.slug" />
            </div>

            <InputError :message="conflict" />

            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing"
                    >作成する</Button
                >
                <Button variant="outline" as-child>
                    <Link href="/admin/pages">キャンセル</Link>
                </Button>
            </div>
        </form>
    </div>
</template>

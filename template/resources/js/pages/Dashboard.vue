<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    contentState,
    contentStateLabel,
    contentStateVariant,
} from '@/lib/content-state';
import GettingStartedPanel from '@/components/GettingStartedPanel.vue';
import StarterPageCard from '@/components/StarterPageCard.vue';
import IndustryPicker from '@/components/IndustryPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

type CollectionStat = {
    id: string;
    label: string;
    count: number;
    indexUrl: string;
    createUrl: string;
};

/** 直近の投稿。**型名を画面へ焼き込まない**（Phase 9.7 / STAGE 20F） */
type RecentEntry = {
    id: number;
    title: string;
    status: string;
    published_at: string | null;
    published_at_iso?: string | null;
    editUrl: string | null;
};

/**
 * 「記事を書けるようにする」の 1 クリック（WP 一周 2026-09-03 / 効くもの ①）。
 * 書ける入れ物が 1 つも無いときだけサーバーが渡す。
 */
type QuickStart = {
    typeId: string;
    label: string;
    url: string;
};

/** 最初の 6 歩（`App\Support\FirstSteps`）。**済んだ印は保存していない** */
type FirstStep = {
    key: string;
    title: string;
    description: string;
    href: string;
    linkLabel: string;
    done: boolean;
    checkable: boolean;
};

const props = defineProps<{
    collections: CollectionStat[];
    quickStart: QuickStart | null;
    firstSteps: {
        steps: FirstStep[];
        allDone: boolean;
        readiness: { checkedAt: string; label: string; count: number } | null;
        doneMessage: string | null;
    };
    media: { count: number; indexUrl: string };
    contact: { total: number; received: number; indexUrl: string };
    recent: { label: string | null; items: RecentEntry[] };
    siteSettingsUrl: string;
    /** 最初に業種を聞く入口（設定を触れる人だけに出す） */
    industry?: {
        current: string | null;
        name?: string;
        options: { key: string; label: string; hint?: string }[];
    };
}>();

// 「はじめに」のコンテンツ導線は schema 駆動の collection から取る
// （News 等を hardcode しない。collection を持たない構成では link を出さない）
const firstCollection = computed<CollectionStat | null>(
    () => props.collections[0] ?? null,
);

/**
 * 押している間だけ止める。**二度押しで 2 回書きに行かせない**
 * （lock version で弾かれるが、利用者にはただの失敗に見える）。
 */
const startingPosts = ref(false);

function startWriting(): void {
    if (props.quickStart === null || startingPosts.value) {
        return;
    }

    startingPosts.value = true;

    router.post(
        props.quickStart.url,
        {},
        { onFinish: () => (startingPosts.value = false) },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ダッシュボード',
                href: dashboard(),
            },
        ],
    },
});

/** 作りの画面（投稿タイプ）へのリンクは、開ける人（制作者）にだけ出す */
const screenNavigation = computed(
    () =>
        (
            usePage().props.adminScreen as
                { navigation?: Record<string, boolean> } | undefined
        )?.navigation ?? {},
);
const canContact = computed(
    () => screenNavigation.value['/admin/contact'] !== false,
);
const canWrite = computed(() =>
    (
        (usePage().props.auth as { capabilities?: string[] } | undefined)
            ?.capabilities ?? []
    ).includes('content.write'),
);
const canSettings = computed(
    () => screenNavigation.value['/admin/site'] !== false,
);

const canBuild = computed(() =>
    (
        (usePage().props.auth as { capabilities?: string[] } | undefined)
            ?.capabilities ?? []
    ).includes('site.build'),
);
</script>

<template>
    <Head title="ダッシュボード" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <!-- 最初に業種を聞く（UX 一周 2026-09-28）。その店の形でサイトが始まるように -->
        <IndustryPicker
            v-if="industry && canSettings"
            :current="industry.current"
            :name="industry.name ?? ''"
            :options="industry.options"
        />

        <StarterPageCard v-if="canSettings" />

        <!-- 最初の 6 歩（済んだ印はサーバーが実状態から導く。閉じると localStorage に記憶される） -->
        <GettingStartedPanel
            :steps="firstSteps.steps"
            :all-done="firstSteps.allDone"
            :readiness-result="firstSteps.readiness"
            :completed-message="firstSteps.doneMessage"
            :quick-start-url="quickStart?.url ?? null"
            :write-url="firstCollection?.createUrl ?? null"
        />

        <!-- コンテンツ概況（実データのみ） -->
        <div class="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
            <Card v-for="collection in collections" :key="collection.id">
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        {{ collection.label }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex items-end justify-between gap-2">
                        <Link
                            :href="collection.indexUrl"
                            class="text-3xl font-semibold tabular-nums hover:underline"
                        >
                            {{ collection.count }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                                >件</span
                            >
                        </Link>
                        <Button
                            v-if="canWrite"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="collection.createUrl">新規作成</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        メディア
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex items-end justify-between gap-2">
                        <Link
                            :href="media.indexUrl"
                            class="text-3xl font-semibold tabular-nums hover:underline"
                        >
                            {{ media.count }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                                >件</span
                            >
                        </Link>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="media.indexUrl">管理</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="canContact">
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        お問い合わせ
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex items-end justify-between gap-2">
                        <Link
                            :href="contact.indexUrl"
                            class="text-3xl font-semibold tabular-nums hover:underline"
                        >
                            {{ contact.total }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                                >件</span
                            >
                        </Link>
                        <!-- 押せる札にする（UX 一周 10 周目 R10-4: 片付けたい人が最初に押すのにリンクでなかった） -->
                        <Link
                            v-if="contact.received > 0"
                            :href="contact.indexUrl"
                            data-testid="contact-received-link"
                        >
                            <Badge variant="default" class="hover:underline">
                                未対応 {{ contact.received }}
                            </Badge>
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- 最近のニュース -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">
                    最近の{{ recent.label ?? '投稿' }}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ul v-if="recent.items.length > 0" class="divide-y">
                    <li
                        v-for="item in recent.items"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <Badge
                                :variant="
                                    contentStateVariant(
                                        contentState(
                                            item.status,
                                            item.published_at_iso,
                                        ),
                                    )
                                "
                            >
                                {{
                                    contentStateLabel(
                                        contentState(
                                            item.status,
                                            item.published_at_iso,
                                        ),
                                    )
                                }}
                            </Badge>
                            <span class="truncate">{{ item.title }}</span>
                            <span
                                v-if="item.published_at"
                                class="shrink-0 text-sm text-muted-foreground"
                            >
                                {{ item.published_at }}
                            </span>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link v-if="item.editUrl" :href="item.editUrl"
                                >編集</Link
                            >
                        </Button>
                    </li>
                </ul>
                <!--
                    🔴 **行き先の無い案内を出さない**（2026-08-30 実測）。

                    ここは「まだニュースがありません。『新規作成』から…」と書いていたが、
                    **「ニュース」という投稿タイプは生成直後に存在せず**、
                    この画面にも「新規作成」は無い。読んだ人は探して見つからない。

                    書けるタイプが在るときは**その名前で**言い、無いときは
                    **どこへ行けば書けるようになるか**を言う（オーナー決定 2026-08-24 で
                    「中身が空の投稿メニューは既定で並べない」としているため、
                    生成直後は「無い」が正しい状態である）。
                -->
                <p
                    v-else-if="firstCollection"
                    class="text-sm text-muted-foreground"
                >
                    まだ{{
                        firstCollection.label
                    }}がありません。上の「新規作成」から
                    最初の記事を作成できます。
                </p>
                <!--
                    🔴 **案内文ではなく、押せるものを置く**（WP 一周 2026-09-03 / 効くもの ①）。

                    ここは「投稿タイプ で『管理メニューに出す』を有効にすると、ここに出ます」
                    と書いていた。行き先はあるが、その先で サイドバー・公開スラッグ・公開する・
                    個別 URL・一覧 URL の **5 か所**を自分で組み立てることになる。
                    WordPress から来た人の一手目は「投稿 → 新規追加」なので、そこまで辿り着けない。

                    既定（B-39 = 空の投稿メニューを並べない）は変えていない。
                    変えたのは**既定から抜け出すまでの手数**だけである。
                -->
                <div v-else-if="quickStart" class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        まだ記事を書く場所がありません。下のボタンを押すと 「{{
                            quickStart.label
                        }}」が使えるようになり、書いたものがここに並びます。
                    </p>
                    <Button
                        size="sm"
                        :disabled="startingPosts"
                        @click="startWriting"
                    >
                        {{
                            startingPosts
                                ? '用意しています…'
                                : '記事を書けるようにする'
                        }}
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        サイドバーに「{{
                            quickStart.label
                        }}」が出て、公開サイトにも一覧が付きます。
                        <template v-if="canBuild">
                            あとから
                            <Link
                                href="/admin/content-types"
                                class="underline underline-offset-4"
                                >投稿タイプ</Link
                            >
                            で戻せます。
                        </template>
                    </p>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    記事を書ける入れ物がまだありません。
                    <template v-if="canBuild">
                        <Link
                            href="/admin/content-types"
                            class="underline underline-offset-4"
                            >投稿タイプ</Link
                        >
                        で「管理メニューに出す」を有効にすると、ここに出ます。
                    </template>
                    <template v-else>
                        サイトを作った人（制作者）に、記事を書く場所を用意してもらってください。
                    </template>
                </p>
            </CardContent>
        </Card>

        <!-- 管理リンク -->
        <div class="flex flex-wrap gap-2">
            <Button v-if="canSettings" variant="outline" size="sm" as-child>
                <Link :href="siteSettingsUrl">サイト設定</Link>
            </Button>
            <Button v-if="canContact" variant="outline" size="sm" as-child>
                <Link :href="contact.indexUrl">お問い合わせ一覧</Link>
            </Button>
        </div>
    </div>
</template>

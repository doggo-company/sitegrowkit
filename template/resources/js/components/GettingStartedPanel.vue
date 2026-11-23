<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AiSetupPanel from '@/components/AiSetupPanel.vue';
import { useGettingStarted } from '@/composables/useGettingStarted';

/**
 * Dashboard の「最初の 6 歩」（WP 一周 2026-09-03 / オーナー 13:06）。
 *
 * オーナーの原文 —「**一番困るのははじめインストールした後に何をすればいいか
 * わからないこと**」。
 *
 * ## Phase 8.5-E の意図をここで覆している
 *
 * ここには「**進捗の自動判定・パーセンテージ・チェックボックスの保存は持たない**
 * （番号 1〜4 は視覚上の順序を示すためだけ）」と書いてあった。
 * 覆したのは**自動判定**の部分だけで、**手動の済んだ印の保存は今も持たない** —
 * 最初の 5 歩の済んだ印はサーバーが**いまのサイトの状態から導く**（`App\Support\FirstSteps`）。
 * パーセンテージも出さない。
 *
 * 「済んだことにして先へ進む」ができないので、印と現実がずれない。
 *
 * ## 6 歩目は実行記録を使う
 *
 * 公開前チェックは公開している URL を全部取りに行くので、ダッシュボードを
 * 開くたびには回せない。安い部分だけで緑にすると**回していないのに緑**になる。
 * 実際に回した最後の結果が 0 件なら済みにする。日時を示し、現在の保証とはしない。
 * 残りの数は従来どおり最初の 5 歩だけで数え、その範囲を画面に明記する。
 */
type Step = {
    key: string;
    title: string;
    description: string;
    href: string;
    linkLabel: string;
    done: boolean;
    checkable: boolean;
};

const props = defineProps<{
    steps: Step[];
    /** 判定できる 5 歩が済んだか。6 歩目は数に入らない */
    allDone: boolean;
    readinessResult?: {
        checkedAt: string;
        label: string;
        count: number;
    } | null;
    completedMessage?: string | null;
    /** 「記事を書けるようにする」1 クリック（書ける入れ物が無いときだけ） */
    quickStartUrl?: string | null;
    /** 判定できる 5 歩が済んだあとの行き先（記事を書く先。書ける入れ物が無ければ null） */
    writeUrl?: string | null;
}>();

const { visible, dismiss } = useGettingStarted();

/** AI 連携のはじめかた。**別ページへ飛ばさず、その場で開く** */
const aiSetupOpen = ref(false);

const remaining = computed(
    () => props.steps.filter((step) => step.checkable && !step.done).length,
);

const readiness = computed<Step | null>(
    () => props.steps.find((step) => !step.checkable) ?? null,
);

/**
 * 2 歩目の行き先は、書ける入れ物があるかで変わる。
 * 無いのに「ページを作る」しか出さないと、記事を書きたい人が迷う。
 */
function hrefFor(step: Step): string {
    return step.key === 'content' && props.quickStartUrl
        ? props.quickStartUrl
        : step.href;
}

function labelFor(step: Step): string {
    return step.key === 'content' && props.quickStartUrl
        ? '記事を書けるようにする'
        : step.linkLabel;
}

/**
 * 🔴 **押すと状態が変わるものは POST にする**（2 周目 2026-09-03 で踏んだ）。
 *
 * 2 歩目の行き先が「記事を書けるようにする」に変わるとき、これは
 * `admin.content-types.quick-start`（POST 専用）である。**link のまま出すと GET で叩いて
 * 404 になる** — 押した人には「壊れている」としか見えない。
 * ここを間違えても型検査も build も通るので、画面で押すまで気づけない。
 */
function isPost(step: Step): boolean {
    return (
        step.key === 'content' &&
        props.quickStartUrl !== null &&
        props.quickStartUrl !== undefined
    );
}

/** 判定できる 5 歩が済んだときの案内。空白で折り返されないよう script 側に持つ */
const doneMessage =
    '最初の 6 歩のうち 5 歩が済みました。残りの 1 歩は、公開の前に公開前チェックでひととおり見ることです。';
</script>

<template>
    <!-- 判定できる 5 歩が済んだら、残りの公開前チェックを案内する -->
    <Card v-if="visible && allDone" data-testid="getting-started">
        <CardContent
            class="flex flex-col gap-3 pt-6 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0 space-y-2">
                <!--
                    最後の公開前チェックの実行記録があれば、その日時を含む案内へ進む。
                -->
                <p class="text-sm">{{ completedMessage ?? doneMessage }}</p>
                <p
                    v-if="readinessResult && !completedMessage"
                    class="text-sm text-muted-foreground"
                >
                    最後の公開前チェック：{{ readinessResult.label }}・直すもの
                    {{ readinessResult.count }} 件。
                </p>
                <!--
                    **次の一手を名前で言う**（4 周目 2026-09-03 の 🟡）。
                    ここが公開前チェックのボタンだけだと、「準備はできています」の先が空く。
                    件数のカードは下に並んでいるが、**やることの名前**にはなっていない。
                -->
                <p class="text-sm text-muted-foreground">
                    次は
                    <Link
                        v-if="writeUrl"
                        :href="writeUrl"
                        class="underline underline-offset-4"
                        >記事を書く</Link
                    >
                    <span v-else>記事を書く</span>
                    ・
                    <Link
                        href="/admin/pages/create"
                        class="underline underline-offset-4"
                        >ページを足す</Link
                    >
                    ・
                    <Button
                        variant="link"
                        size="sm"
                        class="h-auto p-0 text-sm font-normal underline underline-offset-4"
                        @click="aiSetupOpen = true"
                        >デザインを変える</Button
                    >
                    のどれからでも。
                </p>
            </div>
            <div class="flex shrink-0 gap-2">
                <Button v-if="completedMessage" size="sm" as-child>
                    <Link href="/admin/readiness#deploy"
                        >サーバーに出す手順を見る</Link
                    >
                </Button>
                <Button
                    v-else-if="readiness"
                    size="sm"
                    variant="outline"
                    as-child
                >
                    <Link :href="readiness.href">{{
                        readiness.linkLabel
                    }}</Link>
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    data-testid="getting-started-dismiss"
                    aria-label="はじめにパネルを今後表示しない"
                    @click="dismiss"
                >
                    今後表示しない
                </Button>
            </div>
        </CardContent>
    </Card>

    <Card v-else-if="visible" data-testid="getting-started">
        <!-- 狭い画面では見出しと閉じるボタンを縦に積む（intro が細長くならないように） -->
        <CardHeader
            class="flex flex-col items-start gap-2 sm:flex-row sm:justify-between sm:gap-3"
        >
            <div class="min-w-0 sm:order-1">
                <CardTitle class="text-base"
                    >最初の {{ steps.length }} 歩</CardTitle
                >
                <p class="mt-1 text-sm text-muted-foreground">
                    <!-- 最後の公開前チェックは自動判定しないので、残りの数と分けて示す。
                         自動で済みになるのは最後以外なので、そう言う -->
                    上から順にどうぞ。済んだものは自動でチェックが付きます（最初の
                    5 歩はあと
                    {{ remaining }} 歩<template v-if="readiness"
                        >。最後の「{{
                            readiness.title
                        }}」は公開の直前に</template
                    >）。
                </p>
            </div>
            <Button
                variant="ghost"
                size="sm"
                class="-ml-2 shrink-0 self-end sm:order-2 sm:ml-0 sm:self-start"
                data-testid="getting-started-dismiss"
                aria-label="はじめにパネルを今後表示しない"
                @click="dismiss"
            >
                今後表示しない
            </Button>
        </CardHeader>
        <CardContent>
            <p
                v-if="readinessResult"
                class="mb-3 text-sm text-muted-foreground"
            >
                最後の公開前チェック：{{ readinessResult.label }}・直すもの
                {{ readinessResult.count }}
                件。公開の直前にもう一度確かめてください。
            </p>
            <ol class="grid gap-4 sm:grid-cols-2">
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="flex gap-3"
                    data-testid="getting-started-item"
                    :data-done="step.done ? 'true' : 'false'"
                >
                    <span
                        v-if="step.done"
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
                        data-testid="getting-started-done"
                    >
                        <Check class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">済み</span>
                    </span>
                    <span
                        v-else
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        {{ index + 1 }}
                    </span>
                    <div class="min-w-0 space-y-1">
                        <p
                            class="text-sm font-medium"
                            :class="
                                step.done ? 'text-muted-foreground' : undefined
                            "
                        >
                            {{ step.title }}
                        </p>
                        <!-- 済んだものの説明文は出さない（残っているものが埋もれる） -->
                        <p
                            v-if="!step.done"
                            class="text-sm text-muted-foreground"
                        >
                            {{ step.description }}
                        </p>
                        <Link
                            v-if="!step.done && step.href"
                            :href="hrefFor(step)"
                            :method="isPost(step) ? 'post' : undefined"
                            :as="isPost(step) ? 'button' : undefined"
                            class="inline-block rounded-sm text-sm font-medium underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            data-testid="getting-started-link"
                        >
                            {{ labelFor(step) }}
                        </Link>
                    </div>
                </li>
            </ol>

            <!--
                AI 連携は 6 歩に入れない（Stage 1 で足した導線）。
                **順にこなす道の途中に置くと、そこで止まる人が出る。**
                いつでも開けるものとして、下に 1 行だけ残す。
            -->
            <div class="mt-4 border-t pt-4">
                <Button
                    variant="link"
                    size="sm"
                    class="h-auto min-h-11 p-0 text-sm font-medium underline underline-offset-4"
                    data-testid="getting-started-ai-setup"
                    @click="aiSetupOpen = true"
                >
                    AI エージェントと繋ぐには
                </Button>
            </div>
        </CardContent>
    </Card>

    <AiSetupPanel v-model:open="aiSetupOpen" />
</template>

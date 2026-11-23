<script setup lang="ts">
/**
 * メニューの編集（Phase 9.9 / STAGE 22D・SPEC §F）。
 *
 * ## 並べ替えはボタンで行う
 *
 * Drag & Drop は**補助**であって唯一の手段にしない。上へ / 下へ / 字下げ / 字上げ を
 * ボタンにすれば、キーボードだけで木を組み替えられる（マウスでも同じ手順で動く）。
 * 押した項目に focus が残るので、続けて押せる。
 *
 * ## 保存は木ごと
 *
 * 送るのは木そのもので、`parent_id` は含めない（入れ子が親子を表す）。
 * こうすると「別のメニューの項目を親にした」状態を**作れない**。
 *
 * ## 競合したら読み直してもらう
 *
 * 版が合わなければサーバーが 409 を返す。**勝手に取り直して再実行しない** —
 * 押したときと状況が違うので、もう一度確かめてもらう。
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, onMounted } from 'vue';
import { copyMenuTree } from '@/lib/menu-copy';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Choice {
    pages: { id: number; label: string; slug: string; isFront?: boolean }[];
    contentTypes: { id: string; label: string }[];
    routes: { name: string; label: string }[];
    /** 分類の項目（7 周目 2026-09-03）。`taxonomy` は画面で束ねて見せるため */
    terms: { id: string; label: string; taxonomy: string }[];
}

import {
    removeFromTree,
    removedSummary,
    restoreToTree,
} from '@/lib/menu-removal';
import type { RemovedNode } from '@/lib/menu-removal';
import { followTitle, targetLabel } from '@/lib/menu-title';

interface ItemInput {
    id: number | null;
    title: string | null;
    link_type: string;
    link_url: string | null;
    link_route: string | null;
    page_id: number | null;
    content_type: string | null;
    content_id: number | null;
    taxonomy_term_id: string | null;
    target: string;
    children: ItemInput[];
}

const props = defineProps<{
    menu: {
        key: string;
        name: string;
        description: string | null;
        code_managed: boolean;
        lock_version: number;
    };
    items: ItemInput[];
    headerItems: ItemInput[] | null;
    copyHeader: boolean;
    hasFooter: boolean;
    unavailable: Record<string, string>;
    choices: Choice;
    canWrite?: boolean;
}>();

const writable = props.canWrite !== false;
const copied = ref(false);
const saved = ref(false);

/** 置き換えるのは編集中の木だけ。useForm の差分が離脱確認にも使われる。 */
function loadHeader() {
    if (
        !writable ||
        props.menu.key === 'header' ||
        props.headerItems === null
    ) {
        return;
    }

    form.items = copyMenuTree(clone(props.headerItems));
    removed.value = null;
    copied.value = true;
}
onMounted(() => {
    if (props.copyHeader) {
        loadHeader();
    }
});

const conflict = ref<string | null>(null);

/**
 * 木は「親の列」として持つ。子は親の中にだけ在る（2 階層まで — OG3）。
 *
 * `useForm` に載せるのは、送信時の変換（`transform`）と検証エラーの受け取りを
 * 既存の画面と同じ作法に揃えるためである。
 */
const form = useForm({
    name: props.menu.name,
    description: props.menu.description ?? '',
    lock_version: props.menu.lock_version,
    items: clone(props.items ?? []),
});

/**
 * 受け取った木を**編集用に写し取る**。
 *
 * `structuredClone` は使わない。props は reactive な proxy なので複製できず、
 * `DataCloneError` で画面ごと落ちる。列を明示して写せば、
 * 欠けている列も既定値で揃う（サーバーが増やした列を黙って持ち回らない）。
 */
function clone(items: ItemInput[]): ItemInput[] {
    return items.map((item) => ({
        id: item.id ?? null,
        title: item.title ?? '',
        link_type: item.link_type,
        link_url: item.link_url ?? null,
        link_route: item.link_route ?? null,
        page_id: item.page_id ?? null,
        content_type: item.content_type ?? null,
        content_id: item.content_id ?? null,
        taxonomy_term_id: item.taxonomy_term_id ?? null,
        target: item.target ?? '_self',
        children: clone(item.children ?? []),
    }));
}

/** 木の実体（`form.items` をそのまま触る） */
const tree = computed<ItemInput[]>(() => form.items as ItemInput[]);

/** リンク先の中身を 1 本の文字にする（保存したときと同じリンク先かを見るため） */
const linkOf = (item: ItemInput): string =>
    [
        item.link_type,
        item.link_route,
        item.page_id,
        item.content_type,
        item.content_id,
        item.taxonomy_term_id,
        item.link_url,
    ].join('|');

/** 保存してある行のリンク先（行の id → リンク先）。保存し直したら取り直す */
const savedLinks = computed<Record<string, string>>(() => {
    const out: Record<string, string> = {};
    const walk = (items: ItemInput[]): void => {
        for (const item of items) {
            if (item.id) {
                out[String(item.id)] = linkOf(item);
            }

            walk(item.children ?? []);
        }
    };
    walk(props.items ?? []);

    return out;
});

/**
 * 出せない理由（サーバーが保存済みのリンク先で調べたもの）。リンク先を替えた行には出さない
 * （UX 一周 9 周目 R9-3: 自分のページに替えたのに、赤い「非公開にしています」が保存するまで残った）
 */
function unavailableReason(item: ItemInput): string | null {
    if (!item.id) {
        return null;
    }

    const reason = props.unavailable[String(item.id)];

    return reason && savedLinks.value[String(item.id)] === linkOf(item)
        ? reason
        : null;
}

const linkTypes = [
    { value: 'route', label: '組み込みページ' },
    { value: 'page', label: 'ページ' },
    // 型の呼び名は店ごとに違う（施工事例・お知らせ…）ので、ここは「記事」と言う。どの型かは隣で選ぶ
    { value: 'content_index', label: '記事の一覧' },
    { value: 'content', label: '記事（1 件）' },
    /*
     * 分類ごとの一覧（7 周目 2026-09-03）。
     *
     * これが無いと「カテゴリをナビに出す」ができず、`URL を直接指定` で
     * 手打ちするしかなかった。それだと**綴りを変えた瞬間に黙って 404** になる。
     */
    { value: 'term', label: '分類の一覧' },
    { value: 'custom', label: 'URL を直接指定' },
];

/** 表示名を省略できる種類（対象の名前を使う） */
const derivesTitle = (type: string) =>
    ['page', 'content', 'content_index', 'term'].includes(type);

const total = computed(() =>
    tree.value.reduce((sum, item) => sum + 1 + item.children.length, 0),
);

function blank(): ItemInput {
    return {
        id: null,
        title: '',
        link_type: 'custom',
        link_url: '',
        link_route: null,
        page_id: null,
        content_type: null,
        content_id: null,
        taxonomy_term_id: null,
        target: '_self',
        children: [],
    };
}

/**
 * 足す項目の初期値（UX 一周 2026-09-28）。
 *
 * 以前は「URL を直接指定」で始まり、作ったページを足したい人が
 * 種類を切り替えてページを選び直していた。**まだメニューに無い公開中のページ**があれば、
 * それを指した状態で始める。無ければ今までどおり URL の手入力。
 */
function add() {
    const items = tree.value.flatMap((item) => [item, ...item.children]);
    const used = new Set(items.map((item) => item.page_id));
    /*
     * 組み込みページ（/about など）の項目は、同じ URL のページを出している
     * （見本を差し替えたとき）。同じページを 2 つ並べない（UX 一周 2026-09-28）。
     */
    const routeSlugs = new Set(
        items
            .filter((item) => item.link_type === 'route')
            .map((item) => item.link_route ?? ''),
    );
    // トップに選んだページは「ホーム」と同じなので、初期値にしない（UX 一周 2026-09-28）
    const next = props.choices.pages.find(
        (page) =>
            !used.has(page.id) &&
            page.isFront !== true &&
            !routeSlugs.has(page.slug),
    );

    tree.value.push(
        next
            ? {
                  ...blank(),
                  link_type: 'page',
                  link_url: null,
                  page_id: next.id,
              }
            : blank(),
    );
}

/** その項目が今いる場所（親の配列と添字） */
function locate(item: ItemInput): { list: ItemInput[]; index: number } | null {
    const top = tree.value.indexOf(item);

    if (top !== -1) {
        return { list: tree.value, index: top };
    }

    for (const parent of tree.value) {
        const child = parent.children.indexOf(item);

        if (child !== -1) {
            return { list: parent.children, index: child };
        }
    }

    return null;
}

function move(item: ItemInput, delta: number) {
    const at = locate(item);

    if (!at) {
        return;
    }

    const next = at.index + delta;

    if (next < 0 || next >= at.list.length) {
        return;
    }

    at.list.splice(at.index, 1);
    at.list.splice(next, 0, item);
}

/** 字下げ = 直前の兄弟の子にする（2 階層までなので親だけが対象） */
function indent(item: ItemInput) {
    const at = locate(item);

    if (!at || at.list !== tree.value || at.index === 0) {
        return;
    }

    const previous = tree.value[at.index - 1];

    // 自分に子が居るなら下げられない（3 階層目ができてしまう）
    if (item.children.length > 0) {
        return;
    }

    tree.value.splice(at.index, 1);
    previous.children.push(item);
}

/** 字上げ = 親の直後の兄弟に戻す */
function outdent(item: ItemInput) {
    for (const [index, parent] of tree.value.entries()) {
        const child = parent.children.indexOf(item);

        if (child !== -1) {
            parent.children.splice(child, 1);
            tree.value.splice(index + 1, 0, item);

            return;
        }
    }
}

/**
 * 外したものを 1 つだけ覚えておく（WP 一周 6 周目 2026-09-03）。
 *
 * ここは**保存するまで何も起きない**場所なので、1 行ごとに確認を挟むと
 * 並べ替えのたびに確認するのと同じくらい邪魔になる。
 * 要るのは「戻せる」ことと、「何が一緒に外れたか」が見えることである。
 */
const removed = ref<RemovedNode<ItemInput> | null>(null);

const removedMessage = computed(() =>
    removed.value === null
        ? ''
        : removedSummary(
              removed.value.item.title ?? '',
              removed.value.childCount,
          ),
);

function remove(item: ItemInput) {
    // 親を消したら子も消える（画面の見た目と保存の結果を一致させる）。
    // **黙って持っていかない** — 何件付いてきたかは `removedMessage` が言う
    const taken = removeFromTree(tree.value, item);

    if (taken !== null) {
        removed.value = taken;
    }
}

/** 外したものを、同じ場所へ戻す */
function undoRemove() {
    if (removed.value === null) {
        return;
    }

    restoreToTree(tree.value, removed.value);
    removed.value = null;
}

function canIndent(item: ItemInput): boolean {
    const at = locate(item);

    return (
        at !== null &&
        at.list === tree.value &&
        at.index > 0 &&
        item.children.length === 0
    );
}

function isChild(item: ItemInput): boolean {
    const at = locate(item);

    return at !== null && at.list !== tree.value;
}

/**
 * リンク先を替える。表示名が前のリンク先の名前のままなら、一緒に替える（UX 一周 3 周目 M7）。
 * 自分で書き直した表示名は残す（判断は `lib/menu-title`）。
 */
function retarget(item: ItemInput, mutate: () => void) {
    const before = targetLabel(item, props.choices);

    mutate();

    item.title = followTitle(
        item.title,
        before,
        targetLabel(item, props.choices),
        derivesTitle(item.link_type),
    );
}

/** 種類を変えたら前の参照を捨てる（同じことを 2 通りで持たない） */
function changeType(item: ItemInput, type: string) {
    retarget(item, () => resetTarget(item, type));
}

function resetTarget(item: ItemInput, type: string) {
    item.link_type = type;
    item.link_url = type === 'custom' ? '' : null;
    item.link_route =
        type === 'route' ? (props.choices.routes[0]?.name ?? '') : null;
    item.page_id =
        type === 'page' ? (props.choices.pages[0]?.id ?? null) : null;
    // 分類の一覧も入口（投稿タイプ）を持つ
    item.content_type = ['content', 'content_index', 'term'].includes(type)
        ? (props.choices.contentTypes[0]?.id ?? null)
        : null;
    item.content_id = null;
    item.taxonomy_term_id =
        type === 'term' ? (props.choices.terms[0]?.id ?? null) : null;
}

/** 保存に送る形（`children` は 2 階層目まで） */
function payload(items: ItemInput[]): Record<string, unknown>[] {
    return items.map((item) => ({
        ...item,
        title: item.title === '' ? null : item.title,
        children: payload(item.children),
    }));
}

function save() {
    // 送っている最中の 2 回目は送らない（UX 一周 7 周目 R7-1: ダブルクリックで同じ版を 2 回送り、
    // 1 回目で保存できているのに 2 回目が「重なった」と赤字を出していた）
    if (form.processing) {
        return;
    }

    conflict.value = null;

    form.transform((data) => ({
        name: data.name,
        description: data.description,
        lock_version: data.lock_version,
        items: payload(data.items as ItemInput[]),
    })).patch(`/admin/menus/${props.menu.key}`, {
        preserveScroll: true,
        onSuccess: () => {
            // 保存したので、外したものはもう消えている。「保存するまで消えません／元に戻す」を
            // 残すと、外れたのかまだなのかが読めない（UX 一周 4 周目 R4-4）
            removed.value = null;
            // 保存で版が 1 つ進む。手元の版を新しくしないと、続けて保存した 2 回目が
            // 「ほかの操作と重なった」で断られ、足した行が消えていた（UX 一周 6 周目 R6-3）
            form.lock_version = props.menu.lock_version;
            form.items = clone(props.items ?? []);
            form.defaults();
            saved.value = true;
            copied.value = false;
        },
        onError: (errors) => {
            // **自動で取り直して再実行しない。** 読み直してもらう
            conflict.value = Object.values(errors)[0] ?? null;
        },
    });
}

/** 平たい一覧（親→子の順）。画面はこれを 1 本の表として描く */
const rows = computed(() =>
    tree.value.flatMap((item) => [item, ...item.children]),
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'サイトのメニュー', href: '/admin/menus' }],
    },
});

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="`サイトのメニュー: ${menu.name}`" />
    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">{{ menu.name }}</h1>
            <p class="text-sm text-muted-foreground">
                項目 {{ total }} 件。上から順に表示されます。
            </p>
            <p
                v-if="menu.code_managed"
                class="mt-1 text-sm text-muted-foreground"
                data-testid="menu-code-managed"
            >
                このメニューはテーマが使っています。名前と中身は編集できますが、削除はできません。
            </p>
            <p
                v-if="menu.key === 'header' && hasFooter"
                class="mt-1 text-sm text-muted-foreground"
                data-testid="menu-footer-is-separate"
            >
                ページの下（フッター）のメニューは別です。
                <Link href="/admin/menus/footer" class="underline"
                    >フッターのメニューも直す</Link
                >
            </p>
        </div>

        <p
            v-if="!writable"
            class="rounded-md border border-dashed px-4 py-3 text-sm text-muted-foreground"
            data-testid="menu-readonly"
        >
            このメニューを編集する権限がありません。
        </p>

        <Button
            v-if="writable && menu.key !== 'header' && headerItems !== null"
            variant="outline"
            class="w-fit"
            @click="loadHeader"
            >ヘッダーと同じ並びにする</Button
        >
        <p v-if="copied" role="status" class="text-sm">
            ヘッダーの並びを読み込みました（いまの項目と入れ替えています）。『保存』を押すと{{
                menu.key === 'footer' ? 'フッター' : menu.name
            }}に反映されます。
        </p>
        <Link
            v-if="saved && writable && menu.key === 'header' && hasFooter"
            href="/admin/menus/footer?copy=header"
            class="underline"
            >フッターにも同じ並びを使う</Link
        >

        <div class="grid gap-4 sm:max-w-md">
            <div class="grid gap-1.5">
                <Label for="menu-name">名前</Label>
                <Input
                    id="menu-name"
                    v-model="form.name"
                    :disabled="!writable"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="menu-description">説明</Label>
                <Input
                    id="menu-description"
                    v-model="form.description"
                    :disabled="!writable"
                />
            </div>
        </div>

        <!-- 「組み込みページ」が何かを書いていなかった（UX 一周 2 周目 M7） -->
        <p
            v-if="choices.routes.length > 0"
            class="text-sm text-muted-foreground"
            data-testid="menu-route-help"
        >
            種類の「組み込みページ」は、サイトに最初から用意されているページです（{{
                choices.routes.map((route) => route.label).join('・')
            }}）。自分で作ったページは「ページ」から選びます。リンク先を替えると、表示名も新しいリンク先の名前に替わります（自分で書き直した表示名はそのまま残ります）。
        </p>

        <ul class="flex flex-col gap-2" data-testid="menu-items">
            <li
                v-for="(item, index) in rows"
                :key="item.id ?? `new-${index}`"
                class="rounded-lg border p-3"
                :class="isChild(item) ? 'ml-6' : ''"
                :data-testid="isChild(item) ? 'menu-item-child' : 'menu-item'"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        class="h-9 rounded-md border px-2 text-sm"
                        :value="item.link_type"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目の種類`"
                        @change="
                            changeType(
                                item,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option
                            v-for="type in linkTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select>

                    <select
                        v-if="item.link_type === 'route'"
                        :value="item.link_route"
                        class="h-9 rounded-md border px-2 text-sm"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目のリンク先`"
                        @change="
                            retarget(item, () => {
                                item.link_route = (
                                    $event.target as HTMLSelectElement
                                ).value;
                            })
                        "
                    >
                        <option
                            v-for="route in choices.routes"
                            :key="route.name"
                            :value="route.name"
                        >
                            {{ route.label }}
                        </option>
                    </select>

                    <select
                        v-if="item.link_type === 'page'"
                        :value="item.page_id"
                        class="h-9 rounded-md border px-2 text-sm"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目のリンク先`"
                        @change="
                            retarget(item, () => {
                                item.page_id = Number(
                                    ($event.target as HTMLSelectElement).value,
                                );
                            })
                        "
                    >
                        <option
                            v-for="page in choices.pages"
                            :key="page.id"
                            :value="page.id"
                        >
                            {{ page.label }}
                        </option>
                    </select>

                    <select
                        v-if="
                            ['content', 'content_index', 'term'].includes(
                                item.link_type,
                            )
                        "
                        :value="item.content_type"
                        class="h-9 rounded-md border px-2 text-sm"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目の投稿タイプ`"
                        @change="
                            retarget(item, () => {
                                item.content_type = (
                                    $event.target as HTMLSelectElement
                                ).value;
                            })
                        "
                    >
                        <option
                            v-for="type in choices.contentTypes"
                            :key="type.id"
                            :value="type.id"
                        >
                            {{ type.label }}
                        </option>
                    </select>

                    <!-- どの分類の項目か（7 周目 2026-09-03）。
                             **綴りではなく id で持つ** ので、あとから綴りを変えても切れない -->
                    <select
                        v-if="item.link_type === 'term'"
                        :value="item.taxonomy_term_id"
                        class="h-9 rounded-md border px-2 text-sm"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目の分類`"
                        @change="
                            retarget(item, () => {
                                item.taxonomy_term_id = (
                                    $event.target as HTMLSelectElement
                                ).value;
                            })
                        "
                    >
                        <option
                            v-for="term in choices.terms"
                            :key="term.id"
                            :value="term.id"
                        >
                            {{ term.taxonomy }} / {{ term.label }}
                        </option>
                    </select>

                    <Input
                        v-if="item.link_type === 'content'"
                        :model-value="item.content_id ?? ''"
                        class="w-28"
                        @update:model-value="
                            item.content_id =
                                $event === '' ? null : Number($event)
                        "
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目の投稿 ID`"
                        placeholder="投稿 ID"
                    />

                    <Input
                        v-if="item.link_type === 'custom'"
                        :model-value="item.link_url ?? ''"
                        class="min-w-48 flex-1"
                        @update:model-value="item.link_url = String($event)"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目の URL`"
                        placeholder="https://example.com"
                    />

                    <Input
                        :model-value="item.title ?? ''"
                        class="min-w-40 flex-1"
                        @update:model-value="item.title = String($event)"
                        :disabled="!writable"
                        :aria-label="`${index + 1} 番目の項目の表示名`"
                        :placeholder="
                            derivesTitle(item.link_type)
                                ? targetLabel(item, props.choices)
                                    ? `${targetLabel(item, props.choices)}（リンク先の名前のまま）`
                                    : '省略するとリンク先の名前'
                                : '表示名（必須）'
                        "
                    />

                    <label class="flex items-center gap-1 text-sm">
                        <input
                            type="checkbox"
                            :checked="item.target === '_blank'"
                            :disabled="!writable"
                            :aria-label="`${index + 1} 番目の項目を新しいタブで開く`"
                            @change="
                                item.target = (
                                    $event.target as HTMLInputElement
                                ).checked
                                    ? '_blank'
                                    : '_self'
                            "
                        />
                        新しいタブ
                    </label>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="!writable"
                        :data-testid="`menu-up-${index}`"
                        @click="move(item, -1)"
                    >
                        上へ
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="!writable"
                        :data-testid="`menu-down-${index}`"
                        @click="move(item, 1)"
                    >
                        下へ
                    </Button>
                    <Button
                        v-if="!isChild(item)"
                        variant="ghost"
                        size="sm"
                        :disabled="!writable || !canIndent(item)"
                        :data-testid="`menu-indent-${index}`"
                        @click="indent(item)"
                    >
                        字下げ
                    </Button>
                    <Button
                        v-else
                        variant="ghost"
                        size="sm"
                        :disabled="!writable"
                        :data-testid="`menu-outdent-${index}`"
                        @click="outdent(item)"
                    >
                        字上げ
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        :disabled="!writable"
                        :data-testid="`menu-remove-${index}`"
                        @click="remove(item)"
                    >
                        取り除く
                    </Button>

                    <Badge
                        v-if="unavailableReason(item)"
                        variant="destructive"
                        data-testid="menu-unavailable"
                    >
                        {{ unavailableReason(item) }}
                    </Badge>
                </div>
            </li>

            <li
                v-if="rows.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                data-testid="menu-items-empty"
            >
                項目がありません。追加すると公開ページに出ます。
            </li>
        </ul>

        <p
            v-if="conflict"
            class="text-sm text-destructive"
            role="alert"
            aria-live="polite"
            data-testid="menu-conflict"
        >
            {{ conflict }}
        </p>

        <!--
                外したことと、戻せることを言う（6 周目 2026-09-03）。
                押した直後に行が消えるだけだと「戻せないことをした」ように見える。
            -->
        <p
            v-if="removed !== null"
            class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
            role="status"
            aria-live="polite"
            data-testid="menu-removed-notice"
        >
            {{ removedMessage }}（保存するまで消えません）
            <Button
                type="button"
                variant="link"
                size="sm"
                class="h-auto p-0"
                data-testid="menu-remove-undo"
                @click="undoRemove"
            >
                元に戻す
            </Button>
        </p>

        <div class="flex gap-2">
            <Button
                :disabled="!writable"
                data-testid="menu-add"
                variant="secondary"
                @click="add"
            >
                項目を追加
            </Button>
            <Button
                :disabled="!writable || form.processing"
                data-testid="menu-save"
                @click="save"
                >保存</Button
            >
        </div>
    </div>
</template>

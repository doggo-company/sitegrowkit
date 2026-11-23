<script setup lang="ts">
/**
 * Canvas（Phase 9.6 / P0-C2・`PHASE9_6_SPEC.md` §4.1）。
 *
 * 公開と**同じ描画**（Blade SSR のプレビュー）を iframe で出し、その上で選択させる。
 * Editor 専用の描画を作らない。ここが分かれた瞬間に
 * 「Editor では出るが公開では出ない」が生まれる。
 *
 * ## 中身を書き換えない
 *
 * この component が iframe に対してすることは **読むことだけ**。
 * クリックの受け取りと位置の計測しかせず、DOM も document も触らない。
 * 変更は必ず semantic command → `page.document.patch` を往復して、
 * 返ってきた内容で描き直す（同 §3 の I-5）。
 */
import { onBeforeUnmount, ref, watch } from 'vue';
import { selectableIdFrom } from '@/lib/canvas-select';

const props = defineProps<{
    src: string;
    selectedId: string | null;
    /** 選択できるノードの id。これ以外の要素はクリックしても無視する */
    selectableIds: string[];
    /**
     * 確認したい画面幅（px）。null なら枠いっぱい。
     *
     * **枠そのものをその幅にする**ことで、公開ページと同じ媒体クエリを働かせる。
     * Editor 側で媒体クエリを模倣しない（模倣した瞬間に公開とずれる）。
     *
     * `max-width` では足りない。管理画面の中央列がそれより狭ければ枠は縮み、
     * 媒体クエリは**縮んだ幅**で解決してしまう。実際の幅を与えて、
     * 入り切らないときは外側を横スクロールさせる。
     */
    width: number | null;
}>();

/**
 * 選んだ。2 つ目は**押した場所の文字**（右の欄のどれへカーソルを移すかの手がかり・UX 一周 3 周目 M4）。
 * iframe の中は読むだけで、書き換えない。
 */
const emit = defineEmits<{
    (e: 'select', id: string, text: string | null): void;
}>();

const frame = ref<HTMLIFrameElement | null>(null);
/** 枠が入り切らないときに横スクロールする外側。動いたら選択枠も測り直す */
const viewport = ref<HTMLElement | null>(null);
/** 選択枠の位置（親側の座標。iframe の中には何も足さない） */
const outline = ref<{
    top: number;
    left: number;
    width: number;
    height: number;
} | null>(null);

let detach: (() => void) | null = null;

/**
 * クリックされた場所から、選択できる一番近いブロックを探す。
 *
 * 判定そのものは `lib/canvas-select` が持つ（R-9 Stage 0 ⑤）。
 * **B-25（変更リクエスト）が同じ判定を使う**ためで、
 * **共有するのは判定だけ・見た目は共有しない**（`docs/PHASE9_16_SPEC.md` §3）。
 */
function selectableFrom(target: EventTarget | null): string | null {
    return selectableIdFrom(target, props.selectableIds);
}

function measure(): void {
    const document_ = frame.value?.contentDocument ?? null;
    const rect = frame.value?.getBoundingClientRect() ?? null;

    if (!document_ || !rect || props.selectedId === null) {
        outline.value = null;

        return;
    }

    const element = document_.getElementById(props.selectedId);

    if (!element) {
        outline.value = null;

        return;
    }

    const box = element.getBoundingClientRect();

    outline.value = {
        top: rect.top + box.top,
        left: rect.left + box.left,
        width: box.width,
        height: box.height,
    };
}

function onLoad(): void {
    detach?.();

    const view = frame.value?.contentWindow ?? null;
    const document_ = frame.value?.contentDocument ?? null;

    if (!view || !document_) {
        return;
    }

    const onClick = (event: MouseEvent) => {
        const id = selectableFrom(event.target);

        if (id !== null) {
            // プレビュー内のリンクで画面が飛ばないようにする（編集中なので遷移しない）
            event.preventDefault();
            const target = event.target as {
                textContent?: string | null;
            } | null;
            emit('select', id, target?.textContent?.trim() || null);
        }
    };

    const outer = viewport.value;

    document_.addEventListener('click', onClick);
    view.addEventListener('scroll', measure, { passive: true });
    outer?.addEventListener('scroll', measure, { passive: true });
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, { passive: true });

    detach = () => {
        document_.removeEventListener('click', onClick);
        view.removeEventListener('scroll', measure);
        outer?.removeEventListener('scroll', measure);
        window.removeEventListener('resize', measure);
        window.removeEventListener('scroll', measure);
    };

    measure();
}

/** document が変わったら描き直す（描画はサーバーが持っているため読み込み直す） */
function reload(): void {
    outline.value = null;
    frame.value?.contentWindow?.location.reload();
}

watch(() => props.selectedId, measure);
// 枠の幅が変わると位置も変わる。描き直しを待ってから測る
watch(
    () => props.width,
    () => requestAnimationFrame(measure),
);

onBeforeUnmount(() => detach?.());

defineExpose({ reload });
</script>

<template>
    <div
        ref="viewport"
        class="relative h-full overflow-x-auto"
        data-testid="editor-canvas-viewport"
    >
        <iframe
            ref="frame"
            :src="src"
            title="ページのプレビュー"
            class="mx-auto h-full rounded-lg border bg-white"
            :style="
                width === null
                    ? { width: '100%' }
                    : {
                          // 枠線を幅に含めない。含めると**中の viewport が枠線ぶん狭くなり**、
                          // 宣言した画面幅の媒体クエリが成立しない
                          boxSizing: 'content-box',
                          width: `${width}px`,
                          minWidth: `${width}px`,
                      }
            "
            :data-canvas-width="width ?? ''"
            data-testid="editor-canvas"
            @load="onLoad"
        />

        <!-- 選択枠は**親側**に描く。iframe の中には何も足さない -->
        <div
            v-if="outline"
            class="pointer-events-none fixed z-10 rounded-sm ring-2 ring-primary"
            :style="{
                top: `${outline.top}px`,
                left: `${outline.left}px`,
                width: `${outline.width}px`,
                height: `${outline.height}px`,
            }"
            data-testid="editor-canvas-selection"
        />
    </div>
</template>

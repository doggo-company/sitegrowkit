<script setup lang="ts">
/**
 * 本文の見たまま編集（リリース完成 2026-09-09・R02）。
 *
 * ## 何を変えたか
 *
 * `richtext` は「HTML を手で書く textarea + tag を挟むボタン」だった（Phase 9.15 / 28D）。
 * WordPress から来た人はそこで最初に驚く — `<p>` を書かないと段落にならない。
 * ここは **見たまま編集を既定にし、HTML ソース切替を残す**。
 *
 * ## 守ること
 *
 * - **出す tag は `Purifier::ALLOWED` の中だけ**（`richtext-editor.ts` の約束）。
 *   TipTap の StarterKit から code / codeBlock / horizontalRule を外し、見出しは h2〜h4 に限る。
 * - **扱えない HTML を黙って捨てない**。`<table>` などが入った本文は HTML のまま開き、
 *   見たままへ切り替えるには「落として開く」を明示的に押させる。
 * - **リンク先は http / https / mailto / 相対パスだけ**（`normalizeLinkHref`）。
 *   `javascript:` は入らない。貼り付けた HTML は TipTap が schema で読み直すので、
 *   script も on* 属性も本文に残らない（そもそも DOM に流し込まない）。
 * - **サーバーの sanitize は緩めない**。ここは編集の体験で、守りは `ContentValues` の Purifier のまま。
 *
 * ## 値の正本は `modelValue`（HTML 文字列）1 つ
 *
 * 見たまま編集は更新のたびに `getHTML()` を model へ写し、HTML 編集は textarea が直接 model を
 * 書く。**2 つの状態を持たない**ので、切り替えで食い違わない。空の本文は `''` にして
 * 必須の検査が効く形で渡す（`htmlForModel`）。
 */
import {
    Bold,
    Heading2,
    Heading3,
    Heading4,
    Italic,
    Link2,
    List,
    ListOrdered,
    Pilcrow,
    Quote,
    Redo2,
    Strikethrough,
    Underline,
    Undo2,
    Unlink2,
} from '@lucide/vue';
import Image from '@tiptap/extension-image';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import type { Component } from 'vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';

import RichtextImageButton from '@/components/schema/RichtextImageButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    applyRichtextCommand,
    insertRichtextImage,
    RICHTEXT_COMMANDS,
    sameSitePath,
} from '@/lib/richtext';
import type { RichtextCommand } from '@/lib/richtext';
import {
    findUnsupportedTags,
    HEADING_LEVELS,
    htmlForModel,
    initialRichtextMode,
    isAllowedLinkHref,
    normalizeLinkHref,
    VISUAL_RICHTEXT_COMMANDS,
} from '@/lib/richtext-editor';
import type {
    RichtextMode,
    VisualRichtextCommand,
} from '@/lib/richtext-editor';
import type { MediaItem } from '@/types/media';

const props = withDefaults(
    defineProps<{
        /** 入力欄の id。label の `for` と E2E の `#body` がここを指す */
        inputId: string;
        /** 読み上げが「何の欄か」を知るための label の id */
        labelId: string;
        required?: boolean;
        disabled?: boolean;
        /** サーバーから誤りが返っているか（`aria-invalid` に写す） */
        invalid?: boolean;
        /** 文字数の上限（schema の `max`）。HTML 編集では textarea の maxlength に写す */
        maxlength?: number | null;
        /**
         * 本文が空のときだけ出す仮の文字（UX 一周 3 周目 N3）。値ではないので保存されない。
         * 打てば消える（本文が 1 文字でも入れば出さない）
         */
        placeholder?: string | null;
    }>(),
    {
        required: false,
        disabled: false,
        invalid: false,
        maxlength: null,
        placeholder: null,
    },
);

const model = defineModel<string | null>({ default: '' });

const currentHtml = computed(() => model.value ?? '');

/* ── 編集の形（見たまま / HTML） ───────────────────────────── */

const mode = ref<RichtextMode>(initialRichtextMode(currentHtml.value));

/** 見たままで扱えない tag。空でなければ帯を出し、見たままへは明示の操作でしか入れない */
const unsupported = ref<string[]>(findUnsupportedTags(currentHtml.value));

/* ── TipTap ────────────────────────────────────────────── */

/**
 * ProseMirror の要素に付ける属性。**contenteditable は label の `for` で結べない**ので、
 * `aria-labelledby` で欄の名前を、`role="textbox"` で欄であることを伝える。
 */
function editorAttributes(): Record<string, string> {
    return {
        id: props.inputId,
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-labelledby': props.labelId,
        'aria-required': String(props.required),
        'aria-invalid': String(props.invalid),
        class: 'richtext-editor__content',
        'data-testid': 'richtext-visual',
        // 見えている仮の文字（下の <p>）は読み上げから外し、ここで欄の説明として渡す
        ...(props.placeholder ? { 'aria-placeholder': props.placeholder } : {}),
    };
}

/** 貼り付けで文字だけになった要素（table など）。空でなければ帯で知らせる */
const pasted = ref<string[]>([]);

/**
 * `editorProps` は属性と貼り付けの見張りをまとめて渡す（`setOptions` でも同じ形で入れ直す）。
 *
 * 貼り付けは TipTap が schema で読み直すので、script も `on*` 属性も本文に入らない。
 * ただし `<table>` のような**公開で表示されない要素は文字だけになる**。
 * 黙って落とさず、何が落ちたかを帯で知らせる（`false` を返して貼り付け自体は通す）。
 */
function editorProps() {
    return {
        attributes: editorAttributes(),
        handlePaste: (_view: unknown, event: ClipboardEvent): boolean => {
            const html = event.clipboardData?.getData('text/html') ?? '';
            const found = findUnsupportedTags(html);

            if (found.length > 0) {
                pasted.value = found;
            }

            return false;
        },
    };
}

const editor = useEditor({
    content: currentHtml.value,
    editable: !props.disabled,
    /*
     * 🔴 **実行時に `<style>` を差し込ませない**（実ブラウザで実測 2026-09-09）。
     *
     * TipTap の既定は起動時に `<style>` を head へ足すが、管理画面の CSP は
     * `style-src 'self' 'nonce-…'` なので**ブラウザに弾かれる**（`AdminCspMiddleware`）。
     * 弾かれると `white-space: pre-wrap` が効かず、**空白と改行が潰れる**。
     * nonce を JS から拾う手もあるが、拾えなかった日に黙って壊れる。
     * ここでは差し込みを止め、同じ規則を下の `<style>`（Vite が CSS ファイルにする）へ写す。
     * **CSP を緩めない**ための選び方である。
     */
    injectCSS: false,
    extensions: [
        StarterKit.configure({
            /*
             * `Purifier::ALLOWED` に無いものは**ボタンにも近道にも出さない**。
             * 出せば編集画面では見えて公開では消える。
             */
            code: false,
            codeBlock: false,
            horizontalRule: false,
            // 末尾に空の `<p>` を足す拡張。HTML 側に `<p></p>` が残るので使わない
            trailingNode: false,
            heading: { levels: [...HEADING_LEVELS] },
            link: {
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                // 通す URL の判断は `richtext-editor.ts` の 1 か所（既定の ftp / tel を通さない）
                isAllowedUri: (url) => isAllowedLinkHref(url),
                /*
                 * `target` は公開側の Purifier が落とす（`Attr.AllowedFrameTargets` = []）。
                 * 編集画面だけ別タブで開く形を見せると、画面が嘘をつく。出さない。
                 */
                HTMLAttributes: { target: null, rel: 'noopener noreferrer' },
            },
        }),
        Image.configure({
            inline: false,
            // `data:` の画像は Purifier が落とす。編集画面でも受けない
            allowBase64: false,
        }),
    ],
    editorProps: editorProps(),
    onUpdate: ({ editor: instance }) => {
        model.value = htmlForModel(instance.getHTML(), instance.isEmpty);
    },
});

/**
 * 外から model が変わったとき（自動保存の復帰・HTML 編集からの戻り）だけ、
 * 本文を入れ直す。**自分が写した値なら触らない** — 触るとカーソルが先頭へ飛ぶ。
 */
watch(currentHtml, (value) => {
    const instance = editor.value;

    if (instance === undefined || mode.value !== 'visual') {
        return;
    }

    if (htmlForModel(instance.getHTML(), instance.isEmpty) === value) {
        return;
    }

    instance.commands.setContent(value, { emitUpdate: false });
});

watch(
    () => props.disabled,
    (disabled) => editor.value?.setEditable(!disabled),
);

watch(
    () => [props.invalid, props.required],
    () => editor.value?.setOptions({ editorProps: editorProps() }),
);

/* ── 切り替え ──────────────────────────────────────────── */

const htmlTextarea = useTemplateRef<HTMLTextAreaElement>('htmlTextarea');

function switchToHtml(): void {
    mode.value = 'html';
    closeLinkForm();
}

/**
 * 見たままへ戻す。
 *
 * 扱えない tag が在れば**止まって帯を出す**。`force` は帯の中のボタンからだけ来る —
 * 何が消えるかを見せたうえで、押した人が決める。
 */
async function switchToVisual(force = false): Promise<void> {
    const found = findUnsupportedTags(currentHtml.value);
    unsupported.value = found;

    if (found.length > 0 && !force) {
        return;
    }

    const instance = editor.value;

    /*
     * HTML 側で何も変えていなければ入れ直さない。`setContent` は undo の履歴に 1 段積まれるので、
     * 切り替えるたびに入れ直すと **「元に戻す」が切り替えを戻すだけ**になる（実ブラウザで実測）。
     */
    if (
        instance !== undefined &&
        htmlForModel(instance.getHTML(), instance.isEmpty) !== currentHtml.value
    ) {
        instance.commands.setContent(currentHtml.value, { emitUpdate: false });
    }

    mode.value = 'visual';
    await nextTick();

    // 落として開いたなら、開いた時点の HTML を model へ写す（消えたものを黙って抱えない）
    if (force && instance !== undefined) {
        model.value = htmlForModel(instance.getHTML(), instance.isEmpty);
        unsupported.value = [];
    }

    editor.value?.commands.focus();
}

/* ── 見たままの書式ボタン ──────────────────────────────── */

const icons: Record<VisualRichtextCommand['action'], Component | null> = {
    paragraph: Pilcrow,
    heading2: Heading2,
    heading3: Heading3,
    heading4: Heading4,
    bold: Bold,
    italic: Italic,
    underline: Underline,
    strike: Strikethrough,
    blockquote: Quote,
    bulletList: List,
    orderedList: ListOrdered,
    link: Link2,
    image: null,
    undo: Undo2,
    redo: Redo2,
};

function isActive(command: VisualRichtextCommand): boolean {
    const instance = editor.value;

    if (instance === undefined) {
        return false;
    }

    switch (command.action) {
        case 'paragraph':
            return instance.isActive('paragraph');
        case 'heading2':
            return instance.isActive('heading', { level: 2 });
        case 'heading3':
            return instance.isActive('heading', { level: 3 });
        case 'heading4':
            return instance.isActive('heading', { level: 4 });
        case 'link':
            return instance.isActive('link') || linkOpen.value;
        case 'undo':
        case 'redo':
        case 'image':
            return false;
        default:
            return instance.isActive(command.action);
    }
}

function isDisabled(command: VisualRichtextCommand): boolean {
    const instance = editor.value;

    if (props.disabled || instance === undefined) {
        return true;
    }

    if (command.action === 'undo') {
        return !instance.can().undo();
    }

    if (command.action === 'redo') {
        return !instance.can().redo();
    }

    return false;
}

function run(command: VisualRichtextCommand): void {
    const instance = editor.value;

    if (instance === undefined) {
        return;
    }

    /*
     * リンクと画像は本文へ焦点を戻さない。**`chain().focus()` は `run()` しなくても
     * 次フレームで `view.focus()` を予約する**（実ブラウザで実測 2026-09-09）。
     * 先に chain を作っておくと、開いたばかりのリンク欄から焦点が奪われ、
     * URL を打ったつもりの文字が本文へ落ちる。
     */
    if (command.action === 'link') {
        void toggleLinkForm();

        return;
    }

    if (command.action === 'image') {
        // 画像は `RichtextImageButton` が選んで `insertVisualImage` へ渡す
        return;
    }

    const chain = instance.chain().focus();

    switch (command.action) {
        case 'paragraph':
            chain.setParagraph().run();
            break;
        case 'heading2':
            chain.toggleHeading({ level: 2 }).run();
            break;
        case 'heading3':
            chain.toggleHeading({ level: 3 }).run();
            break;
        case 'heading4':
            chain.toggleHeading({ level: 4 }).run();
            break;
        case 'bold':
            chain.toggleBold().run();
            break;
        case 'italic':
            chain.toggleItalic().run();
            break;
        case 'underline':
            chain.toggleUnderline().run();
            break;
        case 'strike':
            chain.toggleStrike().run();
            break;
        case 'blockquote':
            chain.toggleBlockquote().run();
            break;
        case 'bulletList':
            chain.toggleBulletList().run();
            break;
        case 'orderedList':
            chain.toggleOrderedList().run();
            break;
        case 'undo':
            chain.undo().run();
            break;
        case 'redo':
            chain.redo().run();
            break;
    }
}

function buttonTitle(command: VisualRichtextCommand): string {
    return command.shortcut === undefined
        ? command.label
        : `${command.label}（${command.shortcut}）`;
}

/* ── リンク ──────────────────────────────────────────── */

const linkOpen = ref(false);
const linkHref = ref('');
const linkError = ref('');
const linkInput = useTemplateRef<InstanceType<typeof Input>>('linkInput');

/** いま選んでいる範囲がリンクなら、その URL を欄へ入れて開く */
async function toggleLinkForm(): Promise<void> {
    if (linkOpen.value) {
        closeLinkForm();

        return;
    }

    const current = editor.value?.getAttributes('link').href;
    linkHref.value = typeof current === 'string' ? current : '';
    linkError.value = '';
    linkOpen.value = true;

    await nextTick();
    (linkInput.value?.$el as HTMLInputElement | undefined)?.focus();
}

function closeLinkForm(): void {
    linkOpen.value = false;
    linkError.value = '';
}

function applyLink(): void {
    const instance = editor.value;

    if (instance === undefined) {
        return;
    }

    const href = normalizeLinkHref(linkHref.value);

    if (href === null) {
        // 何が駄目かを言う。「無効です」だけでは直せない
        linkError.value =
            'http://・https:// の URL、メールアドレス（mailto:）、またはサイト内の相対パス（/about・../about など）を入れてください。';

        return;
    }

    const { from, to } = instance.state.selection;
    const hasSelection = from !== to;

    if (hasSelection || instance.isActive('link')) {
        instance
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href })
            .run();
    } else {
        // 何も選んでいなければ、URL そのものをリンクの文字として置く（押したのに何も起きない、を避ける）
        instance
            .chain()
            .focus()
            .insertContent({
                type: 'text',
                text: href,
                marks: [{ type: 'link', attrs: { href } }],
            })
            .run();
    }

    closeLinkForm();
}

function removeLink(): void {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    closeLinkForm();
}

/** Ctrl+K / ⌘K でリンク欄を開く（TipTap の既定には無い） */
function onEditorKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        void toggleLinkForm();
    }
}

/* ── 画像（見たまま） ─────────────────────────────────── */

/**
 * 出す属性は `img[src|alt|width|height]` だけ（Purifier の allowlist）。
 * URL は `richtext.ts` と同じ判断で、同じサイトならパスへ落とす。
 */
function insertVisualImage(image: MediaItem): void {
    const instance = editor.value;

    if (instance === undefined || image.url === null || image.url === '') {
        return;
    }

    const attributes: {
        src: string;
        alt: string;
        width?: number;
        height?: number;
    } = {
        src: sameSitePath(image.url, window.location.origin),
        // 説明が無くても `alt` は出す（読み上げがファイル名を読まないように）
        alt: image.alt ?? '',
    };

    if (image.width !== null && image.height !== null) {
        attributes.width = image.width;
        attributes.height = image.height;
    }

    instance.chain().focus().setImage(attributes).run();
}

/* ── HTML 編集（Phase 9.15 / 28D の形をそのまま残す） ────── */

async function applyHtmlFormat(command: RichtextCommand): Promise<void> {
    const element = htmlTextarea.value;

    if (element === null) {
        return;
    }

    const result = applyRichtextCommand(
        element.value,
        { start: element.selectionStart, end: element.selectionEnd },
        command,
    );

    model.value = result.value;

    await nextTick();
    element.setSelectionRange(result.selection.start, result.selection.end);
    element.focus();
}

async function insertHtmlImage(image: MediaItem): Promise<void> {
    const element = htmlTextarea.value;

    if (element === null) {
        return;
    }

    const result = insertRichtextImage(
        element.value,
        { start: element.selectionStart, end: element.selectionEnd },
        image,
        window.location.origin,
    );

    model.value = result.value;

    await nextTick();
    element.setSelectionRange(result.selection.start, result.selection.end);
    element.focus();
}

/* ── 文字数（上限があるときだけ見せる） ─────────────────── */

/**
 * **サーバーが数えるのと同じものを数える。**
 *
 * `max` は保存する文字列（＝ HTML）に対して効く（`SchemaRules::rulesFor()` の `max:N`）。
 * 見た目の文字数を出すと、上限に届いていないように見えて保存で弾かれる。
 */
const savedLength = computed(() => currentHtml.value.length);
</script>

<template>
    <div
        class="richtext-editor flex flex-col gap-2"
        data-testid="richtext-editor"
    >
        <!-- 編集の形。押されている方に aria-pressed（読み上げに「いまどちらか」が伝わる） -->
        <div
            class="flex flex-wrap items-center gap-1"
            role="group"
            aria-label="編集の形"
        >
            <Button
                type="button"
                :variant="mode === 'visual' ? 'secondary' : 'ghost'"
                size="sm"
                class="h-11"
                :aria-pressed="mode === 'visual'"
                data-testid="richtext-mode-visual"
                @click="switchToVisual()"
            >
                見たまま
            </Button>
            <Button
                type="button"
                :variant="mode === 'html' ? 'secondary' : 'ghost'"
                size="sm"
                class="h-11"
                :aria-pressed="mode === 'html'"
                data-testid="richtext-mode-html"
                @click="switchToHtml"
            >
                HTML
            </Button>
        </div>

        <!-- 扱えない要素がある。黙って落とさず、何が消えるかを言ってから選ばせる -->
        <div
            v-if="unsupported.length > 0"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
            role="status"
            data-testid="richtext-unsupported"
        >
            <!-- 見たまま編集の対応範囲はサーバーの allowlist（Purifier::ALLOWED）と同じ。
                 ここに無い要素は**保存時にサーバーが取り除く**ので、「HTML なら残る」とは言わない -->
            <p>
                この本文には、公開サイトでは表示されない要素が入っています（<code
                    >{{ unsupported.join(', ') }}</code
                >）。保存すると取り除かれ、中の文字だけが残ります。HTML
                のまま開いているのは、取り除かれる前に中身を確かめられるようにするためです。
            </p>
            <p v-if="mode === 'html'" class="mt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-11"
                    :disabled="disabled"
                    data-testid="richtext-force-visual"
                    @click="switchToVisual(true)"
                >
                    これらの要素を取り除いて、見たままで開く
                </Button>
            </p>
        </div>

        <!-- 貼り付けで文字だけになったものを知らせる（公開でも同じ形になる） -->
        <div
            v-if="pasted.length > 0"
            class="flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
            role="status"
            data-testid="richtext-paste-notice"
        >
            <p class="flex-1">
                貼り付けた内容のうち <code>{{ pasted.join(', ') }}</code>
                は公開サイトでは表示されない要素なので、中の文字だけを残しました。
            </p>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-11"
                @click="pasted = []"
            >
                閉じる
            </Button>
        </div>

        <template v-if="mode === 'visual'">
            <div
                class="flex flex-wrap gap-1"
                role="toolbar"
                aria-label="書式"
                data-testid="richtext-toolbar"
            >
                <template
                    v-for="command in VISUAL_RICHTEXT_COMMANDS"
                    :key="command.action"
                >
                    <RichtextImageButton
                        v-if="command.action === 'image'"
                        :label="command.label"
                        @select="insertVisualImage"
                    />
                    <Button
                        v-else
                        type="button"
                        :variant="isActive(command) ? 'secondary' : 'outline'"
                        size="sm"
                        class="h-11"
                        :title="buttonTitle(command)"
                        :aria-label="command.label"
                        :aria-pressed="
                            command.action === 'undo' ||
                            command.action === 'redo'
                                ? undefined
                                : isActive(command)
                        "
                        :disabled="isDisabled(command)"
                        :data-testid="`richtext-${command.action}`"
                        @click="run(command)"
                    >
                        <component
                            :is="icons[command.action]"
                            v-if="icons[command.action] !== null"
                            aria-hidden="true"
                        />
                        <span
                            :class="
                                command.action === 'undo' ||
                                command.action === 'redo'
                                    ? 'sr-only'
                                    : 'hidden sm:inline'
                            "
                            >{{ command.label }}</span
                        >
                    </Button>
                </template>
            </div>

            <!-- リンク先の欄。dialog にしないのは、スマホでも入力欄が本文の真上に残るように -->
            <form
                v-if="linkOpen"
                class="flex flex-wrap items-end gap-2 rounded-md border p-2"
                data-testid="richtext-link-form"
                @submit.prevent="applyLink"
            >
                <div class="flex min-w-48 flex-1 flex-col gap-1">
                    <Label :for="`${inputId}-link-href`">リンク先</Label>
                    <Input
                        :id="`${inputId}-link-href`"
                        ref="linkInput"
                        v-model="linkHref"
                        type="text"
                        inputmode="url"
                        placeholder="https://example.com または /about"
                        :aria-invalid="linkError !== ''"
                        :aria-describedby="
                            linkError !== ''
                                ? `${inputId}-link-href-error`
                                : undefined
                        "
                        @keydown.esc.prevent="closeLinkForm"
                    />
                </div>
                <Button type="submit" size="sm" class="h-11">
                    リンクを付ける
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-11"
                    :disabled="!editor?.isActive('link')"
                    data-testid="richtext-unlink"
                    @click="removeLink"
                >
                    <Unlink2 aria-hidden="true" />
                    リンクを外す
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-11"
                    @click="closeLinkForm"
                >
                    閉じる
                </Button>
                <p
                    v-if="linkError !== ''"
                    :id="`${inputId}-link-href-error`"
                    class="w-full text-sm text-destructive"
                    data-testid="richtext-link-error"
                >
                    {{ linkError }}
                </p>
            </form>

            <div
                class="relative rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
                :class="{
                    'border-destructive': invalid,
                    'opacity-60': disabled,
                }"
                @keydown="onEditorKeydown"
            >
                <EditorContent :editor="editor" />
                <p
                    v-if="placeholder && currentHtml.trim() === ''"
                    class="pointer-events-none absolute top-0 left-0 p-3 text-[0.95rem] leading-[1.7] text-muted-foreground"
                    aria-hidden="true"
                    data-testid="richtext-placeholder"
                >
                    <!-- props. を付けて書く（波かっこの直後に placeholder と書くと、配布物の検査が置き換え忘れとして止める） -->
                    {{ props.placeholder }}
                </p>
            </div>

            <!-- 上限はタグを含む保存後の文字数に効くので、そう書く -->
            <p
                v-if="maxlength !== null"
                class="text-xs text-muted-foreground"
                :class="{ 'text-destructive': savedLength > maxlength }"
            >
                {{ savedLength }} / {{ maxlength }} 文字（書式のタグを含みます）
            </p>
        </template>

        <template v-else>
            <!-- HTML 編集の書式ボタン（28D）。挟む tag は `richtext.ts` の allowlist から -->
            <div
                class="flex flex-wrap gap-1"
                role="toolbar"
                aria-label="HTML の書式"
                data-testid="richtext-html-toolbar"
            >
                <template
                    v-for="command in RICHTEXT_COMMANDS"
                    :key="command.tag"
                >
                    <RichtextImageButton
                        v-if="command.kind === 'media'"
                        :label="command.label"
                        @select="insertHtmlImage"
                    />
                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-11"
                        :disabled="disabled"
                        @click="applyHtmlFormat(command)"
                    >
                        {{ command.label }}
                    </Button>
                </template>
            </div>

            <textarea
                :id="inputId"
                ref="htmlTextarea"
                :rows="12"
                :maxlength="maxlength ?? undefined"
                :aria-required="required"
                :disabled="disabled"
                :aria-invalid="invalid"
                :aria-labelledby="labelId"
                class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive"
                data-testid="richtext-html"
                :placeholder="placeholder ?? undefined"
                :value="currentHtml"
                @input="model = ($event.target as HTMLTextAreaElement).value"
            />
        </template>
    </div>
</template>

<style>
/*
 * ProseMirror が動くために要る規則（`injectCSS: false` にした分をここへ写した）。
 * **実行時の `<style>` 差し込みではなく、ビルドされた CSS として配る** —
 * 管理画面の CSP（`style-src 'self'`）を緩めずに済む。
 * 出どころ: `@tiptap/core` の `style`（v3.31.3）。増やすときは向こうと突き合わせる。
 */
.ProseMirror {
    position: relative;
    word-wrap: break-word;
    white-space: pre-wrap;
    white-space: break-spaces;
    font-variant-ligatures: none;
    font-feature-settings: 'liga' 0;
}

.ProseMirror [contenteditable='false'] {
    white-space: normal;
}

.ProseMirror [contenteditable='false'] [contenteditable='true'] {
    white-space: pre-wrap;
}

img.ProseMirror-separator {
    display: inline !important;
    border: none !important;
    margin: 0 !important;
    width: 0 !important;
    height: 0 !important;
}

.ProseMirror-gapcursor {
    display: none;
    pointer-events: none;
    position: absolute;
    margin: 0;
}

.ProseMirror-gapcursor::after {
    content: '';
    display: block;
    position: absolute;
    top: -2px;
    width: 20px;
    border-top: 1px solid black;
    animation: ProseMirror-cursor-blink 1.1s steps(2, start) infinite;
}

.ProseMirror-hideselection *::selection {
    background: transparent;
}

.ProseMirror-hideselection *::-moz-selection {
    background: transparent;
}

.ProseMirror-hideselection * {
    caret-color: transparent;
}

.ProseMirror-focused .ProseMirror-gapcursor {
    display: block;
}

@keyframes ProseMirror-cursor-blink {
    to {
        visibility: hidden;
    }
}

/*
 * 見たまま編集の中身の見た目。**管理画面の中だけ**（`.richtext-editor` の下）。
 * 公開側の見た目は各テーマの CSS が決める — ここは「段落と見出しの区別が付く」までで止める。
 */
.richtext-editor__content {
    min-height: 16rem;
    padding: 0.75rem;
    font-size: 0.95rem;
    line-height: 1.7;
    outline: none;
    overflow-wrap: anywhere;
}

.richtext-editor__content > * + * {
    margin-top: 0.75em;
}

.richtext-editor__content h2 {
    font-size: 1.4em;
    font-weight: 700;
    line-height: 1.3;
}

.richtext-editor__content h3 {
    font-size: 1.2em;
    font-weight: 700;
    line-height: 1.3;
}

.richtext-editor__content h4 {
    font-size: 1.05em;
    font-weight: 700;
}

.richtext-editor__content ul,
.richtext-editor__content ol {
    padding-left: 1.5em;
}

.richtext-editor__content ul {
    list-style: disc;
}

.richtext-editor__content ol {
    list-style: decimal;
}

.richtext-editor__content li + li {
    margin-top: 0.25em;
}

.richtext-editor__content blockquote {
    border-left: 3px solid var(--border, #ccc);
    padding-left: 0.75em;
    color: var(--muted-foreground, #666);
}

.richtext-editor__content a {
    color: var(--primary, #2558c8);
    text-decoration: underline;
}

.richtext-editor__content img {
    display: block;
    max-width: 100%;
    height: auto;
}

/* 選ばれた画像（ProseMirror が付ける印）を見えるようにする */
.richtext-editor__content img.ProseMirror-selectednode {
    outline: 3px solid var(--ring, #2558c8);
}

.richtext-editor__content s {
    text-decoration: line-through;
}

.richtext-editor__content u {
    text-decoration: underline;
}
</style>

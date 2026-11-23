<script setup lang="ts">
/**
 * Inspector（Phase 9.6 / P0-C3・`PHASE9_6_SPEC.md` §4.3）。
 *
 * 入力欄は **Registry の宣言から組み立てる**。宣言に無いものは出さない。
 * 「とりあえず入力欄を出しておく」をやると、`propsSchema` / `styleSchema` の
 * allowlist が形だけになる（同 §3 の I-2 / I-3）。
 *
 * 4 つに分ける（同 §4.3）:
 *
 * | 区分 | 中身 | この Stage での扱い |
 * | --- | --- | --- |
 * | 内容 | `propsSchema` | 編集できる |
 * | 見た目 | `styleSchema` | 編集できる |
 * | 表示条件 | `responsive` | **読むだけ**（breakpoint の編集は P0-C4） |
 * | データ接続 | `binding` | **読むだけ**（接続先の選択は P0-C5） |
 *
 * 送るのは差分だけ。**値を外すときは `null`** を送る（サーバー側の削除の意味 —
 * `PatchPageDocument::updatePatch()`）。
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MediaPicker from '@/components/schema/MediaPicker.vue';
import RichtextEditor from '@/components/schema/RichtextEditor.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    bindingProblem,
    completeBinding,
    fieldChoices,
    sourceChoices,
} from '@/lib/binding';
import { createFieldAutosave } from '@/lib/field-autosave';
import { ALT_NOTICE, altAfterPick } from '@/lib/media-alt';
import { propPlaceholder } from '@/lib/prop-placeholder';
import { resolveLinkInput } from '@/lib/link-input';
import { fieldForHint, sampleCellAt } from '@/lib/sample-text';
import { instanceLabel, instanceOf, isInstance } from '@/lib/reusable';
import type { ReusableSummary } from '@/lib/reusable';
import type { BindingRecord } from '@/lib/binding';
import type {
    BindingSources,
    ComponentPropSpec,
    ComponentSpec,
    DocumentNode,
    StylePropertySpec,
} from '@/types/document';

const props = defineProps<{
    node: DocumentNode;
    spec: ComponentSpec;
    /** style キー → 表示名と選べる値（サーバーの StyleProperties 由来） */
    styles: Record<string, StylePropertySpec>;
    /** `type: token` の prop が選べるトークン名（group ごと） */
    tokens: Record<string, string[]>;
    /** いま編集している画面幅。null は「すべての画面幅」（= base） */
    breakpoint: string | null;
    /** binding の接続先。**型整合の表は入っていない**（`spec.accepts` が解いた結果を持つ） */
    sources: BindingSources;
    /** 使える再利用 component（名前を出すためだけ。中身は持たない） */
    reusables: ReusableSummary[];
    /** 親から先祖までのどこかが、この画面幅で非表示になっているか */
    hiddenByAncestor: boolean;
    blocked: boolean;
    /** トークンの表示名（`gapSmall` → 狭い） */
    tokenLabels?: Record<string, string>;
    /** リンク先の候補（サイト内のページ）。URL を手で打たなくても選べるように */
    linkTargets?: { href: string; label: string }[];
    /** 再利用ブロックの編集画面を開けるか（制作者だけ）。渡されなければ開ける */
    canEditReusable?: boolean;
}>();

const emit = defineEmits<{
    /**
     * 変更。2 つ目は**どのブロックへの変更か**（UX 一周 3 周目 M5）。
     * 入力中の自動保存は時間差で送るので、そのあいだに選択が替わっても、
     * 打っていたブロックへ届くようにする。
     */
    (e: 'change', changes: Record<string, unknown>, target: string): void;
    /** 実体を編集する画面へ行く */
    (e: 'edit-reusable', id: string): void;
    /** 切り離す（**戻せない**ので、確認はサーバーの確認ゲートが出す） */
    (e: 'detach'): void;
    /**
     * 入力が始まった（まだ送っていない）。保存状態を「入力中」にする。
     *
     * 送るのは `lib/field-autosave` の判断で、**打つ手が止まったら（0.8 秒）・打ち続けても 3 秒ごと・
     * 欄を離れたらすぐ**（UX 一周 3 周目 M5）。以前は欄を離れたときだけで、
     * 打っても待ってもプレビューが変わらず、3 回続けて「保存されない」と言われた。
     * 1 文字ごとには送らない（版を打鍵ごとに積まない）。
     */
    (e: 'dirty'): void;
    (e: 'draft', changes: Record<string, unknown>, target: string): void;
}>();

/** binding で供給される prop は、ここから編集しても描画に出ない（binding が勝つ） */
function boundProp(key: string): boolean {
    const binding = props.node.binding;

    if (!binding || typeof binding !== 'object') {
        return false;
    }

    /*
     * **`prop` の無い binding は「供給されている」と見なさない**（§4.9 / P0-C5）。
     * 先頭 prop へ落とす読みは、描く側でも a11y の側でも外した。
     * ここだけ残すと、**編集できるはずの欄が「binding が勝つ」と言って触れなくなる**。
     */
    return (binding as { prop?: string }).prop === key;
}

/** その key を、いま編集している画面幅で変えられるか */
function responsiveHere(key: string): boolean {
    return props.breakpoint !== null && props.spec.responsive.includes(key);
}

/** 画面幅を選んでいるとき、その画面幅に**明示的な指定があるか**（= 継承ではないか） */
function overridden(key: string): boolean {
    return (
        props.breakpoint !== null &&
        props.node.responsive?.[props.breakpoint]?.[key] !== undefined
    );
}

function propValue(key: string): unknown {
    if (overridden(key)) {
        return props.node.responsive?.[props.breakpoint ?? '']?.[key];
    }

    return (
        props.node.props?.[key] ?? props.spec.propsSchema[key]?.default ?? null
    );
}

function styleValue(key: string): string {
    if (overridden(key)) {
        return props.node.responsive?.[props.breakpoint ?? '']?.[key] ?? '';
    }

    return props.node.style?.[key] ?? '';
}

/** 変更をどこへ書くか。画面幅を選んでいて、その key が対応していれば入れ子側へ */
function change(section: 'props' | 'style', key: string, value: unknown): void {
    if (responsiveHere(key)) {
        emit(
            'change',
            {
                responsive: { [props.breakpoint as string]: { [key]: value } },
            },
            props.node.id,
        );

        return;
    }

    emit('change', { [section]: { [key]: value } }, props.node.id);
}

/** 空にしたら **外す**（null を送る）。空文字を保存しても消えたことにはならない */
function setProp(key: string, value: unknown): void {
    change('props', key, value === '' ? null : value);
}

/** 選択肢の表示名（UX 一周 2026-09-28: `left` `gapSmall` `primary` がそのまま出ていた） */
function choiceLabel(
    labels: Record<string, string> | undefined,
    value: unknown,
): string {
    return labels?.[String(value)] ?? String(value);
}

/*
 * 本文（HTML）は見たまま編集する（UX 一周 2026-09-28）。
 *
 * 以前は HTML の生の textarea で、改行しても公開面では 1 行につながった。
 * **送るのは欄を離れたときだけ**（文字を打つたびに版を作らない — `dirty` の説明と同じ境界）。
 */
const htmlDraft = ref<Record<string, string>>({});

/**
 * 文字の欄の打ちかけ（UX 一周 第 17 周）。
 *
 * 欄 A を離れると A の保存が走り、応答で document が入れ替わる。そのとき欄 B に
 * 打ちかけの文字があると、再描画が B の値をサーバーの値（打つ前）へ戻してしまい、
 * **Tab で次の欄へ移ってすぐ打った文字が消えた**。打ちかけはここに置き、
 * 欄を離れて送るまでは、document が入れ替わっても欄の値はこちらを使う。
 */
const textDraft = ref<Record<string, string>>({});

/**
 * 入力中の自動保存（UX 一周 3 周目 M5）。送る中身は欄を離れたときと同じ（`setPropFromText` / `setProp`）。
 *
 * **URL の欄は自動では送らない** — 打ちかけの URL（`https://ex`）は検証で落ち、
 * 打っている最中に「保存できませんでした」が出る。URL は今までどおり欄を離れたときに送る。
 */
const autosave = createFieldAutosave({
    send: (key, value) => {
        const spec = props.spec.propsSchema[key];

        if (!spec) {
            return;
        }

        if (isHtml(spec)) {
            if (value !== String(propValue(key) ?? '')) {
                setProp(key, value);
            }

            return;
        }

        setPropFromText(key, spec, value);
    },
});

/** Keep the typed text before the autosave debounce can fail or be interrupted. */
function rememberText(key: string, value: string): void {
    const spec = props.spec.propsSchema[key];

    if (!spec || !['string', 'text', 'url'].includes(spec.type)) {
        return;
    }

    const changes = responsiveHere(key)
        ? {
              responsive: {
                  [props.breakpoint as string]: {
                      [key]: value === '' ? null : value,
                  },
              },
          }
        : { props: { [key]: value === '' ? null : value } };
    emit('draft', changes, props.node.id);
}

function autosaves(key: string): boolean {
    const spec = props.spec.propsSchema[key];

    return spec !== undefined && spec.type !== 'url';
}

watch(
    () => props.node.id,
    () => {
        // 打ちかけは欄を離れたとき（blur）に送り済み。残っていても前のブロックへは送らない
        autosave.cancel();
        htmlDraft.value = {};
        textDraft.value = {};
        fieldErrors.value = {};
    },
);

onBeforeUnmount(() => autosave.flush());

function draftText(key: string, value: string): void {
    textDraft.value = { ...textDraft.value, [key]: value };
    emit('dirty');
    rememberText(key, value);

    if (autosaves(key)) {
        autosave.input(key, value);
    }
}

/** 欄のすぐ下に出す、送れなかった理由（リンク先。UX 一周 5 周目 R5-1） */
const fieldErrors = ref<Record<string, string>>({});

function commitText(key: string, raw: string): void {
    if (props.spec.propsSchema[key]?.type === 'url') {
        // ページの名前ならその URL に。使えない形なら送らず、打った文字を残して欄の下で言う
        const link = resolveLinkInput(raw, props.linkTargets ?? []);
        const others = Object.fromEntries(
            Object.entries(fieldErrors.value).filter(([k]) => k !== key),
        );

        if (!link.ok) {
            fieldErrors.value = { ...others, [key]: link.message };
            textDraft.value = { ...textDraft.value, [key]: raw };
            autosave.cancel();

            return;
        }

        fieldErrors.value = others;
        raw = link.value;
        textDraft.value = { ...textDraft.value, [key]: raw };
        rememberText(key, raw);
    }

    // Keep the visible draft until the server acknowledges it. A conflict must not
    // replace the typed text when blur happens before the failed response.

    // もう自動で送った値なら送り直さない（版を無駄に積まない）
    if (autosave.lastSent(key) === raw) {
        autosave.cancel();

        return;
    }

    autosave.commit(key, raw);
}

/**
 * 外から値が変わったら（取り消し・やり直し・別の画面での変更）、「最後に送った値」を忘れる。
 * 覚えたままだと、同じ文字を打ち直したときに「もう送った」と判断して送らない。
 */
watch(
    () => [props.node.props, props.node.responsive],
    () => {
        const pendingText = { ...textDraft.value };
        const pendingHtml = { ...htmlDraft.value };

        for (const [key, value] of Object.entries(pendingText)) {
            if (value === String(propValue(key) ?? '')) {
                delete pendingText[key];
            }
        }

        for (const [key, value] of Object.entries(pendingHtml)) {
            if (value === String(propValue(key) ?? '')) {
                delete pendingHtml[key];
            }
        }

        textDraft.value = pendingText;
        htmlDraft.value = pendingHtml;

        for (const key of Object.keys(props.spec.propsSchema)) {
            const sent = autosave.lastSent(key);

            if (sent !== undefined && sent !== String(propValue(key) ?? '')) {
                autosave.forget(key);
            }
        }
    },
);

/** 欄に出す文字。打ちかけがあればそちら、無ければ document の値 */
function textValue(key: string): string {
    return textDraft.value[key] ?? String(propValue(key) ?? '');
}

/**
 * 新しいブロックが持って置かれる初期文言（UX 一周 第 21 周）。
 *
 * 宣言に `default` があればそれ（営業時間の「〇曜日〜〇曜日｜…」）、無ければ必須の文字は
 * ブロックの名前（見出し・テキスト・リッチテキスト — サーバーの `defaultsFor()` と同じ規則）。
 */
function placeholderOf(key: string, spec: ComponentPropSpec): string | null {
    if (typeof spec.default === 'string' && spec.default !== '') {
        return spec.default;
    }

    return props.spec.propsSchema[key]?.required ? props.spec.label : null;
}

/**
 * 欄に入ったとき、中身が初期文言のままなら**選んだ状態**にする。
 *
 * Tab で入ると caret は文末に置かれ、「〇〇（デザート）｜〇〇円タンブラー（小）｜3,300円」のように
 * 初期文言の後ろへ続いて打たれた。選んでおけば打ち始めで置き換わる。
 * **click でも呼ぶ**: クリックは focus の後に caret を置き直すので、focus だけでは選択が解ける。
 */
function selectPlaceholder(
    event: FocusEvent | MouseEvent,
    key: string,
    spec: ComponentPropSpec,
): void {
    const el = event.target as HTMLInputElement | HTMLTextAreaElement | null;

    if (!el || el.value !== placeholderOf(key, spec)) {
        return;
    }

    el.select();
}

/** リッチテキスト（contenteditable）の同じ守り。文だけを比べる（タグは見ない） */
function selectHtmlPlaceholder(
    event: FocusEvent | MouseEvent,
    key: string,
    spec: ComponentPropSpec,
): void {
    const box = event.currentTarget as HTMLElement | null;
    const el = box?.querySelector<HTMLElement>('[contenteditable="true"]');

    if (!el || el.textContent?.trim() !== placeholderOf(key, spec)) {
        return;
    }

    const selection = window.getSelection();

    if (selection) {
        const range = window.document.createRange();
        range.selectNodeContents(el);
        selection.removeAllRanges();
        selection.addRange(range);
    }
}

function isHtml(spec: ComponentPropSpec): boolean {
    return spec.format === 'html';
}

function draftHtml(key: string, value: string | null): void {
    htmlDraft.value = { ...htmlDraft.value, [key]: value ?? '' };
    emit('dirty');
    rememberText(key, value ?? '');
    autosave.input(key, value ?? '');
}

function commitHtml(key: string, event: FocusEvent): void {
    const box = event.currentTarget as HTMLElement | null;

    // 書式ボタンなど、同じ欄の中を移っただけなら送らない
    if (
        box &&
        event.relatedTarget instanceof Node &&
        box.contains(event.relatedTarget)
    ) {
        return;
    }

    const draft = htmlDraft.value[key];

    if (draft === undefined || autosave.lastSent(key) === draft) {
        autosave.cancel();

        return;
    }

    autosave.commit(key, draft);
}

/**
 * プレビューで押した場所の文字を、右の欄で打てるようにする（UX 一周 3 周目 M4）。
 *
 * プレビュー（iframe）は**読むだけ**の約束なので（`CanvasFrame`）、そこへ直接は書かない。
 * 代わりに、押した文字と同じ値の欄（無ければ最初の文字の欄）へカーソルを移す。
 * 押してすぐ打てば、その欄に入り、打つ手が止まればプレビューも変わる。
 */
function focusField(hint: string | null = null): boolean {
    // まだ画像を選んでいない画像ブロックなら、押した案内（「まだ画像を選んでいません」）から
    // そのまま選べるようにする（UX 一周 3 周目 N5）
    if (revealMedia(true)) {
        return true;
    }

    const keys = Object.entries(props.spec.propsSchema)
        .filter(
            ([key, spec]) =>
                editable(key) &&
                !boundProp(key) &&
                (spec.type === 'string' ||
                    spec.type === 'text' ||
                    isHtml(spec)),
        )
        .map(([key]) => key);
    const wanted = hint?.replace(/\s+/g, ' ').trim() ?? '';
    const plain = (key: string): string =>
        String(propValue(key) ?? '').replace(/<[^>]*>/g, ' ');
    // 押した文字のある欄へ。メニュー表の 1 品を押して「表の題」へ飛んでいた（4 周目 R4-1）
    const key =
        fieldForHint(
            keys.map((k) => ({ key: k, text: plain(k) })),
            wanted,
        ) ?? keys[0];

    if (!key) {
        return false;
    }

    const box = window.document.getElementById(`prop-${key}`);
    const field =
        box instanceof HTMLInputElement || box instanceof HTMLTextAreaElement
            ? box
            : (box?.querySelector<HTMLElement>('[contenteditable="true"]') ??
              box);

    if (!(field instanceof HTMLElement)) {
        return false;
    }

    field.focus({ preventScroll: false });

    if (
        field instanceof HTMLInputElement ||
        field instanceof HTMLTextAreaElement
    ) {
        // 押した文字の所。見本の文字ならそのマスを選び、打てば置き換わる（4 周目 R4-2）
        const at = wanted === '' ? -1 : field.value.indexOf(wanted);
        const pos = at >= 0 ? at : field.value.length;
        const sample = sampleCellAt(field.value, pos, props.spec.label);

        if (sample) {
            field.setSelectionRange(sample[0], sample[1]);
        } else {
            const end = at >= 0 ? at + wanted.length : field.value.length;
            field.setSelectionRange(end, end);
        }
    }

    return true;
}

/**
 * 欄を押したとき、押した所が見本の文字（〇〇 の入ったマス・ブロック名のまま）なら、そのマスを選ぶ
 * （4 周目 R4-2: `〇〇曜日月曜日`・`ボタン予約の問い合わせをする` とくっついた）。
 * 範囲を選んで押したとき（自分で選んだ）と、店が書いた文字のときは何もしない。
 */
function selectSampleAtCaret(event: Event): void {
    const field = event.target;

    if (
        !(field instanceof HTMLInputElement) &&
        !(field instanceof HTMLTextAreaElement)
    ) {
        return;
    }

    if (
        field.selectionStart === null ||
        field.selectionStart !== field.selectionEnd
    ) {
        return;
    }

    const sample = sampleCellAt(
        field.value,
        field.selectionStart,
        props.spec.label,
    );

    if (sample) {
        field.setSelectionRange(sample[0], sample[1]);
    }
}

/**
 * 右の欄の中の要素を、上に留まっている「追加する」（`editor-palette`）に隠れない所まで出す。
 *
 * 「追加する」は右の欄の上に留まる（N2）ので、ふつうに `scrollIntoView` すると、
 * 画面の中には入っても「追加する」の**下に隠れる**ことがある（実測: 1440×900 で隠れた）。
 * 下の端へ寄せてから、まだ隠れていれば、隠れているぶんだけ戻す。
 *
 * スマホ・タブレットでは画面の下に「公開する」の帯（`editor-bottom-bar`）が留まっている。
 * 下の端へ寄せると、欄がその帯の**裏に入る**（直し 4 回目に実測: 390×844 で置いた直後の
 * 「見出しを入力」が帯に隠れていた）。帯があれば、帯の上に出るまで送る。上の「追加する」の方を優先する。
 */
function revealBelowPalette(el: HTMLElement): void {
    el.scrollIntoView({ block: 'end' });

    const bar = window.document.querySelector<HTMLElement>(
        '[data-testid="editor-bottom-bar"]',
    );

    // 広い画面では帯は lg:hidden（display:none）で、位置が 0 になる。見えている帯だけ数える
    if (bar && bar.getBoundingClientRect().height > 0) {
        const under =
            el.getBoundingClientRect().bottom -
            (bar.getBoundingClientRect().top - 8);

        if (under > 0) {
            const scroller = scrollParent(el);

            if (scroller) {
                scroller.scrollBy({ top: under });
            } else {
                window.scrollBy({ top: under });
            }
        }
    }

    const palette = window.document.querySelector<HTMLElement>(
        '[data-testid="editor-palette"]',
    );
    const cover = palette ? palette.getBoundingClientRect().bottom : 0;
    const gap = el.getBoundingClientRect().top - (cover + 8);

    if (gap >= 0) {
        return;
    }

    const scroller = scrollParent(el);

    if (scroller) {
        scroller.scrollBy({ top: gap });
    } else {
        window.scrollBy({ top: gap });
    }
}

/** 縦に巻ける一番近い親（無ければ画面そのもの = null） */
function scrollParent(el: HTMLElement): HTMLElement | null {
    for (let node = el.parentElement; node; node = node.parentElement) {
        const overflow = window.getComputedStyle(node).overflowY;

        if (
            (overflow === 'auto' || overflow === 'scroll') &&
            node.scrollHeight > node.clientHeight
        ) {
            return node;
        }
    }

    return null;
}

/**
 * まだ選んでいない画像の「画像を選択」を、右の欄の見える所まで出す（UX 一周 3 周目 N5）。
 *
 * 画像を置くと、プレビューと気づきの欄に「右の『画像を選択』から選べます」と出るのに、
 * 右の欄は下がったままで、ボタンが画面の外にあった。置いた直後とプレビューで押したときに呼ぶ。
 * `open` なら選ぶ窓も開く（プレビューの案内を押したとき）。
 */
function revealMedia(open = false): boolean {
    const key = Object.entries(props.spec.propsSchema).find(
        ([key, spec]) =>
            spec.type === 'media' &&
            editable(key) &&
            !boundProp(key) &&
            (propValue(key) ?? null) === null,
    )?.[0];

    if (key === undefined) {
        return false;
    }

    const button = window.document.querySelector<HTMLElement>(
        `[data-testid="inspector-prop-${key}"] [data-testid="media-picker-open"]`,
    );

    if (!button) {
        return false;
    }

    revealBelowPalette(button);
    button.focus({ preventScroll: true });

    if (open) {
        button.click();
    }

    return true;
}

/**
 * 置いたばかりのブロックの欄を、隠れない所まで出す（UX 一周 5 周目 R5-4）。
 *
 * PC 幅でテキストを置くと、打つ欄が右の欄のいちばん下に半分だけ出ていた。
 * 画像なら「画像を選択」（N5）、それ以外は最初の内容の欄を出す。カーソルは動かさない
 * （欄を選ぶのは押した人。勝手に選ぶとタブレットでキーボードが開く）。
 */
function revealFirstField(): boolean {
    if (revealMedia()) {
        return true;
    }

    const key = Object.keys(props.spec.propsSchema).find(
        (k) => editable(k) && !boundProp(k),
    );
    const box = key ? window.document.getElementById(`prop-${key}`) : null;

    if (!box) {
        return false;
    }

    revealBelowPalette(box);

    return true;
}

defineExpose({
    focusField,
    revealMedia,
    revealFirstField,
    flush: () => autosave.flush(),
});

/*
 * 画像を選んだら、メディアに付けた「画像の説明」を代替テキストへ写す（UX 一周 2026-09-28）。
 * アップロードのときに書いたのに、置いたブロックでは空として「要修正」が出ていた。
 * **すでに書いてある代替テキストは上書きしない。**
 */
/** 画像を替えて代替テキストを消した・替えたときの知らせ（23 周目 #5） */
const altNotice = ref<{ key: string; text: string } | null>(null);
watch(
    () => props.node.id,
    () => {
        altNotice.value = null;
    },
);

function pickMedia(
    key: string,
    item: { id: number; alt?: string | null } | null,
    previous: { alt?: string | null } | null = null,
): void {
    const altKey =
        typeof props.spec.a11y?.alt === 'string' ? props.spec.a11y.alt : null;
    const currentAlt = altKey
        ? String(props.node.props?.[altKey] ?? '').trim()
        : '';
    // 決まりは lib/media-alt.ts（替えたら前の写真の説明を残さない・UX 一周 23 周目 #5）
    const currentId = propValue(key);
    const decision = altAfterPick({
        currentAlt,
        previousMediaAlt: previous?.alt ?? null,
        currentMediaId: typeof currentId === 'number' ? currentId : null,
        pickedMediaId: item?.id ?? null,
        pickedMediaAlt: item?.alt ?? null,
    });
    altNotice.value =
        decision.notice === null
            ? null
            : { key, text: ALT_NOTICE[decision.notice] };

    if (item && altKey && decision.alt !== undefined && !responsiveHere(key)) {
        emit(
            'change',
            {
                props: { [key]: item.id, [altKey]: decision.alt },
            },
            props.node.id,
        );

        return;
    }

    setProp(key, item?.id ?? null);
}

/**
 * 入力欄の生の文字列から値を決める。
 *
 * **空欄は必ず「外す」**。数値へ変換してから空を判定すると `Number('') === 0` で
 * 「0 を選んだ」ことになり、外せないうえに宣言の下限（`min`）も割る。
 */
function setPropFromText(
    key: string,
    spec: ComponentPropSpec,
    raw: string,
): void {
    if (raw === '') {
        setProp(key, null);

        return;
    }

    setProp(key, spec.type === 'number' ? Number(raw) : raw);
}

/** enum の選択。「指定しない」は空文字で来るので、宣言された値だけを通す */
function setPropFromChoice(
    key: string,
    spec: ComponentPropSpec,
    raw: string,
): void {
    setProp(
        key,
        (spec.values ?? []).find((value) => String(value) === raw) ?? null,
    );
}

function setStyle(key: string, value: string): void {
    change('style', key, value === '' ? null : value);
}

/** 長い文字列は複数行で入れさせる（宣言された `max` で決める） */
function multiline(spec: ComponentPropSpec): boolean {
    return (spec.max ?? 0) > 200;
}

/** 打てば消える仮の文字（UX 一周 3 周目 N3）。値ではないので保存されない */
function inputPlaceholderOf(key: string): string | undefined {
    return propPlaceholder(
        props.spec.type,
        props.spec.label,
        key,
        props.spec.propsSchema[key]?.label,
    );
}

const breakpointsOf = (node: DocumentNode): string[] =>
    Object.keys(node.responsive ?? {});

function overridesOf(node: DocumentNode, breakpoint: string): string {
    const values = node.responsive?.[breakpoint] ?? {};

    return Object.entries(values)
        .map(([key, value]) => `${props.styles[key]?.label ?? key}: ${value}`)
        .join(' / ');
}

/** その入力欄を触れるか。画面幅を選んでいるときは、対応している key だけ */
function editable(key: string): boolean {
    return !props.blocked && (props.breakpoint === null || responsiveHere(key));
}

/*
 * ── データ接続（Phase 9.6 / P0-C5・SPEC §4.9）────────────────────────
 *
 * **ここに型整合の表は無い。** どの Field をどの prop へ流せるかは
 * `spec.accepts`（サーバーの `BindingTypes` が解いた結果）が持っている。
 * 画面がするのは「候補にこの型が入っているか」を見ることだけである。
 *
 * 不正な binding は**隠さずエラーとして見せて、直す道と外す道を出す**。
 * 隠すと、保存できない document を抱えたまま理由が分からなくなる。
 */
/*
 * ── 再利用 component の参照（Phase 9.6 / P0-C7・SPEC §4.5）────────────
 *
 * **中は編集できない。** 参照側は `instanceOf` だけを持ち `props` / `children` を
 * 持たないので、中を編集する先が無い（契約の帰結である）。
 * だから内容と見た目の欄は出さず、**2 つの道**（実体を編集する / 切り離す）を出す。
 */
const instance = computed(() => (isInstance(props.node) ? props.node : null));

const instanceName = computed(() => instanceLabel(props.node, props.reusables));

const binding = computed<BindingRecord | null>(() => {
    const value = props.node.binding;

    return value && typeof value === 'object' ? (value as BindingRecord) : null;
});

/**
 * 一覧の binding か。
 *
 * **component の型名では判定しない**（画面に型名を焼き込まない — §4.1）。
 * 一覧かどうかは binding の `source` が言う。
 */
const isCollection = computed(() => binding.value?.source === 'collection');

/** この component は単一値の binding を持てるか */
const canBind = computed(() => props.spec.bindable.length > 0);

/** いまの参照元で選べる項目（流し先の受け入れで絞る） */
const fields = computed(() =>
    fieldChoices(binding.value, props.spec, props.sources),
);

/** 選べる参照元。**流せる項目を 1 つも持たない参照元は出さない** */
const originChoices = computed(() =>
    sourceChoices(binding.value?.prop, props.spec, props.sources),
);

/** 接続できるものが何も無ければ、始める口も出さない */
const canStart = computed(
    () =>
        sourceChoices(props.spec.bindable[0], props.spec, props.sources)
            .length > 0,
);

/** この接続の壊れているところ。壊れていなければ null */
const problem = computed(() =>
    bindingProblem(binding.value, props.spec, props.sources),
);

function propLabel(prop: string): string {
    return props.spec.propsSchema[prop]?.label ?? prop;
}

/**
 * binding の一部を差し替えて送る。**送るのは binding の全体**（重ねない欄なので）。
 *
 * 参照元や流し先を変えると、いま指している項目がその組み合わせで使えなくなる。
 * **欠けたまま送るとサーバーが断る**ので、送る前に `completeBinding()` で整える。
 */
function changeBinding(patch: Partial<BindingRecord>): void {
    const next = completeBinding(
        { ...(binding.value ?? {}), ...patch },
        props.spec,
        props.sources,
    );

    if (next !== null) {
        emit('change', { binding: next }, props.node.id);
    }
}

/** 接続を外す。**`null` が「外す」である**（§4.9 — props の作法と揃える） */
function removeBinding(): void {
    emit('change', { binding: null }, props.node.id);
}

/**
 * まだ接続していない component に、最初の接続を作る。
 *
 * **最初から送れる形にする。** 参照元も項目も選ばれていない接続を送ると
 * サーバーが断り、画面には「押したのに何も起きない」だけが残る。
 */
function startBinding(): void {
    const prop = props.spec.bindable[0];
    const first = sourceChoices(prop, props.spec, props.sources)[0];

    if (first === undefined) {
        return;
    }

    changeBinding(
        first.value === 'page'
            ? { source: 'page', prop }
            : { source: 'entry', type: first.value, prop },
    );
}

function bindingSummary(binding: Record<string, unknown>): string {
    return [binding.source, binding.type, binding.field]
        .filter((part) => typeof part === 'string' && part !== '')
        .join(' / ');
}
</script>

<template>
    <div class="space-y-4">
        <!--
            親が消えていれば子も出てこない。ブラウザがそうする以上ここでは選べないので、
            **事実として伝える**（Owner Gate V4）
        -->
        <p
            v-if="hiddenByAncestor"
            class="rounded-md border border-amber-500/60 bg-amber-500/10 px-2 py-1.5 text-xs"
            data-testid="inspector-hidden-by-ancestor"
        >
            親のブロックが非表示のため、このブロックも表示されません。
        </p>

        <!--
            再利用 component の参照（§4.5）。

            **中は編集できない。** 参照側は中身を持たないので、内容と見た目の欄を
            出しても保存されない。代わりに**2 つの道**を置く。
        -->
        <section
            v-if="instance"
            class="rounded-md border bg-muted/40 p-2"
            data-testid="inspector-instance"
        >
            <h3 class="mb-1 text-sm font-medium">再利用ブロック</h3>

            <!--
                **中身は編集できないが、見た目は指定できる。**
                入れ物はそれ自体が node なので、描画は入れ物の `style` を使う
                （差し替わるのは props と children だけ）。文面をそこに合わせる。
            -->
            <p class="mb-2 text-xs text-muted-foreground">
                この部分は「{{
                    instanceName
                }}」を読み込んでいます。中身はここでは
                編集できません（余白などの見た目は指定できます）。
                <template v-if="canEditReusable === false">
                    中身を変えたいときは、サイトを作った人（制作者）に頼んでください。
                </template>
            </p>

            <div class="flex flex-wrap gap-3">
                <button
                    v-if="canEditReusable !== false"
                    type="button"
                    class="text-xs underline underline-offset-2 disabled:opacity-50"
                    :disabled="blocked"
                    data-testid="inspector-instance-edit"
                    @click="emit('edit-reusable', instanceOf(node) ?? '')"
                >
                    再利用ブロックを編集する
                </button>

                <!-- 戻せない操作。確認はサーバーの確認ゲートが出す -->
                <button
                    type="button"
                    class="text-xs underline underline-offset-2 disabled:opacity-50"
                    :disabled="blocked"
                    data-testid="inspector-instance-detach"
                    @click="emit('detach')"
                >
                    切り離す
                </button>
            </div>
        </section>

        <!-- 内容（propsSchema）。**参照しているあいだは出さない** -->
        <section v-if="!instance && Object.keys(spec.propsSchema).length > 0">
            <h3 class="mb-2 text-sm font-medium">内容</h3>

            <div class="space-y-3">
                <div
                    v-for="(propSpec, key) in spec.propsSchema"
                    :key="key"
                    class="space-y-1"
                >
                    <Label
                        :id="`prop-${key}-label`"
                        :for="`prop-${key}`"
                        class="flex items-center gap-2 text-xs"
                    >
                        {{ propSpec.label ?? key }}
                        <span
                            v-if="breakpoint !== null"
                            class="rounded px-1 text-[10px]"
                            :class="
                                overridden(key)
                                    ? 'bg-primary/15 text-primary'
                                    : 'text-muted-foreground'
                            "
                            :data-testid="`inspector-state-${key}`"
                        >
                            {{
                                !responsiveHere(key)
                                    ? 'すべての画面幅で共通'
                                    : overridden(key)
                                      ? 'この画面幅で指定'
                                      : '継承'
                            }}
                        </span>
                    </Label>

                    <p
                        v-if="boundProp(key)"
                        class="text-xs text-muted-foreground"
                        :data-testid="`inspector-bound-${key}`"
                    >
                        この値はコンテンツから流し込まれています。ここでは変更できません。
                    </p>

                    <!-- フォームなどの実データを、内部キーではなく表示名で選ぶ。 -->
                    <select
                        v-else-if="propSpec.options !== undefined"
                        :id="`prop-${key}`"
                        class="min-h-11 w-full rounded-md border bg-transparent px-2 text-sm"
                        :disabled="!editable(key)"
                        :value="String(propValue(key) ?? '')"
                        :data-testid="`inspector-prop-${key}`"
                        @change="
                            setProp(
                                key,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">フォームを選択</option>
                        <option
                            v-for="option in propSpec.options"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>

                    <!-- 選べる値が決まっているもの -->
                    <select
                        v-else-if="propSpec.type === 'enum'"
                        :id="`prop-${key}`"
                        class="h-9 w-full rounded-md border bg-transparent px-2 text-sm"
                        :disabled="!editable(key)"
                        :value="String(propValue(key) ?? '')"
                        :data-testid="`inspector-prop-${key}`"
                        @change="
                            setPropFromChoice(
                                key,
                                propSpec,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <!-- 選んだものを外す道を必ず残す（外すと宣言の既定へ戻る） -->
                        <option value="">指定しない</option>
                        <option
                            v-for="value in propSpec.values ?? []"
                            :key="String(value)"
                            :value="String(value)"
                        >
                            {{ choiceLabel(propSpec.valueLabels, value) }}
                        </option>
                    </select>

                    <!-- デザイントークン -->
                    <select
                        v-else-if="propSpec.type === 'token'"
                        :id="`prop-${key}`"
                        class="h-9 w-full rounded-md border bg-transparent px-2 text-sm"
                        :disabled="!editable(key)"
                        :value="String(propValue(key) ?? '')"
                        :data-testid="`inspector-prop-${key}`"
                        @input="emit('dirty')"
                        @change="
                            setProp(
                                key,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">指定しない</option>
                        <option
                            v-for="name in tokens[propSpec.group ?? ''] ?? []"
                            :key="name"
                            :value="`{${propSpec.group}.${name}}`"
                        >
                            {{ choiceLabel(tokenLabels, name) }}
                        </option>
                    </select>

                    <label
                        v-else-if="propSpec.type === 'boolean'"
                        class="flex min-h-11 cursor-pointer items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :id="`prop-${key}`"
                            :disabled="!editable(key)"
                            :model-value="propValue(key) === true"
                            :data-testid="`inspector-prop-${key}`"
                            @update:model-value="setProp(key, $event === true)"
                        />
                        使う
                    </label>

                    <div v-else-if="propSpec.type === 'media'">
                        <MediaPicker
                            :model-value="
                                (propValue(key) as number | null) ?? null
                            "
                            :disabled="!editable(key)"
                            compact
                            :data-testid="`inspector-prop-${key}`"
                            @picked="
                                (item, previous) =>
                                    pickMedia(key, item, previous)
                            "
                        />
                        <p
                            v-if="altNotice !== null && altNotice.key === key"
                            role="status"
                            class="mt-1 text-xs text-amber-700 dark:text-amber-300"
                            data-testid="inspector-alt-notice"
                        >
                            {{ altNotice.text }}
                        </p>
                    </div>

                    <div
                        v-else-if="isHtml(propSpec)"
                        :data-testid="`inspector-prop-${key}`"
                        @focusin="selectHtmlPlaceholder($event, key, propSpec)"
                        @click="selectHtmlPlaceholder($event, key, propSpec)"
                        @focusout="commitHtml(key, $event)"
                    >
                        <RichtextEditor
                            :input-id="`prop-${key}`"
                            :label-id="`prop-${key}-label`"
                            :disabled="!editable(key)"
                            :maxlength="propSpec.max ?? null"
                            :placeholder="inputPlaceholderOf(key) ?? null"
                            :model-value="
                                htmlDraft[key] ?? String(propValue(key) ?? '')
                            "
                            @update:model-value="draftHtml(key, $event)"
                        />
                    </div>

                    <textarea
                        v-else-if="multiline(propSpec)"
                        :id="`prop-${key}`"
                        class="min-h-24 w-full rounded-md border bg-transparent p-2 text-sm"
                        :disabled="!editable(key)"
                        :maxlength="propSpec.max"
                        :placeholder="inputPlaceholderOf(key)"
                        :value="textValue(key)"
                        @focus="selectPlaceholder($event, key, propSpec)"
                        @click="selectPlaceholder($event, key, propSpec); selectSampleAtCaret($event)"
                        :data-testid="`inspector-prop-${key}`"
                        :aria-describedby="
                            propSpec.help ? `prop-${key}-help` : undefined
                        "
                        @input="
                            draftText(
                                key,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                        @change="
                            commitText(
                                key,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />

                    <Input
                        v-else
                        :id="`prop-${key}`"
                        :list="
                            propSpec.type === 'url' &&
                            (linkTargets ?? []).length > 0
                                ? `prop-${key}-targets`
                                : undefined
                        "
                        :type="propSpec.type === 'number' ? 'number' : 'text'"
                        :disabled="!editable(key)"
                        :maxlength="
                            propSpec.type === 'number'
                                ? undefined
                                : propSpec.max
                        "
                        :min="propSpec.min"
                        :max="
                            propSpec.type === 'number'
                                ? propSpec.max
                                : undefined
                        "
                        :model-value="textValue(key)"
                        :placeholder="inputPlaceholderOf(key)"
                        @focus="selectPlaceholder($event, key, propSpec)"
                        @click="selectPlaceholder($event, key, propSpec); selectSampleAtCaret($event)"
                        :data-testid="`inspector-prop-${key}`"
                        @input="
                            draftText(
                                key,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                        @change="
                            commitText(
                                key,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <template
                        v-if="
                            propSpec.type === 'url' &&
                            (linkTargets ?? []).length > 0
                        "
                    >
                        <datalist :id="`prop-${key}-targets`">
                            <option
                                v-for="target in linkTargets"
                                :key="target.href"
                                :value="target.href"
                            >
                                {{ target.label }}
                            </option>
                        </datalist>
                        <p class="text-xs text-muted-foreground">
                            サイト内のページは、欄を押すと候補から選べます（例:
                            {{ linkTargets?.[0]?.label }} →
                            {{ linkTargets?.[0]?.href }}）。
                        </p>
                    </template>
                    <p
                        v-if="fieldErrors[key]"
                        role="alert"
                        class="text-xs text-destructive"
                        :data-testid="`inspector-error-${key}`"
                    >
                        {{ fieldErrors[key] }}
                    </p>
                    <p
                        v-if="propSpec.help"
                        :id="`prop-${key}-help`"
                        class="text-xs text-muted-foreground"
                        :data-testid="`inspector-help-${key}`"
                    >
                        {{ propSpec.help }}
                    </p>
                </div>
            </div>
        </section>

        <!-- 見た目（styleSchema） -->
        <section v-if="spec.styleSchema.length > 0">
            <h3 class="mb-2 text-sm font-medium">見た目</h3>

            <div class="space-y-3">
                <div
                    v-for="key in spec.styleSchema"
                    :key="key"
                    class="space-y-1"
                >
                    <Label
                        :for="`style-${key}`"
                        class="flex items-center gap-2 text-xs"
                    >
                        {{ styles[key]?.label ?? key }}
                        <span
                            v-if="breakpoint !== null"
                            class="rounded px-1 text-[10px]"
                            :class="
                                overridden(key)
                                    ? 'bg-primary/15 text-primary'
                                    : 'text-muted-foreground'
                            "
                            :data-testid="`inspector-state-${key}`"
                        >
                            {{
                                !responsiveHere(key)
                                    ? 'すべての画面幅で共通'
                                    : overridden(key)
                                      ? 'この画面幅で指定'
                                      : '継承'
                            }}
                        </span>
                    </Label>

                    <select
                        v-if="styles[key]?.values"
                        :id="`style-${key}`"
                        class="h-9 w-full rounded-md border bg-transparent px-2 text-sm"
                        :disabled="!editable(key)"
                        :value="styleValue(key)"
                        :data-testid="`inspector-style-${key}`"
                        @change="
                            setStyle(
                                key,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">指定しない</option>
                        <option
                            v-for="value in styles[key]?.values ?? []"
                            :key="value"
                            :value="value"
                        >
                            {{ choiceLabel(styles[key]?.valueLabels, value) }}
                        </option>
                    </select>

                    <Input
                        v-else
                        :id="`style-${key}`"
                        type="text"
                        :disabled="!editable(key)"
                        :model-value="styleValue(key)"
                        :data-testid="`inspector-style-${key}`"
                        @change="
                            setStyle(
                                key,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
            </div>
        </section>

        <!-- 表示条件（読むだけ） -->
        <section v-if="breakpointsOf(node).length > 0">
            <h3 class="mb-2 text-sm font-medium">表示条件</h3>

            <ul class="space-y-1 text-xs text-muted-foreground">
                <li
                    v-for="breakpoint in breakpointsOf(node)"
                    :key="breakpoint"
                    :data-testid="`inspector-responsive-${breakpoint}`"
                >
                    {{ breakpoint }}: {{ overridesOf(node, breakpoint) }}
                </li>
            </ul>

            <p class="mt-1 text-xs text-muted-foreground">
                画面幅ごとの編集はこの画面ではまだできません。
            </p>
        </section>

        <!-- データ接続 -->
        <section v-if="node.binding || canBind">
            <h3 class="mb-2 text-sm font-medium">データ接続</h3>

            <!--
                壊れている接続は**隠さない**。隠すと、保存できない document を
                抱えたまま理由が分からなくなる。正当なものはエラーと呼ばない
            -->
            <p
                v-if="problem"
                class="mb-2 rounded-md border border-destructive/60 bg-destructive/10 px-2 py-1.5 text-xs"
                data-testid="inspector-binding-error"
            >
                {{ problem }}
            </p>

            <p
                v-if="node.binding"
                class="mb-2 text-xs text-muted-foreground"
                data-testid="inspector-binding"
            >
                {{ bindingSummary(node.binding) }}
            </p>

            <div v-if="binding" class="space-y-2">
                <!-- 一覧は「どの種類を出すか」だけ。絞り込みと並び順は別の面 -->
                <template v-if="isCollection">
                    <div class="grid gap-1">
                        <Label class="text-xs">出すコンテンツ</Label>
                        <select
                            class="h-8 rounded-md border bg-background px-2 text-sm"
                            :value="binding.type ?? ''"
                            :disabled="blocked"
                            data-testid="inspector-binding-type"
                            @change="
                                changeBinding({
                                    type: ($event.target as HTMLSelectElement)
                                        .value,
                                })
                            "
                        >
                            <option
                                v-for="entry in sources.collection"
                                :key="entry.type"
                                :value="entry.type"
                            >
                                {{ entry.label }}
                            </option>
                        </select>
                    </div>

                    <p
                        class="text-xs text-muted-foreground"
                        data-testid="inspector-binding-collection-note"
                    >
                        絞り込みと並び順は、この面では変えられません。
                    </p>
                </template>

                <!-- 単一値。流し先 → 参照元 → 項目 の順で選ぶ -->
                <template v-else>
                    <div class="grid gap-1">
                        <Label class="text-xs">流し先</Label>
                        <select
                            class="h-8 rounded-md border bg-background px-2 text-sm"
                            :value="binding.prop ?? ''"
                            :disabled="blocked"
                            data-testid="inspector-binding-prop"
                            @change="
                                changeBinding({
                                    prop: ($event.target as HTMLSelectElement)
                                        .value,
                                })
                            "
                        >
                            <option
                                v-for="prop in spec.bindable"
                                :key="prop"
                                :value="prop"
                            >
                                {{ propLabel(prop) }}
                            </option>
                        </select>
                    </div>

                    <!--
                        `settings` は選べるようにしていない（schema が無く型が引けない）。
                        既にあるものは正当なものとして表示し、外すこともできる
                    -->
                    <div
                        v-if="binding.source !== 'settings'"
                        class="grid gap-1"
                    >
                        <Label class="text-xs">参照元</Label>
                        <select
                            class="h-8 rounded-md border bg-background px-2 text-sm"
                            :value="
                                binding.source === 'page'
                                    ? 'page'
                                    : (binding.type ?? '')
                            "
                            :disabled="blocked"
                            data-testid="inspector-binding-source"
                            @change="
                                changeBinding(
                                    ($event.target as HTMLSelectElement)
                                        .value === 'page'
                                        ? { source: 'page' }
                                        : {
                                              source: 'entry',
                                              type: (
                                                  $event.target as HTMLSelectElement
                                              ).value,
                                          },
                                )
                            "
                        >
                            <option
                                v-for="origin in originChoices"
                                :key="origin.value"
                                :value="origin.value"
                            >
                                {{ origin.label }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="binding.source !== 'settings'"
                        class="grid gap-1"
                    >
                        <Label class="text-xs">項目</Label>
                        <select
                            class="h-8 rounded-md border bg-background px-2 text-sm"
                            :value="binding.field ?? ''"
                            :disabled="blocked"
                            data-testid="inspector-binding-field"
                            @change="
                                changeBinding({
                                    field: ($event.target as HTMLSelectElement)
                                        .value,
                                })
                            "
                        >
                            <option value="" disabled>選んでください</option>
                            <option
                                v-for="field in fields"
                                :key="field.key"
                                :value="field.key"
                            >
                                {{ field.label }}
                            </option>
                        </select>

                        <!-- 候補が無いのは、その流し先に流せる項目が無いということ -->
                        <p
                            v-if="fields.length === 0"
                            class="text-xs text-muted-foreground"
                            data-testid="inspector-binding-no-field"
                        >
                            この流し先へ流せる項目が、参照元にありません。
                        </p>
                    </div>
                </template>

                <!-- 外す口。付ける口があるなら外す口も要る（§4.9） -->
                <button
                    v-if="!isCollection"
                    type="button"
                    class="text-xs underline underline-offset-2 disabled:opacity-50"
                    :disabled="blocked"
                    data-testid="inspector-binding-remove"
                    @click="removeBinding()"
                >
                    接続を外す
                </button>
            </div>

            <button
                v-else-if="canBind && canStart"
                type="button"
                class="text-xs underline underline-offset-2 disabled:opacity-50"
                :disabled="blocked"
                data-testid="inspector-binding-start"
                @click="startBinding()"
            >
                コンテンツを接続する
            </button>
        </section>
    </div>
</template>

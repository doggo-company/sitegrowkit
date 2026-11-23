<script setup lang="ts">
/**
 * Visual Page Editor — Canvas + Layers（Phase 9.6 / P0-C2）。
 *
 * 仕様は `docs/PHASE9_6_SPEC.md` §4.1（Canvas）/ §4.2（Layers）/ §5.1（変更経路）。
 *
 * - 変更は **semantic command → `page.document.patch`** の 1 本だけ
 * - Canvas は公開と同じ描画（プレビュー）を読むだけで、中身を書き換えない
 * - 置けない場所には置かせない。判定は Registry の `allowedParents` / `allowedChildren`
 * - 削除はサーバーの ConfirmationGate を通る（ブラウザの confirm を使わない）
 * - 版がずれたら**黙って上書きしない**。読み込み直しを促す
 *
 * 記事本文（richtext）の編集はここではない（Content Editor / P0-B3 の範囲）。
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Box,
    Columns2,
    FileCode2,
    Heading1,
    Image as ImageIcon,
    LayoutGrid,
    MousePointerClick,
    Rows3,
    Type,
} from '@lucide/vue';
import FrontPagePanel from '@/components/page-editor/FrontPagePanel.vue';
import CanvasFrame from '@/components/page-editor/CanvasFrame.vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import InspectorPanel from '@/components/page-editor/InspectorPanel.vue';
import LayersTree from '@/components/page-editor/LayersTree.vue';
import { Button } from '@/components/ui/button';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { usePageDocument } from '@/composables/usePageDocument';
import { useEditorRecovery } from '@/composables/useEditorRecovery';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

import { detachCommand, placeCommand, placementFor } from '@/lib/reusable';
import type { ReusableSummary } from '@/lib/reusable';
import {
    canDropInto as canDrop,
    insertPlan,
    withFirstHeadingLevel,
    moveDestination,
} from '@/lib/document-commands';
import type { InsertPlan } from '@/lib/document-commands';
import type {
    BindingSources,
    ComponentSpec,
    DocumentNode,
    PageDocument,
    PaletteEntry,
    StylePropertySpec,
} from '@/types/document';
import type {
    AccessibilityFinding,
    BreakpointSpec,
    PageVersionSummary,
} from '@/types/document';
import type { PageDetail } from '@/types/page';

const props = defineProps<{
    page: PageDetail;
    frontPageOffer?: boolean;
    frontPageSelected?: boolean;
    document: PageDocument;
    version: number;
    /** 追加できる component。`html` はサーバー側で除いてある */
    components: PaletteEntry[];
    /** 既にある node を読み書きするための宣言（登録 9 種すべて） */
    specs: ComponentSpec[];
    styles: Record<string, StylePropertySpec>;
    tokens: Record<string, string[]>;
    /** 画面幅の宣言（theme.json 由来） */
    breakpoints: BreakpointSpec[];
    /** 編集中の気づき。保存も公開も止めない */
    accessibility: AccessibilityFinding[];
    /** binding の接続先。**型整合の表は入っていない**（§4.9 / P0-C5） */
    bindingSources: BindingSources;
    /** 使える再利用 component（名前を出すためだけ。中身は持たない — §4.5） */
    reusables: ReusableSummary[];
    /** 再利用 component の編集画面（id を付けて開く） */
    /** 再利用ブロックの編集画面。開けない人（制作者以外）には null */
    reusableEditUrl: string | null;
    canvasUrl: string;
    /** トークンの表示名（`gapSmall` → 狭い） */
    tokenLabels?: Record<string, string>;
    /** ボタンのリンク先の候補（公開面で開けるページだけ） */
    linkTargets?: { href: string; label: string }[];
    backUrl: string;
}>();

const recovery = useEditorRecovery(usePage().props.auth.user.id, props.page.id);
// Only a backup found when opening the screen asks for recovery. Current typing does not.
const recoveryAvailable = ref(recovery.drafts.value.length > 0);
const reloadConfirm = ref(false);
const copyMessage = ref('');
const recoveryMessage = ref('');
const recovering = ref(false);

const document = ref<PageDocument>(props.document);
const version = ref<number>(props.version);
const selectedId = ref<string | null>(null);
/** いま掴んでいるブロック（Layers の drag&drop） */
const dragging = ref<string | null>(null);
/** いま確認・編集している画面幅。null は「すべての画面幅」（= base） */
const breakpoint = ref<string | null>(null);
/** アクセシビリティの気づき。変更のたびにサーバーの検査結果で入れ替わる */
const findings = ref<AccessibilityFinding[]>(props.accessibility);

/**
 * ブロックの絵（R-9 Stage 0 ②）。
 *
 * **手がかりであって、意味の正本ではない。** 名前は必ず一緒に出す。
 * 登録に無い type が来たら既定の絵にする — **絵が無いから出さない、をしない**。
 */
const BlockDefaultIcon = Box;

const blockIcons: Record<string, typeof Box> = {
    section: Rows3,
    container: Columns2,
    heading: Heading1,
    text: Type,
    richtext: Type,
    image: ImageIcon,
    button: MousePointerClick,
    collection: LayoutGrid,
    html: FileCode2,
};

const canvas = useTemplateRef<InstanceType<typeof CanvasFrame>>('canvas');

/*
 * 「このページをトップページにする」の案内が出る・トップに選ばれると、Canvas の上に帯が入り
 * 枠ごと下へずれる。トップになれば帯（題）も消える。描き直さないと、選択の枠が古い位置に残り、
 * 消えたはずの題の帯も再読み込みまで残っていた（迷った 4 件の直し・2026-11-23 実測）。
 */
watch(
    () => [props.frontPageOffer, props.frontPageSelected],
    (now, before) => {
        if (now[0] !== before[0] || now[1] !== before[1]) {
            canvas.value?.reload();
        }
    },
);

const {
    saving,
    error,
    conflict,
    confirmation,
    run,
    accept,
    dismiss,
    undo,
    redo,
    undoStack,
    redoStack,
    dirty,
    markDirty,
    versions,
    restore,
} = usePageDocument(props.page.id, document, version, findings);

/**
 * 変更を受け付けない状態。
 *
 * **競合したら止める。**版がずれたまま操作を続けさせると、
 * 「押しても毎回失敗する画面」になるか、悪くすると他人の編集を踏む。
 * 読み込み直すまでは追加も移動も複製も削除もさせない（§8）。
 */
const blocked = computed(() => saving.value || conflict.value !== null);

/**
 * 入力欄を止めるのは**競合のときだけ**（UX 一周 第 17 周）。
 *
 * 保存中にも止めると、欄 A を離れた瞬間に欄 B が disabled になって焦点が外れ、
 * Tab で移ってすぐ打った文字が落ちた。保存は欄ごとに順に送る（`update()`）ので、
 * 打ち続けてよい。
 */
const locked = computed(() => conflict.value !== null);

// **パレットではなく Registry 全体**から引く。パレットに出さない `html` も、
// 既にある node は名前を出し、Inspector で編集できる必要がある（Owner Gate V1）
const labels = computed<Record<string, string>>(() =>
    Object.fromEntries(props.specs.map((c) => [c.type, c.label])),
);

const specs = computed<Record<string, ComponentSpec>>(() =>
    Object.fromEntries(props.specs.map((c) => [c.type, c])),
);

/** 木を上から順に平らにする（親と兄弟の位置を一緒に持つ） */
interface Entry {
    node: DocumentNode;
    parent: DocumentNode | null;
    index: number;
}

const entries = computed<Entry[]>(() => {
    const out: Entry[] = [];

    const walk = (
        node: DocumentNode,
        parent: DocumentNode | null,
        index: number,
    ) => {
        out.push({ node, parent, index });
        (node.children ?? []).forEach((child, i) => walk(child, node, i));
    };

    walk(document.value.root, null, 0);

    return out;
});

const selectableIds = computed(() =>
    entries.value
        .map((entry) => entry.node.id)
        .filter((id) => id !== document.value.root.id),
);

const selected = computed<Entry | null>(
    () =>
        entries.value.find((entry) => entry.node.id === selectedId.value) ??
        null,
);

const rootChildren = computed(() => document.value.root.children ?? []);

/** Canvas をこの幅にする。「すべての画面幅」のときは枠いっぱい */
const canvasWidth = computed<number | null>(
    () =>
        props.breakpoints.find((b) => b.name === breakpoint.value)?.width ??
        null,
);

/** その節点が、いま見ている画面幅で非表示になっているか */
function hidden(node: DocumentNode): boolean {
    if (node.style?.visibility === 'hidden') {
        return true;
    }

    return (
        breakpoint.value !== null &&
        node.responsive?.[breakpoint.value]?.visibility === 'hidden'
    );
}

/**
 * 先祖のどこかが非表示なら、この節点も出てこない。
 *
 * `display: none` は子孫ごと消すので、子側で「表示」を選んでも越えられない。
 * 選べない以上、**そうなっていることを伝える**しかない（Owner Gate V4）。
 */
const hiddenByAncestor = computed<boolean>(() => {
    let cursor = selected.value?.parent ?? null;

    while (cursor) {
        if (hidden(cursor)) {
            return true;
        }

        cursor = entryOf(cursor.id)?.parent ?? null;
    }

    return false;
});

const hasBlocks = computed(() => rootChildren.value.length > 0);

/** 気づきから、その原因のブロックへ行けること（見つけて終わりにしない） */
function reveal(finding: AccessibilityFinding): void {
    if (entryOf(finding.nodeId)) {
        selectedId.value = finding.nodeId;
    }
}

/*
 * どこへ入れるか・どこへ動かすかは **`@/lib/document-commands` が決める**（F-19）。
 * 画面を開かなくても決まることなので、SFC の外へ出して単体で固めてある。
 * 追加先は `plan()`（下）が `insertPlan()` に聞く。見つからなければ
 * **ボタンを押せなくする**（validator へ届く前に止める — §4.1）。
 */

/**
 * 入れ物として使える component（セクションを先頭に）。
 *
 * 空のページで「見出し」を押したとき、**置ける場所が無ければ入れ物ごと置く**
 * （UX 一周 2026-09-27）。ここで選ぶのは順番だけで、入るかどうかは
 * `insertPlan()` が Registry の宣言から決める。
 */
const wrappers = computed<PaletteEntry[]>(() =>
    props.components
        .filter((c) => c.container)
        .sort(
            (a, b) =>
                Number(b.type === 'section') - Number(a.type === 'section'),
        ),
);

/** 追加の段取り。null なら **どこにも置けない**（ボタンを押せなくする） */
function plan(spec: ComponentSpec): InsertPlan | null {
    return insertPlan(
        spec,
        selected.value,
        entries.value,
        document.value.root,
        specs.value,
        wrappers.value,
    );
}

/**
 * ボタンに添える説明。**ホバーしないと見えない `title` だけにしない**ので、
 * 同じ文を下の案内にも出す（`paletteHint`）。
 */
function paletteTitle(spec: PaletteEntry): string | undefined {
    const p = plan(spec);

    if (p === null) {
        return `「${spec.label}」は、いま選んでいる場所には置けません`;
    }

    return p.wrapper
        ? `「${p.wrapper.label}」を作って、その中に「${spec.label}」を置きます`
        : undefined;
}

/** 押せないボタンがあるなら、その理由を**画面に文字で**出す */
const paletteHint = computed<string | null>(() => {
    // 保存中でも部品は押せる（前の保存が済んでから置く。4 周目 R4-3）。
    // 以前はここで「保存しています。終わるまで追加できません。」を出し、その 1 行のぶん部品が動いて、
    // 押した指が離れる前に別の所へずれていた（5 周目で記録）

    if (conflict.value !== null) {
        return 'ほかの画面で変更されたため、読み込み直すまで追加できません。';
    }

    const unavailable = props.components.filter((c) => plan(c) === null);

    if (unavailable.length > 0) {
        const names = unavailable.map((c) => `「${c.label}」`).join('');

        return `${names}は、いま選んでいるブロックの中には置けません。置きたい場所（セクションやレイアウト）を「階層」で選び直してください。`;
    }

    if (!hasBlocks.value) {
        return 'ページはまだ空です。見出しや文章を押すと、ページの区切り（セクション）ごと置かれます。';
    }

    return null;
});

/** 空のページの入口で置くもの（見出しが無い構成なら入口を出さない） */
const headingEntry = computed<PaletteEntry | null>(
    () => props.components.find((c) => c.type === 'heading') ?? null,
);

const siblings = computed<DocumentNode[]>(
    () => selected.value?.parent?.children ?? [],
);

const canMoveUp = computed(
    () => selected.value !== null && selected.value.index > 0,
);
const canMoveDown = computed(
    () =>
        selected.value !== null &&
        selected.value.index < siblings.value.length - 1,
);

const entryOf = (id: string | null): Entry | null =>
    entries.value.find((entry) => entry.node.id === id) ?? null;

/**
 * いま掴んでいるものを、その節点の中へ置けるか。
 *
 * 判断材料は Registry の宣言と木の形だけ。**受け付けない場所では drop 自体を起こさない**
 * ので、validator まで届く前に止まる（§4.1）。
 */
function canDropInto(parentId: string): boolean {
    return canDrop(entryOf(dragging.value), entryOf(parentId), specs.value);
}

async function moveNode(
    targetId: string,
    parentId: string,
    afterId: string | null,
): Promise<void> {
    if (blocked.value) {
        return;
    }

    const ok = await run({
        operation: 'component.move',
        target: targetId,
        parent: parentId,
        after: afterId,
        expectedVersion: version.value,
    });

    if (ok) {
        canvas.value?.reload();
    }
}

/** 版の一覧（開いたときだけ読む。編集のたびに引かない） */
const history = ref<PageVersionSummary[]>([]);
const historyOpen = ref(false);

async function openHistory(): Promise<void> {
    historyOpen.value = true;
    history.value = await versions();
}

/**
 * その版へ戻す。**新しい下書きができるだけ**で、公開中のページは変わらない。
 *
 * 打ちかけの変更があるときは、確認ダイアログが出る（`ConfirmationGate`）。
 */
async function restoreTo(revision: number): Promise<void> {
    if (blocked.value) {
        return;
    }

    if (await restore(revision)) {
        historyOpen.value = false;
        canvas.value?.reload();
    }
}

/**
 * 公開中なのに、下書きの版が公開の版より進んでいるか。
 * 開いた時点で進んでいるか、この画面で 1 度でも保存したら進んでいる（保存は必ず新しい版を作る）。
 */
const draftAhead = computed(
    () =>
        props.page.publishState === 'published' &&
        (props.page.draftVersionId !== props.page.publishedVersionId ||
            version.value !== props.version),
);

/**
 * 保存の状態を**文字で**出す（§4.11 — 色だけに頼らない）。
 *
 * 4 つある。**「未保存の変更あり」を持たないと、打ちかけのまま閉じた人に
 * 何も言えない**（保存は入力欄を離れたときに走るため）。
 */
/** 保存の表示に出しうる文（幅を先に取っておくため。`saveState` を変えたらここも） */
const SAVE_STATES = [
    '保存できていません',
    '保存できませんでした',
    '保存中…',
    '入力中…（自動で保存します）',
    '保存済み（公開ページにはまだ出ていません）',
    '保存済み（公開中と同じ内容）',
    '保存済み（下書き）',
] as const;

const saveState = computed<string>(() => {
    if (conflict.value !== null) {
        return '保存できていません';
    }

    if (error.value !== null) {
        return '保存できませんでした';
    }

    if (saving.value) {
        return '保存中…';
    }

    // 打つ手が止まれば自動で送る（UX 一周 3 周目 M5）。以前は欄を離れるまで送らなかった
    if (dirty.value) {
        return '入力中…（自動で保存します）';
    }

    // 公開中のページでは、保存＝公開ではない。言わないと「直したのに出ない」になる（UX 一周 第 15 周）
    if (draftAhead.value) {
        return '保存済み（公開ページにはまだ出ていません）';
    }

    // 公開中で、直していない（UX 一周 11 周目 R11-1: 開き直すと「下書き」と出て、下書きに戻ったように読めた）
    return props.page.publishState === 'published'
        ? '保存済み（公開中と同じ内容）'
        : '保存済み（下書き）';
});

/**
 * 取り消し／やり直し。**送るのはサーバーが返した逆の command** で、
 * 経路も確認ゲートも普通の編集と同じである（`PHASE9_6_SPEC.md` §4.10）。
 *
 * 履歴はこの画面が持っているので、**閉じれば消える**（Owner Gate V6-a）。
 * 削除の確認文言もそう言っている。
 */
async function stepBack(): Promise<void> {
    if (blocked.value) {
        return;
    }

    if (await undo()) {
        canvas.value?.reload();
    }
}

async function stepForward(): Promise<void> {
    if (blocked.value) {
        return;
    }

    if (await redo()) {
        canvas.value?.reload();
    }
}

/**
 * `Ctrl/⌘ + Z` で取り消し、`Ctrl/⌘ + Shift + Z` でやり直し。
 *
 * **入力中は横取りしない。** 文字を打っている最中の Ctrl+Z は、
 * その入力欄の取り消しであってページの取り消しではない。
 */
function onKeydown(event: KeyboardEvent): void {
    if (event.key.toLowerCase() !== 'z' || !(event.metaKey || event.ctrlKey)) {
        return;
    }

    const target = event.target as HTMLElement | null;
    const tag = target?.tagName.toLowerCase();

    if (tag === 'input' || tag === 'textarea' || target?.isContentEditable) {
        return;
    }

    event.preventDefault();

    void (event.shiftKey ? stepForward() : stepBack());
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

/** Layers から落とされた（drag&drop） */
function place(payload: { parentId: string; afterId: string | null }): void {
    const targetId = dragging.value;

    dragging.value = null;

    if (targetId !== null && targetId !== payload.afterId) {
        void moveNode(targetId, payload.parentId, payload.afterId);
    }
}

/**
 * キーボードでの移動（`Alt` + 矢印）。drag と同じことができる。
 *
 * 置けない場所を指したときは **何も起きない**。drag と同じく、
 * 実行してから validator に断られる形にしない。
 */
function nudge(payload: {
    id: string;
    direction: 'up' | 'down' | 'in' | 'out';
}): void {
    const entry = entryOf(payload.id);
    const spec = entry ? specs.value[entry.node.type] : undefined;

    if (!entry || !entry.parent || !spec) {
        return;
    }

    const to = moveDestination(
        entry,
        payload.direction,
        entries.value,
        specs.value,
    );

    if (to === null) {
        return;
    }

    void moveNode(entry.node.id, to.parent, to.after);
}

/** タッチ操作の可否も、移動そのものと同じ規則から求める。 */
function canNudge(
    id: string,
    direction: 'up' | 'down' | 'in' | 'out',
): boolean {
    const entry = entryOf(id);

    return (
        entry !== null &&
        moveDestination(entry, direction, entries.value, specs.value) !== null
    );
}

/**
 * 部品を押したら置く。**保存中でも受け付けて、前の保存が済んでから置く**（UX 一周 4 周目 R4-3）。
 *
 * 欄に打った直後に部品を押すと、押した瞬間に欄から離れて保存が始まり、保存中は部品が
 * 押せなくなっていたので、**1 回目の押しが黙って捨てられた**（2 回押してやっと置かれた）。
 * 欄の変更と同じ待ち行列（`updates`）に並べ、新しい版で置く。
 */
async function insert(spec: PaletteEntry): Promise<void> {
    if (conflict.value !== null) {
        return;
    }

    // 打ちかけがあれば先に送る（欄を離れる前に押されたとき）
    inspector.value?.flush();

    const job = updates.then(() => insertNow(spec));
    updates = job.catch(() => undefined);
    await job;
}

async function insertNow(spec: PaletteEntry): Promise<void> {
    const steps = plan(spec);

    if (!steps || conflict.value !== null) {
        return;
    }

    const parent = steps.parent;
    // ページで最初の見出しなら 1 段目にする（置いた直後に「要修正」を出さない）
    const block = withFirstHeadingLevel(
        { ...spec.defaults, type: spec.type },
        spec,
        document.value.root,
        specs.value,
    );
    const wrapperDefaults =
        props.components.find((c) => c.type === steps.wrapper?.type)
            ?.defaults ?? {};

    // 置ける場所が無ければ、入れ物ごと 1 本の command で置く（取り消しも 1 回）
    const component = steps.wrapper
        ? {
              ...wrapperDefaults,
              type: steps.wrapper.type,
              // id はサーバーが採番する（`insertPatch()`）。ここでは持たない
              children: [
                  ...(wrapperDefaults.children ?? []),
                  block as DocumentNode,
              ],
          }
        : block;

    // 追加したブロックを選び、すぐ内容を入力できるようにする。
    // 入れ物ごと置いたときも、選ぶのは **押したほう**（見出しなら見出し）。
    const previousIds = new Set(entries.value.map((entry) => entry.node.id));
    const ok = await run({
        operation: 'component.insert',
        parent: parent.id,
        component,
        expectedVersion: version.value,
    });

    if (ok) {
        const inserted = entries.value.find(
            (entry) =>
                !previousIds.has(entry.node.id) &&
                (steps.wrapper
                    ? entry.node.type === spec.type
                    : entry.parent?.id === parent.id),
        );

        if (inserted) {
            selectedId.value = inserted.node.id;
            // 置いたブロックの欄を見える所へ出す（画像なら「画像を選択」。3 周目 N5・5 周目 R5-4）
            await nextTick();
            inspector.value?.revealFirstField();
            // 入れ物も一緒に作る最初の見出しでも、描画後に文字の欄を選ぶ。
            requestAnimationFrame(focusFirstField);
        }

        canvas.value?.reload();
    }
}

/**
 * 置いた直後、最初の文字の欄へ焦点を移して**中身を選んだ状態**にする（UX 一周 第 20 周）。
 *
 * 初期文言を持つブロックは選んで打ち始めで置き換える。現在の見出し・テキスト・
 * リッチテキストは空の値と仮の文字で始まるので、焦点を移すだけでそのまま打てる。
 */
function focusFirstField(): void {
    const panel = window.document.querySelector(
        '[aria-label="ブロックの追加と操作"]',
    );
    const field = panel?.querySelector<HTMLElement>(
        'input[id^="prop-"]:not([type="checkbox"]), textarea[id^="prop-"], [id^="prop-"][contenteditable="true"]',
    );

    if (!field) {
        return;
    }

    // 画面が入れた焦点。打ち始めるまで下の帯（公開する）を隠さない（上の autoFocused）
    autoFocused = field;
    field.focus();

    if (
        field instanceof HTMLInputElement ||
        field instanceof HTMLTextAreaElement
    ) {
        field.select();

        return;
    }

    const selection = window.getSelection();

    if (selection) {
        const range = window.document.createRange();
        range.selectNodeContents(field);
        selection.removeAllRanges();
        selection.addRange(range);
    }
}

function move(direction: -1 | 1): void {
    if (selected.value) {
        nudge({
            id: selected.value.node.id,
            direction: direction === -1 ? 'up' : 'down',
        });
    }
}

/**
 * いま置ける再利用ブロック（**置けないものは出さない**）。
 *
 * 「押せるのに置けない」ボタンを出さないのは、パレットと同じ作法である（§4.1）。
 */
const placeableReusables = computed(() =>
    props.reusables
        .map((component) => ({
            component,
            placement: placementFor(
                component,
                selected.value,
                entries.value,
                document.value.root,
                specs.value,
            ),
        }))
        .filter(
            (
                item,
            ): item is {
                component: ReusableSummary;
                placement: { parentId: string; rootType: string };
            } => item.placement !== null,
        ),
);

/**
 * 参照を置く。
 *
 * **入れ物の type は起点と同じ**にする（組み立ては `lib/reusable`）。
 * 揃っていないとサーバーが断るので、画面の都合で決めない。
 */
async function placeReusable(item: {
    component: ReusableSummary;
    placement: { parentId: string; rootType: string };
}): Promise<void> {
    if (blocked.value) {
        return;
    }

    const ok = await run(
        placeCommand(
            item.placement.parentId,
            item.placement.rootType,
            item.component.id,
            version.value,
        ),
    );

    if (ok) {
        canvas.value?.reload();
    }
}

/**
 * 再利用 component の編集画面へ行く（§4.5）。
 *
 * **同じ画面で編集させない。** 実体は複数のページから参照されるので、
 * どのページから開いても同じものを直すことになる — それは別の画面である。
 */
function openReusable(id: string): void {
    if (id !== '' && props.reusableEditUrl) {
        router.visit(`${props.reusableEditUrl}/${id}`);
    }
}

/**
 * 切り離す（`component.detach`）。
 *
 * **戻せない**ので確認が要るが、**その確認はサーバーが出す**（`ConfirmationGate`）。
 * 画面が独自に訊くと、文面が 2 か所になり、片方だけ直る。
 */
async function detach(): Promise<void> {
    if (!selected.value || blocked.value) {
        return;
    }

    if (await run(detachCommand(selected.value.node.id, version.value))) {
        canvas.value?.reload();
    }
}

/**
 * Inspector からの変更（`component.update`）。
 *
 * 送るのは触った 1 項目だけ。サーバー側が現在値と混ぜ、**`null` は外す**の意味になる。
 */
/**
 * 欄の変更は**順に**送る（UX 一周 第 17 周）。
 *
 * 以前は保存中に来た変更を黙って捨てていた。欄 A を離れた保存が終わる前に欄 B を離れると、
 * B の変更が消えた。待ち行列に積み、前の応答が返ってから（新しい版で）送る。
 */
let updates: Promise<void> = Promise.resolve();

/**
 * ページ設定へ戻るのは、打ちかけを送り終えてから（UX 一周 10 周目 R10-2: 打ってすぐ戻ると、保存が
 * 届く前に設定画面を読み、「公開中の内容と同じです」と出て「公開する」が押せなかった）。
 */
const leaving = ref(false);

async function goBack(): Promise<void> {
    if (leaving.value) {
        return;
    }

    leaving.value = true;
    inspector.value?.flush();
    await updates;
    router.visit(props.backUrl);
}

/**
 * この画面から、そのまま公開する（触り心地 2026-11-05）。
 *
 * 以前は 置く→「ページ設定へ戻る」→「公開する」と画面を行き来しないと公開できなかった
 * （競合 3 社は編集中の画面に公開がある。docs/feel-bar.md）。送る先は設定画面と同じ
 * `/publish` 1 本で、判定（空のページは公開できない 等）もサーバーのまま。
 *
 * - 打ちかけを送り終えてから公開する（goBack と同じ理由: R10-2）
 * - 予約中のページには出さない。ここで押すと予約を「いま」で上書きしてしまう
 */
const publishing = ref(false);
const publishHereError = ref<string | null>(null);
const isLive = computed(() => props.page.publishState === 'published');
/** 打ちかけ・保存中の変更がある（まだ版になっていないが、公開すれば出る） */
const changing = computed(() => dirty.value || saving.value);
const canPublishHere = computed(
    () =>
        hasBlocks.value &&
        (!isLive.value || draftAhead.value || changing.value),
);
const publishHereLabel = computed(() => {
    if (publishing.value) {
        return '公開しています…';
    }

    if (!isLive.value) {
        return '公開する';
    }

    return draftAhead.value || changing.value ? '変更を公開する' : '公開中';
});
/**
 * 「公開する」を止めるのは競合のときだけ（UX 一周 23 周目 #1）。
 *
 * 以前は保存中も止めていた。入力欄に打った直後に押すと、押した瞬間に欄から離れて保存が始まり、
 * ボタンが disabled になって **1 回目の押しが捨てられた**（3 回中 2 回。2 秒待てば通った）。
 * 押した操作は受け付けて、保存が済んでから続けて公開する（部品を置くときの R4-3 と同じ考え）。
 */
const publishDisabled = computed(
    () => conflict.value !== null || publishing.value || !canPublishHere.value,
);

async function publishHere(): Promise<void> {
    if (publishDisabled.value) {
        return;
    }

    // 押した見た目はすぐ（保存の待ちは「公開しています…」の中で待つ）
    publishing.value = true;
    publishHereError.value = null;
    inspector.value?.flush();
    await updates;

    if (conflict.value !== null || error.value !== null) {
        publishing.value = false;
        publishHereError.value =
            '変更を保存できなかったので、公開していません。保存の表示を確かめてから、もう一度押してください';

        return;
    }

    // 保存した結果、公開中の内容と同じだった（打って元に戻した 等）
    if (!hasBlocks.value || (isLive.value && !draftAhead.value)) {
        publishing.value = false;

        return;
    }

    router.post(
        `/admin/pages/${props.page.id}/publish`,
        { publishedAt: null, from: 'editor' },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                publishHereError.value =
                    Object.values(errors)[0] ?? '公開できませんでした';
            },
            onFinish: () => {
                publishing.value = false;
            },
        },
    );
}

/*
 * 打ってすぐブラウザの「戻る」・サイドバーで離れても、最後の打ち込みを消さない（14 周目 R14-1）。
 * 窓は出さず、保存を確定してから移る。確定できなかったとき（失敗・競合）だけ窓で聞く。
 */
useLeaveGuard(() => !leaving.value && (dirty.value || saving.value), {
    settle: async () => {
        inspector.value?.flush();
        await updates;

        return error.value === null && conflict.value === null;
    },
});

/*
 * スマホでは「公開する」を画面の下に留める（UX 一周 15 周目 T1: 上の帯にあると、下の欄で打ち終えた所から
 * 見えなかった）。**打っている間は出さない** — キーボードが出た画面で、下に留めた帯が入力欄を隠さないように。
 * プレビュー（iframe）の中で打つときは、親の画面からは iframe に焦点があるように見えるので、それも「打っている」。
 */
const typing = ref(false);
const NOT_TYPED = [
    'checkbox',
    'radio',
    'button',
    'submit',
    'file',
    'range',
    'color',
];

function isTypingTarget(el: Element | null): boolean {
    if (el === null) {
        return false;
    }

    if (el.tagName === 'IFRAME' || el.tagName === 'TEXTAREA') {
        return true;
    }

    if (el.tagName === 'INPUT') {
        return !NOT_TYPED.includes((el as HTMLInputElement).type);
    }

    return (el as HTMLElement).isContentEditable === true;
}

/*
 * **置いた直後に画面が入れた焦点は、まだ「打っている」と数えない**（直し 4 回目・触り心地 16 周目 N1 が赤）。
 *
 * ブロックを置くと最初の欄へ焦点を移す（`focusFirstField`・そのまま打てるように）。焦点だけで帯を隠すと、
 * スマホ幅で「見出しを置いて始める」を押した直後に、下の「公開する」も保存の表示も画面から消えていた
 * （実測: 37fe80a5 から。11ad755e では出ていた）。押した人は何もしていないのに、公開の入口が消える。
 * iPhone の Safari は、押した操作から間をおいた focus() ではキーボードを出さないとされる（実機は未確認）
 * — そうならキーボードが無いのに帯だけ消える。
 *
 * だから、画面が入れた焦点は**その欄で打ち始める（キー・入力・指で触る）か、キーボードが実際に出る
 * （visualViewport が縮む）まで**「打っている」にしない。自分で欄を触った焦点は今までどおりすぐ隠す（15 周目 T1）。
 */
let autoFocused: HTMLElement | null = null;

/**
 * 画面の見えている高さが 3/4 未満に縮んだら、キーボードが出ているとみなす。
 * 指で拡大しても見えている高さは縮むので、拡大していないとき（scale がほぼ 1）だけ数える（採点 P2）。
 */
function keyboardShown(): boolean {
    const viewport = window.visualViewport;

    return (
        viewport !== null &&
        viewport !== undefined &&
        viewport.scale < 1.01 &&
        viewport.height < window.innerHeight * 0.75
    );
}

function syncTyping(): void {
    // focusout の直後はまだ次の焦点が決まっていないので、1 拍おいて見る
    window.setTimeout(() => {
        const active = window.document.activeElement;

        if (autoFocused !== null && active !== autoFocused) {
            autoFocused = null;
        }

        typing.value =
            isTypingTarget(active) && (autoFocused === null || keyboardShown());
    }, 0);
}

/** 画面が入れた焦点の欄で、本人が打ち始めた・触った */
function startTyping(event: Event): void {
    if (
        autoFocused !== null &&
        event.target instanceof Node &&
        autoFocused.contains(event.target)
    ) {
        autoFocused = null;
        syncTyping();
    }
}

const TYPING_STARTS = ['keydown', 'beforeinput', 'pointerdown'] as const;

onMounted(() => {
    window.document.addEventListener('focusin', syncTyping);
    window.document.addEventListener('focusout', syncTyping);
    TYPING_STARTS.forEach((name) =>
        window.document.addEventListener(name, startTyping, true),
    );
    window.visualViewport?.addEventListener('resize', syncTyping);
});

onBeforeUnmount(() => {
    window.document.removeEventListener('focusin', syncTyping);
    window.document.removeEventListener('focusout', syncTyping);
    TYPING_STARTS.forEach((name) =>
        window.document.removeEventListener(name, startTyping, true),
    );
    window.visualViewport?.removeEventListener('resize', syncTyping);
});

/*
 * 下の帯は本文（この画面の外枠）の左端と幅に合わせる。タブレットの幅ではサイドバーが出ていて、
 * 画面の端から端に置くとサイドバーに重なる。サイドバーを畳む・開くと外枠の幅が替わるので、大きさを見張る。
 */
const editorRoot = useTemplateRef<HTMLElement>('editorRoot');
const barBox = ref<Record<string, string>>({ left: '0px', width: '100%' });
let barObserver: ResizeObserver | null = null;

function placeBar(): void {
    const el = editorRoot.value;

    if (!el) {
        return;
    }

    const r = el.getBoundingClientRect();
    barBox.value = { left: `${r.left}px`, width: `${r.width}px` };
}

onMounted(() => {
    placeBar();
    barObserver = new ResizeObserver(placeBar);

    if (editorRoot.value) {
        barObserver.observe(editorRoot.value);
    }

    window.addEventListener('resize', placeBar);
});

onBeforeUnmount(() => {
    barObserver?.disconnect();
    window.removeEventListener('resize', placeBar);
});

/** 右の欄（Inspector）。プレビューで押した文字の欄へカーソルを移すのに使う */
const inspector =
    useTemplateRef<InstanceType<typeof InspectorPanel>>('inspector');

/**
 * プレビューで押した（UX 一周 3 周目 M4・9/27 の周から 3 回目の指摘）。
 *
 * 見出しを押して打っても、何も入らなかった。プレビューは公開と同じ描画を**読むだけ**なので
 * そこには書かない（`CanvasFrame` の約束）。押した文字の欄へカーソルを移し、
 * 押してすぐ打てばその欄に入るようにする。打つ手が止まればプレビューも変わる。
 */
async function selectFromCanvas(
    id: string,
    text: string | null = null,
): Promise<void> {
    selectedId.value = id;
    await nextTick();
    inspector.value?.focusField(text);
}

function update(changes: Record<string, unknown>, nodeId?: string): void {
    if (conflict.value !== null) {
        return;
    }

    // 入力中の自動保存は時間差で届く。**打っていたブロック**へ送る（UX 一周 3 周目 M5）
    const target = nodeId ?? selected.value?.node.id;

    if (!target) {
        return;
    }

    updates = updates.then(async () => {
        if (conflict.value !== null) {
            return;
        }

        const ok = await run({
            operation: 'component.update',
            target,
            changes,
            expectedVersion: version.value,
        });

        if (ok) {
            recovery.saved(target, changes);
            canvas.value?.reload();
        }
    });
}

async function duplicate(): Promise<void> {
    if (!selected.value || blocked.value) {
        return;
    }

    const ok = await run({
        operation: 'component.duplicate',
        target: selected.value.node.id,
        expectedVersion: version.value,
    });

    if (ok) {
        canvas.value?.reload();
    }
}

/** 1 回目は確認要求が返る。承認は `accept()`（= 同じ command を token 付きで送り直す） */
function remove(): void {
    if (!selected.value || blocked.value) {
        return;
    }

    void run({
        operation: 'component.delete',
        target: selected.value.node.id,
        expectedVersion: version.value,
    });
}

/**
 * 確認ダイアログの「はい」。**削除と復元の両方がここへ来る。**
 *
 * どちらだったかは `accept()` が知っている（確認待ちの中身を持っているのはあちら）。
 * ここは**承認されたあとの画面の後始末**だけをする — 確認の口を 2 つ作らない。
 */
async function confirmPending(): Promise<void> {
    const removed = selectedId.value;
    const ok = await accept();

    if (!ok) {
        return;
    }

    if (selectedId.value === removed) {
        selectedId.value = null;
    }

    // 復元だった場合は履歴を閉じる（戻した先で続きを編集する）
    historyOpen.value = false;
    canvas.value?.reload();
}

function rememberDraft(changes: Record<string, unknown>, target: string): void {
    recovery.remember(target, changes);
}

function reloadPage(): void {
    reloadConfirm.value = true;
}

function confirmReload(): void {
    leaving.value = true;
    window.location.reload();
}

async function copyUnsaved(): Promise<void> {
    try {
        await navigator.clipboard.writeText(recovery.text.value);
        copyMessage.value = 'コピーしました。';
    } catch {
        copyMessage.value = '下の欄を選んでコピーしてください。';
    }
}

async function restoreUnsaved(): Promise<void> {
    if (recovering.value || blocked.value) {
        return;
    }

    recovering.value = true;
    recoveryMessage.value = '';

    try {
        for (const draft of [...recovery.drafts.value]) {
            if (
                !entries.value.some((entry) => entry.node.id === draft.target)
            ) {
                recoveryMessage.value =
                    '元のブロックが見つかりません。残っている文をコピーして、新しいブロックへ貼り付けてください。';
                break;
            }

            const ok = await run({
                operation: 'component.update',
                target: draft.target,
                changes: draft.changes,
                expectedVersion: version.value,
            });

            if (!ok) {
                break;
            }

            recovery.saved(draft.target, draft.changes);
            selectedId.value = draft.target;
        }

        recoveryAvailable.value = recovery.drafts.value.length > 0;
        canvas.value?.reload();
    } finally {
        recovering.value = false;
    }
}
</script>

<template>
    <Head :title="`${page.title} — ブロック編集`" />

    <!-- スマホでは内容に合わせてページを伸ばす。3 ペインを画面の高さへ押し込まない。 -->
    <div
        ref="editorRoot"
        class="page-editor flex min-h-[calc(100dvh-4rem)] flex-col gap-3 p-4 max-lg:pb-24 lg:h-[calc(100dvh-4rem)]"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold break-words">
                    {{ page.title }}
                </h1>
                <span class="text-sm text-muted-foreground">ブロック編集</span>
                <!-- 予約中は編集画面でも分かるようにする。公開操作はページ設定側にある -->
                <span
                    v-if="page.scheduled"
                    class="text-sm text-muted-foreground"
                    data-testid="page-editor-scheduled"
                >
                    公開予約中（{{ page.publishedAtLocal }}
                    {{ page.timezone }}）
                </span>
                <!--
                    履歴・戻る・公開は 1 組で、題の行の右端に置く（2026-11-05）。
                    操作の帯に入れると、ボタンを 44px にした分で帯が 2 行になり、
                    右の欄（追加する）が縮んで「画像を選択」が隠れた（UX 一周 3 周目 N5 の回帰が赤）
                -->
                <div class="ml-auto flex items-center gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="blocked"
                        data-testid="editor-history"
                        @click="openHistory"
                    >
                        履歴
                    </Button>
                    <Button variant="secondary" as-child>
                        <Link :href="backUrl" @click.prevent="goBack"
                            >ページ設定へ戻る</Link
                        >
                    </Button>
                    <Button
                        v-if="!page.scheduled"
                        class="hidden lg:inline-flex"
                        :disabled="publishDisabled"
                        :aria-busy="publishing"
                        :title="
                            hasBlocks
                                ? undefined
                                : 'ブロックを 1 つ置くと公開できます'
                        "
                        data-testid="editor-publish-button"
                        @click="publishHere"
                    >
                        {{ publishHereLabel }}
                    </Button>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <!-- 画面幅の切替。名前も幅も theme.json 由来 -->
                <!-- スマホ幅では折り返す（UX 一周 2026-09-28: 「パソコン」が画面の外で切れていた） -->
                <!--
                    画面幅の切替はタブレット以上だけ（UX 一周 15 周目 T6: スマホでは上の帯が 5 段に折れて
                    画面の上の半分ほどを使い、キャンバスと「追加する」を下へ押していた。スマホの画面は、もうスマホの幅）
                -->
                <div
                    class="hidden flex-wrap items-center gap-1 md:flex"
                    role="group"
                    aria-label="画面幅"
                >
                    <Button
                        size="sm"
                        :variant="breakpoint === null ? 'default' : 'outline'"
                        :disabled="blocked"
                        data-testid="breakpoint-all"
                        @click="breakpoint = null"
                    >
                        すべての画面幅
                    </Button>
                    <Button
                        v-for="spec in breakpoints"
                        :key="spec.name"
                        size="sm"
                        :variant="
                            breakpoint === spec.name ? 'default' : 'outline'
                        "
                        :disabled="blocked"
                        :title="spec.condition"
                        :data-testid="`breakpoint-${spec.name}`"
                        @click="breakpoint = spec.name"
                    >
                        {{ spec.label ?? spec.name }}
                    </Button>
                </div>

                <!-- 取り消し／やり直し。**押せるかどうかを見た目だけで伝えない** -->
                <div
                    class="flex items-center gap-1"
                    role="group"
                    aria-label="取り消しとやり直し"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="blocked || undoStack.length === 0"
                        :title="
                            undoStack.length === 0
                                ? '取り消せる操作がありません'
                                : '取り消す（Ctrl/⌘ + Z）'
                        "
                        data-testid="editor-undo"
                        @click="stepBack"
                    >
                        取り消す
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="blocked || redoStack.length === 0"
                        :title="
                            redoStack.length === 0
                                ? 'やり直せる操作がありません'
                                : 'やり直す（Ctrl/⌘ + Shift + Z）'
                        "
                        data-testid="editor-redo"
                        @click="stepForward"
                    >
                        やり直す
                    </Button>
                </div>

                <!--
                    保存の表示は、出しうる文の中で一番長い幅を先に取っておく（UX 一周 5 周目 R4-3・R5-3）。
                    文の長さが「保存済み（下書き）」→「入力中…」→「保存中…」と変わるたびに上の帯が折り返し、
                    部品や「ページ設定へ戻る」が押した瞬間に 44px 動いて、押しが別の所に落ちていた。
                    同じ升に全部の文を重ね、見えるのは今の 1 つだけにする。
                -->
                <!-- 保存の表示は、狭い画面では下の帯（editor-bottom-bar）に出す（同じ文を 2 か所に出さない） -->
                <span class="hidden text-sm lg:grid">
                    <span
                        v-for="text in SAVE_STATES"
                        :key="text"
                        class="invisible col-start-1 row-start-1"
                        aria-hidden="true"
                        >{{ text }}</span
                    >
                    <span
                        class="col-start-1 row-start-1"
                        :class="
                            saveState === '保存できませんでした'
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                        data-testid="editor-save-state"
                    >
                        {{ saveState }}
                    </span>
                </span>
            </div>
        </div>

        <FrontPagePanel
            :page-id="page.id"
            :offer="frontPageOffer"
            :selected="frontPageSelected"
        />

        <p
            v-if="publishHereError"
            role="alert"
            class="text-sm text-destructive"
            data-testid="editor-publish-error"
        >
            {{ publishHereError }}
        </p>

        <section
            v-if="recoveryAvailable"
            role="status"
            class="border p-3 text-sm"
            data-testid="editor-recovery"
        >
            <p>保存されていない文があります。</p>
            <p>
                戻すと、その欄の内容をこの文に替えて下書き保存します。公開ページは変わりません。
            </p>
            <div class="my-3 flex flex-wrap items-center gap-4">
                <Button
                    :disabled="blocked || recovering"
                    data-testid="editor-restore-unsaved"
                    @click="restoreUnsaved"
                    >戻す</Button
                >
                <button type="button" class="underline" @click="copyUnsaved">
                    文をコピーする
                </button>
            </div>
            <p>{{ recoveryMessage }}</p>
            <textarea
                readonly
                :value="recovery.text.value"
                aria-label="保存されていない文"
                class="mt-2 w-full border p-2"
            />
        </section>

        <Dialog :open="reloadConfirm" @update:open="reloadConfirm = $event">
            <DialogContent data-testid="editor-reload-confirm">
                <DialogHeader>
                    <DialogTitle>読み込み直しますか？</DialogTitle>
                    <DialogDescription
                        >この画面で打った文が消えます。保存できなかった文は、このブラウザに一時保存して、読み込み直したあとに戻せます。必要なら先にコピーしてください。</DialogDescription
                    >
                </DialogHeader>
                <p v-if="!recovery.stored.value" role="alert">
                    このブラウザには一時保存できませんでした。読み込み直す前に、下の文をコピーしてください。
                </p>
                <textarea
                    readonly
                    :value="recovery.text.value"
                    aria-label="保存されていない文"
                    data-testid="editor-unsaved-copy-text"
                    class="min-h-32 w-full border p-2"
                />
                <Button
                    variant="secondary"
                    data-testid="editor-copy-unsaved"
                    @click="copyUnsaved"
                    >文をコピーする</Button
                >
                <p role="status">{{ copyMessage }}</p>
                <DialogFooter>
                    <Button
                        variant="secondary"
                        data-testid="editor-cancel-reload"
                        @click="reloadConfirm = false"
                        >この画面に残る</Button
                    >
                    <Button
                        data-testid="editor-confirm-reload"
                        @click="confirmReload"
                        >読み込み直す</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <p
            v-if="conflict"
            class="rounded-md border border-destructive bg-destructive/10 px-3 py-2 text-sm"
            data-testid="editor-conflict"
        >
            {{ conflict }}
            打った文は保存できていません。読み込み直す前に、文をコピーできます。
            <button type="button" class="underline" @click="reloadPage">
                読み込み直す
            </button>
        </p>

        <p
            v-else-if="error"
            class="rounded-md border border-destructive bg-destructive/10 px-3 py-2 text-sm"
            data-testid="editor-error"
        >
            {{ error }}
        </p>

        <div
            class="grid min-h-0 flex-1 grid-cols-1 gap-3 lg:grid-cols-[16rem_1fr_14rem]"
        >
            <!-- Layers -->
            <section
                class="min-h-0 rounded-xl border bg-muted/30 p-3 lg:overflow-y-auto"
                aria-label="ブロックの階層"
            >
                <h2 class="mb-2 text-sm font-medium">階層</h2>

                <div
                    v-if="!hasBlocks"
                    class="space-y-3 text-sm text-muted-foreground"
                    data-testid="editor-empty"
                >
                    <p>
                        まだブロックがありません。「追加する」から「見出し」や「テキスト」を押すと、
                        ページの区切り（セクション）ごと置かれます。
                    </p>
                    <!-- 空のページの入口を大きく出す。何から押せばよいか迷わせない -->
                    <Button
                        v-if="headingEntry"
                        class="w-full"
                        :disabled="locked"
                        data-testid="editor-empty-start"
                        @click="insert(headingEntry)"
                    >
                        見出しを置いて始める
                    </Button>
                </div>

                <template v-else>
                    <p class="mb-2 text-xs text-muted-foreground">
                        選んだブロックの下にあるボタンか、ドラッグで移動できます。キーボードでは
                        <kbd>Alt</kbd> + <kbd>↑</kbd><kbd>↓</kbd>（前後へ）/
                        <kbd>→</kbd>（中へ）/ <kbd>←</kbd>（外へ）。
                    </p>

                    <LayersTree
                        :nodes="rootChildren"
                        :parent-id="document.root.id"
                        :selected-id="selectedId"
                        :labels="labels"
                        :dragging="dragging"
                        :can-drop-into="canDropInto"
                        :blocked="blocked"
                        :can-nudge="canNudge"
                        @select="selectedId = $event"
                        @dragging="dragging = $event"
                        @place="place"
                        @nudge="nudge"
                    />
                </template>
            </section>

            <!--
                Canvas。**ここが主役**なので、左右の区画より前に出す（R-9 Stage 0 ④）。
                左右は `bg-muted/30` で沈め、こちらは地の色 + 影で持ち上げる。
                全部おなじ濃さの箱だと、どこを見ればよいか分からない。
            -->
            <section
                class="relative h-[28rem] min-h-0 overflow-hidden rounded-xl border bg-background shadow-sm lg:h-auto"
                aria-label="ページの表示"
            >
                <CanvasFrame
                    ref="canvas"
                    :src="canvasUrl"
                    :selected-id="selectedId"
                    :selectable-ids="selectableIds"
                    :width="canvasWidth"
                    @select="selectFromCanvas"
                />
                <!--
                    空のページでは、Canvas にサイトの枠（ヘッダーとフッター）しか出ず、
                    真ん中が白いままだった（UX 一周 2026-09-28）。壊れているのか、
                    ここに何が出るのかが分からない。**空のあいだだけ**、ここで何をするかを出す。
                -->
                <div
                    v-if="!hasBlocks"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center p-6"
                    data-testid="canvas-empty"
                >
                    <div
                        class="pointer-events-auto max-w-sm rounded-xl border border-dashed bg-background p-6 text-center shadow-sm"
                    >
                        <p class="font-medium">このページはまだ空です</p>
                        <p class="mt-2 text-sm text-muted-foreground">
                            置いたブロックは、公開したときと同じ見た目でここに表示されます。
                        </p>
                        <Button
                            v-if="headingEntry"
                            class="mt-4"
                            :disabled="locked"
                            data-testid="canvas-empty-start"
                            @click="insert(headingEntry)"
                        >
                            見出しを置いて始める
                        </Button>
                    </div>
                </div>
            </section>

            <!-- 追加と操作 -->
            <section
                class="min-h-0 space-y-4 rounded-xl border bg-muted/30 p-3 lg:overflow-y-auto"
                aria-label="ブロックの追加と操作"
            >
                <!--
                    **「追加する」は右の欄の上に留める**（UX 一周 3 周目 N2）。
                    ブロックを置くと下に「選択中のブロック」の欄が伸び、そこを触るうちに
                    右の欄が下がって、次のブロックを足す部品が見えなくなっていた。
                -->
                <div
                    class="sticky -top-3 z-10 -mx-3 -mt-3 border-b bg-muted px-3 pt-3 pb-3"
                    data-testid="editor-palette"
                >
                    <h2 class="mb-2 text-sm font-medium">追加する</h2>
                    <!--
                        **タイルで並べる**（R-9 Stage 0 ②）。
                        無印の帯ボタンを縦に積むと、**8 種**が同じ見た目の列になり、
                        **探すのに読むしかない**。絵があれば形で選べる。
                        （パレットに出るのは 8 種。`html` は**新規追加させない**が、
                        既に document に在るものは編集できるので、**絵の対応表は 9 型**ある）
                        絵はあくまで手がかりなので、**名前は必ず一緒に出す**
                        （絵だけにすると、知らない人には何も分からない）。
                        **背の低い PC の画面（1366×768 など）では、タイルを 1 行の帯にして低くする**
                        （UX 一周 23 周目 #7: 留めた「追加する」が右の欄の高さをほとんど取り、画像を置くと
                        「画像を選択」が下端で見切れた）。
                    -->
                    <div class="grid grid-cols-2 gap-1">
                        <button
                            v-for="spec in components"
                            :key="spec.type"
                            type="button"
                            class="flex flex-col items-center gap-1 rounded-md border bg-background px-1 py-2 text-xs hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 lg:[@media(max-height:56rem)]:flex-row lg:[@media(max-height:56rem)]:justify-start lg:[@media(max-height:56rem)]:gap-2 lg:[@media(max-height:56rem)]:px-2 lg:[@media(max-height:56rem)]:py-1.5"
                            :disabled="locked || plan(spec) === null"
                            :title="paletteTitle(spec)"
                            :data-testid="`palette-${spec.type}`"
                            @click="insert(spec)"
                        >
                            <component
                                :is="blockIcons[spec.type] ?? BlockDefaultIcon"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span>{{ spec.label }}</span>
                        </button>
                    </div>
                    <!--
                        押せない理由を**文字で**出す（UX 一周 2026-09-27）。
                        以前は `title` にしか無く、ホバーしない人には「押しても何も起きない」
                        だけが残っていた。
                    -->
                    <p
                        v-if="paletteHint"
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        role="status"
                        data-testid="palette-hint"
                    >
                        {{ paletteHint }}
                    </p>
                </div>

                <!--
                    再利用ブロックを置く（§4.5）。

                    **置けるかどうかは、その起点の type を置けるかと同じ**なので、
                    追加先の判断をそのまま使い回している（`lib/reusable`）。
                    未公開のものも置けるが、**公開ページには出ない**ので、そう言う。
                -->
                <div v-if="placeableReusables.length > 0">
                    <h2 class="mb-2 text-sm font-medium">
                        再利用ブロックを置く
                    </h2>
                    <div class="flex flex-col gap-1">
                        <Button
                            v-for="item in placeableReusables"
                            :key="item.component.id"
                            size="sm"
                            variant="outline"
                            :disabled="blocked"
                            :title="
                                item.component.published
                                    ? undefined
                                    : 'まだ公開されていないので、公開ページには出ません'
                            "
                            :data-testid="`palette-reusable-${item.component.key}`"
                            @click="placeReusable(item)"
                        >
                            {{ item.component.label
                            }}<span
                                v-if="!item.component.published"
                                class="ml-1 text-xs text-muted-foreground"
                                >（未公開）</span
                            >
                        </Button>
                    </div>
                </div>

                <div>
                    <h2 class="mb-2 text-sm font-medium">選択中のブロック</h2>

                    <p
                        v-if="!selected"
                        class="text-sm text-muted-foreground"
                        data-testid="editor-no-selection"
                    >
                        ブロックを選ぶと操作できます。
                    </p>

                    <div v-else class="space-y-2">
                        <p class="text-sm" data-testid="editor-selection-label">
                            {{
                                labels[selected.node.type] ?? selected.node.type
                            }}
                        </p>
                        <div class="flex flex-col gap-1">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="blocked || !canMoveUp"
                                data-testid="editor-move-up"
                                @click="move(-1)"
                            >
                                上へ
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="blocked || !canMoveDown"
                                data-testid="editor-move-down"
                                @click="move(1)"
                            >
                                下へ
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="blocked"
                                data-testid="editor-duplicate"
                                @click="duplicate"
                            >
                                複製
                            </Button>
                            <!--
                                削除は**一番強い見た目にしない**（R-9 Stage 0 ③）。
                                並んでいる操作の中で塗りつぶしの赤が最大の面積を持つと、
                                **目が最初にそこへ行く**。危ないものは「目立つ」のではなく
                                **「取り違えにくい」**であるべきで、
                                ページ設定の画面（`pages/Edit.vue` のゴミ箱）と同じ形に揃える。
                                ▷ 同じ話が `FieldRowEditor` にもある（`docs/BACKLOG.md` R-9）。
                            -->
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                :disabled="blocked"
                                data-testid="editor-delete"
                                @click="remove"
                            >
                                削除
                            </Button>
                        </div>

                        <InspectorPanel
                            v-if="specs[selected.node.type]"
                            ref="inspector"
                            class="border-t pt-3"
                            :node="selected.node"
                            :spec="specs[selected.node.type]"
                            :styles="styles"
                            :tokens="tokens"
                            :breakpoint="breakpoint"
                            :sources="bindingSources"
                            :reusables="reusables"
                            :hidden-by-ancestor="hiddenByAncestor"
                            :blocked="locked"
                            :link-targets="linkTargets ?? []"
                            :token-labels="tokenLabels ?? {}"
                            @change="update"
                            :can-edit-reusable="reusableEditUrl !== null"
                            @edit-reusable="openReusable"
                            @detach="detach"
                            @dirty="markDirty"
                            @draft="rememberDraft"
                        />
                    </div>
                </div>
            </section>

            <!--
                気づき（アクセシビリティ）。**保存も公開も止めない**。

                **grid の最後に置く**（R-9 Stage 0 ①）。3 列ぶんを占めるこの子を
                Canvas より前に置いていたため、**気づきが 1 件でもあると**
                CSS grid の既定（sparse）で後の子が前の空きへ戻れず、
                **Canvas が狭い列へ落ちていた**（実測: 1440 幅の画面で **半分以下**まで縮み、
                Inspector と幅が入れ替わる。空の見出し 1 つで再現。数値は
                `docs/PHASE9_16_SPEC.md` §0.2 と隔離レーンの e2e に置いてある —
                **この file に画面幅の数値は書かない**）。
                DOM の最後に置けば、狭い画面でも最後に出るので `order-*` も要らない。
            -->
            <section
                v-if="findings.length > 0"
                class="min-h-0 overflow-y-auto rounded-xl border bg-muted/30 p-3 lg:col-span-3"
                aria-label="アクセシビリティの気づき"
                data-testid="a11y-panel"
            >
                <h2 class="mb-2 text-sm font-medium">
                    アクセシビリティの気づき（{{ findings.length }} 件）
                </h2>

                <ul class="space-y-1">
                    <li v-for="(finding, index) in findings" :key="index">
                        <button
                            type="button"
                            class="flex w-full items-start gap-2 rounded px-2 py-1 text-left text-xs hover:bg-muted"
                            :data-testid="`a11y-finding-${finding.rule}`"
                            @click="reveal(finding)"
                        >
                            <span
                                class="shrink-0 rounded px-1"
                                :class="
                                    finding.level === 'warning'
                                        ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                                        : 'bg-muted text-muted-foreground'
                                "
                            >
                                {{
                                    finding.level === 'warning'
                                        ? '要修正'
                                        : finding.level === 'todo'
                                          ? '未設定'
                                          : '要確認'
                                }}
                            </span>
                            <span>
                                {{ labels[finding.type] ?? finding.type }}:
                                {{ finding.message }}
                            </span>
                        </button>
                    </li>
                </ul>

                <p class="mt-2 text-xs text-muted-foreground">
                    ここでの指摘は保存や公開を止めません。直すかどうかは判断してください。
                </p>
            </section>
        </div>

        <!--
            スマホ・タブレットの「公開する」は画面の下に留める（UX 一周 15 周目 T1・タブレットの縦も同じ）。
            打っている間（キーボードが出ている間）は出さない。ページの終わりは帯の高さぶん空けてある（max-lg:pb-24）。
            sticky にしないのは、管理画面の main が overflow:auto で、sticky が効かないため（実測）
        -->
        <div
            v-if="!page.scheduled && !typing"
            :style="barBox"
            class="fixed bottom-0 z-20 flex items-center justify-between gap-3 border-t bg-background px-4 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] lg:hidden"
            data-testid="editor-bottom-bar"
        >
            <!-- 切らずに 2 行まで折り返して全部見せる（UX 一周 16 周目 N1: 「保存済み（公開ページにはまだ…」で切れていた） -->
            <span
                class="min-w-0 text-sm leading-snug text-muted-foreground"
                data-testid="editor-bottom-save-state"
            >
                {{ saveState }}
            </span>
            <Button
                class="shrink-0"
                :disabled="publishDisabled"
                :aria-busy="publishing"
                data-testid="editor-publish-button-bottom"
                @click="publishHere"
            >
                {{ publishHereLabel }}
            </Button>
        </div>
    </div>

    <!--
        版の一覧（§4.12）。**差分は出さない** — component の木の差分表示は
        独立した設計課題で、初期版には含めない。戻してから見る。
    -->
    <div
        v-if="historyOpen"
        class="fixed inset-0 z-50 flex items-start justify-end bg-black/30 p-4"
        @click.self="historyOpen = false"
    >
        <section
            class="max-h-full w-full max-w-md overflow-auto rounded-lg border bg-background p-4 shadow-lg"
            role="dialog"
            aria-label="このページの履歴"
            data-testid="editor-history-panel"
        >
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold">このページの履歴</h2>
                <Button size="sm" variant="ghost" @click="historyOpen = false">
                    閉じる
                </Button>
            </div>

            <p class="mb-3 text-sm text-muted-foreground">
                戻すと新しい下書きができます。公開中のページと過去のバージョンは
                変わりません。
            </p>

            <p
                v-if="history.length === 0"
                class="text-sm text-muted-foreground"
            >
                履歴がまだありません。
            </p>

            <ul v-else class="space-y-2">
                <li
                    v-for="entry in history"
                    :key="entry.revision"
                    class="flex items-center justify-between gap-3 rounded border p-2 text-sm"
                >
                    <div>
                        <div class="font-medium">
                            バージョン {{ entry.revision }}
                            <!-- 色だけに頼らない（既存 Admin の作法） -->
                            <span
                                v-if="entry.draft"
                                class="text-xs text-muted-foreground"
                                >（編集中）</span
                            >
                            <span
                                v-else-if="entry.published"
                                class="text-xs text-muted-foreground"
                                >（公開中）</span
                            >
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ entry.createdAt ?? '日時不明' }} /
                            {{ entry.autosave ? '自動保存' : '操作による保存' }}
                        </div>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="blocked || entry.draft"
                        :title="
                            entry.draft
                                ? 'いま編集しているバージョンです'
                                : 'このバージョンの内容で新しい下書きを作ります'
                        "
                        :data-testid="`editor-restore-${entry.revision}`"
                        @click="restoreTo(entry.revision)"
                    >
                        この状態に戻す
                    </Button>
                </li>
            </ul>
        </section>
    </div>

    <ConfirmationDialog
        :confirmation="confirmation"
        @confirm="confirmPending"
        @cancel="dismiss"
    />
</template>

<style scoped>
/* 画面幅や入力方式によらず、文字サイズを保ったまま押せる面積を確保する。
   チェックボックスは箱そのものを伸ばさない（16×44 の縦長の棒に見えていた・UX 一周 第10周）。
   押せる面積は、箱を包む label（「使う」まで）で取る */
.page-editor :deep(button:not([role='checkbox'])),
.page-editor :deep(input),
.page-editor :deep(select) {
    min-height: 44px;
}
</style>

/**
 * 再利用 component を画面から扱うための判断（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 画面の中に判断を置かない
 *
 * C5 で、判定（与えられたものが正しいか）を固めたのに**画面が作る payload**を
 * 一度も通しておらず、「押しても何も起きない」画面を出しかけた（f765cb7）。
 * だから**送るものを組み立てるところ**をここへ出して、単体で固める。
 *
 * ## サーバーが受ける形に揃える
 *
 * `component.detach` は `page.document.patch` の command である（独立した口は無い）。
 * 参照する入れ物の type は実体の起点と同じでなければならない、という規則も
 * ここで満たしてから送る。
 */
import { insertParent } from '@/lib/document-commands';
import type { TreeEntry } from '@/lib/document-commands';
import type {
    ComponentSpec,
    DocumentCommand,
    DocumentNode,
} from '@/types/document';

/** 一覧の 1 件（`GET /api/v1/components/reusable`） */
export interface ReusableSummary {
    id: string;
    key: string;
    label: string;
    /**
     * 起点の type。**参照する入れ物は同じ type でなければならない**（§4.5）。
     * これを知らずに置くと、サーバーが断る参照を作ってしまう。
     */
    rootType: string | null;
    published: boolean;
    publishedAt: string | null;
    archived: boolean;
}

/** その node は再利用 component を参照しているか */
export function isInstance(node: DocumentNode): boolean {
    return typeof node.instanceOf === 'string' && node.instanceOf !== '';
}

/** 参照している実体の id（参照していなければ null） */
export function instanceOf(node: DocumentNode): string | null {
    return isInstance(node) ? (node.instanceOf as string) : null;
}

/**
 * 画面に出す実体の名前。
 *
 * **見つからないときは id を出さない。** 利用者に ULID を見せても直せないので、
 * 「見つかりません」と言う（描画側も fail closed で出さないので、その説明にもなる）。
 */
export function instanceLabel(
    node: DocumentNode,
    components: ReusableSummary[],
): string | null {
    const id = instanceOf(node);

    if (id === null) {
        return null;
    }

    return components.find((c) => c.id === id)?.label ?? '見つかりません';
}

/**
 * 切り離しの command。
 *
 * **`page.document.patch` の command として送る。** 独立した口を作ると、
 * validator・確認ゲート・監査・楽観ロックを二重化するか穴にするかになる。
 */
export function detachCommand(
    nodeId: string,
    expectedVersion: number,
): DocumentCommand {
    return {
        operation: 'component.detach',
        target: nodeId,
        expectedVersion,
    };
}

/**
 * 参照を置く command。
 *
 * **入れ物の type は実体の起点と同じにする。** 揃っていないと、展開したときに
 * 置き場所の規則に反する木ができるのでサーバーが断る（§4.5）。
 * だから**実体の起点の type を渡してもらう**。
 */
export function placeCommand(
    parentId: string,
    rootType: string,
    reusableId: string,
    expectedVersion: number,
): DocumentCommand {
    return {
        operation: 'component.insert',
        parent: parentId,
        component: { type: rootType, instanceOf: reusableId },
        expectedVersion,
    };
}

/**
 * 影響の言い方。
 *
 * 問いは「**いま公開されている見た目がいくつ変わるか**」なので、
 * **公開中を主に**出し、下書きは分けて添える。大きく見せた警告は読まれなくなる。
 */
export function impactSummary(publicPages: number, draftPages: number): string {
    if (publicPages === 0 && draftPages === 0) {
        return 'まだどのページにも使われていません';
    }

    const head =
        publicPages === 0
            ? '公開中のページには、まだ使われていません'
            : `公開中の ${publicPages} ページに反映されます`;

    return draftPages === 0
        ? head
        : `${head}（下書きのみ ${draftPages} ページ）`;
}

/**
 * その実体を、いま置けるか。
 *
 * **入れ物の type は起点と同じ**なので、置ける場所は「その type を置ける場所」と
 * 同じである。だから**追加先を決める判断をそのまま使い回す**（`document-commands`）—
 * 再利用ブロックのために別の置き場所の規則を持たない。
 *
 * 起点の type が分からない実体は置けない（**選ばせない**）。
 */
export function placementFor(
    component: ReusableSummary,
    selected: TreeEntry | null,
    entries: TreeEntry[],
    root: DocumentNode,
    specs: Record<string, ComponentSpec>,
): { parentId: string; rootType: string } | null {
    const rootType = component.rootType;

    if (rootType === null) {
        return null;
    }

    const spec = specs[rootType];

    if (spec === undefined) {
        // 起点の component が registry から外れている。置けない
        return null;
    }

    const parent = insertParent(spec, selected, entries, root, specs);

    return parent === null ? null : { parentId: parent.id, rootType };
}

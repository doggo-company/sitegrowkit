/**
 * 編集画面が送る command の**組み立て**（Phase 9.6 / F-19・`PHASE9_6_SPEC.md` §4.1〜§4.3）。
 *
 * ## なぜ画面の外に置くのか
 *
 * P0-C5 で、判定（与えられた binding が正しいか）は 13 本の単体試験で固めたのに、
 * **画面が作る側**を一度も通していなかった。結果、「接続する」を押しても
 * サーバーが断るだけの画面を出しかけた（f765cb7）。
 *
 * 5 つの command は全部 SFC の中で組み立てられていて、守りは e2e だけだった。
 * **どこへ入れるか・どこへ動かすかは、画面を開かなくても決まる**。だからここに出す。
 *
 * ## ここで止める理由
 *
 * 受け付けない場所へは **command を作らない**（`null` を返す）。作って送れば
 * validator が断るが、画面には「押したのに何も起きない」だけが残る。
 * 押せないなら押せないと見せるのが、この層の仕事である（§4.1）。
 */
import type { ComponentSpec, DocumentNode } from '@/types/document';

/** 木を平らにした 1 行。`parent` が null なら根 */
export interface TreeEntry {
    node: DocumentNode;
    parent: DocumentNode | null;
    index: number;
}

/** 動かす向き。`in` は直前の兄弟の中へ、`out` は親の外へ */
export type MoveDirection = 'up' | 'down' | 'in' | 'out';

/** `component.move` が要る 2 つ。`after` が null なら先頭へ */
export interface MoveDestination {
    parent: string;
    after: string | null;
}

/**
 * その component を受け入れられる節点か。**Registry の宣言だけで決める。**
 *
 * 根（`page`）は宣言を持たないので、子は何でも受ける側に置く。
 */
export function acceptsChild(
    parent: DocumentNode,
    spec: ComponentSpec,
    specs: Record<string, ComponentSpec>,
): boolean {
    const parentSpec = specs[parent.type];
    const allowedChildren =
        parentSpec?.allowedChildren ?? (parent.type === 'page' ? ['*'] : []);

    return (
        spec.allowedParents.includes(parent.type) &&
        (allowedChildren.includes('*') || allowedChildren.includes(spec.type))
    );
}

/**
 * 追加先を決める。選んでいる節点 → その先祖 → 根 → 文書のどこか、の順に探す。
 *
 * **見つからなければ `null`**。呼ぶ側はボタンを押せなくする。
 */
export function insertParent(
    spec: ComponentSpec,
    selected: TreeEntry | null,
    entries: TreeEntry[],
    root: DocumentNode,
    specs: Record<string, ComponentSpec>,
): DocumentNode | null {
    const chain: DocumentNode[] = [];

    let cursor: TreeEntry | null = selected;

    while (cursor) {
        chain.push(cursor.node);

        const parentId: string | undefined = cursor.parent?.id;

        cursor = entries.find((e) => e.node.id === parentId) ?? null;
    }

    chain.push(root);

    const fromSelection = chain.find((node) => acceptsChild(node, spec, specs));

    if (fromSelection) {
        return fromSelection;
    }

    return (
        entries
            .map((e) => e.node)
            .find((node) => acceptsChild(node, spec, specs)) ?? null
    );
}

/**
 * 追加の段取り。`wrapper` があれば **入れ物ごと** 置く（UX 一周 2026-09-27）。
 *
 * 🔴 **空のページで「見出し」を押しても何も起きなかった。**見出しは
 * セクションの中にしか置けないので `insertParent()` は `null` を返し、ボタンは
 * 押せないまま、理由は `title`（ホバーしないと見えない）にしか無かった。
 * 初めて触る人は「壊れている」としか受け取れない。
 *
 * 置ける場所が無いときは、**置ける入れ物を探して、入れ物ごと 1 回で置く**。
 * command は 1 本（`component.insert` に子を持たせる）なので、取り消しも 1 回で戻る。
 * 入れ物は `wrappers` の順に試す（呼ぶ側はセクションを先頭に渡す）。
 */
export interface InsertPlan {
    parent: DocumentNode;
    /** これを先に置き、その中へ目的のブロックを入れる。直接置けるなら null */
    wrapper: ComponentSpec | null;
}

export function insertPlan(
    spec: ComponentSpec,
    selected: TreeEntry | null,
    entries: TreeEntry[],
    root: DocumentNode,
    specs: Record<string, ComponentSpec>,
    wrappers: ComponentSpec[],
): InsertPlan | null {
    const direct = insertParent(spec, selected, entries, root, specs);

    if (direct) {
        return { parent: direct, wrapper: null };
    }

    for (const wrapper of wrappers) {
        if (wrapper.type === spec.type) {
            continue;
        }

        // 入れ物の中へ本当に入るか（入れ物の種類の宣言だけで決める）
        const shell: DocumentNode = { id: '', type: wrapper.type, props: {} };

        if (!acceptsChild(shell, spec, specs)) {
            continue;
        }

        const parent = insertParent(wrapper, selected, entries, root, specs);

        if (parent) {
            return { parent, wrapper };
        }
    }

    return null;
}

/**
 * ページで最初の見出しなら、段を 1 にして置く（UX 一周 2026-09-28）。
 *
 * 見出しの既定の段は 2 なので、空のページで「見出し」を押した直後に
 * 「ページ最初の見出しが 2 段目です」（要修正）が出ていた。**最初の一手で注意される。**
 * 検査（`AccessibilityCheck`）は、最初の見出しが 1 段目であることを求めている。
 *
 * 見出しかどうかは Registry の宣言（`a11y.level`）だけで決める。型名は見ない。
 * 2 本目からは既定の段のまま（1 の次に 2 が来るので、段は飛ばない）。
 * 渡された `block` は書き換えず、新しい値を返す。
 */
export function withFirstHeadingLevel<
    T extends { props?: Record<string, unknown> },
>(
    block: T,
    spec: ComponentSpec,
    root: DocumentNode,
    specs: Record<string, ComponentSpec>,
): T {
    const levelProp = spec.a11y?.level;

    if (typeof levelProp !== 'string') {
        return block;
    }

    const values = spec.propsSchema[levelProp]?.values;

    if (Array.isArray(values) && !values.map(Number).includes(1)) {
        return block;
    }

    const hasHeading = (node: DocumentNode): boolean =>
        typeof specs[node.type]?.a11y?.level === 'string' ||
        (node.children ?? []).some(hasHeading);

    if (hasHeading(root)) {
        return block;
    }

    return { ...block, props: { ...(block.props ?? {}), [levelProp]: 1 } };
}

/** その節点の内側にあるか（自分の中へ自分を入れさせないため） */
export function contains(node: DocumentNode, id: string): boolean {
    return (node.children ?? []).some(
        (child) => child.id === id || contains(child, id),
    );
}

/** 掴んでいるものを、その節点の中へ落とせるか */
export function canDropInto(
    dragged: TreeEntry | null,
    parent: TreeEntry | null,
    specs: Record<string, ComponentSpec>,
): boolean {
    const spec = dragged ? specs[dragged.node.type] : undefined;

    if (!dragged || !parent || !spec) {
        return false;
    }

    // 自分自身の中、自分の子孫の中へは入れられない
    if (
        parent.node.id === dragged.node.id ||
        contains(dragged.node, parent.node.id)
    ) {
        return false;
    }

    return acceptsChild(parent.node, spec, specs);
}

/**
 * その向きへ動かしたときの行き先。動かせなければ `null`。
 *
 * `after` は **行き先の親が持っている兄弟**でなければならない。ここを間違えると
 * 「もう居ない節点の後ろへ」という command ができ、送っても届かない。
 */
export function moveDestination(
    entry: TreeEntry,
    direction: MoveDirection,
    entries: TreeEntry[],
    specs: Record<string, ComponentSpec>,
): MoveDestination | null {
    const spec = specs[entry.node.type];

    if (!entry.parent || !spec) {
        return null;
    }

    const brothers = entry.parent.children ?? [];

    if (direction === 'up' || direction === 'down') {
        const to = entry.index + (direction === 'up' ? -1 : 1);

        if (to < 0 || to >= brothers.length) {
            return null;
        }

        /*
         * **行き先の 1 つ前**を指す。上へ動かすなら移動先の手前の兄弟、
         * 下へ動かすなら入れ替わる相手そのものである。
         */
        const after =
            direction === 'up'
                ? (brothers[to - 1]?.id ?? null)
                : brothers[to].id;

        return { parent: entry.parent.id, after };
    }

    if (direction === 'in') {
        // 直前の兄弟の中へ入れる
        const previous = brothers[entry.index - 1];

        if (!previous || !acceptsChild(previous, spec, specs)) {
            return null;
        }

        const children = previous.children ?? [];

        return {
            parent: previous.id,
            after: children[children.length - 1]?.id ?? null,
        };
    }

    // 親の外へ（親の直後へ出す）
    const grandparent = entries.find(
        (e) => e.node.id === entry.parent?.id,
    )?.parent;

    if (!grandparent || !acceptsChild(grandparent, spec, specs)) {
        return null;
    }

    return { parent: grandparent.id, after: entry.parent.id };
}

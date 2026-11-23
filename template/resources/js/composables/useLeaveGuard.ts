import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, readonly, ref, watch } from 'vue';
import { trackHistoryPosition } from '@/lib/history-position';
import { cancelPendingHistoryRefresh } from '@/lib/history-refresh';

/**
 * 保存していない変更があるのに別の画面へ移ろうとしたら、移る前に確かめる。
 *
 * - UX 一周 12 周目 R12-2: フォームとお知らせの編集画面で、保存せずにサイドバーを押すと、打った内容が
 *   何の知らせも無く消えた → 画面の中の移動（GET の visit）を止める
 * - 13 周目 R12-2 残り: ブラウザの「戻る」では、確認も無く移って消えた → popstate も止める
 * - 13 周目 R13-5: 確かめにブラウザの `confirm()` を使っていたので、窓を出さないブラウザ
 *   （自動操作・ポップアップを止めた設定）では「やめる」扱いになり、何も起きず理由も出なかった
 *   → 画面の中の確かめ（`LeaveConfirmDialog`）に替えた
 *
 * 止めるのは移動だけ。保存（POST/PATCH）は止めない。
 *
 * - 14 周目 R14-1: ブロック編集で打ってすぐブラウザの「戻る」を押すと、自動保存の待ち（約 0.8 秒）の
 *   あいだの打ち込みが黙って消えた → `settle` を渡すと、窓を出さずに**保存を確定してから**移る。
 *   確定できなかったとき（保存の失敗・競合）だけ窓で聞く
 * - 14 周目 R14-2・R14-3: サイト設定・メニューなど、守りの無い編集画面があった → 全部の編集画面に付けた
 *   （どの画面に付いているかは e2e `tests/e2e/feel/leave.spec.ts` が見る）
 *
 * ## 「戻る」の止め方
 *
 * popstate は取り消せない（URL はもう前の画面に替わっている）。そこで、Inertia より先に
 * （capture で）受け取って Inertia に描き直させず、記録した履歴位置の差で今の画面の URL に戻す（Forward・複数段の Back も同じ）。
 * 戻したときの popstate も Inertia に渡さない（渡すと、履歴に残った古い値で画面を描き直し、打った内容が消える）。
 * 「保存せずに移る」を選んだら、選んだ履歴位置へ進め、今度は止めない。
 *
 * 🔴 window に先に登録した listener から順に呼ばれる（capture でも、window が的のときは登録順）。
 * Inertia は createInertiaApp の中で popstate を登録するので、それより**先に**この module の
 * 読み込みで 1 本だけ登録し、いま開いている画面の守りへ渡す（実測: 画面ごとに後から登録すると、
 * Inertia が先に古い値で描き直し、打った内容が消えた）。app.ts が createInertiaApp より前に import する。
 */
export const LEAVE_TITLE = 'まだ保存していない変更があります';
export const LEAVE_MESSAGE = 'このまま移ると、打った内容は消えます。';

interface Pending {
    proceed: () => void;
}

const pending = ref<Pending | null>(null);

/** いま開いている画面の「戻る」の守り（同時に 1 つ） */
let activePop:
    ((event: PopStateEvent, from: number, to: number | null) => void) | null =
    null;
const positions =
    typeof window === 'undefined' ? null : trackHistoryPosition(window.history);

if (typeof window !== 'undefined') {
    window.addEventListener(
        'popstate',
        (event) => {
            const from = positions?.current() ?? 0;
            const to = positions?.accept(event.state) ?? null;
            activePop?.(event, from, to);
        },
        {
            capture: true,
        },
    );
}

/** 画面の中の確かめ（LeaveConfirmDialog が読む） */
export const leavePrompt = readonly(pending);

export function answerLeave(leave: boolean): void {
    const current = pending.value;
    pending.value = null;

    if (leave && current !== null) {
        current.proceed();
    }
}

type VisitLike = {
    url?: URL | string;
    method?: string;
    replace?: boolean;
    preserveScroll?: boolean;
    preserveState?: boolean;
    only?: string[];
    except?: string[];
    headers?: Record<string, string>;
};

export interface LeaveGuardOptions {
    /** 離れる前に保存を確定する。確定できたら true（窓を出さずに移る） */
    settle?: () => Promise<boolean>;
}

export function useLeaveGuard(
    isDirty: () => boolean,
    options: LeaveGuardOptions = {},
): void {
    let bypassVisit = false;
    let bypassPop = false;
    let swallowNextPop = false;
    let restoring: { origin: number; target: number } | null = null;
    let bypassTarget: number | null = null;
    const stopDirtyWatch = watch(
        isDirty,
        (dirty) => {
            if (dirty) {
                cancelPendingHistoryRefresh();
            }
        },
        { flush: 'sync' },
    );

    /** 確定できれば移る。できなければ窓で聞く */
    const settleThen = (proceed: () => void, ask: () => void) => {
        if (!options.settle) {
            ask();

            return;
        }

        options.settle().then(
            (ok) => (ok && !isDirty() ? proceed() : ask()),
            () => ask(),
        );
    };

    const goBack = () => {
        bypassPop = true;
        bypassVisit = true;
        window.history.back();
    };

    const askBeforeBack = () => {
        settleThen(goBack, () => {
            pending.value = { proceed: goBack };
        });
    };

    const askBeforeTraversal = (origin: number, target: number) => {
        const proceed = () => {
            bypassTarget = target;
            // A history refresh is part of the explicitly permitted traversal too.
            bypassVisit = true;
            window.history.go(target - (positions?.current() ?? origin));
        };
        settleThen(proceed, () => {
            pending.value = { proceed };
        });
    };

    const offBefore = router.on('before', (event) => {
        const visit = (event as CustomEvent).detail?.visit as
            VisitLike | undefined;

        if (bypassVisit || (visit?.method ?? 'get') !== 'get' || !isDirty()) {
            return;
        }

        event.preventDefault();
        cancelPendingHistoryRefresh();
        const proceed = () => {
            bypassVisit = true;
            router.visit(String(visit?.url ?? window.location.href), {
                method: 'get',
                replace: visit?.replace,
                preserveScroll: visit?.preserveScroll,
                preserveState: visit?.preserveState,
                only: visit?.only,
                except: visit?.except,
                headers: visit?.headers,
                onFinish: () => {
                    bypassVisit = false;
                },
            });
        };
        settleThen(proceed, () => {
            pending.value = { proceed };
        });

        return false;
    });

    const onPopstate = (
        event: PopStateEvent,
        from: number,
        to: number | null,
    ) => {
        if (restoring !== null && to !== null) {
            event.stopImmediatePropagation();

            if (to === restoring.origin) {
                const traversal = restoring;
                restoring = null;
                askBeforeTraversal(traversal.origin, traversal.target);
            } else {
                window.history.go(restoring.origin - to);
            }

            return;
        }

        if (bypassTarget !== null && to === bypassTarget) {
            bypassTarget = null;

            return;
        }

        if (swallowNextPop) {
            // 「戻る」を取り消すために 履歴を戻したときの popstate。Inertia に描き直させない
            swallowNextPop = false;
            event.stopImmediatePropagation();
            // 今の画面の URL に戻りきってから聞く（戻る途中に開くと、ブラウザがフォーカスを動かして窓が閉じた）
            askBeforeBack();

            return;
        }

        if (bypassPop) {
            bypassPop = false;

            return;
        }

        if (!isDirty()) {
            return;
        }

        event.stopImmediatePropagation();
        cancelPendingHistoryRefresh();

        if (to !== null) {
            // Same-position entries only change the fragment; keep the dirty form.
            if (to === from) {
                return;
            }

            restoring = { origin: from, target: to };
            window.history.go(from - to);

            return;
        }

        // Old/foreign entries without our position retain the single-Back fallback.
        swallowNextPop = true;
        window.history.go(1);
    };

    // 閉じる・再読み込み・アドレス欄での移動は、ブラウザの標準の確かめに任せる
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
        if (isDirty()) {
            event.preventDefault();
        }
    };

    activePop = onPopstate;
    window.addEventListener('beforeunload', onBeforeUnload);

    onBeforeUnmount(() => {
        stopDirtyWatch();
        offBefore();

        if (activePop === onPopstate) {
            activePop = null;
        }

        window.removeEventListener('beforeunload', onBeforeUnload);
        pending.value = null;
    });
}

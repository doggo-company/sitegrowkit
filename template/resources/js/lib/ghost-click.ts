/**
 * 二度押しの 2 回目が、画面が替わったあとの**別のボタン**に当たるのを止める（UX 一周 8 周目 R8-2・R8-4）。
 *
 * スマホで二度タップすると、1 回目で画面が替わり（公開した・ゴミ箱へ移して一覧へ戻った）、
 * 2 回目が**同じ場所に来た別のボタン**に当たっていた — 「下書きとして複製」が押されて見知らぬ
 * コピーへ飛ぶ、非公開にした見本が「見本を公開に戻す」で黙って公開に戻る、など。
 *
 * 止めるのは次の 3 つが揃ったときだけ（ふつうの操作は止めない）:
 * - 前の押しから 500ms 以内（人の二度押しの間隔）
 * - 前の押しとほぼ同じ場所（12px 以内）
 * - そのあいだに画面が替わった（Inertia の応答が届いた）
 */

export const DOUBLE_PRESS_MS = 500;
export const SAME_SPOT_PX = 12;

export type GhostClickGuard = {
    /** 画面が替わった（Inertia の応答・画面遷移） */
    pageUpdated(): void;
    /** この押しを捨てるべきか。捨てない押しは「前の押し」として覚える */
    shouldSwallow(x: number, y: number): boolean;
};

export function createGhostClickGuard(
    now: () => number = () => performance.now(),
): GhostClickGuard {
    let last: { at: number; x: number; y: number } | null = null;
    let updatedAt = -Infinity;

    return {
        pageUpdated() {
            updatedAt = now();
        },
        shouldSwallow(x, y) {
            const at = now();
            const swallow =
                last !== null &&
                at - last.at < DOUBLE_PRESS_MS &&
                Math.abs(x - last.x) <= SAME_SPOT_PX &&
                Math.abs(y - last.y) <= SAME_SPOT_PX &&
                updatedAt > last.at;

            if (!swallow) {
                last = { at, x, y };
            }

            return swallow;
        },
    };
}

type RouterLike = { on(event: 'success' | 'navigate', cb: () => void): unknown };

/** 画面全体に付ける。押しの前（capture）で止めるので、ボタン側の処理は動かない */
export function installGhostClickGuard(router: RouterLike): void {
    if (typeof document === 'undefined') {
        return;
    }

    const guard = createGhostClickGuard();
    router.on('success', () => guard.pageUpdated());
    router.on('navigate', () => guard.pageUpdated());

    document.addEventListener(
        'click',
        (event) => {
            if (guard.shouldSwallow(event.clientX, event.clientY)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        },
        true,
    );
}

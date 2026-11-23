/**
 * ブラウザの「戻る」「進む」で出た画面を、サーバーから読み直す（UX 一周 13 周目 R13-1・R13-2）。
 *
 * ## なぜ要るか
 *
 * Inertia は「戻る」のとき、サーバーに聞かずに**履歴に残した props**で画面を描き直す
 * （`@inertiajs/core` の `handlePopstateEvent` → `page.setQuietly(履歴の page)`）。
 * 管理画面ではその props が古い:
 *
 * - ブロック編集でブロックを置いて「戻る」→ ページ設定が「このページはまだ空です」で「公開する」が押せない（R13-1）
 * - 受信を「対応済み」にして「戻る」→ 一覧は「未対応」のまま（R13-2）
 *
 * ## 公式の考え
 *
 * Inertia 3.9 は `navigate` イベントに `type: 'history'` を足し、その場合に `router.reload()` で
 * 読み直す例を出している（https://laravel-news.com/inertia-3-9-0）。このキットは 3.6.1 なので、
 * `popstate` を見て自分で見分ける。読み直しは `preserveState: false`（フォームの初期値も今の値で作り直す。
 * `router.reload()` は preserveState を true に固定するので使わない）。
 *
 * bfcache から戻った画面（`pageshow` の `persisted`）も読み直す。管理画面の応答は
 * `Cache-Control: no-store` なので通常は bfcache に入らないが、入ったときの保険。
 *
 * 保存していない変更がある画面での「戻る」は、先に `useLeaveGuard` が止める（ここまで来ない）。
 */
export interface RouterLike {
    on(event: 'navigate', callback: (event: unknown) => void): unknown;
    visit(url: string, options: Record<string, unknown>): unknown;
}

export interface WindowLike extends EventTarget {
    location: { href: string };
}

/** popstate から Inertia が描き直すまでの間（履歴の復号は非同期）。これを過ぎたら別の移動とみなす */
const WINDOW_MS = 3000;

let pendingRefresh: { cancel: (() => void) | null; cancelled: boolean } | null =
    null;

/** A late history response must not replace a form the user has started editing. */
export function cancelPendingHistoryRefresh(): void {
    const request = pendingRefresh;
    pendingRefresh = null;

    if (request !== null) {
        request.cancelled = true;
        request.cancel?.();
    }
}

export function installHistoryRefresh(
    router: RouterLike,
    win: WindowLike | undefined = typeof window === 'undefined'
        ? undefined
        : window,
    now: () => number = () => Date.now(),
): void {
    if (win === undefined) {
        return;
    }

    let poppedAt: number | null = null;

    const refresh = () => {
        cancelPendingHistoryRefresh();
        const request = {
            cancel: null as (() => void) | null,
            cancelled: false,
        };
        pendingRefresh = request;
        router.visit(win.location.href, {
            replace: true,
            preserveScroll: true,
            preserveState: false,
            onCancelToken: (token: { cancel: () => void }) => {
                request.cancel = token.cancel;

                if (request.cancelled) {
                    token.cancel();
                }
            },
            onFinish: () => {
                if (pendingRefresh === request) {
                    pendingRefresh = null;
                }
            },
        });
    };

    win.addEventListener('popstate', (event) => {
        // state の無い popstate（# だけの移動）は Inertia も描き直さない
        poppedAt = (event as PopStateEvent).state ? now() : null;
    });

    router.on('navigate', () => {
        if (poppedAt === null) {
            return;
        }

        const fresh = now() - poppedAt <= WINDOW_MS;
        poppedAt = null;

        if (fresh) {
            refresh();
        }
    });

    win.addEventListener('pageshow', (event) => {
        if ((event as PageTransitionEvent).persisted) {
            refresh();
        }
    });
}

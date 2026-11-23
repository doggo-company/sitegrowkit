import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    cancelPendingHistoryRefresh,
    installHistoryRefresh,
} from '../history-refresh';

/**
 * UX 一周 13 周目 R13-1・R13-2（ブラウザの「戻る」で古い画面が出る）の回帰。
 *
 * Inertia は「戻る」のとき、サーバーに聞かずに履歴に残した props で画面を描き直す。
 * 管理画面ではその props が古い（ブロックを置く前・対応済みにする前）ので、戻った直後に読み直す。
 * Inertia 3.9 の `navigate` の `type === 'history'` と同じ考え（このキットは 3.6.1 なので自前で見分ける）。
 */
function fakeRouter() {
    const listeners: Record<string, ((e: unknown) => void)[]> = {};
    const visits: { url: string; options: Record<string, unknown> }[] = [];

    return {
        visits,
        on(event: string, cb: (e: unknown) => void) {
            (listeners[event] ??= []).push(cb);

            return () => {};
        },
        visit(url: string, options: Record<string, unknown>) {
            visits.push({ url, options });
        },
        fire(event: string) {
            (listeners[event] ?? []).forEach((cb) => cb(new Event(event)));
        },
    };
}

function fakeWindow(href = 'http://localhost/admin/pages/1/edit') {
    const target = new EventTarget() as EventTarget & {
        location: { href: string };
    };
    target.location = { href };

    return target;
}

function popstate(state: unknown): Event {
    const e = new Event('popstate');
    Object.defineProperty(e, 'state', { value: state });

    return e;
}

function pageshow(persisted: boolean): Event {
    const e = new Event('pageshow');
    Object.defineProperty(e, 'persisted', { value: persisted });

    return e;
}

describe('history-refresh', () => {
    it('「戻る」で描き直したら、いまの URL をサーバーから読み直す（古い props を捨てる）', () => {
        const router = fakeRouter();
        const win = fakeWindow();
        installHistoryRefresh(router, win, () => 0);

        win.dispatchEvent(popstate({ page: { url: '/admin/pages/1/edit' } }));
        router.fire('navigate');

        expect(router.visits).toHaveLength(1);
        expect(router.visits[0].url).toBe(
            'http://localhost/admin/pages/1/edit',
        );
        expect(router.visits[0].options).toMatchObject({
            replace: true,
            preserveScroll: true,
            preserveState: false,
        });
    });

    it('ふつうの移動（リンク・保存）では読み直さない', () => {
        const router = fakeRouter();
        const win = fakeWindow();
        installHistoryRefresh(router, win, () => 0);

        router.fire('navigate');

        expect(router.visits).toHaveLength(0);
    });

    it('読み直しの移動でまた読み直さない（1 回だけ）', () => {
        const router = fakeRouter();
        const win = fakeWindow();
        installHistoryRefresh(router, win, () => 0);

        win.dispatchEvent(popstate({ page: {} }));
        router.fire('navigate');
        router.fire('navigate');

        expect(router.visits).toHaveLength(1);
    });

    it('# だけの移動（state の無い popstate）のあとのふつうの移動では読み直さない', () => {
        const router = fakeRouter();
        const win = fakeWindow();
        installHistoryRefresh(router, win, () => 0);

        win.dispatchEvent(popstate(null));
        router.fire('navigate');

        expect(router.visits).toHaveLength(0);
    });

    it('「戻る」のあと描き直されないまま時間が経ったら、次のふつうの移動では読み直さない', () => {
        const router = fakeRouter();
        const win = fakeWindow();
        let t = 0;
        installHistoryRefresh(router, win, () => t);

        win.dispatchEvent(popstate({ page: {} }));
        t = 10_000;
        router.fire('navigate');

        expect(router.visits).toHaveLength(0);
    });

    it('bfcache から戻ってきた画面（pageshow persisted）も読み直す', () => {
        const router = fakeRouter();
        const win = fakeWindow();
        installHistoryRefresh(router, win, () => 0);

        win.dispatchEvent(pageshow(false));
        expect(router.visits).toHaveLength(0);

        win.dispatchEvent(pageshow(true));
        expect(router.visits).toHaveLength(1);
    });
});

describe('pending history refresh', () => {
    beforeEach(cancelPendingHistoryRefresh);
    afterEach(cancelPendingHistoryRefresh);

    function start() {
        const router = fakeRouter();
        const win = fakeWindow();
        installHistoryRefresh(router, win, () => 0);
        win.dispatchEvent(popstate({ page: {} }));
        router.fire('navigate');

        return { router, win };
    }

    it('cancels only the automatic refresh when editing starts', () => {
        const { router } = start();
        const cancel = vi.fn();
        const token = router.visits[0].options.onCancelToken as (token: {
            cancel: () => void;
        }) => void;
        token({ cancel });
        cancelPendingHistoryRefresh();
        cancelPendingHistoryRefresh();
        expect(cancel).toHaveBeenCalledTimes(1);
    });

    it('also cancels a token that arrives after editing started', () => {
        const { router } = start();
        cancelPendingHistoryRefresh();
        const cancel = vi.fn();
        const token = router.visits[0].options.onCancelToken as (token: {
            cancel: () => void;
        }) => void;
        token({ cancel });
        expect(cancel).toHaveBeenCalledOnce();
    });

    it('an old response cannot clear a newer pending refresh', () => {
        const { router, win } = start();
        const finishOld = router.visits[0].options.onFinish as () => void;
        win.dispatchEvent(popstate({ page: {} }));
        router.fire('navigate');
        const cancel = vi.fn();
        const token = router.visits[1].options.onCancelToken as (token: {
            cancel: () => void;
        }) => void;
        token({ cancel });
        finishOld();
        cancelPendingHistoryRefresh();
        expect(cancel).toHaveBeenCalledOnce();
    });

    it('a completed response is no longer cancelled', () => {
        const { router } = start();
        const cancel = vi.fn();
        const token = router.visits[0].options.onCancelToken as (token: {
            cancel: () => void;
        }) => void;
        token({ cancel });
        const finish = router.visits[0].options.onFinish as () => void;
        finish();
        cancelPendingHistoryRefresh();
        expect(cancel).not.toHaveBeenCalled();
    });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createAutosave } from '@/composables/useAutosave';

/**
 * 自動保存の引き金（Phase 9.15 / STAGE 28B・28C・SPEC §C.1）。
 *
 * ## ここでしか見られないもの
 *
 * 「30 秒経ったら送る」は**時間の判断**なので、サーバー側の試験からは見えない。
 * 実際に画面を開いて 30 秒待つのも現実的ではない。
 * **時計を進める**ことでしか確かめられない。
 *
 * 28B ではこの引き金に自動試験が無かった（`setInterval` が本当に発火するかを
 * 確かめていなかった）。その穴をここで埋める。
 */
describe('自動保存の引き金', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /** 触っていない画面が版を積み続けない */
    it('変更が無ければ、いくら経っても送らない', async () => {
        const save = vi.fn(async () => true);
        const autosave = createAutosave({ save, intervalMs: 30_000 });

        await vi.advanceTimersByTimeAsync(30_000 * 10);

        expect(save).not.toHaveBeenCalled();
        autosave.stop();
    });

    it('変更があれば、間隔が来たときに 1 回だけ送る', async () => {
        const save = vi.fn(async () => true);
        const autosave = createAutosave({ save, intervalMs: 30_000 });

        autosave.markDirty();

        // まだ来ていない
        await vi.advanceTimersByTimeAsync(29_000);
        expect(save).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(save).toHaveBeenCalledTimes(1);

        // 通ったので、次の間隔では送らない
        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(1);

        autosave.stop();
    });

    /**
     * **失敗したまま dirty を下ろさない。**
     *
     * 下ろすと、失敗した内容が次の機会にも送られない。
     */
    it('送信が失敗したら、次の機会に送り直す', async () => {
        const save = vi.fn(async () => false);
        const autosave = createAutosave({ save, intervalMs: 30_000 });

        autosave.markDirty();

        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(1);
        expect(autosave.isDirty()).toBe(true);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(2);

        autosave.stop();
    });

    it('通信そのものが落ちても、次の機会に送り直す', async () => {
        const save = vi.fn(async () => {
            throw new Error('切れた');
        });
        const autosave = createAutosave({ save, intervalMs: 30_000 });

        autosave.markDirty();

        await vi.advanceTimersByTimeAsync(30_000);
        expect(autosave.isDirty()).toBe(true);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(2);

        autosave.stop();
    });

    /**
     * **送信中に来た変更を「送った」ことにしない。**
     *
     * 確定した内容だけが送られているので、その後の変更は次の機会に要る。
     * ここを下ろすと、**その分がどこにも残らないまま消える**。
     */
    it('送信中に変更が来たら、下ろさずに残す', async () => {
        let release: () => void = () => {};
        const save = vi.fn(
            async () =>
                await new Promise<boolean>((resolve) => {
                    release = () => resolve(true);
                }),
        );

        const autosave = createAutosave({ save, intervalMs: 30_000 });

        autosave.markDirty();
        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(1);

        // 送信の最中に書き足した
        autosave.markDirty();
        release();
        await vi.advanceTimersByTimeAsync(0);

        expect(autosave.isDirty()).toBe(true);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(2);

        autosave.stop();
    });

    /** 明示保存の最中に、同じ内容を 2 通りの経路で書きにいかない */
    it('送ってよくないときは送らない', async () => {
        const save = vi.fn(async () => true);
        let allowed = false;
        const autosave = createAutosave({
            save,
            enabled: () => allowed,
            intervalMs: 30_000,
        });

        autosave.markDirty();
        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).not.toHaveBeenCalled();

        allowed = true;
        await vi.advanceTimersByTimeAsync(30_000);
        expect(save).toHaveBeenCalledTimes(1);

        autosave.stop();
    });

    it('止めたあとは、時計が進んでも送らない', async () => {
        const save = vi.fn(async () => true);
        const autosave = createAutosave({ save, intervalMs: 30_000 });

        autosave.markDirty();
        autosave.stop();

        await vi.advanceTimersByTimeAsync(30_000 * 5);

        expect(save).not.toHaveBeenCalled();
    });
});

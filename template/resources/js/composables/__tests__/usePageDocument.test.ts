import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

import { usePageDocument } from '@/composables/usePageDocument';
import type {
    AccessibilityFinding,
    DocumentCommand,
    PageDocument,
} from '@/types/document';

/**
 * 取り消しの履歴（Phase 9.6 / P0-C6a・`PHASE9_6_SPEC.md` §4.10）。
 *
 * ## ここでしか見られないもの
 *
 * 履歴は画面が持っている（Owner Gate V6-a）ので、**どこまで戻せるか**は
 * サーバー側の試験からは見えない。画面を開かずに決まることなので Playwright でもない。
 *
 * ## 送るのは本物の経路
 *
 * `fetch` だけを差し替え、composable は普通に動かす。
 * 積む・捨てる・切り詰める の判断を、実際に通る道の上で見る。
 */
describe('usePageDocument の取り消し履歴', () => {
    const undoCommand = (n: number): DocumentCommand =>
        ({
            operation: 'component.update',
            target: `node-${n}`,
            changes: { props: { text: `${n}` } },
        }) as unknown as DocumentCommand;

    /** 成功と、その取り消しを返すサーバー */
    function answerWith(undo: (call: number) => DocumentCommand | null): void {
        let call = 0;

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                call++;

                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        data: {
                            root: { id: 'page', type: 'page', children: [] },
                        },
                        meta: {
                            version: call,
                            accessibility: [],
                            undo: undo(call),
                        },
                    }),
                };
            }),
        );
    }

    function subject() {
        const document = ref({
            root: { id: 'page', type: 'page', children: [] },
        } as unknown as PageDocument);

        return usePageDocument(
            1,
            document,
            ref(1),
            ref<AccessibilityFinding[]>([]),
        );
    }

    beforeEach(() => {
        // csrfToken() が cookie を読む。**画面は開かない**ので最小限だけ置く
        vi.stubGlobal('window', { document: { cookie: '' } });
    });

    it('100 手までは積み上がる', async () => {
        answerWith((call) => undoCommand(call));

        const editor = subject();

        for (let i = 0; i < 100; i++) {
            await editor.run(undoCommand(i));
        }

        expect(editor.undoStack.value).toHaveLength(100);
    });

    it('101 手目で最古が落ちて、長さは 100 のまま', async () => {
        answerWith((call) => undoCommand(call));

        const editor = subject();

        for (let i = 0; i < 101; i++) {
            await editor.run(undoCommand(i));
        }

        expect(editor.undoStack.value).toHaveLength(100);

        // **落ちるのは古い側**。1 手目（サーバーの 1 回目の答え）が消えている
        expect(editor.undoStack.value[0]).toEqual(undoCommand(2));
        expect(editor.undoStack.value.at(-1)).toEqual(undoCommand(101));
    });

    it('取り消しを組み立てられない一手が来たら、履歴ごと捨てる', async () => {
        // 3 回目だけ「逆が作れない」と答える（初めて binding を付けた場合など）
        answerWith((call) => (call === 3 ? null : undoCommand(call)));

        const editor = subject();

        for (let i = 0; i < 3; i++) {
            await editor.run(undoCommand(i));
        }

        /*
         * **戻れない一手を挟んだまま「戻れる」と見せない。**
         * 押した人が違うところへ着くより、押せない方がよい。
         */
        expect(editor.undoStack.value).toHaveLength(0);
        expect(editor.redoStack.value).toHaveLength(0);
    });

    it('編集するとやり直しの列は捨てられる', async () => {
        answerWith((call) => undoCommand(call));

        const editor = subject();

        await editor.run(undoCommand(0));
        await editor.undo();

        expect(editor.redoStack.value).toHaveLength(1);

        // 枝分かれした先へ「やり直し」で戻れると、どこへ戻るのか言えなくなる
        await editor.run(undoCommand(1));

        expect(editor.redoStack.value).toHaveLength(0);
    });
});

describe('usePageDocument の保存状態（UX 一周 3 周目 M5）', () => {
    beforeEach(() => {
        vi.stubGlobal('window', { document: { cookie: '' } });
    });

    function subject() {
        const document = ref({
            root: { id: 'page', type: 'page', children: [] },
        } as unknown as PageDocument);

        return usePageDocument(
            1,
            document,
            ref(1),
            ref<AccessibilityFinding[]>([]),
        );
    }

    const command = {
        operation: 'component.update',
        target: 'node-1',
        changes: { props: { text: 'a' } },
    } as unknown as DocumentCommand;

    function answerAfter(gate: Promise<void>): void {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                await gate;

                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        data: {
                            root: { id: 'page', type: 'page', children: [] },
                        },
                        meta: { version: 2, accessibility: [], undo: null },
                    }),
                };
            }),
        );
    }

    it('送れたら「未保存」を下ろす', async () => {
        answerAfter(Promise.resolve());
        const editor = subject();

        editor.markDirty();
        await editor.run(command);

        expect(editor.dirty.value).toBe(false);
    });

    it('送っている間に打たれた分は、送れても「未保存」のまま残す', async () => {
        let release: () => void = () => {};
        answerAfter(new Promise<void>((resolve) => (release = resolve)));
        const editor = subject();

        editor.markDirty();
        const sending = editor.run(command);
        editor.markDirty(); // 送信中に次の文字
        release();
        await sending;

        expect(editor.dirty.value).toBe(true);
    });
});

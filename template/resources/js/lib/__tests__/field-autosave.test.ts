import { describe, expect, it } from 'vitest';

import { createFieldAutosave } from '@/lib/field-autosave';

/**
 * UX 一周 2 周目 M5（9/27 の周から 3 回目）: 欄に打って 3 秒・8 秒待っても
 * 「未保存の変更あり」のまま、プレビューも変わらなかった。
 */
function fakeClock() {
    let now = 0;
    let seq = 0;
    const timers = new Map<number, { at: number; fn: () => void }>();

    return {
        clock: {
            setTimeout: (fn: () => void, ms: number) => {
                seq += 1;
                timers.set(seq, { at: now + ms, fn });

                return seq;
            },
            clearTimeout: (handle: unknown) => {
                timers.delete(handle as number);
            },
            now: () => now,
        },
        advance(ms: number) {
            const end = now + ms;

            for (;;) {
                const next = [...timers.entries()]
                    .filter(([, t]) => t.at <= end)
                    .sort((a, b) => a[1].at - b[1].at)[0];

                if (!next) {
                    break;
                }

                timers.delete(next[0]);
                now = next[1].at;
                next[1].fn();
            }

            now = end;
        },
    };
}

function setup() {
    const t = fakeClock();
    const sent: [string, string][] = [];
    const autosave = createFieldAutosave({
        send: (key, value) => sent.push([key, value]),
        clock: t.clock,
    });

    return { t, sent, autosave };
}

describe('入力中の自動保存', () => {
    it('打つ手が止まったら、欄を離れなくても送る', () => {
        const { t, sent, autosave } = setup();

        autosave.input('text', '焼');
        autosave.input('text', '焼きたて');
        t.advance(500);
        expect(sent).toEqual([]);

        t.advance(400);
        expect(sent).toEqual([['text', '焼きたて']]);
    });

    it('打ち続けていても、3 秒経ったら送る（書き続けている人ほど守る）', () => {
        const { t, sent, autosave } = setup();

        for (let i = 1; i <= 10; i += 1) {
            autosave.input('text', 'あ'.repeat(i));
            t.advance(400);
        }

        expect(sent.length).toBeGreaterThanOrEqual(1);
        expect(sent[0]?.[1].length).toBeLessThanOrEqual(8);
    });

    it('欄を離れたらすぐ送る。送った値と同じならもう送らない', () => {
        const { t, sent, autosave } = setup();

        autosave.input('text', '焼きたて');
        t.advance(1000);
        autosave.commit('text', '焼きたて');
        expect(sent).toEqual([['text', '焼きたて']]);

        autosave.input('text', '焼きたてのパン');
        autosave.commit('text', '焼きたてのパン');
        expect(sent).toEqual([
            ['text', '焼きたて'],
            ['text', '焼きたてのパン'],
        ]);

        // 離れた後に時間が経っても、二重に送らない
        t.advance(5000);
        expect(sent).toHaveLength(2);
    });

    it('欄ごとに別々に送る', () => {
        const { t, sent, autosave } = setup();

        autosave.input('text', '見出し');
        autosave.input('sub', '小見出し');
        t.advance(1000);

        expect(sent).toEqual([
            ['text', '見出し'],
            ['sub', '小見出し'],
        ]);
    });

    it('flush は打ちかけをいま送る／cancel は捨てる', () => {
        const { t, sent, autosave } = setup();

        autosave.input('text', 'a');
        autosave.flush();
        expect(sent).toEqual([['text', 'a']]);

        autosave.input('text', 'b');
        autosave.cancel();
        t.advance(5000);
        expect(sent).toEqual([['text', 'a']]);
        expect(autosave.lastSent('text')).toBe('a');
    });

    it('forget の後は、同じ値でも送り直す（取り消しで外から値が戻ったとき）', () => {
        const { sent, autosave } = setup();

        autosave.commit('text', 'a');
        autosave.commit('text', 'a');
        expect(sent).toHaveLength(1);

        autosave.forget('text');
        autosave.commit('text', 'a');
        expect(sent).toHaveLength(2);
    });
});

import { describe, expect, it } from 'vitest';

import { createGhostClickGuard } from '../ghost-click';

/** UX 一周 8 周目 R8-2・R8-4（花屋・スマホの二度タップ）の回帰 */
describe('ghost-click', () => {
    function clock() {
        let t = 0;

        return { now: () => t, advance: (ms: number) => (t += ms) };
    }

    it('二度タップの間に画面が替わったら、2 回目を捨てる', () => {
        const c = clock();
        const guard = createGhostClickGuard(c.now);

        expect(guard.shouldSwallow(100, 360)).toBe(false);
        c.advance(100);
        guard.pageUpdated();
        c.advance(50);
        expect(guard.shouldSwallow(102, 358)).toBe(true);
    });

    it('画面が替わっていなければ、二度押しでも捨てない（ボタン側の守りに任せる）', () => {
        const c = clock();
        const guard = createGhostClickGuard(c.now);

        guard.shouldSwallow(100, 360);
        c.advance(150);
        expect(guard.shouldSwallow(100, 360)).toBe(false);
    });

    it('別の場所を押したとき・間が空いたときは捨てない', () => {
        const c = clock();
        const guard = createGhostClickGuard(c.now);

        guard.shouldSwallow(100, 360);
        c.advance(100);
        guard.pageUpdated();
        c.advance(50);
        expect(guard.shouldSwallow(300, 600)).toBe(false);

        c.advance(100);
        guard.pageUpdated();
        c.advance(600);
        expect(guard.shouldSwallow(300, 600)).toBe(false);
    });
});

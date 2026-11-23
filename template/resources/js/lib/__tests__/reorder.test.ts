import { describe, expect, it } from 'vitest';
import { afterIdForMoveDown, afterIdForMoveUp } from '../reorder';

/**
 * 「1 つ上へ」を「2 つ上の直後へ」に読み替えるところ（P1 ②）。
 *
 * **off-by-one がそのまま並びの誤りになる**ので、端と真ん中の両方を留める。
 */
describe('afterIdForMoveUp', () => {
    const rows = [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }];

    it('真ん中の行は、2 つ上の直後へ送る', () => {
        // [1,2,3,4] の 3 を上げると [1,3,2,4] — つまり「1 の直後」
        expect(afterIdForMoveUp(rows, 2)).toBe(1);
    });

    it('2 行目は先頭へ（前が 1 行しか無い）', () => {
        expect(afterIdForMoveUp(rows, 1)).toBeNull();
    });

    it('先頭は動かす先が無い（先頭へ送らない）', () => {
        // **null と undefined を畳むと、ここで「先頭へ」を送ってしまう**
        expect(afterIdForMoveUp(rows, 0)).toBeUndefined();
    });

    it('範囲の外は動かす先が無い', () => {
        expect(afterIdForMoveUp(rows, 9)).toBeUndefined();
        expect(afterIdForMoveUp([], 0)).toBeUndefined();
    });
});

describe('afterIdForMoveDown', () => {
    const rows = [{ id: 1 }, { id: 2 }, { id: 3 }];

    it('次の行の直後へ送る', () => {
        expect(afterIdForMoveDown(rows, 0)).toBe(2);
        expect(afterIdForMoveDown(rows, 1)).toBe(3);
    });

    it('最後の行は動かす先が無い', () => {
        expect(afterIdForMoveDown(rows, 2)).toBeUndefined();
    });

    it('範囲の外は動かす先が無い', () => {
        expect(afterIdForMoveDown(rows, -1)).toBeUndefined();
        expect(afterIdForMoveDown([], 0)).toBeUndefined();
    });
});

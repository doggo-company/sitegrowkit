import { describe, expect, it } from 'vitest';

import { altAfterPick } from '../media-alt';

/** UX 一周 23 周目 #5（写真館）: 画像を替えても代替テキストが前の写真の説明のまま残った */
describe('media-alt', () => {
    const base = {
        currentAlt: '',
        previousMediaAlt: null,
        currentMediaId: null,
        pickedMediaId: 2,
        pickedMediaAlt: null,
    };

    it('空なら、選んだ画像の説明を写す', () => {
        expect(altAfterPick({ ...base, pickedMediaAlt: '館内の様子' })).toEqual(
            { alt: '館内の様子', notice: null },
        );
    });

    it('画像を替えたら、自分で書いた代替テキストは消して知らせる', () => {
        expect(
            altAfterPick({
                ...base,
                currentAlt: '晴れ着姿で笑う七五三の女の子',
                currentMediaId: 1,
                pickedMediaId: 2,
            }),
        ).toEqual({ alt: null, notice: 'cleared' });
    });

    it('新しい画像に説明があれば、それに替えて知らせる', () => {
        expect(
            altAfterPick({
                ...base,
                currentAlt: '晴れ着姿で笑う七五三の女の子',
                currentMediaId: 1,
                pickedMediaId: 2,
                pickedMediaAlt: 'スタジオの外観',
            }),
        ).toEqual({ alt: 'スタジオの外観', notice: 'replaced' });
    });

    it('同じ画像を選び直しただけなら、書いた代替テキストは触らない', () => {
        expect(
            altAfterPick({
                ...base,
                currentAlt: '自分で書いた',
                currentMediaId: 2,
                pickedMediaId: 2,
            }),
        ).toEqual({
            alt: undefined,
            notice: null,
        });
    });

    it('外したときは触らない', () => {
        expect(
            altAfterPick({
                ...base,
                currentAlt: 'x',
                currentMediaId: 1,
                pickedMediaId: null,
            }).alt,
        ).toBeUndefined();
    });
});

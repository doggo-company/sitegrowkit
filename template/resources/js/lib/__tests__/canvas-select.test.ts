import { describe, expect, it } from 'vitest';
import { selectableIdFrom } from '@/lib/canvas-select';

/** iframe の中の要素のつもり。**こちらの Element ではない** */
const node = (id: string, parent: unknown = null): object => ({
    id,
    parentElement: parent,
});

const IDS = ['01AAA', '01BBB'];

describe('クリックした場所から、選択できる一番近いブロックを探す', () => {
    it('その要素が選べるなら、それを返す', () => {
        expect(selectableIdFrom(node('01AAA'), IDS)).toBe('01AAA');
    });

    it('選べないなら、一番近い祖先まで辿る', () => {
        const child = node('', node('not-selectable', node('01BBB')));

        expect(selectableIdFrom(child, IDS)).toBe('01BBB');
    });

    it('どこにも無ければ null（近いものを勝手に選ばない）', () => {
        expect(selectableIdFrom(node('zzz', node('yyy')), IDS)).toBeNull();
    });

    it('何も渡されなければ null', () => {
        expect(selectableIdFrom(null, IDS)).toBeNull();
        expect(selectableIdFrom(undefined, IDS)).toBeNull();
    });

    /**
     * **`instanceof Element` で書いてはいけない。**
     * iframe の中は別の実行環境なので、向こうの Element はこちらの Element とは
     * 別物として扱われ、判定が常に false になる。ここでは
     * **Element ですらないただのオブジェクト**を渡して、形だけで辿れることを確かめる。
     */
    it('Element でなくても、形が合っていれば辿れる', () => {
        expect(selectableIdFrom({ id: '01AAA', parentElement: null }, IDS)).toBe(
            '01AAA',
        );
    });

    it('選べる id が空なら、何も選ばない', () => {
        expect(selectableIdFrom(node('01AAA'), [])).toBeNull();
    });
});

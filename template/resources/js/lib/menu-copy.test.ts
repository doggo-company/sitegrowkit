import { describe, expect, it } from 'vitest';
import { copyMenuTree } from './menu-copy';

describe('別メニューへ保存前の木を写す', () => {
    it('親子の ID を外し、リンクと並びを残し、元は変えない', () => {
        const original = [
            {
                id: 1,
                link_url: '/one',
                children: [{ id: 2, link_url: '/two', children: [] }],
            },
        ];
        const copied = copyMenuTree(original);
        expect(copied).toEqual([
            {
                id: null,
                link_url: '/one',
                children: [{ id: null, link_url: '/two', children: [] }],
            },
        ]);
        copied[0].children[0].link_url = '/changed';
        expect(original[0].children[0].link_url).toBe('/two');
        expect(original[0].id).toBe(1);
    });
});

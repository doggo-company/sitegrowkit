import { describe, expect, it } from 'vitest';

import { applyRichtextCommand, RICHTEXT_COMMANDS } from '@/lib/richtext';
import type { RichtextCommand } from '@/lib/richtext';

/**
 * 書式ボタンが値をどう変えるか（Phase 9.15 / STAGE 28D・SPEC §D）。
 *
 * **判断は lib にある。** 画面は押された command を渡すだけなので、
 * ここで確かめれば画面を開かずに済む。
 */
describe('richtext の書式ボタン', () => {
    const command = (tag: string): RichtextCommand =>
        RICHTEXT_COMMANDS.find((c) => c.tag === tag)!;

    it('選んだ文字を挟む', () => {
        const result = applyRichtextCommand(
            'これは大事な話です',
            { start: 3, end: 6 },
            command('strong'),
        );

        expect(result.value).toBe('これは<strong>大事な</strong>話です');
    });

    /** 挟んだあとも選ばれたままにする（続けて別の書式を当てられる） */
    it('挟んだ範囲を選んだままにする', () => {
        const result = applyRichtextCommand(
            'これは大事な話です',
            { start: 3, end: 6 },
            command('em'),
        );

        expect(
            result.value.slice(result.selection.start, result.selection.end),
        ).toBe('<em>大事な</em>');
    });

    /**
     * **押したのに何も起きないように見せない。**
     *
     * 何も選んでいなくても tag を置き、**カーソルを中へ入れる**。
     */
    it('何も選んでいなければ空の tag を置き、カーソルを中へ入れる', () => {
        const result = applyRichtextCommand(
            '',
            { start: 0, end: 0 },
            command('h2'),
        );

        expect(result.value).toBe('<h2></h2>');
        expect(result.selection.start).toBe('<h2>'.length);
        expect(result.selection.end).toBe('<h2>'.length);
    });

    it('箇条書きは行ごとに項目にする', () => {
        const result = applyRichtextCommand(
            'りんご\nみかん',
            { start: 0, end: 7 },
            command('ul'),
        );

        expect(result.value).toBe(
            '<ul>\n  <li>りんご</li>\n  <li>みかん</li>\n</ul>',
        );
    });

    it('前後の文字を壊さない', () => {
        const result = applyRichtextCommand(
            '前<p>本文</p>後',
            { start: 1, end: 10 },
            command('blockquote'),
        );

        expect(result.value.startsWith('前<blockquote>')).toBe(true);
        expect(result.value.endsWith('</blockquote>後')).toBe(true);
    });

    /** ボタンの一覧そのものが空になっていないこと（画面から書式が消えない） */
    it('ボタンが 1 つ以上ある', () => {
        expect(RICHTEXT_COMMANDS.length).toBeGreaterThan(0);
    });
});

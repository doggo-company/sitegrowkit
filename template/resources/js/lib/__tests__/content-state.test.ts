import { describe, expect, it } from 'vitest';

import { contentState, contentStateLabel } from '../content-state';

/** UX 一周 7 周目 R7-3（司法書士事務所・お知らせの予約）の回帰 */
describe('contentState', () => {
    const now = Date.parse('2026-11-05T10:00:00Z');

    it('公開日時が先なら「予約」', () => {
        expect(contentState('published', '2026-11-12T00:00:00Z', now)).toBe('scheduled');
        expect(contentStateLabel('scheduled')).toBe('予約');
    });

    it('公開日時が過去・空なら「公開」、下書きは「下書き」', () => {
        expect(contentState('published', '2026-11-01T00:00:00Z', now)).toBe('published');
        expect(contentState('published', null, now)).toBe('published');
        expect(contentState('draft', '2026-11-12T00:00:00Z', now)).toBe('draft');
    });
});

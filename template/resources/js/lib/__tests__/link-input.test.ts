import { describe, expect, it } from 'vitest';

import { acceptableHref, resolveLinkInput } from '../link-input';

/** UX 一周 5 周目 R5-1（犬の幼稚園・タブレット）の回帰 */
describe('link-input', () => {
    const targets = [
        { href: '/', label: 'ホーム' },
        { href: '/contact', label: 'お問い合わせ' },
    ];

    it('ページの名前を打つと、その URL になる', () => {
        expect(resolveLinkInput('お問い合わせ', targets)).toEqual({ ok: true, value: '/contact' });
        expect(resolveLinkInput(' お問い合わせ ', targets)).toEqual({ ok: true, value: '/contact' });
    });

    it('URL はそのまま通す', () => {
        expect(resolveLinkInput('/price', targets)).toEqual({ ok: true, value: '/price' });
        expect(resolveLinkInput('https://example.com', targets)).toEqual({ ok: true, value: 'https://example.com' });
        expect(resolveLinkInput('tel:0120000000', targets)).toEqual({ ok: true, value: 'tel:0120000000' });
    });

    it('使えない文字は送らず、理由と例を返す', () => {
        const result = resolveLinkInput('見学のページ', targets);
        expect(result.ok).toBe(false);
        expect(result.ok ? '' : result.message).toContain('お問い合わせ → /contact');
    });

    it('サーバーと同じ規則で断る', () => {
        expect(acceptableHref('//evil.example')).toBe(false);
        expect(acceptableHref('javascript:alert(1)')).toBe(false);
        expect(acceptableHref('#top')).toBe(true);
    });
});

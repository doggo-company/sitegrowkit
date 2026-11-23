import { describe, expect, it } from 'vitest';

import {
    findUnsupportedTags,
    HEADING_LEVELS,
    htmlForModel,
    initialRichtextMode,
    isAllowedLinkHref,
    normalizeLinkHref,
    VISUAL_RICHTEXT_COMMANDS,
} from '@/lib/richtext-editor';
import { RICHTEXT_COMMANDS } from '@/lib/richtext';

/**
 * 見たまま編集の判断（リリース完成 2026-09-09・R02）。
 *
 * 画面を開かずに決まることだけをここで見る。押せる・見える・保存されるは
 * `tests/e2e/daily-editing.spec.ts` が見る（`vitest.config.ts` の住み分け）。
 */
describe('リンク先の形', () => {
    it('http / https / mailto はそのまま通す', () => {
        expect(normalizeLinkHref('https://example.com/a?b=1#c')).toBe(
            'https://example.com/a?b=1#c',
        );
        expect(normalizeLinkHref('http://example.com')).toBe(
            'http://example.com',
        );
        expect(normalizeLinkHref('mailto:info@example.com')).toBe(
            'mailto:info@example.com',
        );
    });

    /**
     * 🔴 **相対 URL を落とさない**（2026-09-09 の指摘）。
     *
     * `Purifier` は相対 URL をそのまま残すので、**既存の本文に実在する**これらのリンクは
     * 見たまま編集で開いても保存しても消えてはいけない。以前の実装は
     * `../about` `./about` `about/team` `contact` を落としていた。
     */
    it.each([
        '/about',
        '#top',
        '?page=2',
        './about',
        '../about',
        '../../news/2026',
        'about/team',
        'contact',
        'contact.html',
        '/news?page=2#list',
        'ただの文字',
    ])('相対 URL はそのまま通す: %j', (href) => {
        expect(normalizeLinkHref(href)).toBe(href);
        expect(isAllowedLinkHref(href)).toBe(true);
    });

    it('scheme を落として打ったドメインには https を補う', () => {
        expect(normalizeLinkHref('example.com')).toBe('https://example.com');
        expect(normalizeLinkHref('www.example.co.jp/news/')).toBe(
            'https://www.example.co.jp/news/',
        );
    });

    /**
     * ドメインと相対パスの見分け。**迷ったら補わない** —
     * 相対 URL として残すほうが、既存の本文を壊さない。
     */
    it('相対パスにドメイン扱いの https を付けない', () => {
        expect(normalizeLinkHref('about/team')).toBe('about/team');
        expect(normalizeLinkHref('index.php')).toBe('index.php');
    });

    /**
     * 🔴 XSS を入れない。危ない scheme は名前ではなく
     * **「http / https / mailto 以外の scheme」**として断る。
     * 空白・制御文字・NUL を混ぜた形も、大文字も、素通しさせない。
     */
    it.each([
        'javascript:alert(1)',
        'JavaScript:alert(1)',
        'java\tscript:alert(1)',
        ' javascript:alert(1)',
        'java script:alert(1)',
        'java\u0000script:alert(1)',
        'data:text/html;base64,PHNjcmlwdD4=',
        'vbscript:msgbox',
        'ftp://example.com/file',
        'tel:0120-000-000',
        '',
        '   ',
    ])('通さない: %j', (href) => {
        expect(normalizeLinkHref(href)).toBeNull();
        expect(isAllowedLinkHref(href)).toBe(false);
    });

    /**
     * protocol-relative は**相対に見えて別サイトへ飛ぶ**。
     * backslash は browser が `/` として読むので、その変種も潰す。
     */
    it.each([
        '//evil.example/steal',
        '\\\\evil.example/steal',
        '/\\evil.example/steal',
        '\\/evil.example/steal',
        '/ /evil.example',
    ])('protocol-relative は通さない: %j', (href) => {
        expect(normalizeLinkHref(href)).toBeNull();
        expect(isAllowedLinkHref(href)).toBe(false);
    });

    it('未定義は通さない', () => {
        expect(isAllowedLinkHref(undefined)).toBe(false);
    });
});

describe('見たまま編集で扱えない HTML', () => {
    it('allowlist の tag だけなら 0 件', () => {
        expect(
            findUnsupportedTags(
                '<h2>題</h2><p>本文 <strong>太</strong> <a href="/a">リンク</a></p><ul><li>1</li></ul><img src="/x.png" alt="">',
            ),
        ).toEqual([]);
    });

    it('古い書き方（b / i / strike / del）は読み替えられるので数えない', () => {
        expect(
            findUnsupportedTags(
                '<p><b>太</b><i>斜</i><strike>消</strike><del>消</del></p>',
            ),
        ).toEqual([]);
    });

    it('table / div / span / iframe / h1 を数える（重複は 1 回）', () => {
        expect(
            findUnsupportedTags(
                '<div><H1>題</H1><table><tr><td>a</td></tr></table><span>x</span><iframe src="x"></iframe></div>',
            ),
        ).toEqual(['div', 'h1', 'table', 'tr', 'td', 'span', 'iframe']);
    });

    it('コメントの中の tag は数えない', () => {
        expect(findUnsupportedTags('<!-- <table> --><p>a</p>')).toEqual([]);
    });

    /**
     * この集合は **サーバーの `Purifier::ALLOWED` と同じ**でなければならない。
     * ずれると「見たままでは扱えないが公開では残る」（＝ HTML で編集し続ける意味がある）か、
     * 逆に「見たままでは扱えるのに公開で消える」（＝ 画面が嘘をつく）が生まれる。
     * PHP 側の `RichtextToolbarTest` はボタンの tag しか見ないので、ここで集合ごと固定する。
     */
    it('扱える tag は Purifier の allowlist と同じ集合（別名を除く）', () => {
        // `Purifier::ALLOWED` の tag（core/src/Html/Purifier.php）
        const purifierTags = [
            'p',
            'br',
            'strong',
            'em',
            'u',
            's',
            'blockquote',
            'ul',
            'ol',
            'li',
            'h2',
            'h3',
            'h4',
            'a',
            'img',
        ];

        expect(
            findUnsupportedTags(purifierTags.map((t) => `<${t}>`).join('')),
        ).toEqual([]);
    });

    it('扱えない tag が在れば HTML のまま開く', () => {
        expect(initialRichtextMode('')).toBe('visual');
        expect(initialRichtextMode('<p>a</p>')).toBe('visual');
        expect(initialRichtextMode('<table><tr><td>a</td></tr></table>')).toBe(
            'html',
        );
    });
});

describe('ボタンが出す tag', () => {
    /**
     * 公開で残る tag（`Purifier::ALLOWED`）と同じ集合を、ここにも写しておく。
     * 正本は PHP 側で、`RichtextToolbarTest` が両方のファイルの `tag:` を読んで突き合わせる。
     * ここは「見たままのボタンが、HTML 側のボタンより広くない」を見る。
     */
    const htmlToolbarTags = new Set(RICHTEXT_COMMANDS.map((c) => c.tag));

    it('見たまま編集のボタンは、HTML 側のボタンに a / u / h4 を足しただけ', () => {
        const visualTags = VISUAL_RICHTEXT_COMMANDS.map((c) => c.tag).filter(
            (tag): tag is string => tag !== null,
        );
        const extra = visualTags.filter((tag) => !htmlToolbarTags.has(tag));

        expect(extra.sort()).toEqual(['a', 'h4', 'u']);
    });

    it('undo / redo だけが tag を持たない', () => {
        expect(
            VISUAL_RICHTEXT_COMMANDS.filter((c) => c.tag === null).map(
                (c) => c.action,
            ),
        ).toEqual(['undo', 'redo']);
    });

    it('見出しの段階は h2 / h3 / h4', () => {
        expect([...HEADING_LEVELS]).toEqual([2, 3, 4]);
    });
});

describe('保存する値', () => {
    it('空の本文は空文字にする（必須の検査が効くように）', () => {
        expect(htmlForModel('<p></p>', true)).toBe('');
        expect(htmlForModel('<p>a</p>', false)).toBe('<p>a</p>');
    });
});

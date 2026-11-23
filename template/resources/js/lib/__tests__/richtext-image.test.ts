import { describe, expect, it } from 'vitest';

import {
    RICHTEXT_COMMANDS,
    insertRichtextImage,
} from '@/lib/richtext';

/**
 * 本文へ画像を挿す（WP 一周 7 周目 2026-09-03 / 効くもの ①）。
 *
 * ## 何が起きていたか
 *
 * 記事に写真を入れる道はあった — 本文は `textarea` なので `<img>` を手で書けば通る
 *（`img[src|alt|width|height]` は `Purifier::ALLOWED` に入っている）。
 * **ただし手で書くしかなかった。** 書式ボタンに画像は無く、
 * メディアの一覧に URL を写す手段も無い（並ぶのは「編集」「削除」だけ）。
 *
 * つまり編集する人は、HTML を書き、URL を自分で調べることを求められていた。
 * これは `richtext.ts` の先頭が掲げている目的そのものに反している:
 *
 * > ## HTML を手で書かせない、が目的である
 *
 * 書式については消えたのに、**画像だけその要求が残っていた**。
 */
describe('本文へ画像を挿す', () => {
    const image = {
        url: '/storage/media/abc.png',
        alt: '診療時間の表',
        width: 1200,
        height: 800,
    };

    const at = (value: string, pos: number) => ({ start: pos, end: pos });

    /** このサイトの origin（画面は `window.location.origin` を渡す） */
    const SITE = 'http://localhost:8180';

    it('カーソルの位置へ img を挿す', () => {
        const result = insertRichtextImage('<p>前</p>', at('<p>前</p>', 8), image);

        expect(result.value).toBe(
            '<p>前</p>\n<img src="/storage/media/abc.png" alt="診療時間の表" width="1200" height="800">',
        );
    });

    /** 選んでいる文字は**置き換えない**（消したつもりが無いのに消えるのが一番困る） */
    it('選んだ文字を消さずに、そのうしろへ挿す', () => {
        const value = 'まえ うしろ';
        const result = insertRichtextImage(value, { start: 0, end: 3 }, image);

        expect(result.value.startsWith('まえ')).toBe(true);
        expect(result.value).toContain('<img ');
        expect(result.value).toContain('うしろ');
    });

    /**
     * 🔴 **説明が無ければ `alt=""` を出す。**
     *
     * 属性ごと落とすと、読み上げが**ファイル名を読む**。
     * 空の `alt` は「飾りなので読み飛ばしてよい」という意味になる。
     */
    it('説明が無ければ空の alt を出す', () => {
        const result = insertRichtextImage('', at('', 0), {
            ...image,
            alt: null,
        });

        expect(result.value).toContain('alt=""');
    });

    /** 寸法が無い画像は、寸法の属性を出さない（嘘の値を書かない） */
    it('寸法が無ければ width と height を出さない', () => {
        const result = insertRichtextImage('', at('', 0), {
            ...image,
            width: null,
            height: null,
        });

        expect(result.value).toBe('<img src="/storage/media/abc.png" alt="診療時間の表">');
    });

    /** 🔴 名前は人が付ける。**属性の中で閉じられない**ようにする */
    it('説明の引用符と山括弧を逃がす', () => {
        const result = insertRichtextImage('', at('', 0), {
            ...image,
            alt: '"><script>alert(1)</script>',
        });

        expect(result.value).not.toContain('<script>');
        expect(result.value).toContain('&quot;');
    });

    /**
     * 🔴 **本文にドメインを焼き込まない**（7 周目 2026-09-03 実測）。
     *
     * メディアが返す URL は絶対（`http://localhost:8180/storage/…`）である。
     * そのまま書くと、**公開ドメインへ移した瞬間に記事の画像が全部旧ドメインを指す**。
     * 同じサイトのものなら、パスだけにする。
     */
    it('同じサイトの URL は、パスだけにして挿す', () => {
        const result = insertRichtextImage(
            '',
            at('', 0),
            { ...image, url: 'http://localhost:8180/storage/media/abc.png' },
            SITE,
        );

        expect(result.value).toContain('src="/storage/media/abc.png"');
        expect(result.value).not.toContain('localhost:8180');
    });

    /** よそのサイトの画像は、そのまま（勝手にパスへ落とすと 404 になる） */
    it('よそのサイトの URL はそのまま挿す', () => {
        const result = insertRichtextImage(
            '',
            at('', 0),
            { ...image, url: 'https://cdn.example.com/a.png' },
            SITE,
        );

        expect(result.value).toContain('src="https://cdn.example.com/a.png"');
    });

    it('url が無い（一般ファイル）ときは何も挿さない', () => {
        const value = 'そのまま';
        const result = insertRichtextImage(value, at(value, 4), {
            ...image,
            url: null,
        });

        expect(result.value).toBe(value);
    });

    /**
     * 挿したあとのカーソルは、**画像のうしろ**に置く。
     * 続けて書けるようにするためである。
     */
    it('カーソルを画像のうしろへ置く', () => {
        const result = insertRichtextImage('', at('', 0), image);

        expect(result.selection.start).toBe(result.value.length);
        expect(result.selection.end).toBe(result.value.length);
    });

    /**
     * 🔴 **ボタンの一覧に載せる。**
     *
     * `RichtextToolbarTest` は `RICHTEXT_COMMANDS` の `tag` を読んで
     * 「公開でも残る tag か」を検査している。ここに載せておかないと、
     * 画像だけその検査から外れる。
     */
    it('画像のボタンが一覧にあり、tag は img', () => {
        const media = RICHTEXT_COMMANDS.filter((c) => c.kind === 'media');

        expect(media).toHaveLength(1);
        expect(media[0].tag).toBe('img');
        expect(media[0].label).toBe('画像');
    });
});

import { describe, expect, it } from 'vitest';

import { suggestTaxonomyKey } from '@/lib/taxonomy-key';

/**
 * 表示名から分類キーの下書きを作る（WP 一周 6 周目 2026-09-03）。
 *
 * サーバー（`TaxonomyKey::suggestFrom`）と**同じ答え**でなければならない。
 * ずれると「画面に出ていたキーと、作られたキーが違う」になる。
 */
describe('分類キーの下書き', () => {
    it('英字の名前からは名前どおりに作る', () => {
        expect(suggestTaxonomyKey('Press Release')).toBe('press_release');
    });

    /** 🔴 `-` はキーに使えない（`^[a-z][a-z0-9_]*$`） */
    it('記号は _ へ寄せる', () => {
        expect(suggestTaxonomyKey('News / Topics')).toBe('news_topics');
        expect(suggestTaxonomyKey('  Case-Study  ')).toBe('case_study');
    });

    /** 日本語だけの名前からは作れない。**番号はここで作らない** */
    it('日本語だけなら空を返す', () => {
        expect(suggestTaxonomyKey('診療科目')).toBe('');
    });

    it('数字で始まる名前は使えないので空', () => {
        expect(suggestTaxonomyKey('2026 news')).toBe('');
    });

    it('空の名前は空', () => {
        expect(suggestTaxonomyKey('')).toBe('');
        expect(suggestTaxonomyKey('   ')).toBe('');
    });
});

import { describe, expect, it } from 'vitest';

import {
    formatDateTime,
    toDateOnly,
    toDateTimeLocal,
} from '@/lib/admin-datetime';

/**
 * 管理画面の日時は、**サーバーの時間帯**で読む（WP 一周 5 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * `published_at` は Carbon なので Inertia が ISO8601（UTC・末尾 Z）で流す。
 * 画面はそれを `String(value).replace('T', ' ').slice(0, 16)` で切っていた —
 * **UTC の文字をそのまま JST の欄へ入れていた**。
 *
 * 実測（`Asia/Tokyo`）: DB は `19:29:46` で正しいのに、
 * 一覧は `10:29`、編集の欄も `10:29`。**何も触らずに保存を押すと DB が `10:29` になる。**
 * もう一度押すと `01:29`。押すたびに 9 時間ずつ過去へ戻り、警告は出ない。
 *
 * ## ここで固定すること
 *
 * 1. 出す文字は**サーバーの時間帯**で読む（見ている人の時計に依存しない）
 * 2. **入れ直しても同じ時刻**（`toDateTimeLocal` の結果をサーバーが読み直しても動かない）
 * 3. 時間帯を持たない文字（`Y-m-d H:i:s`）は**動かさない** — フォームの受信日時は
 *    サーバーが既に現地時刻で渡しており、ずらすと今度はそちらが壊れる
 *
 * 🔴 **この試験は動かす側の時間帯に依存してはいけない。** CI は UTC で回る。
 * `process.env.TZ` を読まない書き方にしてある（`Intl` に時間帯を明示で渡す）。
 */
describe('admin-datetime', () => {
    const TOKYO = 'Asia/Tokyo';

    describe('formatDateTime', () => {
        it('UTC で届いた瞬間を、サーバーの時間帯で出す', () => {
            expect(formatDateTime('2026-09-03T10:29:46.000000Z', TOKYO)).toBe(
                '2026-09-03 19:29',
            );
        });

        it('日付をまたぐ側も正しく出す（UTC の 15:00 は翌日 0:00）', () => {
            expect(formatDateTime('2026-09-03T15:00:00.000000Z', TOKYO)).toBe(
                '2026-09-04 00:00',
            );
        });

        it('オフセット付き（+09:00）も同じ瞬間として読む', () => {
            expect(formatDateTime('2026-09-03T19:29:46+09:00', TOKYO)).toBe(
                '2026-09-03 19:29',
            );
        });

        it('時間帯を持たない文字は動かさない（フォームの受信日時）', () => {
            expect(formatDateTime('2026-09-03 19:44:12', TOKYO)).toBe(
                '2026-09-03 19:44',
            );
        });

        it('空・null は空文字にする（呼ぶ側が「—」を決める）', () => {
            expect(formatDateTime(null, TOKYO)).toBe('');
            expect(formatDateTime('', TOKYO)).toBe('');
            expect(formatDateTime(undefined, TOKYO)).toBe('');
        });

        it('読めない文字はそのまま返す（勝手に消さない）', () => {
            expect(formatDateTime('あとで', TOKYO)).toBe('あとで');
        });
    });

    describe('toDateTimeLocal', () => {
        it('datetime-local が読める形にする', () => {
            expect(toDateTimeLocal('2026-09-03T10:29:46.000000Z', TOKYO)).toBe(
                '2026-09-03T19:29',
            );
        });

        it('時間帯を持たない文字は切るだけ（T を補う）', () => {
            expect(toDateTimeLocal('2026-09-03 19:44:12', TOKYO)).toBe(
                '2026-09-03T19:44',
            );
        });

        /**
         * 🔴 **これが本体。** 画面が出した文字をサーバーが読み直しても、
         * 同じ瞬間でなければならない。ここが崩れると「触らず保存」で日時が動く。
         */
        it('触らず保存しても同じ瞬間のまま（往復して動かない）', () => {
            const fromServer = '2026-09-03T10:29:46.000000Z';

            const inTheInput = toDateTimeLocal(fromServer, TOKYO);

            // サーバーは時間帯を持たない文字を「サーバーの時間帯の時刻」として読む
            const readBackByServer = new Date(`${inTheInput}:00+09:00`);

            /*
             * 比べるのは **分まで**。`datetime-local` は分までしか持たないので、
             * 秒（`:46`）が落ちるのは元からの仕様で、欠陥ではない。
             * 動いてはいけないのは **分より上**である。
             */
            expect(readBackByServer.toISOString().slice(0, 16)).toBe(
                new Date(fromServer).toISOString().slice(0, 16),
            );
        });

        it('往復を 3 回繰り返しても動かない（毎回ずれるのが元の症状）', () => {
            const tokyoOffset = ':00+09:00';
            let value = '2026-09-03T10:29:46.000000Z';

            for (let i = 0; i < 3; i++) {
                const local = toDateTimeLocal(value, TOKYO);
                value = new Date(`${local}${tokyoOffset}`).toISOString();
            }

            expect(formatDateTime(value, TOKYO)).toBe('2026-09-03 19:29');
        });

        it('空は空のまま（欄を勝手に埋めない）', () => {
            expect(toDateTimeLocal(null, TOKYO)).toBe('');
            expect(toDateTimeLocal('', TOKYO)).toBe('');
        });
    });

    describe('toDateOnly', () => {
        it('日付だけの欄は、サーバーの時間帯の日付にする', () => {
            expect(toDateOnly('2026-09-03T15:00:00.000000Z', TOKYO)).toBe(
                '2026-09-04',
            );
        });

        /**
         * 時間帯を持たない日付を時間帯で読み直すと**前日に飛ぶ**。
         * `date` の欄は日付そのものなので、触らないのが正しい。
         */
        it('時間帯を持たない日付は動かさない', () => {
            expect(toDateOnly('2026-09-03', TOKYO)).toBe('2026-09-03');
            expect(toDateOnly('2026-09-03 00:00:00', TOKYO)).toBe('2026-09-03');
        });
    });
});

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { adminApi } from '@/lib/adminApi';

/**
 * 管理画面から操作 API を呼ぶ口（Phase 9.6 / gateway 一本化）。
 *
 * ## なぜ 1 つにするのか
 *
 * C5 と C7 で**同じ 4 種類の欠陥が 2 度**出た。どちらも「画面が作るもの」の形である:
 *
 * 1. XSRF の取り方を各々書いていた（meta タグ / cookie で分かれた）
 * 2. 応答の unwrap を推測で書いていた（`dataKey` で 1 段外れる）
 * 3. 確認ゲート（409）の扱いを画面ごとに書いていた
 * 4. 検証の誤り（422）を 1 行に潰していた
 *
 * 新しい画面を書くたびに 4 つ全部を思い出す必要があり、**1 つ忘れると
 * 「押しても何も起きない」**になる。だから薄い口を 1 つにする。
 *
 * ## 成功後の引き直しは入れない
 *
 * 何を引き直すかは画面ごとに違う。ここへ入れると**画面の都合が口に漏れる**。
 */
function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as Response;
}

describe('管理画面の操作 API', () => {
    /*
     * **DOM は持ち込まない。** vitest の設定は「画面を開かずに決まること」を最小で
     * 持つ方針なので、jsdom を足さずに `window` だけ差し替える
     * （この口が触るのは `window.document.cookie` だけである）。
     */
    beforeEach(() => {
        vi.stubGlobal('window', {
            document: { cookie: 'XSRF-TOKEN=tok%20en' },
        });
        vi.restoreAllMocks();
    });

    it('XSRF を cookie から付ける', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(jsonResponse(200, { data: null, meta: {} }));

        vi.stubGlobal('fetch', fetchMock);

        await adminApi.send('POST', '/api/v1/pages');

        const headers = fetchMock.mock.calls[0][1].headers as Record<
            string,
            string
        >;

        // **decode してから渡す**（cookie は URL encode されている）
        expect(headers['X-XSRF-TOKEN']).toBe('tok en');
    });

    it('確認の token は渡されたときだけ付ける', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(jsonResponse(200, { data: null, meta: {} }));

        vi.stubGlobal('fetch', fetchMock);

        await adminApi.send('POST', '/api/v1/pages');
        expect(
            (fetchMock.mock.calls[0][1].headers as Record<string, string>)[
                'X-Confirmation'
            ],
        ).toBeUndefined();

        await adminApi.send(
            'POST',
            '/api/v1/pages',
            {},
            { confirmation: 'c1' },
        );
        expect(
            (fetchMock.mock.calls[1][1].headers as Record<string, string>)[
                'X-Confirmation'
            ],
        ).toBe('c1');
    });

    it('成功したら data と meta を解いて返す', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    jsonResponse(200, {
                        data: { id: 7 },
                        meta: { version: 3 },
                    }),
                ),
        );

        const result = await adminApi.send('GET', '/api/v1/pages/7');

        expect(result.status).toBe('success');

        if (result.status === 'success') {
            expect(result.data).toEqual({ id: 7 });
            expect(result.meta).toEqual({ version: 3 });
        }
    });

    /** **確認は型で返す。** 画面ごとに 409 を読み解かせない */
    it('確認を求められたら確認として返す', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                jsonResponse(409, {
                    confirmation: { token: 't', summary: 's', impact: ['i'] },
                }),
            ),
        );

        const result = await adminApi.send('POST', '/api/v1/pages/7/publish');

        expect(result.status).toBe('confirmation');

        if (result.status === 'confirmation') {
            expect(result.confirmation.token).toBe('t');
        }
    });

    /** 確認を伴わない 409 は**競合**である（版がずれている） */
    it('確認の無い409は競合として返す', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    jsonResponse(409, { message: 'ずれています' }),
                ),
        );

        const result = await adminApi.send('PATCH', '/api/v1/pages/7/document');

        expect(result.status).toBe('conflict');

        if (result.status === 'conflict') {
            expect(result.message).toBe('ずれています');
        }
    });

    /** **項目ごとに返す。** 1 行に潰すと、どこを直せばよいか分からない */
    it('検証の誤りは項目ごとに返す', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                jsonResponse(422, {
                    message: '入力を確認してください',
                    errors: { key: ['形式が不正です'], label: ['必須です'] },
                }),
            ),
        );

        const result = await adminApi.send(
            'POST',
            '/api/v1/components/reusable',
        );

        expect(result.status).toBe('invalid');

        if (result.status === 'invalid') {
            expect(result.errors.key).toEqual(['形式が不正です']);
            expect(result.errors.label).toEqual(['必須です']);
        }
    });

    it('通信そのものの失敗も status で返す', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        const result = await adminApi.send('GET', '/api/v1/pages');

        expect(result.status).toBe('unreachable');
    });

    /** 応答が JSON でないとき（502 の HTML 等）も落ちない */
    it('JSONで返らなくても落ちない', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 502,
                json: async () => {
                    throw new Error('not json');
                },
            } as unknown as Response),
        );

        const result = await adminApi.send('GET', '/api/v1/pages');

        expect(result.status).toBe('failed');
    });
});

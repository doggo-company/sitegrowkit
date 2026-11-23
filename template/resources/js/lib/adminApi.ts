import type { OperationConfirmation } from '@/types/operation';

/**
 * 管理画面から操作 API を呼ぶ、**唯一の口**（Phase 9.6 / gateway 一本化）。
 *
 * ## なぜ 1 つにするのか
 *
 * C5 と C7 で**同じ 4 種類の欠陥が 2 度**出た。どちらも「画面が作るもの」の形である:
 *
 * 1. **XSRF の取り方**を各々書いていた（meta タグを読む画面と cookie を読む画面に分かれた）
 * 2. **応答の unwrap** を推測で書いていた（`ApiResponse::from` は `dataKey` で 1 段外す）
 * 3. **確認ゲート（409）**の扱いを画面ごとに書いていた
 * 4. **検証の誤り（422）**を 1 行に潰していた
 *
 * 新しい画面を書くたびに 4 つ全部を思い出す必要があり、**1 つ忘れると
 * 「押しても何も起きない」**になる。だから薄い口を 1 つにする。
 *
 * ## 入れないもの
 *
 * **成功したあとに何を引き直すか**は入れない。画面ごとに違うので、ここへ入れると
 * **画面の都合が口に漏れる**。返すのは「どうなったか」だけで、次にどうするかは画面が決める。
 *
 * **宛先も組み立てない。** どの API を呼ぶかは呼ぶ側の責任である
 * （`usePageDocument` が `/api/v1/pages/` へだけ送ることは、そちらの試験が固定している）。
 */

/**
 * 確認ゲートが返す文面（サーバーが出したものをそのまま見せる）。
 *
 * **型は既に在るものを使う。** 同じものに 2 つ名前を付けると、片方だけ直る。
 */
export type AdminConfirmation = OperationConfirmation;

/**
 * 何が起きたか。**画面に 409 や 422 を読み解かせない。**
 *
 * - `success` … 通った
 * - `confirmation` … **確認を求められた**（もう一度 token を付けて送る）
 * - `conflict` … **競合**（版がずれている等。確認とは別物である）
 * - `invalid` … 検証の誤り。**項目ごと**に返る
 * - `failed` … それ以外の断り（権限・見つからない・想定外）
 * - `unreachable` … 通信そのものが失敗した
 */
export type AdminResult<T = unknown> =
    | { status: 'success'; data: T; meta: Record<string, unknown> }
    | { status: 'confirmation'; confirmation: AdminConfirmation }
    | { status: 'conflict'; message: string | null }
    | {
          status: 'invalid';
          message: string | null;
          errors: Record<string, string[]>;
      }
    | { status: 'failed'; message: string | null; httpStatus: number }
    | { status: 'unreachable' };

export interface AdminApiOptions {
    /** 確認ゲートの token（求められた 1 度目のあと、同じ操作へ付けて送り直す） */
    confirmation?: string | null;
}

interface Envelope {
    data?: unknown;
    meta?: Record<string, unknown>;
    message?: string;
    errors?: Record<string, string[]>;
    confirmation?: AdminConfirmation;
}

/**
 * Laravel が発行する `XSRF-TOKEN` cookie を読む（同一オリジンのセッション経路）。
 *
 * **取り方はここだけ。** meta タグを読む形と cookie を読む形が混ざっていて、
 * 管理画面のレイアウトが meta を出していない画面で動かなかった（C7 の段 5）。
 */
function csrfToken(): string {
    const match = window.document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match === null ? '' : decodeURIComponent(match[1]);
}

async function send<T = unknown>(
    method: string,
    path: string,
    body?: unknown,
    options: AdminApiOptions = {},
): Promise<AdminResult<T>> {
    // ファイルを送るとき（FormData）は、境界つきの Content-Type をブラウザに付けさせる
    const isForm = body instanceof FormData;
    const headers: Record<string, string> = {
        ...(isForm ? {} : { 'Content-Type': 'application/json' }),
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
    };

    if (options.confirmation) {
        headers['X-Confirmation'] = options.confirmation;
    }

    let response: Response;

    try {
        response = await fetch(path, {
            method,
            headers,
            body:
                body === undefined
                    ? undefined
                    : isForm
                      ? body
                      : JSON.stringify(body),
        });
    } catch {
        return { status: 'unreachable' };
    }

    let payload: Envelope = {};

    try {
        payload = (await response.json()) as Envelope;
    } catch {
        // JSON で返らないこともある（502 の HTML 等）。**落ちない**
        payload = {};
    }

    if (response.ok) {
        return {
            status: 'success',
            data: payload.data as T,
            meta: payload.meta ?? {},
        };
    }

    /*
     * **確認と競合は同じ 409 で来る。** 見分けるのは `confirmation` の有無である。
     * ここを画面ごとに書くと、片方だけ扱う画面ができる。
     */
    if (response.status === 409) {
        return payload.confirmation
            ? { status: 'confirmation', confirmation: payload.confirmation }
            : { status: 'conflict', message: payload.message ?? null };
    }

    if (response.status === 422) {
        return {
            status: 'invalid',
            message: payload.message ?? null,
            errors: payload.errors ?? {},
        };
    }

    return {
        status: 'failed',
        message: payload.message ?? null,
        httpStatus: response.status,
    };
}

export const adminApi = { send };

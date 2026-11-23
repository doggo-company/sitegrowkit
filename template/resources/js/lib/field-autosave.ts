/**
 * ブロック編集の入力中の自動保存（UX 一周 3 周目 M5・9/27 の周から 3 回目の指摘）。
 *
 * ## 何が起きていたか
 *
 * 欄に打っても、**欄を離れるまで**保存もプレビューも変わらなかった。
 * 3 秒・8 秒待っても「未保存の変更あり」のまま。Tab で抜けた人だけが保存できた。
 *
 * ## いつ送るか
 *
 * - **打つ手が止まったら**（`delayMs`、既定 0.8 秒）送る
 * - **打ち続けていても**、最初の未送信の入力から `maxWaitMs`（既定 3 秒）経ったら送る。
 *   debounce だけにすると、**書き続けている人ほど守られない**（`useAutosave` の説明と同じ理由）
 * - 欄を離れたら（`commit`）**すぐ**送る。もう送った値と同じなら送らない（版を無駄に積まない）
 *
 * 判断だけを持つ（送る中身・送り先は `send` が持つ）。画面を開かずに試せるよう、時計は差し替えられる。
 */
export interface FieldAutosaveOptions {
    send: (key: string, value: string) => void;
    delayMs?: number;
    maxWaitMs?: number;
    clock?: {
        setTimeout: (fn: () => void, ms: number) => unknown;
        clearTimeout: (handle: unknown) => void;
        now: () => number;
    };
}

export interface FieldAutosave {
    /** 打っている（まだ送らない） */
    input: (key: string, value: string) => void;
    /** 欄を離れた（すぐ送る。送った値と同じなら送らない） */
    commit: (key: string, value: string) => void;
    /** 未送信の打ちかけを、いま送る（ブロックを替える前など） */
    flush: () => void;
    /** 送らずに捨てる */
    cancel: () => void;
    /** その欄で最後に送った値（無ければ undefined） */
    lastSent: (key: string) => string | undefined;
    /** 「最後に送った値」を忘れる（取り消しなどで、外から値が変わったとき） */
    forget: (key: string) => void;
}

export const FIELD_AUTOSAVE_DELAY_MS = 800;
export const FIELD_AUTOSAVE_MAX_WAIT_MS = 3000;

export function createFieldAutosave(
    options: FieldAutosaveOptions,
): FieldAutosave {
    const delayMs = options.delayMs ?? FIELD_AUTOSAVE_DELAY_MS;
    const maxWaitMs = options.maxWaitMs ?? FIELD_AUTOSAVE_MAX_WAIT_MS;
    const clock = options.clock ?? {
        setTimeout: (fn: () => void, ms: number) => setTimeout(fn, ms),
        clearTimeout: (handle: unknown) =>
            clearTimeout(handle as ReturnType<typeof setTimeout>),
        now: () => Date.now(),
    };

    /** 欄ごとの、まだ送っていない値 */
    const pending = new Map<string, string>();
    /** 欄ごとの、最後に送った値 */
    const sent = new Map<string, string>();
    /** 未送信が始まった時刻（打ち続けても maxWait で送るため） */
    let firstPendingAt: number | null = null;
    let timer: unknown = null;

    function clear(): void {
        if (timer !== null) {
            clock.clearTimeout(timer);
            timer = null;
        }
    }

    function sendOne(key: string, value: string): void {
        pending.delete(key);

        if (sent.get(key) === value) {
            return;
        }

        sent.set(key, value);
        options.send(key, value);
    }

    function flush(): void {
        clear();
        firstPendingAt = null;

        for (const [key, value] of [...pending]) {
            sendOne(key, value);
        }
    }

    function schedule(): void {
        clear();

        const now = clock.now();
        firstPendingAt ??= now;

        const untilMax = firstPendingAt + maxWaitMs - now;
        timer = clock.setTimeout(
            flush,
            Math.max(0, Math.min(delayMs, untilMax)),
        );
    }

    return {
        input(key, value) {
            pending.set(key, value);
            schedule();
        },
        commit(key, value) {
            pending.set(key, value);
            sendOne(key, value);

            if (pending.size === 0) {
                clear();
                firstPendingAt = null;
            }
        },
        flush,
        cancel() {
            clear();
            pending.clear();
            firstPendingAt = null;
        },
        lastSent(key) {
            return sent.get(key);
        },
        forget(key) {
            sent.delete(key);
        },
    };
}

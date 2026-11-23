import { onBeforeUnmount, onMounted } from 'vue';

/**
 * 自動保存の引き金（Phase 9.15 / STAGE 28B・28C・SPEC §C.1）。
 *
 * ## なぜ画面から出すのか
 *
 * 「いつ送るか」は**判断**であって、描画ではない。画面に置くと
 * **開かないと確かめられない**ものになる。判断は lib / composable へ出し、
 * 画面は呼ぶだけにする。
 *
 * ## 引き金は 2 つの条件が揃ったときだけ
 *
 * - **変更があった**（dirty）
 * - **前回から時間が経った**（interval）
 *
 * 入力の debounce にしない — **書き続けている人ほど守られない**。
 * 自動保存が要るのはまさに長文を書いているときなので、目的と逆になる。
 *
 * 定期 timer だけにもしない — 触っていない画面が版を積み続ける。
 *
 * ## dirty を下ろすのは、通ったときだけ
 *
 * 失敗したまま下ろすと、**失敗した内容が次の機会にも送られない**。
 * 送信中に新しい変更が来たら、その変更は**下ろさずに残す**。
 */
export interface AutosaveOptions {
    /** 送る本体。通ったら true を返す */
    save: () => Promise<boolean>;
    /** いま送ってよいか（明示保存の最中などは false） */
    enabled?: () => boolean;
    /** 送る間隔（ミリ秒） */
    intervalMs?: number;
}

export interface AutosaveController {
    /** 変更があったことを伝える */
    markDirty: () => void;
    /** いま溜まっているか（試験と画面の表示用） */
    isDirty: () => boolean;
    /** 間隔を待たずに 1 回試す（保存前・離脱前など） */
    flush: () => Promise<void>;
    /** timer を止める */
    stop: () => void;
}

export const AUTOSAVE_INTERVAL_MS = 30_000;

export function createAutosave(options: AutosaveOptions): AutosaveController {
    const intervalMs = options.intervalMs ?? AUTOSAVE_INTERVAL_MS;
    const enabled = options.enabled ?? (() => true);

    let dirty = false;
    let sending = false;
    let timer: ReturnType<typeof setInterval> | null = null;

    async function tick(): Promise<void> {
        if (!dirty || sending || !enabled()) {
            return;
        }

        sending = true;

        /*
         * **送る内容を確定した時点で dirty を下ろさない。**
         *
         * 送信中に来た変更まで「送った」ことにしてしまうと、その分が失われる。
         * 通ったあとで「その間に変更が無ければ」下ろす。
         */
        let changedWhileSending = false;
        const observe = (): void => {
            changedWhileSending = true;
        };

        watchers.push(observe);

        try {
            const saved = await options.save();

            if (saved && !changedWhileSending) {
                dirty = false;
            }
        } catch {
            // 通信が落ちても dirty は下ろさない（次の機会に送り直す）
        } finally {
            watchers.splice(watchers.indexOf(observe), 1);
            sending = false;
        }
    }

    const watchers: Array<() => void> = [];

    timer = setInterval(() => void tick(), intervalMs);

    return {
        markDirty(): void {
            dirty = true;
            watchers.forEach((notify) => notify());
        },
        isDirty(): boolean {
            return dirty;
        },
        flush: tick,
        stop(): void {
            if (timer !== null) {
                clearInterval(timer);
                timer = null;
            }
        },
    };
}

/** Vue component から使う形（mount で始まり、unmount で止まる） */
export function useAutosave(options: AutosaveOptions): AutosaveController {
    let controller: AutosaveController | null = null;

    const proxy: AutosaveController = {
        markDirty: () => controller?.markDirty(),
        isDirty: () => controller?.isDirty() ?? false,
        flush: async () => controller?.flush(),
        stop: () => controller?.stop(),
    };

    onMounted(() => {
        controller = createAutosave(options);
    });

    onBeforeUnmount(() => {
        controller?.stop();
        controller = null;
    });

    return proxy;
}

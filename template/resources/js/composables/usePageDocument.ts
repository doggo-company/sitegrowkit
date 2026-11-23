import { ref } from 'vue';
import type { Ref } from 'vue';

import { adminApi } from '@/lib/adminApi';
import type {
    AccessibilityFinding,
    DocumentCommand,
    PageDocument,
    PageVersionSummary,
} from '@/types/document';
import type { OperationConfirmation } from '@/types/operation';

/**
 * PageDocument の変更（Phase 9.6 / P0-C2・`PHASE9_6_SPEC.md` §5.1）。
 *
 * **変更経路は `PATCH /api/v1/pages/{id}/document` の 1 本だけ**。
 * 管理画面に 2 本目を作らない。Admin / CLI / MCP が同じ operation・同じ認可・
 * 同じ確認ゲート・同じ監査を通る（ADR-0012）。
 *
 * この composable が引き受けるのは transport だけで、判断はサーバーにある:
 *
 * - 破壊的操作は 409 + `confirmation` で返る → 承認したら `X-Confirmation` で送り直す
 * - 版がずれていれば 409 + `message` で返る → **黙って上書きしない**
 * - 検証エラーは 422 → 文言をそのまま出す
 *
 * ## 取り消しの履歴はここが持つ（Owner Gate V6-a）
 *
 * ただし**何が逆か**を決めるのはサーバーである（`meta.undo`）。こちらは
 * 積んで、押されたら送り返すだけ。逆を組み立てさせると、消える node の id を
 * クライアントが握ることになる（`PHASE9_6_SPEC.md` §4.10）。
 *
 * **履歴はこの画面を閉じるまで**である。リロードで消えるのは V6-a の帰結で、
 * 削除の確認文言もそう言っている。
 */
/**
 * 何手まで戻せるか（`PHASE9_6_SPEC.md` §4.10）。
 *
 * 履歴は画面が持っているので、上限はメモリの話でもある。
 * 超えたぶんは**古い側から落とす**。
 */
const UNDO_LIMIT = 100;

export function usePageDocument(
    pageId: number,
    document: Ref<PageDocument>,
    version: Ref<number>,
    findings: Ref<AccessibilityFinding[]>,
) {
    const saving = ref(false);
    const error = ref<string | null>(null);
    const conflict = ref<string | null>(null);
    const confirmation = ref<OperationConfirmation | null>(null);
    /** 確認待ちのあいだ保持しておく command（承認されたら同じものを送り直す） */
    const pending = ref<DocumentCommand | null>(null);
    /** 取り消せること／やり直せること。**積むのは送った結果であって、送ったものではない** */
    const undoStack = ref<DocumentCommand[]>([]);
    const redoStack = ref<DocumentCommand[]>([]);
    /** いま送っているものの出どころ（積む先が変わる） */
    const origin = ref<'edit' | 'undo' | 'redo'>('edit');
    /**
     * まだ送っていない入力があるか。
     *
     * 送るのは入力欄を離れたときなので、**打っている最中はまだサーバーに無い**。
     * そこを「保存済み」と出すと、閉じてよいと読める。
     */
    const dirty = ref(false);
    /**
     * 打ちかけの「代」（UX 一周 3 周目 M5）。
     *
     * 入力中も自動で送るようになったので、**送っている最中に次の文字が打たれる**。
     * 送れたからといって下ろすと、まだ送っていない打ちかけまで「保存済み」と出る。
     * 送り始めた時点の代と同じときだけ下ろす。
     */
    let dirtyGeneration = 0;
    /** 確認待ちの復元先（承認されたら同じ版へ戻す） */
    const restoring = ref<number | null>(null);

    async function send(
        command: DocumentCommand,
        token: string | null = null,
    ): Promise<boolean> {
        saving.value = true;
        error.value = null;
        conflict.value = null;
        const generation = dirtyGeneration;

        try {
            const result = await adminApi.send<PageDocument>(
                'PATCH',
                `/api/v1/pages/${pageId}/document`,
                { command },
                { confirmation: token },
            );

            if (result.status === 'success') {
                document.value = result.data;
                version.value =
                    (result.meta.version as number | undefined) ??
                    version.value;
                // 直した瞬間に消える／出るよう、毎回サーバーの検査結果へ入れ替える
                findings.value =
                    (result.meta.accessibility as
                        AccessibilityFinding[] | undefined) ?? [];
                confirmation.value = null;
                pending.value = null;

                remember(
                    (result.meta.undo as DocumentCommand | null | undefined) ??
                        null,
                );

                // 送れたので、抱えていた入力はもう無い（送っている間に打たれたものは残す）
                if (generation === dirtyGeneration) {
                    dirty.value = false;
                }

                return true;
            }

            if (result.status === 'confirmation') {
                confirmation.value = result.confirmation;
                pending.value = command;

                return false;
            }

            if (result.status === 'conflict') {
                // 版がずれている。取り込み直すまで編集を続けさせない
                conflict.value =
                    result.message ??
                    'このページは他の編集で更新されています。読み込み直してください。';

                return false;
            }

            if (result.status === 'unreachable') {
                error.value = '通信に失敗しました。接続を確認してください。';

                return false;
            }

            error.value =
                result.status === 'invalid'
                    ? Object.values(result.errors).flat().join(' ') ||
                      result.message ||
                      '保存できませんでした'
                    : (result.message ?? '保存できませんでした');

            return false;
        } finally {
            saving.value = false;
        }
    }

    /**
     * 返ってきた取り消しを積む。**出どころで積む先が変わる。**
     *
     * - 編集 → 取り消せるようになる。やり直しの列は**捨てる**
     *   （枝分かれした先へ「やり直し」で戻れると、どこへ戻るのか言えなくなる）
     * - 取り消し → その取り消しがやり直しになる
     * - やり直し → その取り消しが、また取り消しになる
     */
    function remember(undo: DocumentCommand | null): void {
        if (undo === null) {
            /*
             * サーバーが取り消しを組み立てられなかった。
             * **積まずに、積んであるものも捨てる** — 戻れない一手を挟んだまま
             * 「戻れる」と見せると、押した人は違うところへ着く。
             *
             * **`binding` はもうここへ来ない**（P0-C5 で `binding: null` が
             * 「外す」になり、初めて付けた操作も逆を組み立てられるようになった）。
             * 残っているのは `key` を初めて付けた場合で、**この画面は `key` を
             * 送らない**。つまりこの経路は、いまの編集画面からは通らない。
             *
             * **`key` を送る口を作る人へ**: この経路が復活したら、
             * 「ここより前へは戻れません」と**画面で言う要件も復活する**（§4.10）。
             * 黙って履歴だけ捨てると、押した人は違うところへ着く。
             */
            undoStack.value = [];
            redoStack.value = [];

            return;
        }

        if (origin.value === 'undo') {
            redoStack.value.push(undo);

            return;
        }

        undoStack.value.push(undo);

        /*
         * **何手戻せるかに上限を置く**（100 手）。
         *
         * 上限が無いと、長い編集ほど積み上がったまま画面が閉じるまで解放されない。
         * 落とすのは**いちばん古い側**である — 直前の操作から順に戻すので、
         * 古い方から失う方が「押した人の期待」に近い。
         */
        if (undoStack.value.length > UNDO_LIMIT) {
            undoStack.value = undoStack.value.slice(-UNDO_LIMIT);
        }

        if (origin.value === 'edit') {
            redoStack.value = [];
        }
    }

    /** 1 回目の送信（token なし）。破壊的操作はここで確認要求が返る */
    function run(command: DocumentCommand): Promise<boolean> {
        confirmation.value = null;
        origin.value = 'edit';

        return send(command);
    }

    /** 直前の変更を取り消す。**送るのは、さっきサーバーが返した逆の command** */
    async function undo(): Promise<boolean> {
        const command = undoStack.value.pop();

        if (!command) {
            return false;
        }

        confirmation.value = null;
        origin.value = 'undo';

        const ok = await send(command);

        // 断られたら（確認待ち・conflict）積み直す。押した事実を失わない
        if (!ok) {
            undoStack.value.push(command);
        }

        return ok;
    }

    /** 取り消したものを、もう一度やる */
    async function redo(): Promise<boolean> {
        const command = redoStack.value.pop();

        if (!command) {
            return false;
        }

        confirmation.value = null;
        origin.value = 'redo';

        const ok = await send(command);

        if (!ok) {
            redoStack.value.push(command);
        }

        return ok;
    }

    /** 利用者が確認ダイアログを承認した（2 回目の送信） */
    function accept(): Promise<boolean> {
        const token = confirmation.value?.token ?? null;
        const command = pending.value;
        const revision = restoring.value;

        confirmation.value = null;
        pending.value = null;
        restoring.value = null;

        if (!token) {
            return Promise.resolve(false);
        }

        // 復元の確認も同じダイアログを通る（**確認の口を 2 つ作らない**）
        if (revision !== null) {
            return restore(revision, token);
        }

        return command ? send(command, token) : Promise.resolve(false);
    }

    /**
     * 版の一覧（`PHASE9_6_SPEC.md` §4.12）。**中身は返らない** — いつ・誰が・どの保存か、だけ。
     */
    async function versions(): Promise<PageVersionSummary[]> {
        const result = await adminApi.send<{ versions?: PageVersionSummary[] }>(
            'GET',
            `/api/v1/pages/${pageId}/versions`,
        );

        if (result.status === 'unreachable') {
            error.value = '通信に失敗しました。接続を確認してください。';

            return [];
        }

        return result.status === 'success' ? (result.data?.versions ?? []) : [];
    }

    /**
     * 過去の版へ戻す。**新しい draft ができるだけ**で、公開面も過去の版も変わらない。
     *
     * 確認は削除と同じ経路（`X-Confirmation`）を通る。
     * **取り消しの履歴は捨てる** — 戻した先から「さっきの取り消し」へ進むと、
     * どこへ戻るのか言えなくなる。
     */
    async function restore(
        revision: number,
        token: string | null = null,
    ): Promise<boolean> {
        saving.value = true;
        error.value = null;

        try {
            const result = await adminApi.send<PageDocument>(
                'POST',
                `/api/v1/pages/${pageId}/versions/restore`,
                { revision },
                { confirmation: token },
            );

            if (result.status === 'success') {
                document.value = result.data;
                version.value =
                    (result.meta.version as number | undefined) ??
                    version.value;
                undoStack.value = [];
                redoStack.value = [];
                dirty.value = false;
                confirmation.value = null;

                return true;
            }

            if (result.status === 'confirmation') {
                confirmation.value = result.confirmation;
                restoring.value = revision;

                return false;
            }

            error.value =
                result.status === 'unreachable'
                    ? '通信に失敗しました。接続を確認してください。'
                    : ((result as { message?: string | null }).message ??
                      '戻せませんでした');

            return false;
        } finally {
            saving.value = false;
        }
    }

    /** 入力が始まった（保存はまだ）。表示を「未保存の変更あり」へ倒す */
    function markDirty(): void {
        dirtyGeneration += 1;
        dirty.value = true;
    }

    function dismiss(): void {
        confirmation.value = null;
        pending.value = null;
        restoring.value = null;
    }

    return {
        saving,
        error,
        conflict,
        confirmation,
        run,
        accept,
        dismiss,
        undo,
        redo,
        undoStack,
        redoStack,
        dirty,
        markDirty,
        versions,
        restore,
    };
}

import { ref } from 'vue';

import { adminApi } from '@/lib/adminApi';
import type { AdminConfirmation, AdminResult } from '@/lib/adminApi';
import type { DocumentNode } from '@/types/document';

/**
 * 再利用ブロックの中身を読み書きする（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## `usePageDocument` を流用しない
 *
 * あれは `pageId` から URL を組み立てており、**「送信先は全部 `/api/v1/pages/`」を
 * `PageAdminTest` が固定している**。流用するとその守りを緩めることになる。
 * 画面の部品（Canvas / Layers / Inspector）は流用し、**書き込みだけ別にする**。
 *
 * ただし**送り方は共有する**（`adminApi`）。XSRF・応答の unwrap・確認・検証の誤りを
 * 画面ごとに書くと、**1 つ忘れたときに「押しても何も起きない」**になる（C5 / C7 の教訓）。
 *
 * ## 版も取り消しも持たない
 *
 * 実体は draft / published の 2 状態だけで、版の履歴を持たない（§4.5）。
 * 共有物の版は「**誰の操作を戻すか**」が未定義になるからである。
 * だから `expectedVersion` も無い — **後から保存した人が勝つ**。
 * 複数人で同じ実体を同時に直す運用は、v1 では想定しない。
 */
export function useReusableDocument(id: string, initial: DocumentNode) {
    const document = ref<DocumentNode>(initial);
    const error = ref<string | null>(null);
    const saving = ref(false);
    // 型は口のものを使う（同じものに 2 つ名前を付けない）
    const confirmation = ref<AdminConfirmation | null>(null);

    /** 中身を保存する。**公開には出ない**（`publish` を通してから） */
    async function save(next: DocumentNode): Promise<boolean> {
        return send(
            'PATCH',
            `/api/v1/components/reusable/${id}`,
            { document: next },
            () => {
                document.value = next;
            },
        );
    }

    /**
     * 公開する。
     *
     * **確認はサーバーが出す**（`ConfirmationGate`）。画面が独自に訊くと、
     * 文面が 2 か所になり、片方だけ直る。
     */
    async function publish(token: string | null = null): Promise<boolean> {
        return send(
            'POST',
            `/api/v1/components/reusable/${id}/publish`,
            {},
            () => {},
            token,
        );
    }

    /**
     * 送る。**XSRF・応答の unwrap・確認・検証の誤りは `adminApi` が引き受ける。**
     *
     * ここが持つのは「この画面がその結果をどう見せるか」だけである。
     */
    async function send(
        method: string,
        url: string,
        body: Record<string, unknown>,
        onSuccess: () => void,
        token: string | null = null,
    ): Promise<boolean> {
        error.value = null;
        saving.value = true;

        try {
            const result = await adminApi.send(method, url, body, {
                confirmation: token,
            });

            if (result.status === 'confirmation') {
                // 確認を求められた。**文面はサーバーが出したものをそのまま見せる**
                confirmation.value = result.confirmation;

                return false;
            }

            if (result.status === 'success') {
                confirmation.value = null;
                onSuccess();

                return true;
            }

            error.value = message(result);

            return false;
        } finally {
            saving.value = false;
        }
    }

    /** 断られた理由を 1 行にする。**検証の誤りは項目ごとに来る**ので、そこは並べる */
    function message(result: AdminResult): string {
        if (result.status === 'invalid') {
            const details = Object.values(result.errors).flat().join(' / ');

            return details !== ''
                ? details
                : (result.message ?? '保存できませんでした');
        }

        if (result.status === 'unreachable') {
            return '通信に失敗しました。接続を確認してください。';
        }

        return result.status === 'conflict' || result.status === 'failed'
            ? (result.message ?? '保存できませんでした')
            : '保存できませんでした';
    }

    return { document, error, saving, confirmation, save, publish };
}

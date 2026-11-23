/**
 * フォームの識別子を、利用者に書かせずに決める（R-11 ①）。
 *
 * ## なぜ画面から消さないのか
 *
 * 項目のキー（`field-key.ts`）と違って、**識別子は公開 URL に出る**。
 * `/forms/contact` と `/forms/form-1` では、配られたときの伝わり方が違う。
 * だから**下書きを入れて、直せるようにする** — 消しはしない。
 *
 * ## 作れないことを隠さない
 *
 * 使えるのは `^[a-z][a-z0-9_-]{1,63}$` だけで、「お問い合わせ」からは 1 文字も取れない。
 * **無理に音訳しない**（読み方を推測すると、利用者の意図と違う URL が配られる）。
 * 取れないときは空を返し、**サーバ側が通し番号へ落とす**。
 *
 * ## 規則の正本はサーバ側
 *
 * ここは同じ規則を TypeScript で持つ写しである（下書きを出すために要る）。
 * **同じ表**（`resources/js/lib/__tests__/form-key-cases.json`）で両方を突き合わせているので、
 * 片方だけ触ると、どちらかの試験が落ちる。
 *
 * **式が 2 か所に在るのは意図して残した**（2026-08-22 の裁定）。
 * 生成で 1 本にすると「作り直し忘れ」という新しい失敗が増える。
 * 詳しくは正本側（`AgentCms\Core\Forms\FormKey`）の説明を読むこと。
 */
/** 切り詰める長さ（`AgentCms\Core\Forms\FormKey::MAX_LENGTH` と同じ値） */
const MAX_LENGTH = 60;

/** 使える形（`FormKey::PATTERN` と同じ式） */
const PATTERN = /^[a-z][a-z0-9_-]{1,63}$/;

export function slugifyFormKey(name: string): string {
    const slug = name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, MAX_LENGTH)
        // **切った端の区切りを残さない**（指摘 42）。
        // 残すと `/forms/ab-…-ab-` がそのまま配られる
        .replace(/^-+|-+$/g, '');

    // 先頭は英字・2 文字以上（1 文字は保存側が断るので、ここでも作らない — 指摘 41）
    return PATTERN.test(slug) ? slug : '';
}

/**
 * ボタンなどの「リンク先」の欄に打たれた文字を、送る前に決める（UX 一周 5 周目 R5-1）。
 *
 * ページの名前（「お問い合わせ」）を打つと、サーバーが「使えないリンク先です」と断り、欄は黙って
 * 元の `#` に戻った。理由は画面のいちばん上にしか出ず、欄のあたりにいる人には見えなかった。
 *
 * - サイト内のページの名前なら、その URL にする（候補の名前と同じとき）
 * - サーバーが受ける形（`DocumentValidator::checkUrl` と同じ規則）でなければ、送らずに欄の下で言う
 */

export type LinkTarget = { href: string; label: string };

export type LinkInput =
    | { ok: true; value: string }
    | { ok: false; message: string };

const compact = (text: string): string => text.replace(/[\s　]+/g, '');

/** サーバーが受ける形か（`DocumentValidator::checkUrl` と同じ） */
export function acceptableHref(value: string): boolean {
    if (/[\x00-\x20\x7f]/.test(value)) {
        return false;
    }

    if (value.startsWith('//') || value.startsWith('/\\')) {
        return false;
    }

    if (value.startsWith('/') || value.startsWith('#')) {
        return true;
    }

    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(value)?.[1]?.toLowerCase();

    return scheme !== undefined && ['http', 'https', 'mailto', 'tel'].includes(scheme);
}

export function resolveLinkInput(raw: string, targets: LinkTarget[]): LinkInput {
    const value = raw.trim();

    if (value === '') {
        return { ok: true, value: '' };
    }

    const byName = targets.find((t) => compact(t.label) === compact(value));

    if (byName) {
        return { ok: true, value: byName.href };
    }

    if (acceptableHref(value)) {
        return { ok: true, value };
    }

    const examples = targets
        .slice(0, 2)
        .map((t) => `${t.label} → ${t.href}`)
        .join('、');

    return {
        ok: false,
        message: `「${value}」はリンク先にできません。サイト内のページは名前か「/」から始まる URL（${examples || '例: /contact'}）、外のサイトは https:// から書いてください。`,
    };
}

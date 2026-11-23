/**
 * 通知先の**画面での見せ方**（R-11 ⑤）。
 *
 * 保存形はカンマ区切りの 1 カラム（`AgentCms\Core\Forms\NotifyRecipients`）。
 * 画面は **1 行 1 アドレス**にする — カンマ区切りの 1 行は、
 * 長くなると「どこで切れているか」が読めず、綴り違いを見つけられない。
 *
 * 保存側が正規化するので、**ここでは整えない**（余分な空白も、そのまま送って怒られる方がよい）。
 */
export function notifyLines(stored: string | null | undefined): string {
    return (stored ?? '')
        .split(',')
        .map((one) => one.trim())
        .filter((one) => one !== '')
        .join('\n');
}

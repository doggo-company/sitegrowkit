import { homedir } from "node:os";
/**
 * 詳細ログの秘匿処理（Phase 8.5-B）。
 * 「詳細を表示」で見せる technical log から、認証情報・token を除去する。
 * ログは画面にもファイルにも残り得るため、engine から GUI へ渡る前に必ず通す。
 */
const MASK = "********";
const PATTERNS = [
    // 端末の色・カーソル制御（ESC [ … m など）。画面では `[2m[22m` のような文字として出てしまう
    { re: /\u001b\[[0-9;?]*[ -/]*[@-~]/g, replace: "" },
    // KEY=value / "key": "value" / key: value 形式の秘密値
    {
        // key 側は `KEY=` と `"key":` の両方を受ける（JSON 形式の秘密値を取り逃がさない）
        re: /((?:password|passwd|secret|token|api[_-]?key|access[_-]?key|authorization|bearer)"?\s*[:=]\s*)("?)([^\s"',}]+)\2/gi,
        replace: `$1$2${MASK}$2`,
    },
    // Laravel APP_KEY
    { re: /base64:[A-Za-z0-9+/=]{20,}/g, replace: `base64:${MASK}` },
    // 一般的な長い token 形式（JWT など・ドット区切りの 3 つ組）
    {
        re: /\b[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\b/g,
        replace: MASK,
    },
];
/** 1 行分の technical log を秘匿処理する */
export function sanitizeLogLine(line) {
    let out = line;
    for (const p of PATTERNS)
        out = out.replace(p.re, p.replace);
    // ホームディレクトリの実パスは画面に出さない（ユーザー名の露出を避ける）
    const home = homedir();
    if (home && home !== "/")
        out = out.split(home).join("~");
    return out;
}
/** 与えられた値がログに出してはいけない文字列を含むかの簡易判定（テスト用） */
export function containsSecret(text, secret) {
    return secret.length > 0 && text.includes(secret);
}

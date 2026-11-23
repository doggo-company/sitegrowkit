/**
 * Figma Text Layer の secret redaction（SPEC §91）。
 * credential らしき文字列を Manifest へそのまま複製しない。
 * 高確度パターンのみ（誤検知でデザインテキストを壊さない）。
 */
const SECRET_PATTERNS = [
    /figd_[A-Za-z0-9_-]{8,}/g,
    /sk_live_[0-9a-zA-Z]{16,}/g,
    /sk-ant-[0-9a-zA-Z-]{20,}/g,
    /gh[pousr]_[0-9a-zA-Z]{30,}/g,
    /AKIA[0-9A-Z]{16}/g,
    /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----[\s\S]*/g,
    /\b(?:password|passwd|secret|api[_-]?key|access[_-]?token)\s*[:=]\s*\S{6,}/gi,
];
export function redactDesignText(text) {
    let out = text;
    let redacted = false;
    for (const re of SECRET_PATTERNS) {
        re.lastIndex = 0;
        if (re.test(out)) {
            redacted = true;
            re.lastIndex = 0;
            out = out.replace(re, "[REDACTED]");
        }
    }
    return { text: out, redacted };
}

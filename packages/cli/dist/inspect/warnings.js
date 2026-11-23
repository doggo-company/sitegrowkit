import { readFileSync } from "node:fs";
import { join } from "node:path";
const TEXT_EXT = /\.(html?|css|m?js|json|txt|svg)$/i;
/** 値らしき部分は redact する（実 secret を Manifest へコピーしない — spec §55） */
const SECRET_RE = /(api[_-]?key|apikey|secret|token|password|passwd|private[_-]?key)['"]?\s*[:=]\s*['"]([^'"]{8,})['"]/gi;
const AWS_KEY_RE = /\bAKIA[0-9A-Z]{16}\b/g;
const SPA_IMPORT_RE = /from\s+['"](react|react-dom|vue|next|nuxt|svelte)['"]/;
/** Manifest の warnings を決定論的に収集する（secrets / unsupported / build-tool / form / missing asset） */
export function collectWarnings(sourceDir, files, ctx) {
    const warnings = [];
    for (const page of ctx.emptyPages) {
        warnings.push({
            kind: "empty-page",
            file: page,
            message: "実質空の HTML（0 byte または本文なし）。ページ候補から除外した（home への誤マッチ防止）",
        });
    }
    for (const file of [...files].sort()) {
        // build tool / 非対応スタック入力の検出（spec §46, §47）
        if (/\.(scss|less|sass)$/i.test(file)) {
            warnings.push({
                kind: "build-tool",
                file,
                message: "Sass/Less ソースを検出。Phase 5 はビルド済み CSS のみ対象（ビルド出力があればそれを利用）",
            });
        }
        if (/(^|\/)(webpack|gulpfile|rollup|parcel)\.\w+\.?\w*$/i.test(file)) {
            warnings.push({
                kind: "build-tool",
                file,
                message: "ビルドツール設定を検出。Phase 5 の変換対象外",
            });
        }
        if (/\.php$/i.test(file)) {
            warnings.push({
                kind: "unsupported",
                file,
                message: "PHP ファイルを検出。server-rendered application は UNSUPPORTED",
            });
        }
        if (!TEXT_EXT.test(file))
            continue;
        const text = readFileSync(join(sourceDir, file), "utf8");
        for (const m of text.matchAll(SECRET_RE)) {
            warnings.push({
                kind: "secret",
                file,
                message: `credential らしき値: ${m[1]} = "${(m[2] ?? "").slice(0, 4)}…"（redacted）`,
            });
        }
        for (const m of text.matchAll(AWS_KEY_RE)) {
            warnings.push({
                kind: "secret",
                file,
                message: `AWS アクセスキーらしき値: ${(m[0] ?? "").slice(0, 8)}…（redacted）`,
            });
        }
    }
    for (const jsFile of ctx.js.files) {
        if (jsFile.uses_modules) {
            const text = readFileSync(join(sourceDir, jsFile.path), "utf8");
            if (SPA_IMPORT_RE.test(text)) {
                warnings.push({
                    kind: "unsupported",
                    file: jsFile.path,
                    message: "SPA フレームワークの import を検出（React/Vue/Next/Nuxt は UNSUPPORTED — spec §46）",
                });
            }
        }
    }
    for (const { page, selector } of ctx.emptyMountPages) {
        warnings.push({
            kind: "unsupported",
            file: page,
            message: `空の SPA mount point らしき要素 ${selector} を検出。REVIEW REQUIRED`,
        });
    }
    for (const form of ctx.forms) {
        if (form.external_action) {
            warnings.push({
                kind: "external-form-action",
                file: form.page,
                message: `フォームが外部 endpoint (${form.action}) へ送信している。Contact Pipeline への接続候補`,
            });
        }
        if (form.has_file_upload) {
            warnings.push({
                kind: "security",
                file: form.page,
                message: "ファイルアップロード入力を検出。アップロード規約（allow-list / MIME 検証）の確認が必要",
            });
        }
    }
    for (const cssFile of ctx.css.files) {
        for (const ref of cssFile.url_refs) {
            if (!ref.exists) {
                warnings.push({
                    kind: "missing-asset",
                    file: cssFile.path,
                    message: `CSS が参照する ${ref.ref} が見つからない`,
                });
            }
        }
    }
    return warnings.sort((a, b) => a.kind.localeCompare(b.kind) ||
        a.file.localeCompare(b.file) ||
        a.message.localeCompare(b.message));
}

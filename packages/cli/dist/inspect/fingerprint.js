import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { join } from "node:path";
/** source 全ファイルの fingerprint（apply 時の stale Plan 検出に使う） */
export function fingerprintSource(sourceDir, files) {
    const entries = [...files].sort().map((path) => {
        const buf = readFileSync(join(sourceDir, path));
        return {
            path,
            // 変更検出用途なので先頭 16 桁で十分（Manifest サイズ削減）
            sha256: createHash("sha256").update(buf).digest("hex").slice(0, 16),
            bytes: buf.length,
        };
    });
    const total = createHash("sha256")
        .update(entries.map((e) => `${e.path}:${e.sha256}`).join("\n"))
        .digest("hex");
    return { algorithm: "sha256", total, files: entries };
}

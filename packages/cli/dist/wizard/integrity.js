import { createHash } from "node:crypto";
import { existsSync, readFileSync, statSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
/**
 * 配布物の自己検査（Phase 8.5-D / D7）。
 *
 * 利用者に `shasum` 等を打たせないため、**起動時に自動で**重要ファイルの
 * SHA-256 を検証する。対象は packaging 時に生成した integrity manifest。
 *
 * 注意（意図的な限界）: これは配布物**内部**の改変・欠損の検知であり、
 * ダウンロードした ZIP 自体の暗号学的な真正性を保証するものではない。
 * 公開配布では platform の署名 / notarization を併用する。
 */
export const INTEGRITY_MANIFEST = "integrity.json";
export function sha256File(path) {
    return createHash("sha256").update(readFileSync(path)).digest("hex");
}
/**
 * manifest を探す。配布物では CLI の dist の 1〜3 階層上に置かれる。
 * 見つからなければ「検証しない」（開発ツリーからの実行）。
 */
export function findManifest(startDir) {
    let dir = startDir ?? dirname(fileURLToPath(new URL(".", import.meta.url).href));
    for (let i = 0; i < 5; i++) {
        const candidate = join(dir, INTEGRITY_MANIFEST);
        if (existsSync(candidate))
            return candidate;
        const parent = resolve(dir, "..");
        if (parent === dir)
            break;
        dir = parent;
    }
    return null;
}
/** manifest に列挙されたファイルを検証する。例外は投げない */
export function verifyIntegrity(manifestPath) {
    const path = manifestPath === undefined ? findManifest() : manifestPath;
    if (!path || !existsSync(path)) {
        return { checked: false, ok: true, problems: [] };
    }
    let manifest;
    try {
        manifest = JSON.parse(readFileSync(path, "utf8"));
    }
    catch {
        return {
            checked: true,
            ok: false,
            problems: [{ kind: "missing", path: INTEGRITY_MANIFEST }],
            manifestPath: path,
        };
    }
    const base = dirname(path);
    const problems = [];
    for (const entry of manifest.files ?? []) {
        const target = join(base, entry.path);
        if (!existsSync(target)) {
            problems.push({ kind: "missing", path: entry.path });
            continue;
        }
        try {
            if (statSync(target).size !== entry.size) {
                problems.push({ kind: "size", path: entry.path });
                continue;
            }
            if (sha256File(target) !== entry.sha256) {
                problems.push({ kind: "modified", path: entry.path });
            }
        }
        catch {
            problems.push({ kind: "missing", path: entry.path });
        }
    }
    return {
        checked: true,
        ok: problems.length === 0,
        problems,
        manifestPath: path,
    };
}
/** 利用者向けの文言（技術的な詳細は details 側に置く） */
export function integrityFailureMessage() {
    return {
        title: "SiteGrowKit のファイルを確認できませんでした。",
        message: "ダウンロードしたファイルが壊れているか、一部が失われている可能性があります。もう一度ダウンロードして展開し直してください。",
    };
}

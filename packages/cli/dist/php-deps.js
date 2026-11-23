import { existsSync, lstatSync, rmSync } from "node:fs";
import { isAbsolute, join, resolve } from "node:path";
export function composerState(root) {
    if (!existsSync(join(root, "vendor")))
        return "absent";
    if (existsSync(join(root, "vendor", "autoload.php")))
        return "complete";
    return "incomplete";
}
/**
 * 不完全な vendor/ を安全に削除する（self-healing dev の回復処理）。
 *
 * 誤削除防止のため、以下をすべて満たさない限り削除しない:
 * - root が絶対パスである
 * - root が CMS プロジェクトの marker（artisan + composer.json）を持つ
 * - 対象は root 直下の "vendor" のみ（パスは join(root, "vendor") 固定で合成しない）
 * - vendor が symlink ではない実ディレクトリ（symlink 先を巻き込まない）
 * - composer state が "incomplete"（完了済み vendor/ は削除しない）
 */
export function cleanIncompleteVendor(root) {
    if (!isAbsolute(root)) {
        return { removed: false, reason: "project root が絶対パスではありません" };
    }
    const projectRoot = resolve(root);
    if (!existsSync(join(projectRoot, "artisan")) ||
        !existsSync(join(projectRoot, "composer.json"))) {
        return {
            removed: false,
            reason: "CMS プロジェクトの marker（artisan + composer.json）が見つかりません",
        };
    }
    const state = composerState(projectRoot);
    if (state === "absent") {
        return { removed: false, reason: "vendor/ が存在しません" };
    }
    if (state === "complete") {
        return {
            removed: false,
            reason: "vendor/ は完了状態です（autoload.php あり）— 削除しません",
        };
    }
    const vendorPath = join(projectRoot, "vendor");
    const stat = lstatSync(vendorPath);
    if (stat.isSymbolicLink()) {
        return {
            removed: false,
            reason: "vendor/ が symlink です — リンク先の巻き込みを避けるため削除しません",
        };
    }
    if (!stat.isDirectory()) {
        return { removed: false, reason: "vendor/ がディレクトリではありません" };
    }
    rmSync(vendorPath, { recursive: true, force: true });
    return { removed: true };
}
/**
 * artisan（migrate 等）失敗時の診断（Phase 8 Stage A RC1 Fix 4）。
 *
 * 診断順: PHP 依存（bootstrap artifacts）→ database/container。
 * vendor/autoload.php が無い状態を MySQL failure と誤診しない。
 */
export function migrateFailureDiagnosis(root) {
    if (composerState(root) !== "complete") {
        return {
            what: "migrate を実行できません（PHP 依存が不完全）",
            why: "vendor/autoload.php がありません — composer install が完了していません（MySQL の問題ではありません）",
            fix: "もう一度 `agentcms dev` を実行してください（不完全な vendor/ を自動で検出し、composer install からやり直します）",
        };
    }
    return {
        what: "migrate に失敗しました",
        why: "MySQL コンテナが未初期化か、接続設定が .env と一致していません",
        fix: "`docker compose logs mysql` を確認してください。初回は MySQL の初期化完了まで数十秒待って再実行してください",
    };
}

import { existsSync, readFileSync } from "node:fs";
import { basename, dirname, join } from "node:path";
/**
 * **kit 自身の `template/` は案件ではない**（B-41）。
 *
 * ## なぜ要るのか
 *
 * `requireProject()` は `artisan` と `docker-compose.yml` が在るディレクトリを
 * プロジェクトと認める。**kit の `template/` は両方持っている。**
 * そして開発機の `template/.agentcms/deploy/staging.json` は**実サーバーを指す**。
 * つまり `template/` の中で `agentcms deploy staging` を打つと、
 * **配布物のひな形そのものを客のサーバーへ押す**（`-y` なら確認も出ない）。
 *
 * ## どう見分けるか（推測ではなく実測で選んだ）
 *
 * - **`create` は生成時に必ず `.agentcms/project.json` を書く**（create.ts の手順 5）。
 *   kit の `template/.agentcms/` にはそれが**無い**。
 * - kit の `template/` は**必ず kit の中に在る**。repo なら隣に `packages/cli/package.json`、
 *   配布した npm パッケージなら隣に `package.json`（`agent-cms-kit`）と `dist/index.js`。
 *
 * ## 誤検知より見逃しを選ぶ
 *
 * **利用者の案件を止めるほうが事故として大きい。** だから上を **すべて満たしたときだけ**
 * 止める（名前が `template` + `project.json` が無い + kit の中に在る）。
 * どれか 1 つでも外れたら通す。
 */
const KIT_PACKAGE_NAME = "agent-cms-kit";
function packageNameAt(file) {
    if (!existsSync(file))
        return null;
    try {
        const json = JSON.parse(readFileSync(file, "utf8"));
        return typeof json.name === "string" ? json.name : null;
    }
    catch {
        return null;
    }
}
/** その場所が kit に同梱された `template/` そのものか */
export function isKitTemplateDir(root) {
    if (basename(root) !== "template")
        return false;
    // 生成された案件には必ず在る。在るなら案件として扱う（見逃す側に倒す）
    if (existsSync(join(root, ".agentcms", "project.json")))
        return false;
    const parent = dirname(root);
    // kit の repo（packages/cli/ の隣に template/ が在る）
    const inRepo = packageNameAt(join(parent, "packages", "cli", "package.json")) ===
        KIT_PACKAGE_NAME;
    // 配布した npm パッケージ（dist/ の隣に template/ が在る）
    const inPackage = packageNameAt(join(parent, "package.json")) === KIT_PACKAGE_NAME &&
        existsSync(join(parent, "dist", "index.js"));
    return inRepo || inPackage;
}
/** deploy 系が使う。kit の `template/` なら断り文、案件なら null */
export function kitTemplateRefusal(root) {
    if (!isKitTemplateDir(root))
        return null;
    return {
        what: `ここは SiteGrowKit の template/ です（案件ではありません）: ${root}`,
        why: "template/ は配布物のひな形です。ここから deploy すると、案件ではなくひな形そのものをサーバーへ押すことになります",
        fix: "`agentcms create <dir>` で作った案件のディレクトリへ移動してから実行してください",
    };
}

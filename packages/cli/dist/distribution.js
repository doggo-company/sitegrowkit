/**
 * 配布境界（PHASE 9.7 / SPEC §C.5）。
 *
 * **kit に在るもの ≠ 利用者のサイトへ配るもの。** その線を引くのはここ 1 か所で、
 * `create`（ファイルのコピー）も AI Agent Context の生成も、同じ判断を使う。
 * 2 か所に書くと、片方だけ古くなって「配っていない型の registry が残る」ような
 * ずれが必ず起きる。
 */
// template からコピーしない生成物・ローカル状態（.env は各プロジェクトで生成）
const EXCLUDE_TOP = new Set([
    "vendor",
    "node_modules",
    ".env",
    ".env.backup",
    ".env.production",
    "composer.lock.local",
    ".git",
    ".lighthouseci",
    ".phpunit.cache",
    "test-results",
]);
const EXCLUDE_PREFIX = [
    "storage/logs/",
    "public/build",
    "public/hot",
    "public/storage",
    ".agentcms/local",
    ".agentcms/cache",
    ".agentcms/deploy/build",
    // project override は **その project の状態**であって配布物ではない（#24）。
    // kit のリポジトリで作業していると開発機用の override が置かれていることがあり、
    // それをそのまま新規 project へ複製すると、他人の環境の都合が混入する
    "content/schemas/overrides",
];
const EXCLUDE_EXACT = new Set([".agentcms/figma/project.json"]);
/**
 * kit の**例・検証資産**であって、利用者の site に要らないもの
 * （PHASE 9.7 / STAGE 20B・SPEC §C・Owner Decision OG-A = (b)）。
 *
 * 新しく生成する site が持つ投稿タイプは **汎用の「投稿」1 つだけ**である。
 * `news` / `works` は「制作会社のサイトの形」であって、汎用 CMS の既定ではない。
 * `product` は 20 種の field 型を網羅した検証用 fixture で、registry にも載っていない。
 *
 * **kit からは消さない。** template import の visual regression、Figma のデモ画面、
 * Template Import の適用結果は、いずれも `Work` モデルと `works` の view に依存している。
 * 消すのではなく、**配布境界だけを引き直す**。
 */
const EXAMPLE_ONLY_PREFIX = [
    "modules/News",
    "modules/Works",
    "modules/Products",
    "content/schemas/news.json",
    "content/schemas/works.json",
    "content/schemas/product.json",
    // news 専用の分類。汎用の「投稿」に押しつけない
    "content/taxonomies/news_category.json",
];
/**
 * 上の 3 module に**依存している**もの（PHASE 9.7 / STAGE 20F）。
 *
 * module を配らないだけでは足りなかった。それを参照する view・seeder・demo route が
 * 残っていると、生成した site では **静的解析もテストも通らない**
 * （`Modules\Works\Models\Work` が解決できない）。
 *
 * 実行時に落ちないよう `class_exists` で守るのとは別の話である。
 * **配らないものへの参照を配らない**のが筋であり、隠すために ignore を足さない。
 */
const EXAMPLE_DEPENDENT_PREFIX = [
    // Figma のデモ画面（kit の合成 fixture 専用。Work を直接引く）
    "routes/figma.php",
    "resources/views/figma",
    "public/figma",
    // 例の配線そのもの（対応表・管理 route）。呼び出し側は在るときだけ読む
    "app/Providers/example-content-types.php",
    "routes/admin-examples.php",
    // 例のコンテンツを入れる seeder 群
    "database/seeders/DemoContentSeeder.php",
    "database/seeders/TemplateContentSeeder.php",
    "database/seeders/FigmaContentSeeder.php",
    "database/seeders/template-import",
    "database/seeders/figma-import",
];
/**
 * kit の検証資産としてのテスト。
 *
 * **利用者の site へは配らない。** kit のテストは kit の例（news / works / product）を
 * 前提にしており、配ると生成物単体で緑にならない。
 * 代わりに、生成物には**「投稿」だけを前提にした smoke** を配る（`TEST_ALLOWLIST`）。
 */
const KIT_ONLY_TEST_PREFIX = [
    // 末尾に `/` を付けない。`matches()` は `rel === p` か `p + "/"` で始まるかを見るので、
    // `"tests/"` と書くと `tests/Feature/...` のどちらにも当たらず**全部配ってしまう**
    "tests",
    "playwright.config.ts",
    "playwright.isolated.config.ts",
    // 触り心地の門（docs/feel-bar.md）。kit の門なので配らない（相手は kit の作業ツリーから作ったプロジェクト）
    "playwright.feel.config.ts",
];
/**
 * テストのうち**配るもの**。
 *
 * `tests/Unit` と `tests/Feature` は `phpunit.xml` の testsuite が指しているので、
 * **ディレクトリが消えると Pest が起動しない**。smoke をこの 2 つの下に置くことで、
 * 生成物でも suite の形が保たれる。
 */
const TEST_ALLOWLIST = [
    "tests",
    "tests/TestCase.php",
    "tests/Unit",
    "tests/Unit/Smoke",
    "tests/Feature",
    "tests/Feature/Smoke",
    /*
     * 視覚回帰は**利用者にも要る**（R-15 Stage 2）。
     *
     * 取り込んだ HTML と、CMS が描いた画面の差を見るこの 1 本だけは、
     * kit の例（news / works）ではなく **取り込み結果そのもの**を見ている。
     * 比較元は取り込み元の static HTML を `file://` で読むので**基準画像も要らない**。
     *
     * 標準 E2E の設定（`playwright.config.ts`）は配らない — `globalSetup` が
     * `docker compose exec` と kit の seeder を前提にしていて動かないからである。
     * 代わりに `playwright.visual.config.ts`（setup 無し）が配られる。
     */
    "tests/e2e",
    "tests/e2e/template-visual.spec.ts",
];
const TEST_ALLOWLIST_PREFIX = ["tests/Unit/Smoke/", "tests/Feature/Smoke/"];
function matches(rel, prefixes) {
    return prefixes.some((p) => rel === p || rel.startsWith(`${p}/`));
}
/** その相対パスを生成 project へ配るか */
export function shouldCopyTemplatePath(rel) {
    if (rel === "")
        return true;
    const top = rel.split("/")[0] ?? "";
    if (EXCLUDE_TOP.has(top))
        return false;
    if (EXCLUDE_EXACT.has(rel))
        return false;
    // deploy 接続設定はコミット対象の example のみ配布する
    if (rel.startsWith(".agentcms/deploy/") &&
        rel.endsWith(".json") &&
        !rel.endsWith(".example.json")) {
        return false;
    }
    if (matches(rel, EXAMPLE_ONLY_PREFIX))
        return false;
    if (matches(rel, EXAMPLE_DEPENDENT_PREFIX))
        return false;
    // module に同梱されたテストも kit の検証資産（`modules/Media/tests` など）
    if (/^modules\/[^/]+\/tests(\/|$)/.test(rel))
        return false;
    if (TEST_ALLOWLIST.includes(rel) ||
        TEST_ALLOWLIST_PREFIX.some((p) => rel.startsWith(p))) {
        return true;
    }
    if (matches(rel, KIT_ONLY_TEST_PREFIX))
        return false;
    return !EXCLUDE_PREFIX.some((p) => rel === p || rel.startsWith(p));
}
/**
 * 生成 project にその content type が在るか。
 *
 * registry の変換で使う。**型名を書き並べない** — 判断は配布境界だけが持つ。
 */
export function distributesContentType(id) {
    return shouldCopyTemplatePath(`content/schemas/${id}.json`);
}
/**
 * 生成 project の registry から、**配らないもの**を落とす
 * （PHASE 9.7 / STAGE 20B・20F・SPEC §C）。
 *
 * kit の `template/.agentcms/` は **template 自身の索引**である。そこには
 * `news` も `news_category` も居てよい — template には実在するのだから。
 * だから落とすのは**コピーする瞬間**であって、同期の瞬間ではない。
 * ここを取り違えると、kit の `agentcms:taxonomy-check` が
 * 「宣言は在るのに索引に居ない」と言って落ちる。
 *
 * 生成された site の側では逆で、配られていない定義や居ない consumer が残ると、
 * そこで働く AI は「在るはずの型」を探し続けるし、同じ check が
 * 「索引に居るのに宣言が無い」と言って落ちる。
 *
 * **型名をここへ書かない。** 判断はすべて上の配布境界に問い合わせる。
 *
 * @returns 書き換えた内容。対象外の rel なら null
 */
export function filterRegistryForProject(rel, content) {
    if (rel === "registry/content-types.json") {
        const registry = JSON.parse(content);
        if (!Array.isArray(registry.types))
            return null;
        registry.types = registry.types.filter((t) => typeof t.id === "string" && distributesContentType(t.id));
        return `${JSON.stringify(registry, null, 2)}\n`;
    }
    if (rel === "registry/taxonomies.json") {
        const registry = JSON.parse(content);
        if (!Array.isArray(registry.taxonomies))
            return null;
        registry.taxonomies = registry.taxonomies
            // 定義ファイルごと配らない分類は載せない
            .filter((t) => typeof t.definition === "string"
            ? shouldCopyTemplatePath(t.definition.replace(/^template\//, ""))
            : true)
            // 配られていない投稿タイプを consumer に残さない
            .map((t) => ({
            ...t,
            consumers: Array.isArray(t.consumers)
                ? t.consumers.filter((id) => distributesContentType(id))
                : t.consumers,
        }));
        return `${JSON.stringify(registry, null, 2)}\n`;
    }
    return null;
}

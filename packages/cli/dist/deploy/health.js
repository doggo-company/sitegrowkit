async function get(url) {
    try {
        const res = await fetch(url, {
            redirect: "manual",
            signal: AbortSignal.timeout(15_000),
            headers: { "User-Agent": "agentcms-health/0.1" },
        });
        return { status: res.status, headers: res.headers, body: await res.text() };
    }
    catch {
        return null;
    }
}
/**
 * health の応答から「通ったか」と「**なぜ通らなかったか**」を決める（B-11）。
 *
 * ## 落ちたときにこそ読めなければならない
 *
 * 以前は **200 のときしか body を読まなかった**ので、503 では `db` / `storage` が
 * 分からず「エンドポイント未配置または JSON 以外の応答です」と出ていた。
 * **一番情報の要るときに、一番情報が無い**状態だった。
 *
 * ## 自己申告だけでは通さない
 *
 * body が `ok: true` と言っていても、status が 200 でなければ**外から見て落ちている**。
 * 申告だけで通すと、**切り戻しの引き金が引かれない**。
 */
export function healthVerdict(res) {
    if (res === null) {
        return { healthy: false, why: "接続できませんでした" };
    }
    let json = null;
    try {
        json = JSON.parse(res.body);
    }
    catch {
        json = null;
    }
    if (json === null) {
        return {
            healthy: false,
            why: "エンドポイント未配置、または JSON 以外の応答です",
        };
    }
    // **落ちている理由は、status に関わらず読んで出す**
    const why = `db=${String(json.db)} storage=${String(json.storage)}`;
    return { healthy: res.status === 200 && json.ok === true, why };
}
function check(key, name, ok, failInfo, warn = false) {
    return ok
        ? { key, name, status: "PASS" }
        : { key, name, status: warn ? "WARN" : "FAIL", ...failInfo };
}
/**
 * robots.txt が**サイト全体**を塞いでいるか（B-41）。
 *
 * `body.includes("Disallow: /")` では測れない。**本番の robots.txt には
 * `Disallow: /admin` が在る**ので（`routes/public.php` 実測）、素朴な部分一致は
 * 本番を「塞がれている」と読む。行として `Disallow: /` だけを見る。
 */
export function blocksEntireSite(robotsBody) {
    return /^\s*Disallow:\s*\/\s*$/m.test(robotsBody);
}
/**
 * その target に noindex が**要る**のか**在ってはいけない**のか（B-41）。
 *
 * 根拠はアプリ側の分岐そのもの — `StagingNoindex` も robots.txt の route も
 * `app()->environment('staging')` のときだけ noindex を出す。そして
 * `ensureLayout()` がサーバーの `APP_ENV` を target に一致させている。
 * だから **`staging` という名前の target だけ**が noindex になる。
 */
export function noindexExpectation(target) {
    return target === "staging" ? "required" : "forbidden";
}
/**
 * health check 本体。baseUrl は https://... （末尾スラッシュなし）。
 *
 * **`target` に既定値を置かない。** 以前は `{ staging: true }` が既定で、
 * 呼び出し 3 か所のどこも渡していなかった。その結果**本番では noindex 検査が
 * 必ず FAIL** し、`postHealthAction` が切り戻しを引いていた
 * （初回は戻す先が無いので「成功したデプロイが失敗と報告される」）。
 * 既定値を消せば、呼び出す側が**必ず行き先を言う**ことになる。
 */
export async function runHealthChecks(baseUrl, healthPath, target) {
    const checks = [];
    const url = (p) => baseUrl.replace(/\/$/, "") + p;
    // 1. TOP（HTTPS 応答 + HTML + debug 出力なし）
    const top = await get(url("/"));
    checks.push(check("top", "TOP ページ (HTTPS 200)", top !== null && top.status === 200, {
        what: `${url("/")} が 200 を返しません（${top ? top.status : "接続不可"}）`,
        why: "アプリが起動していないか、SSL / DNS / docroot 配置に問題があります",
        fix: "`agentcms deploy doctor` を実行し、サーバー側 storage/logs/laravel.log を確認してください",
    }));
    if (top && top.status === 200) {
        const debugLeak = top.body.includes("Whoops") ||
            top.body.includes("Ignition") ||
            top.body.includes("APP_KEY") ||
            /Deprecated:|Warning:|Notice:/.test(top.body.slice(0, 2000));
        checks.push(check("no_debug", "debug 出力なし", !debugLeak, {
            what: "レスポンスにデバッグ/警告出力が混入しています",
            why: "APP_DEBUG=true か、PHP の warning が表示されています",
            fix: "shared/.env で APP_DEBUG=false を確認し、エラーの根本原因をログで調査してください",
        }));
        // / が Laravel から配信されていること（DirectoryIndex で静的 index.html が勝っていないか）
        const isHostDefaultPage = top.body.includes("Copyright XSERVER Inc.") ||
            top.body.includes("default_page.png");
        const hasAppHeaders = top.headers.get("x-frame-options") !== null;
        checks.push(check("laravel_root", "/ が Laravel から配信（静的 index.html に奪われていない）", !isHostDefaultPage && hasAppHeaders, {
            what: isHostDefaultPage
                ? "/ がホスティング初期ページ（index.html）を返しています"
                : "/ の応答にアプリのヘッダーがありません（静的ファイル配信の疑い）",
            why: "DirectoryIndex の優先順位（index.html > index.php）により Laravel が表示されていない可能性があります",
            fix: "docroot の index.html / index.htm / index.shtml / index.cgi を退避・削除してください（`agentcms deploy staging` は XServer 初期生成ページを自動退避します）",
        }));
    }
    // 2. health endpoint（DB / storage）
    const hz = await get(url(healthPath));
    const verdict = healthVerdict(hz);
    checks.push(check("health", `health endpoint (${healthPath})`, verdict.healthy, {
        what: `${healthPath} が ok を返しません（${hz ? hz.status : "接続不可"}）`,
        why: verdict.why,
        fix: "DB 接続（shared/.env の DB_*）と storage の書き込み権限を確認してください",
    }));
    // 3. admin ログイン画面
    const login = await get(url("/login"));
    checks.push(check("admin_login", "admin ログイン画面", login !== null && login.status === 200, {
        what: `/login が 200 を返しません（${login ? login.status : "接続不可"}）`,
        why: "admin ルートまたは Inertia アセットに問題があります",
        fix: "public/build がデプロイされているか（ローカル build 済み artifact か）を確認してください",
    }));
    // 4. build アセット（TOP の HTML から実アセット URL を辿る）
    if (top && top.status === 200) {
        const m = /(?:href|src)="([^"]*\/build\/assets\/[^"]+)"/.exec(top.body);
        const assetRef = m?.[1];
        if (assetRef) {
            const assetUrl = assetRef.startsWith("http") ? assetRef : url(assetRef);
            const asset = await get(assetUrl);
            checks.push(check("assets", "build アセット配信", asset !== null && asset.status === 200, {
                what: `${assetUrl} が取得できません（${asset ? asset.status : "接続不可"}）`,
                why: "public/build が docroot へ同期されていません",
                fix: "`agentcms deploy staging` を再実行してください（アセット同期工程を確認）",
            }));
        }
        else {
            checks.push({
                key: "assets",
                name: "build アセット配信",
                status: "WARN",
                what: "TOP から build アセット URL を検出できませんでした",
                why: "HTML 構造が想定と異なります",
                fix: "ページソースの /build/assets/ 参照を目視確認してください",
            });
        }
    }
    // 5. Contact フォーム（GET のみ。送信はしない）
    const contact = await get(url("/contact"));
    checks.push(check("contact", "Contact フォーム表示", contact !== null && contact.status === 200, {
        what: `/contact が 200 を返しません（${contact ? contact.status : "接続不可"}）`,
        why: "ルートまたは Turnstile 設定に問題があります",
        fix: "shared/.env の TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY を確認してください",
    }));
    if (contact && contact.status === 200) {
        checks.push(check("turnstile", "Turnstile ウィジェット", contact.body.includes("turnstile"), {
            what: "Contact ページに Turnstile が見当たりません",
            why: "TURNSTILE_SITE_KEY が未設定の可能性があります",
            fix: "shared/.env に Turnstile のキーを設定してください（未設定でもフォームは表示されますが送信は検証で弾かれます）",
        }, true));
    }
    // 6. Admin PWA（manifest / SW / offline）
    const manifest = await get(url("/admin/manifest.webmanifest"));
    checks.push(check("pwa_manifest", "PWA manifest", manifest !== null &&
        manifest.status === 200 &&
        manifest.body.includes('"start_url"'), {
        what: "/admin/manifest.webmanifest が正しく返りません",
        why: `status=${manifest ? manifest.status : "接続不可"}`,
        fix: "admin ルートの配置と PwaController を確認してください",
    }));
    const sw = await get(url("/admin/sw.js"));
    const swAllowed = sw?.headers.get("service-worker-allowed");
    checks.push(check("pwa_sw", "Service Worker (scope /admin)", sw !== null && sw.status === 200 && swAllowed === "/admin", {
        what: "/admin/sw.js が正しく返りません",
        why: `status=${sw ? sw.status : "接続不可"} / Service-Worker-Allowed=${swAllowed ?? "なし"}`,
        fix: "PwaController の Service-Worker-Allowed ヘッダーがサーバーで落とされていないか確認してください",
    }));
    // 公開サイトへ SW が漏れていないこと（/sw.js が 404）
    const rootSw = await get(url("/sw.js"));
    checks.push(check("no_public_sw", "公開サイトへ SW 非導入 (/sw.js は 404)", rootSw !== null && rootSw.status === 404, {
        what: `/sw.js が ${rootSw ? rootSw.status : "接続不可"} を返します`,
        why: "公開サイト側に Service Worker が存在してはいけません",
        fix: "docroot 直下に sw.js を置いていないか確認してください",
    }));
    // 7. セキュリティヘッダー
    if (top && top.status === 200) {
        const xfo = top.headers.get("x-frame-options");
        const xcto = top.headers.get("x-content-type-options");
        const refpol = top.headers.get("referrer-policy");
        const csp = top.headers.get("content-security-policy");
        const headersOk = xfo === "DENY" &&
            xcto === "nosniff" &&
            refpol === "strict-origin-when-cross-origin" &&
            csp !== null;
        checks.push(check("sec_headers", "セキュリティヘッダー", headersOk, {
            what: "セキュリティヘッダーが不足しています",
            why: `X-Frame-Options=${xfo ?? "なし"} / X-Content-Type-Options=${xcto ?? "なし"} / Referrer-Policy=${refpol ?? "なし"} / CSP=${csp ? "あり" : "なし"}`,
            fix: "サーバー側で応答ヘッダーが削除されていないか確認してください（アプリは Phase 1 のミドルウェアで付与済み）",
        }));
    }
    // 8. 秘密ファイル・ソース非公開
    for (const [key, path, name] of [
        ["env_hidden", "/.env", ".env 非公開"],
        ["vendor_hidden", "/vendor/autoload.php", "vendor 非公開"],
        ["composer_hidden", "/composer.json", "ソースファイル非公開"],
    ]) {
        const r = await get(url(path));
        checks.push(check(key, name, r !== null && (r.status === 404 || r.status === 403), {
            what: `${path} が ${r ? r.status : "接続不可"} を返します（404/403 であるべき）`,
            why: "アプリのソース・秘密情報が Web 公開されています",
            fix: "docroot に public/ 相当以外のファイルを置いていないか直ちに確認してください",
        }));
    }
    // storage ディレクトリリスティングなし
    const storage = await get(url("/storage/"));
    checks.push(check("storage_listing", "storage ディレクトリリスティングなし", storage !== null && storage.status !== 200
        ? true
        : storage !== null && !storage.body.includes("Index of"), {
        what: "/storage/ でディレクトリ一覧が表示されます",
        why: "Apache の autoindex が有効です",
        fix: "docroot の .htaccess に `Options -Indexes` を追加してください",
    }));
    // 9. noindex（robots.txt + X-Robots-Tag）。**target で向きが逆になる**
    const robots = await get(url("/robots.txt"));
    const robotsBlocks = robots !== null && robots.status === 200 && blocksEntireSite(robots.body);
    const tagNoindex = top?.headers.get("x-robots-tag")?.includes("noindex") ?? false;
    const state = `robots.txt Disallow: /=${String(robotsBlocks)} / X-Robots-Tag noindex=${String(tagNoindex)}`;
    if (noindexExpectation(target) === "required") {
        checks.push(check("noindex", `${target} noindex（robots.txt + X-Robots-Tag）`, robotsBlocks && tagNoindex, {
            what: `${target} が検索エンジンへ公開されうる設定です`,
            why: state,
            fix: `shared/.env の APP_ENV=${target} を確認してください（staging では自動で noindex になります）`,
        }));
    }
    else {
        // 本番では逆向きを測る — **noindex が残っていたら検索から消える**
        checks.push(check("noindex_absent", "公開設定（noindex が残っていない）", !robotsBlocks && !tagNoindex, {
            what: `${target} に noindex が残っています（検索エンジンから消えます）`,
            why: state,
            fix: `shared/.env の APP_ENV が ${target} になっているか確認してください（staging のままだと noindex が出ます）`,
        }));
    }
    return checks;
}
/**
 * health の結果から**次の一手**を決める（B-12）。
 *
 * ## なぜ切り出すのか
 *
 * 「落ちたら旧 release へ戻す」は deploy でいちばん効いてほしい経路なのに、
 * **一度も試験で通していなかった**（コードを読む限り繋がってはいた）。
 * 判断だけを取り出せば、SSH も HTTP も無しで固定できる。
 *
 * ## 判断の規則
 *
 * - **`WARN` は引き金にならない。** 警告で切り戻すと、
 *   **動いている release を動いているのに戻す**ことになる
 * - **戻す先が無ければ戻さない。** 初回デプロイには旧 release が無く、
 *   同じ枝へ流すと**存在しない release へ current を向ける**
 * - **検査が 1 つも無いのは「通った」ではない。** 空を PASS と読むと、
 *   **測っていないのに緑**になる
 */
export function postHealthAction(checks, previous) {
    if (checks.length === 0)
        return { kind: "stop" };
    if (!checks.some((c) => c.status === "FAIL"))
        return { kind: "continue" };
    return previous === null ? { kind: "stop" } : { kind: "rollback", to: previous };
}

import { readFile } from "node:fs/promises";
import { createServer, } from "node:http";
import { extname, join, normalize, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { AGENT_CLIENTS, renderConfig, writeConfig, } from "../mcp/agent-config.js";
import { issueLoginLink, readAdminCredential } from "./admin-access.js";
import { pickFolder, pickerKind } from "./picker.js";
import { CSRF_HEADER, cookieHeader, createSession, hasValidSession, isAllowedMutation, } from "./session.js";
import { preloadReading } from "./reading.js";
import { WizardRunner } from "./runner.js";
import { checkProjectDirConflict, defaultLocation, suggestProjectNameByReading, validateLocation, validateProjectName, validateSiteName, } from "./validate.js";
/**
 * Setup Wizard の HTTP サーバ（Phase 8.5-B）。
 *
 * 制約（意図的な設計）:
 *  - bind は 127.0.0.1 のみ（0.0.0.0 は使わない — LAN からアクセスさせない）
 *  - port は OS 任せの ephemeral port
 *  - 静的アセットは同梱のみ（CDN / 外部 JS・CSS・フォントを一切参照しない）
 *  - CORS ヘッダは出さない（他オリジンからの読み取りを許可しない）
 */
const HOST = "127.0.0.1";
const CSP = [
    "default-src 'none'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self' data:",
    "connect-src 'self'",
    "base-uri 'none'",
    "form-action 'none'",
    "frame-ancestors 'none'",
].join("; ");
const ASSET_TYPES = {
    ".html": "text/html; charset=utf-8",
    ".css": "text/css; charset=utf-8",
    ".js": "text/javascript; charset=utf-8",
    ".svg": "image/svg+xml",
};
/**
 * 寿命の上限（Phase 8.5-D.8 / F-D3）。
 *
 * Wizard server はかつて **自分から終了する経路を持たなかった**ため、
 * 起動するたびにプロセスが増え、PC / WSL を再起動するまで残り続けていた。
 * 「利用者が何もしなくても永久には残らない」ことを保証する。
 *
 * - READY は terminal state。READY 画面のボタン（サイトを見る / 管理画面 /
 *   プロジェクトフォルダ）は `/api/open` を叩くため即時終了はできない。
 *   **操作が途切れてから** READY_MS で graceful shutdown する
 * - それ以外の状態（中断・タブを閉じた・launcher を強制終了された等）は IDLE_MS
 * - 実行中（`busy`）の間は決して終了しない
 */
const DEFAULT_IDLE_MS = 30 * 60_000;
const DEFAULT_READY_MS = 3 * 60_000;
/** テストと acceptance で短縮するための上書き（本番では未設定） */
function envMs(name, fallback) {
    const raw = process.env[name];
    if (!raw)
        return fallback;
    const value = Number(raw);
    return Number.isFinite(value) && value > 0 ? value : fallback;
}
export async function startWizardServer(options = {}) {
    const session = createSession();
    const assetsDir = resolve(options.assetsDir ?? defaultAssetsDir());
    const runner = new WizardRunner({
        pickerAvailable: pickerKind() !== "unavailable",
        defaultLocation: defaultLocation(),
        home: options.home,
    });
    const idleMs = envMs("AGENTCMS_WIZARD_IDLE_MS", DEFAULT_IDLE_MS);
    const readyMs = envMs("AGENTCMS_WIZARD_READY_MS", DEFAULT_READY_MS);
    /** 開いている SSE。shutdown 時に自分で閉じないと server.close() が返らない */
    const streams = new Set();
    /** SSE 以外の処理中リクエスト数（処理の途中で切らないため） */
    let inFlight = 0;
    let lastActivity = Date.now();
    let shuttingDown = false;
    let origin = "";
    const server = createServer((req, res) => {
        lastActivity = Date.now();
        const isStream = (req.url ?? "").startsWith("/api/events");
        if (!isStream) {
            inFlight += 1;
            res.on("close", () => {
                inFlight -= 1;
                lastActivity = Date.now();
            });
        }
        void handle(req, res, {
            session,
            runner,
            assetsDir,
            origin,
            streams,
        }).catch(() => {
            sendJson(res, 500, { error: "internal_error" });
        });
    });
    const port = await listen(server);
    origin = `http://${HOST}:${port}`;
    // フォルダ名の候補に使う読みの辞書を、画面を開いている間に読み込んでおく（UX 一周 21 周目 Y3）
    void preloadReading();
    const closed = new Promise((resolveClosed) => {
        server.on("close", () => resolveClosed());
    });
    const shutdown = () => {
        if (shuttingDown)
            return closed;
        shuttingDown = true;
        clearInterval(monitor);
        // SSE は自分から閉じる（クライアント待ちで server.close() が固まらないように）
        for (const stream of streams) {
            try {
                stream.end();
            }
            catch {
                // 既に切れている接続は無視する
            }
        }
        streams.clear();
        server.close();
        // keep-alive が残っていると close が返らないため、猶予後に強制的に閉じる
        const forced = setTimeout(() => server.closeAllConnections(), 2_000);
        forced.unref?.();
        return closed.then(() => clearTimeout(forced));
    };
    // 「実行中でない」「処理中のリクエストが無い」状態が続いたときだけ終了する。
    // SSE は接続時に活動として数えるが、開きっぱなしでは寿命を延ばさない
    // （タブを開いたままでも永久に残らないようにするため）。
    const limitFor = () => runner.snapshot().phase === "READY" ? readyMs : idleMs;
    const tick = Math.max(250, Math.min(15_000, Math.floor(Math.min(idleMs, readyMs) / 4)));
    const monitor = setInterval(() => {
        if (shuttingDown)
            return;
        if (runner.snapshot().busy || inFlight > 0) {
            lastActivity = Date.now();
            return;
        }
        if (Date.now() - lastActivity >= limitFor())
            void shutdown();
    }, tick);
    monitor.unref?.();
    return {
        url: `${origin}/?t=${session.token}`,
        port,
        runner,
        closed,
        close: shutdown,
    };
}
async function handle(req, res, deps) {
    const url = new URL(req.url ?? "/", deps.origin || `http://${HOST}`);
    const path = url.pathname;
    // 一度きりの token 受け取り → cookie 化 → token を含まない URL へ退避
    // （履歴・スクリーンショット・ログに token を残さない）
    if (path === "/" && url.searchParams.has("t")) {
        const token = url.searchParams.get("t") ?? "";
        if (token !== deps.session.token) {
            return sendText(res, 403, "Forbidden");
        }
        res.writeHead(302, {
            Location: "/",
            "Set-Cookie": cookieHeader(deps.session.token),
            ...securityHeaders(),
        });
        return void res.end();
    }
    if (req.method === "GET" || req.method === "HEAD") {
        if (!hasValidSession(req, deps.session)) {
            return sendText(res, 403, "Forbidden");
        }
        if (path === "/api/state") {
            return sendJson(res, 200, deps.runner.snapshot());
        }
        if (path === "/api/logs") {
            return sendJson(res, 200, { lines: deps.runner.detailLogs() });
        }
        // 管理画面のログイン情報（UX 一周 3 周目 N1）。snapshot には載せず、READY のときだけ渡す
        if (path === "/api/credentials") {
            const snap = deps.runner.snapshot();
            const credential = snap.phase === "READY"
                ? readAdminCredential(snap.ready?.projectPath ?? "")
                : null;
            return sendJson(res, credential ? 200 : 404, credential ? { ok: true, ...credential } : { ok: false });
        }
        if (path === "/api/events") {
            return streamEvents(req, res, deps.runner, deps.streams);
        }
        return serveAsset(res, deps.assetsDir, path === "/" ? "/index.html" : path);
    }
    if (req.method === "POST") {
        if (!isAllowedMutation(req, deps.session, deps.origin)) {
            return sendJson(res, 403, { error: "forbidden" });
        }
        return handlePost(req, res, path, deps);
    }
    return sendText(res, 405, "Method Not Allowed");
}
async function handlePost(req, res, path, deps) {
    const { runner } = deps;
    switch (path) {
        case "/api/environment/start":
            runner.enterEnvironment();
            void runner.runEnvironmentCheck();
            return sendJson(res, 200, runner.snapshot());
        case "/api/environment/recheck":
            void runner.runEnvironmentCheck();
            return sendJson(res, 200, runner.snapshot());
        case "/api/environment/continue":
            runner.enterProject();
            return sendJson(res, 200, runner.snapshot());
        case "/api/pick-folder": {
            const picked = await pickFolder();
            if (!picked.ok) {
                return sendJson(res, 200, {
                    ok: false,
                    canceled: picked.canceled === true,
                });
            }
            return sendJson(res, 200, { ok: true, path: picked.path });
        }
        case "/api/suggest-name": {
            const body = await readJson(req);
            const siteName = typeof body.siteName === "string" ? body.siteName : "";
            // 保存場所が分かれば、既にあるフォルダ名を避けた候補を出す（UX 一周 3 周目 M1）
            const loc = typeof body.location === "string"
                ? validateLocation(body.location)
                : null;
            const base = loc?.ok ? loc.path : null;
            const taken = (name) => base !== null && checkProjectDirConflict(base, name) !== null;
            return sendJson(res, 200, {
                projectName: await suggestProjectNameByReading(siteName, taken),
            });
        }
        case "/api/start": {
            const body = await readJson(req);
            const input = validateStartInput(body);
            if ("error" in input) {
                return sendJson(res, 400, { errors: input.error });
            }
            void runner.start(input.value);
            return sendJson(res, 200, runner.snapshot());
        }
        case "/api/resume":
            void runner.resume();
            return sendJson(res, 200, runner.snapshot());
        case "/api/resume/discard":
            runner.discardResume();
            return sendJson(res, 200, runner.snapshot());
        case "/api/cancel":
            runner.cancel();
            return sendJson(res, 200, runner.snapshot());
        // AI エージェント連携の設定生成（Phase 9.1B / STEP 13）。
        // 対象は allowlist の 3 クライアントのみ。保存先はプロジェクトフォルダ内に限定される
        case "/api/agent-config/render":
        case "/api/agent-config/write": {
            const body = await readJson(req);
            const client = AGENT_CLIENTS.find((c) => c.id === body.client);
            const root = runner.snapshot().ready?.projectPath ?? "";
            if (client === undefined || root === "") {
                return sendJson(res, 400, { ok: false });
            }
            if (path === "/api/agent-config/render") {
                return sendJson(res, 200, {
                    ok: true,
                    file: client.file,
                    content: renderConfig(client, root),
                });
            }
            try {
                writeConfig(client, root);
                return sendJson(res, 200, { ok: true, file: client.file });
            }
            catch {
                return sendJson(res, 200, { ok: false, file: client.file });
            }
        }
        // 「管理画面を開く」= 1 回きりのログインリンク（UX 一周 3 周目 N1）。
        // URL は返すだけで、ログにも snapshot にも残さない。作れなければ ok: false（画面はログイン画面を開く）
        case "/api/login-link": {
            const snap = runner.snapshot();
            if (snap.phase !== "READY" || !snap.ready?.loginUrl) {
                return sendJson(res, 400, { ok: false });
            }
            const url = await issueLoginLink(snap.ready.projectPath, snap.ready.loginUrl);
            return sendJson(res, 200, url ? { ok: true, url } : { ok: false });
        }
        case "/api/open": {
            const body = await readJson(req);
            const opened = await openFromSnapshot(runner, body.target);
            return sendJson(res, opened ? 200 : 400, { ok: opened });
        }
        default:
            return sendJson(res, 404, { error: "not_found" });
    }
}
/** ブラウザから来た値の検証（サーバ側が最終権威） */
export function validateStartInput(body) {
    const siteName = typeof body.siteName === "string" ? body.siteName.trim() : "";
    const projectName = typeof body.projectName === "string" ? body.projectName.trim() : "";
    const location = typeof body.location === "string" ? body.location.trim() : "";
    const errors = [];
    const siteError = validateSiteName(siteName);
    if (siteError)
        errors.push(siteError);
    const nameError = validateProjectName(projectName);
    if (nameError)
        errors.push(nameError);
    const loc = validateLocation(location);
    if (!loc.ok && loc.error)
        errors.push(loc.error);
    if (errors.length > 0)
        return { error: errors };
    const resolvedLocation = loc.path;
    const conflict = checkProjectDirConflict(resolvedLocation, projectName);
    if (conflict)
        return { error: [conflict] };
    return {
        value: {
            siteName,
            projectName,
            location: resolvedLocation,
            projectPath: join(resolvedLocation, projectName),
        },
    };
}
/** Ready 画面のアクション。開く対象は snapshot の値に限定する（任意 URL は開かない） */
async function openFromSnapshot(runner, target) {
    const ready = runner.snapshot().ready;
    if (!ready || typeof target !== "string")
        return false;
    const allowed = {
        publicUrl: ready.publicUrl,
        loginUrl: ready.loginUrl,
        mailpitUrl: ready.mailpitUrl,
        projectPath: ready.projectPath,
    };
    const value = allowed[target];
    if (!value)
        return false;
    const { openExternal } = await import("./open.js");
    const result = await openExternal(value);
    return result.opened;
}
function streamEvents(req, res, runner, streams) {
    res.writeHead(200, {
        "Content-Type": "text/event-stream; charset=utf-8",
        "Cache-Control": "no-store",
        Connection: "keep-alive",
        ...securityHeaders(),
    });
    const send = (event) => {
        res.write(`data: ${JSON.stringify(event)}\n\n`);
    };
    send({ type: "snapshot", snapshot: runner.snapshot() });
    const unsubscribe = runner.subscribe((event) => {
        if (event.type === "snapshot") {
            send({ type: "snapshot", snapshot: runner.snapshot() });
            return;
        }
        send(event);
    });
    streams.add(res);
    // 接続維持（プロキシ不在のローカルでも、長時間 build 中の切断を防ぐ）。
    // unref: この timer だけで process が生き残らないようにする
    const keepAlive = setInterval(() => res.write(": ping\n\n"), 15_000);
    keepAlive.unref?.();
    const cleanup = () => {
        clearInterval(keepAlive);
        streams.delete(res);
        unsubscribe();
    };
    req.on("close", cleanup);
    res.on("close", cleanup);
}
async function serveAsset(res, assetsDir, path) {
    // path traversal 対策: 正規化後に assets ディレクトリ配下であることを検証する
    const target = resolve(assetsDir, `.${normalize(path)}`);
    if (target !== assetsDir && !target.startsWith(assetsDir + "/")) {
        return sendText(res, 403, "Forbidden");
    }
    const type = ASSET_TYPES[extname(target)];
    if (!type)
        return sendText(res, 404, "Not Found");
    try {
        const body = await readFile(target);
        res.writeHead(200, {
            "Content-Type": type,
            "Cache-Control": "no-store",
            ...securityHeaders(),
        });
        res.end(body);
    }
    catch {
        sendText(res, 404, "Not Found");
    }
}
function securityHeaders() {
    return {
        "Content-Security-Policy": CSP,
        "X-Content-Type-Options": "nosniff",
        "Referrer-Policy": "no-referrer",
        // CORS ヘッダは意図的に出さない（他オリジンからの読み取りを許可しない）
    };
}
function sendJson(res, status, body) {
    const payload = JSON.stringify(body);
    res.writeHead(status, {
        "Content-Type": "application/json; charset=utf-8",
        "Cache-Control": "no-store",
        ...securityHeaders(),
    });
    res.end(payload);
}
function sendText(res, status, body) {
    res.writeHead(status, {
        "Content-Type": "text/plain; charset=utf-8",
        ...securityHeaders(),
    });
    res.end(body);
}
async function readJson(req) {
    const chunks = [];
    let size = 0;
    for await (const chunk of req) {
        const buf = chunk;
        size += buf.length;
        // ローカル UI からの入力は小さい。大きな body は受け取らない
        if (size > 64 * 1024)
            throw new Error("payload_too_large");
        chunks.push(buf);
    }
    if (chunks.length === 0)
        return {};
    try {
        const parsed = JSON.parse(Buffer.concat(chunks).toString("utf8"));
        return typeof parsed === "object" && parsed !== null
            ? parsed
            : {};
    }
    catch {
        return {};
    }
}
function listen(server) {
    return new Promise((resolvePort, reject) => {
        server.once("error", reject);
        // port 0 = OS が空いている ephemeral port を割り当てる
        server.listen(0, HOST, () => {
            const address = server.address();
            if (address === null || typeof address === "string") {
                reject(new Error("listen_address_unavailable"));
                return;
            }
            resolvePort(address.port);
        });
    });
}
function defaultAssetsDir() {
    return join(fileURLToPath(new URL(".", import.meta.url)), "assets");
}
export { CSP, CSRF_HEADER, HOST };

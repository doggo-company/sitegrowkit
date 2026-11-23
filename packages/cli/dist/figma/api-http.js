/**
 * Figma REST API の本番実装（ADR-0011）。
 * - 認証: X-Figma-Token ヘッダ（PAT。2026-08 公式 Docs 準拠）
 * - 429: Retry-After ヘッダ（秒）を尊重して上限付き retry（無限 retry 禁止: SPEC §86）
 * - token はエラーメッセージへ出さない（redactFigmaSecrets を必ず通す）
 */
import { redactFigmaSecrets } from "./auth.js";
const BASE_URL = "https://api.figma.com";
/** 429 での再試行上限。Retry-After が無い場合は指数 backoff（1s, 2s, 4s） */
const MAX_RETRIES = 3;
/** Retry-After がこの秒数を超える場合は待たずに失敗として返す（CLI を長時間ブロックしない） */
const MAX_RETRY_AFTER_SECONDS = 60;
const defaultSleep = (ms) => new Promise((r) => setTimeout(r, ms));
export class HttpFigmaApi {
    token;
    calls = [];
    baseUrl;
    fetchFn;
    sleep;
    constructor(token, opts = {}) {
        this.token = token;
        this.baseUrl = opts.baseUrl ?? BASE_URL;
        this.fetchFn = opts.fetchFn ?? fetch;
        this.sleep = opts.sleep ?? defaultSleep;
    }
    label() {
        return "Figma REST API (api.figma.com)";
    }
    async getFileMeta(fileKey) {
        this.calls.push({ endpoint: "file_meta", detail: fileKey });
        const res = await this.getJson(`/v1/files/${fileKey}/meta`);
        if (!res.ok)
            return res;
        // 公式レスポンスは { file: {...} } wrapper の可能性があるため両対応
        const raw = res.data;
        return { ok: true, data: raw.file ?? raw };
    }
    async getFile(fileKey, opts = {}) {
        this.calls.push({ endpoint: "file", detail: fileKey });
        const query = opts.depth !== undefined ? `?depth=${opts.depth}` : "";
        const res = await this.getJson(`/v1/files/${fileKey}${query}`);
        if (!res.ok)
            return res;
        return { ok: true, data: res.data };
    }
    async getFileNodes(fileKey, ids, opts = {}) {
        this.calls.push({
            endpoint: "file_nodes",
            detail: `${fileKey} ids=${ids.length}`,
        });
        const params = new URLSearchParams({ ids: ids.join(",") });
        if (opts.version)
            params.set("version", opts.version);
        const res = await this.getJson(`/v1/files/${fileKey}/nodes?${params}`);
        if (!res.ok)
            return res;
        return { ok: true, data: res.data };
    }
    async getImageRenders(fileKey, ids, opts) {
        this.calls.push({
            endpoint: "image_renders",
            detail: `${fileKey} ids=${ids.length}`,
        });
        const params = new URLSearchParams({
            ids: ids.join(","),
            format: opts.format,
        });
        if (opts.scale !== undefined)
            params.set("scale", String(opts.scale));
        const res = await this.getJson(`/v1/images/${fileKey}?${params}`);
        if (!res.ok)
            return res;
        return { ok: true, data: res.data };
    }
    async getImageFills(fileKey) {
        this.calls.push({ endpoint: "image_fills", detail: fileKey });
        const res = await this.getJson(`/v1/files/${fileKey}/images`);
        if (!res.ok)
            return res;
        return { ok: true, data: res.data };
    }
    async getLocalVariables(fileKey) {
        this.calls.push({ endpoint: "variables", detail: fileKey });
        const res = await this.getJson(`/v1/files/${fileKey}/variables/local`);
        if (!res.ok)
            return res;
        return { ok: true, data: res.data };
    }
    async download(url) {
        this.calls.push({ endpoint: "download", detail: new URL(url).hostname });
        try {
            // render / image fill の URL は署名付き CDN URL。token ヘッダは送らない
            const res = await this.fetchFn(url, {});
            if (!res.ok) {
                return this.failure(res.status, `download failed (${res.status})`);
            }
            return { ok: true, data: Buffer.from(await res.arrayBuffer()) };
        }
        catch (e) {
            return {
                ok: false,
                kind: "network",
                status: 0,
                message: redactFigmaSecrets(String(e)),
            };
        }
    }
    /** 429 retry 込みの GET。token は常にヘッダのみ（URL / ログへ出さない） */
    async getJson(path) {
        for (let attempt = 0;; attempt++) {
            let status;
            let retryAfter = null;
            try {
                const res = await this.fetchFn(`${this.baseUrl}${path}`, {
                    headers: { "X-Figma-Token": this.token },
                });
                status = res.status;
                if (res.ok)
                    return { ok: true, data: await res.json() };
                const ra = res.headers.get("Retry-After");
                retryAfter = ra ? Number.parseInt(ra, 10) : null;
                if (status !== 429 || attempt >= MAX_RETRIES) {
                    return this.failure(status, await safeErrorText(res), retryAfter);
                }
            }
            catch (e) {
                return {
                    ok: false,
                    kind: "network",
                    status: 0,
                    message: redactFigmaSecrets(String(e)),
                };
            }
            const waitSeconds = retryAfter ?? 2 ** attempt;
            if (waitSeconds > MAX_RETRY_AFTER_SECONDS) {
                return this.failure(429, "Retry-After が長すぎるため中断", waitSeconds);
            }
            await this.sleep(waitSeconds * 1000);
        }
    }
    failure(status, message, retryAfter) {
        const kind = status === 401
            ? "auth"
            : status === 403
                ? "forbidden"
                : status === 404
                    ? "not_found"
                    : status === 429
                        ? "rate_limit"
                        : status >= 500
                            ? "server"
                            : "network";
        return {
            ok: false,
            kind,
            status,
            message: redactFigmaSecrets(message),
            ...(retryAfter != null ? { retryAfterSeconds: retryAfter } : {}),
        };
    }
}
async function safeErrorText(res) {
    try {
        const body = (await res.json());
        return body.err ?? body.message ?? `HTTP ${res.status}`;
    }
    catch {
        return `HTTP ${res.status}`;
    }
}

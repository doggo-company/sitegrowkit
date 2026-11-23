/**
 * XServer API（Control Plane）クライアント。ADR-0009。
 * - 認証: Bearer（環境変数 XSERVER_API_KEY のみ。設定ファイル・ログ・出力へ出さない）
 * - 対象: サーバー設定（subdomain / SSL / DB / Cron / PHP / SSH）。ファイル操作は SSH 側の責務
 * - 削除系・リストアは実装しない（確認パラメータが API に無く誤爆リスクが大きい。ADR-0009 §4）
 * 出典: https://developer.xserver.ne.jp/api/server/（SSL POST のボディのみ公式詳細未確認 — 初回実測で検証）
 */
/** 環境変数からキーを解決。未設定なら null（呼び出し側は手動手順へ fallback する） */
export function resolveApiKey(env = process.env) {
    const key = env.XSERVER_API_KEY?.trim();
    return key ? key : null;
}
const BASE_URL = "https://api.xserver.ne.jp";
/** エラーメッセージから万一混入した Bearer キーを除去する保険 */
export function redactSecrets(text) {
    return text.replace(/xs_[A-Za-z0-9_-]{8,}/g, "xs_***");
}
export class XserverApiHttp {
    servername;
    apiKey;
    baseUrl;
    constructor(servername, apiKey, baseUrl = BASE_URL) {
        this.servername = servername;
        this.apiKey = apiKey;
        this.baseUrl = baseUrl;
    }
    async request(method, path, body) {
        const url = `${this.baseUrl}/v1/server/${this.servername}${path}`;
        try {
            const res = await fetch(url, {
                method,
                headers: {
                    Authorization: `Bearer ${this.apiKey}`,
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },
                body: body ? JSON.stringify(body) : undefined,
                signal: AbortSignal.timeout(30_000),
            });
            const text = await res.text();
            let data;
            try {
                data = text ? JSON.parse(text) : undefined;
            }
            catch {
                data = undefined;
            }
            if (!res.ok) {
                return {
                    ok: false,
                    status: res.status,
                    data,
                    error: redactSecrets(`${method} ${path} -> ${res.status}${text ? ` ${text.slice(0, 300)}` : ""}`),
                };
            }
            return { ok: true, status: res.status, data };
        }
        catch (e) {
            return {
                ok: false,
                status: 0,
                error: redactSecrets(`${method} ${path} -> ${e instanceof Error ? e.message : String(e)}`),
            };
        }
    }
    sshStatus() {
        return this.request("GET", "/ssh");
    }
    subdomainList() {
        return this.request("GET", "/subdomain");
    }
    subdomainCreate(body) {
        return this.request("POST", "/subdomain", body);
    }
    sslList() {
        return this.request("GET", "/ssl");
    }
    sslCreate(domain) {
        // 公式リファレンスでボディ詳細が未確認のため最小形（実測で検証する）
        return this.request("POST", "/ssl", { domain });
    }
    dbList() {
        return this.request("GET", "/db");
    }
    dbCreate(body) {
        return this.request("POST", "/db", { character_set: "utf8mb4", ...body });
    }
    dbUserList() {
        return this.request("GET", "/db/user");
    }
    dbUserCreate(body) {
        return this.request("POST", "/db/user", body);
    }
    dbUserUpdate(dbUser, password) {
        return this.request("PUT", `/db/user/${encodeURIComponent(dbUser)}`, {
            password,
        });
    }
    dbGrantList(dbUser) {
        return this.request("GET", `/db/user/${encodeURIComponent(dbUser)}/grant`);
    }
    dbGrantCreate(dbUser, dbName) {
        return this.request("POST", `/db/user/${encodeURIComponent(dbUser)}/grant`, {
            db_name: dbName,
        });
    }
    cronList() {
        return this.request("GET", "/cron");
    }
    cronCreate(body) {
        return this.request("POST", "/cron", body);
    }
    phpVersionGet(domain) {
        return this.request("GET", `/php-version?domain=${encodeURIComponent(domain)}`);
    }
    phpVersionSet(domain, version) {
        return this.request("PUT", `/php-version/${encodeURIComponent(domain)}`, {
            version,
        });
    }
}
/**
 * API レスポンス（形式が将来変わりうる一覧系）から名前らしき文字列を許容的に抜き出す。
 * 一覧の器のキー名が違っても、値オブジェクト内の代表キーを探す。
 */
export function extractNames(data, candidates) {
    if (!data)
        return [];
    const out = [];
    const scan = (v) => {
        if (Array.isArray(v)) {
            for (const item of v)
                scan(item);
        }
        else if (typeof v === "object" && v !== null) {
            const o = v;
            for (const key of candidates) {
                if (typeof o[key] === "string") {
                    out.push(o[key]);
                    return;
                }
            }
            for (const val of Object.values(o))
                scan(val);
        }
        else if (typeof v === "string" && candidates.length === 0) {
            out.push(v);
        }
    };
    scan(data);
    return [...new Set(out)];
}
/** 一覧 data から domain に一致するエントリの status 値を探す（SSL 用） */
export function findStatusFor(data, domain) {
    if (!data)
        return null;
    let found = null;
    const scan = (v) => {
        if (found)
            return;
        if (Array.isArray(v)) {
            for (const item of v)
                scan(item);
        }
        else if (typeof v === "object" && v !== null) {
            const o = v;
            const values = Object.values(o);
            if (values.includes(domain) && typeof o.status === "string") {
                found = o.status;
                return;
            }
            for (const val of values)
                scan(val);
        }
    };
    scan(data);
    return found;
}

/**
 * XServer API（Control Plane）クライアント。ADR-0009。
 * - 認証: Bearer（環境変数 XSERVER_API_KEY のみ。設定ファイル・ログ・出力へ出さない）
 * - 対象: サーバー設定（subdomain / SSL / DB / Cron / PHP / SSH）。ファイル操作は SSH 側の責務
 * - 削除系・リストアは実装しない（確認パラメータが API に無く誤爆リスクが大きい。ADR-0009 §4）
 * 出典: https://developer.xserver.ne.jp/api/server/（SSL POST のボディのみ公式詳細未確認 — 初回実測で検証）
 */

export interface ApiResult<T = Record<string, unknown>> {
  ok: boolean;
  status: number;
  data?: T;
  /** 失敗時の説明。API キーを含めてはならない */
  error?: string;
}

/** Control Plane 操作の境界。テストは FakeXserverApi が実装する */
export interface XserverApi {
  readonly servername: string;
  sshStatus(): Promise<ApiResult>;
  subdomainList(): Promise<ApiResult<{ subdomains?: unknown[] }>>;
  subdomainCreate(body: {
    domain: string;
    subdomain: string;
    document_root?: string;
  }): Promise<ApiResult>;
  sslList(): Promise<ApiResult<{ ssl?: unknown[] }>>;
  /** 無料SSL 発行。ボディ形式は公式詳細未確認（初回実測で検証し ADR-0009 を更新） */
  sslCreate(domain: string): Promise<ApiResult>;
  dbList(): Promise<ApiResult<{ databases?: unknown[] }>>;
  dbCreate(body: { name_suffix: string; memo?: string }): Promise<ApiResult>;
  dbUserList(): Promise<ApiResult<{ users?: unknown[] }>>;
  dbUserCreate(body: {
    name_suffix: string;
    password: string;
  }): Promise<ApiResult>;
  /** DB ユーザーのパスワード再設定（作成済みユーザーの認証不一致の修復用） */
  dbUserUpdate(dbUser: string, password: string): Promise<ApiResult>;
  dbGrantList(dbUser: string): Promise<ApiResult<{ grants?: unknown[] }>>;
  dbGrantCreate(dbUser: string, dbName: string): Promise<ApiResult>;
  cronList(): Promise<ApiResult<{ crons?: unknown[] }>>;
  cronCreate(body: {
    minute: string;
    hour: string;
    day: string;
    month: string;
    weekday: string;
    command: string;
    comment?: string;
  }): Promise<ApiResult>;
  phpVersionGet(domain: string): Promise<ApiResult>;
  phpVersionSet(domain: string, version: string): Promise<ApiResult>;
}

/** 環境変数からキーを解決。未設定なら null（呼び出し側は手動手順へ fallback する） */
export function resolveApiKey(
  env: NodeJS.ProcessEnv = process.env,
): string | null {
  const key = env.XSERVER_API_KEY?.trim();
  return key ? key : null;
}

const BASE_URL = "https://api.xserver.ne.jp";

/** エラーメッセージから万一混入した Bearer キーを除去する保険 */
export function redactSecrets(text: string): string {
  return text.replace(/xs_[A-Za-z0-9_-]{8,}/g, "xs_***");
}

export class XserverApiHttp implements XserverApi {
  constructor(
    public readonly servername: string,
    private readonly apiKey: string,
    private readonly baseUrl: string = BASE_URL,
  ) {}

  private async request<T = Record<string, unknown>>(
    method: "GET" | "POST" | "PUT",
    path: string,
    body?: Record<string, unknown>,
  ): Promise<ApiResult<T>> {
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
      let data: T | undefined;
      try {
        data = text ? (JSON.parse(text) as T) : undefined;
      } catch {
        data = undefined;
      }
      if (!res.ok) {
        return {
          ok: false,
          status: res.status,
          data,
          error: redactSecrets(
            `${method} ${path} -> ${res.status}${text ? ` ${text.slice(0, 300)}` : ""}`,
          ),
        };
      }
      return { ok: true, status: res.status, data };
    } catch (e) {
      return {
        ok: false,
        status: 0,
        error: redactSecrets(
          `${method} ${path} -> ${e instanceof Error ? e.message : String(e)}`,
        ),
      };
    }
  }

  sshStatus(): Promise<ApiResult> {
    return this.request("GET", "/ssh");
  }

  subdomainList(): Promise<ApiResult<{ subdomains?: unknown[] }>> {
    return this.request("GET", "/subdomain");
  }

  subdomainCreate(body: {
    domain: string;
    subdomain: string;
    document_root?: string;
  }): Promise<ApiResult> {
    return this.request("POST", "/subdomain", body);
  }

  sslList(): Promise<ApiResult<{ ssl?: unknown[] }>> {
    return this.request("GET", "/ssl");
  }

  sslCreate(domain: string): Promise<ApiResult> {
    // 公式リファレンスでボディ詳細が未確認のため最小形（実測で検証する）
    return this.request("POST", "/ssl", { domain });
  }

  dbList(): Promise<ApiResult<{ databases?: unknown[] }>> {
    return this.request("GET", "/db");
  }

  dbCreate(body: { name_suffix: string; memo?: string }): Promise<ApiResult> {
    return this.request("POST", "/db", { character_set: "utf8mb4", ...body });
  }

  dbUserList(): Promise<ApiResult<{ users?: unknown[] }>> {
    return this.request("GET", "/db/user");
  }

  dbUserCreate(body: {
    name_suffix: string;
    password: string;
  }): Promise<ApiResult> {
    return this.request("POST", "/db/user", body);
  }

  dbUserUpdate(dbUser: string, password: string): Promise<ApiResult> {
    return this.request("PUT", `/db/user/${encodeURIComponent(dbUser)}`, {
      password,
    });
  }

  dbGrantList(dbUser: string): Promise<ApiResult<{ grants?: unknown[] }>> {
    return this.request("GET", `/db/user/${encodeURIComponent(dbUser)}/grant`);
  }

  dbGrantCreate(dbUser: string, dbName: string): Promise<ApiResult> {
    return this.request(
      "POST",
      `/db/user/${encodeURIComponent(dbUser)}/grant`,
      {
        db_name: dbName,
      },
    );
  }

  cronList(): Promise<ApiResult<{ crons?: unknown[] }>> {
    return this.request("GET", "/cron");
  }

  cronCreate(body: {
    minute: string;
    hour: string;
    day: string;
    month: string;
    weekday: string;
    command: string;
    comment?: string;
  }): Promise<ApiResult> {
    return this.request("POST", "/cron", body);
  }

  phpVersionGet(domain: string): Promise<ApiResult> {
    return this.request(
      "GET",
      `/php-version?domain=${encodeURIComponent(domain)}`,
    );
  }

  phpVersionSet(domain: string, version: string): Promise<ApiResult> {
    return this.request("PUT", `/php-version/${encodeURIComponent(domain)}`, {
      version,
    });
  }
}

/**
 * API レスポンス（形式が将来変わりうる一覧系）から名前らしき文字列を許容的に抜き出す。
 * 一覧の器のキー名が違っても、値オブジェクト内の代表キーを探す。
 */
export function extractNames(
  data: Record<string, unknown> | undefined,
  candidates: string[],
): string[] {
  if (!data) return [];
  const out: string[] = [];
  const scan = (v: unknown): void => {
    if (Array.isArray(v)) {
      for (const item of v) scan(item);
    } else if (typeof v === "object" && v !== null) {
      const o = v as Record<string, unknown>;
      for (const key of candidates) {
        if (typeof o[key] === "string") {
          out.push(o[key] as string);
          return;
        }
      }
      for (const val of Object.values(o)) scan(val);
    } else if (typeof v === "string" && candidates.length === 0) {
      out.push(v);
    }
  };
  scan(data);
  return [...new Set(out)];
}

/** 一覧 data から domain に一致するエントリの status 値を探す（SSL 用） */
export function findStatusFor(
  data: Record<string, unknown> | undefined,
  domain: string,
): string | null {
  if (!data) return null;
  let found: string | null = null;
  const scan = (v: unknown): void => {
    if (found) return;
    if (Array.isArray(v)) {
      for (const item of v) scan(item);
    } else if (typeof v === "object" && v !== null) {
      const o = v as Record<string, unknown>;
      const values = Object.values(o);
      if (values.includes(domain) && typeof o.status === "string") {
        found = o.status as string;
        return;
      }
      for (const val of values) scan(val);
    }
  };
  scan(data);
  return found;
}

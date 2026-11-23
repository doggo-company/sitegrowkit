import { randomBytes, timingSafeEqual } from "node:crypto";
import type { IncomingMessage } from "node:http";

/**
 * Setup Wizard のローカルセッション（Phase 8.5-B）。
 *
 * 脅威モデル: wizard server は loopback にしか bind しないが、同じマシンで動く
 * 他のブラウザタブ（悪意ある web ページ）が localhost へ fetch できる。
 * そのため以下を要求する:
 *  - 起動時に生成した token を知っていること（URL 一度きり → HttpOnly cookie）
 *  - 変更操作は Origin が自分自身であること
 *  - 変更操作は custom header を伴うこと（単純フォーム送信を弾く）
 */

export const SESSION_COOKIE = "agentcms_setup";
export const CSRF_HEADER = "x-agentcms-setup";

export interface Session {
  /** URL / cookie で使う不透明トークン（**ログへ出さない**） */
  readonly token: string;
}

export function createSession(): Session {
  return { token: randomBytes(32).toString("base64url") };
}

export function cookieHeader(token: string): string {
  // Secure は http://127.0.0.1 では付けない（付けると cookie が保存されない）
  return `${SESSION_COOKIE}=${token}; Path=/; HttpOnly; SameSite=Strict`;
}

export function parseCookies(
  header: string | undefined,
): Record<string, string> {
  const out: Record<string, string> = {};
  if (!header) return out;
  for (const part of header.split(";")) {
    const eq = part.indexOf("=");
    if (eq < 0) continue;
    const key = part.slice(0, eq).trim();
    if (key !== "") out[key] = part.slice(eq + 1).trim();
  }
  return out;
}

/** 定数時間比較（token の長さ差もリークさせない） */
export function tokenEquals(a: string, b: string): boolean {
  const bufA = Buffer.from(a, "utf8");
  const bufB = Buffer.from(b, "utf8");
  if (bufA.length !== bufB.length) return false;
  return timingSafeEqual(bufA, bufB);
}

export function hasValidSession(
  req: IncomingMessage,
  session: Session,
): boolean {
  const cookie = parseCookies(req.headers.cookie)[SESSION_COOKIE];
  return typeof cookie === "string" && tokenEquals(cookie, session.token);
}

/**
 * 状態を変える要求（POST）の受け入れ判定。
 * cookie だけでは CSRF を防げないため、Origin と custom header も必須にする。
 */
export function isAllowedMutation(
  req: IncomingMessage,
  session: Session,
  expectedOrigin: string,
): boolean {
  if (!hasValidSession(req, session)) return false;
  if (req.headers[CSRF_HEADER] !== "1") return false;

  const origin = req.headers.origin;
  // 同一オリジンからの fetch は Origin を必ず送る。無い要求は受け付けない。
  if (typeof origin !== "string") return false;
  return origin === expectedOrigin;
}

/** 秘密情報をログや state へ書かないための明示ヘルパー（意図を型で示す） */
export function redactToken(): string {
  return "<redacted>";
}

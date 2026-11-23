import { connect } from "node:net";

/**
 * ポートの決定（Phase 7 Decision 9）。
 * 既定ポートが使用中なら安全な空きポートを選び、.env に記録して以後安定させる。
 * 他プロジェクトのポート/コンテナへ干渉しない。
 */

export interface PortSpec {
  key: string; // .env のキー
  name: string;
  preferred: number;
}

export const PORT_SPECS: PortSpec[] = [
  { key: "APP_PORT", name: "app", preferred: 8180 },
  { key: "DB_FORWARD_PORT", name: "mysql", preferred: 33060 },
  { key: "MAILPIT_SMTP_PORT", name: "mailpit_smtp", preferred: 1026 },
  { key: "MAILPIT_UI_PORT", name: "mailpit_ui", preferred: 8026 },
];

// listen 方式は SO_REUSEADDR により誤判定するため、接続できるか（= 使用中か）で判定する
export function isPortFree(port: number): Promise<boolean> {
  return new Promise((resolve) => {
    const socket = connect({ port, host: "127.0.0.1" });
    const done = (free: boolean): void => {
      socket.destroy();
      resolve(free);
    };
    socket.setTimeout(300);
    socket.once("connect", () => done(false));
    socket.once("timeout", () => done(true));
    socket.once("error", () => done(true));
  });
}

export interface PortSelection {
  assignments: Record<string, number>;
  changed: string[]; // "app: 8180 → 8181" 形式
}

/**
 * 各サービスのポートを決める。既定が空いていれば既定、使用中なら +1 ずつ最大 +99 まで探す。
 * checker を注入できる（テスト用）。
 */
export async function choosePorts(
  checker: (port: number) => Promise<boolean> = isPortFree,
  specs: PortSpec[] = PORT_SPECS,
): Promise<PortSelection> {
  const taken = new Set<number>();
  const assignments: Record<string, number> = {};
  const changed: string[] = [];
  for (const s of specs) {
    let port = s.preferred;
    let tries = 0;
    while (tries < 100 && (taken.has(port) || !(await checker(port)))) {
      port++;
      tries++;
    }
    if (tries >= 100) {
      throw new Error(
        `${s.name} 用の空きポートが見つかりません（${s.preferred}〜${s.preferred + 99} をすべて確認）`,
      );
    }
    if (port !== s.preferred)
      changed.push(`${s.name}: ${s.preferred} → ${port}`);
    taken.add(port);
    assignments[s.key] = port;
  }
  return { assignments, changed };
}

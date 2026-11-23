import { connect } from "node:net";
export const PORT_SPECS = [
    { key: "APP_PORT", name: "app", preferred: 8180 },
    { key: "DB_FORWARD_PORT", name: "mysql", preferred: 33060 },
    { key: "MAILPIT_SMTP_PORT", name: "mailpit_smtp", preferred: 1026 },
    { key: "MAILPIT_UI_PORT", name: "mailpit_ui", preferred: 8026 },
];
// listen 方式は SO_REUSEADDR により誤判定するため、接続できるか（= 使用中か）で判定する
export function isPortFree(port) {
    return new Promise((resolve) => {
        const socket = connect({ port, host: "127.0.0.1" });
        const done = (free) => {
            socket.destroy();
            resolve(free);
        };
        socket.setTimeout(300);
        socket.once("connect", () => done(false));
        socket.once("timeout", () => done(true));
        socket.once("error", () => done(true));
    });
}
/**
 * 各サービスのポートを決める。既定が空いていれば既定、使用中なら +1 ずつ最大 +99 まで探す。
 * checker を注入できる（テスト用）。
 */
export async function choosePorts(checker = isPortFree, specs = PORT_SPECS) {
    const taken = new Set();
    const assignments = {};
    const changed = [];
    for (const s of specs) {
        let port = s.preferred;
        let tries = 0;
        while (tries < 100 && (taken.has(port) || !(await checker(port)))) {
            port++;
            tries++;
        }
        if (tries >= 100) {
            throw new Error(`${s.name} 用の空きポートが見つかりません（${s.preferred}〜${s.preferred + 99} をすべて確認）`);
        }
        if (port !== s.preferred)
            changed.push(`${s.name}: ${s.preferred} → ${port}`);
        taken.add(port);
        assignments[s.key] = port;
    }
    return { assignments, changed };
}

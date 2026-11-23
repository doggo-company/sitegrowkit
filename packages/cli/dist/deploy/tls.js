import { connect } from "node:tls";
/**
 * TLS で繋いで `notAfter` を読む。
 *
 * **証明書が読めた時点で切る。** 本文は要らないし、長く掴んでいる理由も無い。
 */
export async function probeCertificateExpiry(host, port = 443, timeoutMs = 5_000) {
    return await new Promise((resolve) => {
        let settled = false;
        const finish = (result) => {
            if (settled)
                return;
            settled = true;
            socket.destroy();
            resolve(result);
        };
        const socket = connect({
            host,
            port,
            servername: host,
            // **期限だけを見たいので、検証の失敗では止めない。**
            // 期限切れの証明書は「検証が通らない」ので、拒むと**測りたいものが測れない**
            rejectUnauthorized: false,
        }, () => {
            const cert = socket.getPeerCertificate();
            if (cert === null || typeof cert.valid_to !== "string") {
                finish({ ok: false, reason: "証明書を取得できませんでした" });
                return;
            }
            const notAfter = new Date(cert.valid_to);
            finish(Number.isNaN(notAfter.getTime())
                ? { ok: false, reason: `有効期限を読めません（${cert.valid_to}）` }
                : { ok: true, notAfter });
        });
        socket.setTimeout(timeoutMs, () => {
            finish({ ok: false, reason: `${timeoutMs}ms で応答がありません` });
        });
        socket.on("error", (error) => {
            finish({ ok: false, reason: error.message });
        });
    });
}
/** 期限が近いと見なす残り日数（既定） */
export const SSL_WARN_DAYS = 30;
/**
 * 測った結果を検査へ写す。**ここは純粋関数**なので、ネットワーク無しで確かめられる。
 *
 * | 状態 | status |
 * | --- | --- |
 * | 切れている | **FAIL** |
 * | 残り `warnDays` 未満 | **WARN** |
 * | それ以外 | PASS |
 * | **測れなかった** | **WARN**（**PASS にしない**） |
 */
export function certificateExpiryCheck(fqdn, probe, now, warnDays = SSL_WARN_DAYS) {
    if (!probe.ok) {
        return {
            key: "ssl_expiry",
            name: "SSL 有効期限",
            status: "WARN",
            what: `${fqdn} の証明書を読めませんでした（${probe.reason}）`,
            why: "期限が近いかどうかを判断できません。**大丈夫だとは言えない**状態です",
            fix: "公開 URL へ外から到達できるか（DNS / ファイアウォール / 認証）を確認してください",
        };
    }
    const days = Math.floor((probe.notAfter.getTime() - now.getTime()) / 86_400_000);
    const detected = `${probe.notAfter.toISOString().slice(0, 10)}（残り ${days} 日）`;
    if (days < 0) {
        return {
            key: "ssl_expiry",
            name: "SSL 有効期限",
            status: "FAIL",
            detected,
            what: `${fqdn} の証明書は既に切れています`,
            why: "HTTPS で開けません。訪問者にはブラウザの警告が出ます",
            fix: "証明書を再発行してください（XServer は無料独自SSL の再取得）",
        };
    }
    if (days < warnDays) {
        return {
            key: "ssl_expiry",
            name: "SSL 有効期限",
            status: "WARN",
            detected,
            what: `${fqdn} の証明書は残り ${days} 日です`,
            why: `切れると HTTPS で開けなくなります（${warnDays} 日を切ったら知らせています）`,
            fix: "自動更新が効いているかを確認してください",
        };
    }
    return {
        key: "ssl_expiry",
        name: "SSL 有効期限",
        status: "PASS",
        detected,
    };
}

import { connect } from "node:tls";

import type { CapabilityCheck } from "./types.js";

/**
 * 証明書の有効期限を、**証明書そのものから**読む（P1 ⑪）。
 *
 * ## なぜ API に訊かないか
 *
 * XServer の Control Plane は `sslList()` で**発行状態**（active / pending）は返すが、
 * **期限を返すかは分かっていない**（応答の型は `unknown[]` で、docblock も
 * 「公式詳細未確認」と書いてある）。
 *
 * それ以前に、**期限は証明書が持っているのが本来で、管理画面の API は写しである**。
 * 写しではなく正本を読む。provider 中立（ADR-0008）にもなる —
 * XServer 以外へ出しても同じ形で効く。
 *
 * ## 測れなかったことを、通ったことにしない
 *
 * 到達できない理由はいくらでもある（basic 認証の内側・IP 制限・DNS 未反映・
 * ネットワーク断）。**そのとき「期限は大丈夫」とは言えない。**
 * だから「測れなかった」は **WARN** にして、**PASS にはしない**。
 */
export type ExpiryProbe =
  { ok: true; notAfter: Date } | { ok: false; reason: string };

/**
 * TLS で繋いで `notAfter` を読む。
 *
 * **証明書が読めた時点で切る。** 本文は要らないし、長く掴んでいる理由も無い。
 */
export async function probeCertificateExpiry(
  host: string,
  port = 443,
  timeoutMs = 5_000,
): Promise<ExpiryProbe> {
  return await new Promise<ExpiryProbe>((resolve) => {
    let settled = false;
    const finish = (result: ExpiryProbe): void => {
      if (settled) return;
      settled = true;
      socket.destroy();
      resolve(result);
    };

    const socket = connect(
      {
        host,
        port,
        servername: host,
        // **期限だけを見たいので、検証の失敗では止めない。**
        // 期限切れの証明書は「検証が通らない」ので、拒むと**測りたいものが測れない**
        rejectUnauthorized: false,
      },
      () => {
        const cert = socket.getPeerCertificate();

        if (cert === null || typeof cert.valid_to !== "string") {
          finish({ ok: false, reason: "証明書を取得できませんでした" });
          return;
        }

        const notAfter = new Date(cert.valid_to);

        finish(
          Number.isNaN(notAfter.getTime())
            ? { ok: false, reason: `有効期限を読めません（${cert.valid_to}）` }
            : { ok: true, notAfter },
        );
      },
    );

    socket.setTimeout(timeoutMs, () => {
      finish({ ok: false, reason: `${timeoutMs}ms で応答がありません` });
    });

    socket.on("error", (error: Error) => {
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
export function certificateExpiryCheck(
  fqdn: string,
  probe: ExpiryProbe,
  now: Date,
  warnDays = SSL_WARN_DAYS,
): CapabilityCheck {
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

  const days = Math.floor(
    (probe.notAfter.getTime() - now.getTime()) / 86_400_000,
  );
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

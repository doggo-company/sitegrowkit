import type { Failure } from "./ui.js";

/** Windows の SSH/WSL 実機で起きた認証ストア障害を、ネットワーク障害と区別する。 */
export function dockerCredentialFailure(output: string): Failure | null {
  if (
    !/error getting credentials|a specified logon session does not exist/i.test(
      output,
    )
  ) {
    return null;
  }
  return {
    code: "docker.credentials_unavailable",
    what: "Docker の認証情報を読み出せませんでした",
    why: "Docker credential helper が利用できないため、必要なイメージを取得できません。SSH から実行した Windows では認証ストアを開けないことがあります",
    fix: "Windows にサインインした状態で Docker Desktop を起動し、PC 上の WSL ターミナルから agentcms dev を再実行してください。保存済みの認証設定を削除する必要はありません",
    title: "Docker の認証情報を利用できません",
    message:
      "PC 上で Docker Desktop を起動し、WSL ターミナルからもう一度実行してください。SSH 経由の場合は、Windows の認証ストアに接続できないことがあります。",
    recovery: [
      { kind: "retry", label: "もう一度試す" },
      { kind: "showDetails", label: "詳細を見る" },
    ],
  };
}

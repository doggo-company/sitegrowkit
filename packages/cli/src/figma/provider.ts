/**
 * FigmaApi の解決（Standard Mode）。
 * - AGENTCMS_FIGMA_FIXTURE=<dir> → FixtureFigmaApi（CI / テスト / offline）
 * - FIGMA_ACCESS_TOKEN → HttpFigmaApi（実 API。Read Only）
 * どちらも無ければ {what,why,fix} 診断を返す（token を CLI 引数では受け取らない: SPEC §8）。
 */
import { existsSync } from "node:fs";

import type { Failure } from "../ui.js";
import type { FigmaApi } from "./api.js";
import { FixtureFigmaApi } from "./api-fixture.js";
import { HttpFigmaApi } from "./api-http.js";
import { resolveFigmaToken } from "./auth.js";

export function resolveFigmaApi(
  env: NodeJS.ProcessEnv = process.env,
): { api: FigmaApi } | { error: Failure } {
  const fixtureDir = env.AGENTCMS_FIGMA_FIXTURE?.trim();
  if (fixtureDir) {
    if (!existsSync(fixtureDir)) {
      return {
        error: {
          what: "Figma fixture ディレクトリが見つかりません",
          why: `AGENTCMS_FIGMA_FIXTURE=${fixtureDir} が存在しません`,
          fix: "正しい fixture ディレクトリを指定するか、環境変数を外して FIGMA_ACCESS_TOKEN を使ってください",
        },
      };
    }
    return { api: new FixtureFigmaApi(fixtureDir) };
  }
  const token = resolveFigmaToken(env);
  if (!token) {
    return {
      error: {
        what: "Figma API へ接続できません",
        why: "FIGMA_ACCESS_TOKEN が設定されていません（--token オプションは shell history に残るため提供していません）",
        fix: "Figma の Settings > Security で file_metadata:read + file_content:read scope の Personal Access Token を発行し、環境変数 FIGMA_ACCESS_TOKEN に設定してください。Git / config へは保存しないでください",
      },
    };
  }
  return { api: new HttpFigmaApi(token) };
}

/** API 失敗 → {what,why,fix} 診断（SPEC §89） */
export function diagnoseApiFailure(
  action: string,
  failure: {
    kind: string;
    status: number;
    message: string;
    retryAfterSeconds?: number;
  },
): Failure {
  switch (failure.kind) {
    case "auth":
      return {
        what: `${action}に失敗しました（401）`,
        why: "FIGMA_ACCESS_TOKEN が無効か失効しています",
        fix: "Figma の Settings > Security で token を再発行し、FIGMA_ACCESS_TOKEN を更新してください",
      };
    case "forbidden":
      return {
        what: `${action}に失敗しました（403）`,
        why: `scope 不足または plan 制限です: ${failure.message}`,
        fix: "token に file_metadata:read / file_content:read scope が付いているか確認してください。Variables API は Enterprise plan 限定です（無くても Phase 6 は動作します）",
      };
    case "not_found":
      return {
        what: `${action}に失敗しました（404）`,
        why: "file key が誤っているか、この token のアカウントにファイルへのアクセス権がありません",
        fix: "Figma URL を確認し、ファイルが共有されているアカウントの token を使ってください",
      };
    case "rate_limit":
      return {
        what: `${action}に失敗しました（429: rate limit）`,
        why: `Retry-After ${failure.retryAfterSeconds ?? "?"} 秒。再試行上限に達しました`,
        fix: "時間をおいて再実行してください。Raw Cache が有効なら version 未変更時は API call は発生しません",
      };
    default:
      return {
        what: `${action}に失敗しました`,
        why: `${failure.status}: ${failure.message}`,
        fix: "ネットワークと https://status.figma.com を確認してください",
      };
  }
}

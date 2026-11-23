import { describe, expect, it } from "vitest";
import { dockerCredentialFailure } from "../src/docker-errors.js";

describe("Docker credential helper の診断", () => {
  it("Windows SSH で観測した失敗を認証情報の問題として説明する", () => {
    const result = dockerCredentialFailure(
      "failed to solve: error getting credentials - err: exit status 1, out: `A specified logon session does not exist. It may already have been terminated.`",
    );
    expect(result?.code).toBe("docker.credentials_unavailable");
    expect(result?.fix).toContain("WSL ターミナル");
  });

  it("レジストリ認証拒否や DNS エラーを認証ストア障害と混同しない", () => {
    expect(
      dockerCredentialFailure("pull access denied: unauthorized"),
    ).toBeNull();
    expect(
      dockerCredentialFailure("lookup registry-1.docker.io: no such host"),
    ).toBeNull();
  });
});

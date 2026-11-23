import { describe, expect, it } from "vitest";

import {
  classifyComposeFailure,
  composeFailureMessage,
  listSiteGrowKitEnvironments,
} from "../src/docker-failure.js";

/** setup の Docker の失敗の言葉（UX 一周 18 周目 G-env） */
describe("docker compose up の失敗の原因", () => {
  it("ネットワークの空き切れを見分ける（以前は「Docker Desktop を確認」と出ていた）", () => {
    const out =
      "Error response from daemon: all predefined address pools have been fully subnetted";
    expect(classifyComposeFailure(out)).toBe("network_pool");
    expect(classifyComposeFailure("Bind for 127.0.0.1:8180 failed: port is already allocated")).toBe("port");
    expect(classifyComposeFailure("Cannot connect to the Docker daemon at unix:///var/run/docker.sock")).toBe("daemon");
    expect(classifyComposeFailure("something else")).toBe("unknown");
  });

  it("空き切れなら、止められる試作環境をフォルダで並べる（自分は除く・自動では止めない）", () => {
    const said = composeFailureMessage(
      "network_pool",
      [
        { project: "agentcms-a-1", folder: "/Users/x/SiteGrowKit Projects/a", state: "running" },
        { project: "agentcms-b-2", folder: "/Users/x/SiteGrowKit Projects/b", state: "exited" },
        { project: "agentcms-me-3", folder: "/Users/x/SiteGrowKit Projects/me", state: "running" },
      ],
      "agentcms-me-3",
    );
    expect(said.title).toContain("ネットワークの空き");
    expect(said.message).toContain("/Users/x/SiteGrowKit Projects/a（動いています）");
    expect(said.message).toContain("/Users/x/SiteGrowKit Projects/b（止まっています）");
    expect(said.message).not.toContain("/me（");
    expect(said.message).toContain("agentcms stop");
    expect(said.message).toContain("自動で止めません");
    expect(said.message).not.toContain("Docker Desktop が起動しているか");
  });

  it("試作環境の一覧は compose の agentcms- だけを、プロジェクトごとに 1 つ", () => {
    const fake = () => ({
      code: 0,
      stdout: [
        "agentcms-a-1\t/p/a\texited",
        "agentcms-a-1\t/p/a\trunning",
        "other-project\t/p/o\trunning",
        "agentcms-b-2\t/p/b\texited",
      ].join("\n"),
      stderr: "",
    });
    expect(listSiteGrowKitEnvironments(fake)).toEqual([
      { project: "agentcms-a-1", folder: "/p/a", state: "running" },
      { project: "agentcms-b-2", folder: "/p/b", state: "exited" },
    ]);
  });
});

describe("繋がっているか", () => {
  it("agentcms dev（setup も同じ）が docker compose up の失敗をこの分け方で言う", async () => {
    const { readFileSync } = await import("node:fs");
    const source = readFileSync(new URL("../src/commands/dev.ts", import.meta.url), "utf8");
    expect(source).toContain("classifyComposeFailure(");
    expect(source).toContain("composeFailureMessage(");
    expect(source).toContain("listSiteGrowKitEnvironments()");
  });
});

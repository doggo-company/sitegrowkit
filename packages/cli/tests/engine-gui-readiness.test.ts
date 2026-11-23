import { execFileSync } from "node:child_process";
import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import { create } from "../src/commands/create.js";
import { dev, requireProject } from "../src/commands/dev.js";
import {
  CREATE_STEP_ORDER,
  DEV_STEP_ORDER,
  EngineError,
  type EngineEvent,
  type StepId,
} from "../src/engine.js";
import { pnpmCommand } from "../src/node-runtime.js";
import { CommandAbortedError, run } from "../src/run.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");

/* ---------------------------------------------------------------- *
 * Phase 8.5-A — GUI-ready engine
 * ---------------------------------------------------------------- */

describe("engine は process.exit しない（GUI から関数として呼べる）", () => {
  it("project でない場所を指定した dev() は EngineError を throw する（プロセスは落ちない）", async () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-noproject-"));
    try {
      await expect(
        dev({ root: dir, onEvent: () => {} }),
      ).rejects.toBeInstanceOf(EngineError);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("EngineError は machine-readable な code を持つ", () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-noproject-code-"));
    try {
      expect(() => requireProject(dir)).toThrow(EngineError);
      try {
        requireProject(dir);
      } catch (e) {
        const failure = (e as EngineError).failure;
        expect(failure.code).toBe("project.not_found");
        expect(failure.what).toBeTruthy();
        expect(failure.why).toBeTruthy();
        expect(failure.fix).toBeTruthy();
        expect(failure.title).toBeTruthy();
      }
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("engine の Golden Path から failAndExit（process.exit）が消えている", () => {
    for (const rel of ["src/commands/dev.ts"]) {
      const source = readFileSync(join(repoRoot, "packages/cli", rel), "utf8");
      expect(source).not.toContain("failAndExit");
    }
  });
});

describe("command runner", () => {
  it("stdout / stderr を capture し、行コールバックへ配信する", async () => {
    const outLines: string[] = [];
    const errLines: string[] = [];
    const r = await run(
      process.execPath,
      [
        "-e",
        "console.log('out-1'); console.log('out-2'); console.error('err-1');",
      ],
      { onStdout: (l) => outLines.push(l), onStderr: (l) => errLines.push(l) },
    );

    expect(r.code).toBe(0);
    expect(r.stdout).toContain("out-1");
    expect(r.stdout).toContain("out-2");
    expect(r.stderr).toContain("err-1");
    expect(outLines).toEqual(["out-1", "out-2"]);
    expect(errLines).toEqual(["err-1"]);
  });

  it("非 0 終了コードを例外にせず返す", async () => {
    const r = await run(process.execPath, ["-e", "process.exit(3)"]);
    expect(r.code).toBe(3);
  });

  it("argument injection: 引数は shell 解釈されず literal で渡る", async () => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-injection-"));
    try {
      const marker = join(dir, "pwned");
      // shell が使われていれば `;` 以降が別コマンドとして実行されてしまう
      const payload = `hello; touch ${marker}`;
      const r = await run(process.execPath, [
        "-e",
        "console.log(process.argv[1])",
        payload,
      ]);

      expect(r.code).toBe(0);
      expect(r.stdout).toBe(payload);
      expect(existsSync(marker)).toBe(false);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("AbortSignal で子プロセスを終了し、CommandAbortedError を返す", async () => {
    const controller = new AbortController();
    const started = Date.now();
    const promise = run(
      process.execPath,
      ["-e", "setTimeout(() => {}, 60000)"],
      { signal: controller.signal },
    );
    setTimeout(() => controller.abort(), 100);

    await expect(promise).rejects.toBeInstanceOf(CommandAbortedError);
    // 60 秒待たずに終了している（プロセスが確実に kill されている）
    expect(Date.now() - started).toBeLessThan(20_000);
  });
});

describe("host pnpm を要求しない（Phase 8.5-A A2）", () => {
  it("pnpm は既定で docker の node service 上で実行される", () => {
    const c = pnpmCommand("/tmp/project", ["install"]);
    expect(c.kind).toBe("docker");
    expect(c.cmd).toBe("docker");
    expect(c.args.slice(0, 6)).toEqual([
      "compose",
      "--profile",
      "tools",
      "run",
      "--rm",
      "-T",
    ]);
    expect(c.args).toContain("node");
    expect(c.args).toContain("pnpm");
    expect(c.args[c.args.length - 1]).toBe("install");
  });

  it("env は -e の引数配列として渡る（shell 文字列にしない）", () => {
    const c = pnpmCommand("/tmp/project", ["run", "build"], {
      env: { AGENTCMS_SKIP_WAYFINDER: "1" },
    });
    const i = c.args.indexOf("-e");
    expect(i).toBeGreaterThan(0);
    expect(c.args[i + 1]).toBe("AGENTCMS_SKIP_WAYFINDER=1");
  });

  it("dev は host の pnpm を直接起動しない", () => {
    const source = readFileSync(
      join(repoRoot, "packages/cli/src/commands/dev.ts"),
      "utf8",
    );
    expect(source).not.toMatch(/runInherit\(\s*"pnpm"/);
    expect(source).not.toMatch(/runInheritCapture\(\s*"pnpm"/);
    expect(source).not.toContain('commandExists("pnpm")');
  });

  it("template の docker-compose に node service（tools profile）がある", () => {
    const compose = readFileSync(
      join(repoRoot, "template/docker-compose.yml"),
      "utf8",
    );
    expect(compose).toMatch(/^ {2}node:/m);
    expect(compose).toContain("profiles: ['tools']");
    expect(compose).toContain("context: ./docker/node");
    // 常駐サービスではない（up --wait の対象を増やさない）
    expect(compose).not.toMatch(/node:[\s\S]*?healthcheck:/);
    expect(existsSync(join(repoRoot, "template/docker/node/Dockerfile"))).toBe(
      true,
    );
  });

  it("node イメージは host UID/GID で実行する（root 所有物を残さない）", () => {
    const dockerfile = readFileSync(
      join(repoRoot, "template/docker/node/Dockerfile"),
      "utf8",
    );
    expect(dockerfile).toContain("ARG HOST_UID=1000");
    expect(dockerfile).toContain("ARG HOST_GID=1000");
    expect(dockerfile).toContain("corepack enable");
  });
});

describe("create の progress events（GUI のチェックリスト表示の基準）", () => {
  let workDir: string;
  const steps: StepId[] = [];
  const results: Record<string, string> = {};

  beforeAll(async () => {
    workDir = mkdtempSync(join(tmpdir(), "agentcms-events-"));
    const onEvent = (e: EngineEvent): void => {
      if (e.type === "step") steps.push(e.id);
      if (e.type === "result") results[e.key] = e.value;
    };
    const code = await create(join(workDir, "evented-site"), { onEvent });
    expect(code).toBe(0);
  }, 120_000);

  afterAll(() => {
    rmSync(workDir, { recursive: true, force: true });
  });

  it("宣言された順序どおりに step が発行される", () => {
    expect(steps).toEqual([...CREATE_STEP_ORDER]);
  });

  it("結果（project path / URL）を event として受け取れる", () => {
    expect(results.projectPath).toContain("evented-site");
    expect(results.publicUrl).toMatch(/^http:\/\/localhost:\d+$/);
  });

  it("dev の step 順序が宣言されている（GUI 進捗の契約）", () => {
    expect(DEV_STEP_ORDER[0]).toBe("dev.started");
    expect(DEV_STEP_ORDER[DEV_STEP_ORDER.length - 1]).toBe("dev.ready");
    expect(DEV_STEP_ORDER).toContain("dev.docker.started");
    expect(DEV_STEP_ORDER).toContain("dev.dependencies.started");
    expect(DEV_STEP_ORDER).toContain("dev.database.started");
    expect(DEV_STEP_ORDER).toContain("dev.build.started");
  });
});

describe("explicit projectPath（cwd 非依存）", () => {
  let workDir: string;
  let projectDir: string;

  beforeAll(async () => {
    workDir = mkdtempSync(join(tmpdir(), "agentcms-explicit-root-"));
    projectDir = join(workDir, "explicit-site");
    expect(await create(projectDir)).toBe(0);
  }, 120_000);

  afterAll(() => {
    rmSync(workDir, { recursive: true, force: true });
  });

  it("cwd がプロジェクト外でも explicit root で解決できる", () => {
    // process.cwd() は kit repo（= 別プロジェクト）だが、明示 root が優先される
    expect(requireProject(projectDir)).toBe(projectDir);
  });

  it("生成 project の compose 定義に node service が含まれる", () => {
    const compose = readFileSync(
      join(projectDir, "docker-compose.yml"),
      "utf8",
    );
    expect(compose).toMatch(/^ {2}node:/m);
    expect(existsSync(join(projectDir, "docker/node/Dockerfile"))).toBe(true);
  });

  it("生成サイトのWeb・DB・MailpitはこのPCだけに公開する", () => {
    // YAMLの文字列だけでなく、生成済み.envを含むComposeの解決結果を検証する。
    const out = execFileSync(
      "docker", ["compose", "config", "--format", "json"],
      { cwd: projectDir, encoding: "utf8" },
    );
    const config = JSON.parse(out) as {
      services: Record<string, { ports?: { host_ip: string; target: number }[] }>;
    };
    const ports = Object.values(config.services).flatMap(service => service.ports ?? []);
    expect(ports.map(port => port.target).sort((a, b) => a - b)).toEqual([80, 1025, 3306, 8025]);
    expect(ports.every(port => port.host_ip === "127.0.0.1")).toBe(true);
  });

  it("生成 project の compose 定義が docker compose config で妥当", () => {
    const out = execFileSync(
      "docker",
      ["compose", "--profile", "tools", "config", "--services"],
      { cwd: projectDir, encoding: "utf8" },
    );
    expect(out.split("\n").map((s) => s.trim())).toContain("node");
  });
});

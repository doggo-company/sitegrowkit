import {
  readFileSync,
  mkdtempSync,
  mkdirSync,
  existsSync,
  rmSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { runInNewContext } from "node:vm";
import { describe, expect, it, vi } from "vitest";
import { WizardRunner } from "../src/wizard/runner.js";
import { writeSetupState, readSetupState } from "../src/wizard/state.js";

const assets = join(
  dirname(fileURLToPath(import.meta.url)),
  "../src/wizard/assets",
);

describe("最初からやり直す前の確認", () => {
  it("押すだけでは送らず、戻ると閉じ、確認して初めて送る", async () => {
    const nodes = new Map<
      string,
      {
        hidden: boolean;
        focus: ReturnType<typeof vi.fn>;
        addEventListener: ReturnType<typeof vi.fn>;
      }
    >();
    const node = (selector: string) => {
      if (!nodes.has(selector))
        nodes.set(selector, {
          hidden: true,
          focus: vi.fn(),
          addEventListener: vi.fn(),
        });
      return nodes.get(selector)!;
    };
    const clicks: ((event: unknown) => Promise<void>)[] = [];
    const fetch = vi
      .fn()
      .mockResolvedValue({ ok: true, status: 200, json: async () => ({}) });
    runInNewContext(readFileSync(join(assets, "app.js"), "utf8"), {
      document: {
        querySelector: node,
        querySelectorAll: () => [],
        addEventListener: (
          name: string,
          callback: (event: unknown) => Promise<void>,
          capture?: boolean,
        ) => {
          if (name === "click" && !capture) clicks.push(callback);
        },
      },
      EventSource: class {
        addEventListener() {}
      },
      fetch,
      setTimeout,
      clearTimeout,
    });
    const click = (action: string) =>
      clicks[0]!({ target: { closest: () => ({ dataset: { action } }) } });
    await click("resume-discard");
    expect(node('[data-bind="discard-confirmation"]').hidden).toBe(false);
    expect(fetch).not.toHaveBeenCalled();
    await click("resume-discard-back");
    expect(node('[data-bind="discard-confirmation"]').hidden).toBe(true);
    expect(fetch).not.toHaveBeenCalled();
    await click("resume-discard");
    await click("resume-discard-confirm");
    expect(fetch).toHaveBeenCalledWith(
      "/api/resume/discard",
      expect.objectContaining({ method: "POST" }),
    );
    const html = readFileSync(join(assets, "index.html"), "utf8");
    expect(html).toContain("消えずに残ります");
    expect(html).toContain(
      "同じサイト名でもう一度作ると、フォルダ名の後ろに -2 などの番号が付いた、別の新しいフォルダになります",
    );
  });

  it("snapshot に実際のパスがあり、やり直してもプロジェクトは残る", () => {
    const home = mkdtempSync(join(tmpdir(), "round3-wizard-"));
    try {
      const projectPath = join(home, "my-project");
      mkdirSync(projectPath);
      writeSetupState(
        {
          version: 1,
          projectPath,
          projectName: "my-project",
          siteName: "犬の庭",
          stage: "created",
          createdAt: new Date().toISOString(),
          updatedAt: new Date().toISOString(),
        },
        home,
      );
      const runner = new WizardRunner({
        home,
        pickerAvailable: false,
        defaultLocation: home,
      });
      expect(runner.snapshot().resumable?.projectPath).toBe(projectPath);
      runner.discardResume();
      expect(runner.snapshot().phase).toBe("WELCOME");
      expect(readSetupState(home)).toBeNull();
      expect(existsSync(projectPath)).toBe(true);
    } finally {
      rmSync(home, { recursive: true, force: true });
    }
  });
});

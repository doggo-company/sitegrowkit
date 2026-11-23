/**
 * B-3 — 「落ちた」と「殺された」を分ける。
 *
 * `verify` の Pest ゲートが **出力 0 バイトのまま静かに終わる**ことがあり、
 * **実行中と見分けが付かない**のが厄介、と起票されていた。
 *
 * 実測すると、原因は memory 設定ではなく **`run()` が signal 死を潰していた**ことだった:
 * Node は signal で死ぬと `code === null` を渡すが、そこを `?? 1` で 1 にしていたので、
 * **OOM killer も segfault も「普通のテスト失敗」と同じ顔**になっていた。
 */
import { describe, expect, it } from "vitest";

import { run, runCapture } from "../src/run.js";

describe("殺されたことを 1 に潰さない", () => {
  it("普通の失敗は、その終了コードのまま", async () => {
    const r = await run("node", ["-e", "process.exit(3)"]);

    expect(r.code).toBe(3);
    expect(r.signal).toBeNull();
  });

  /** OOM killer と同じ死に方。shell の慣例で 137（= 128 + 9） */
  it("SIGKILL は 137 と、名前で分かる", async () => {
    const r = await run("node", ["-e", 'process.kill(process.pid, "SIGKILL")']);

    expect(r.code).toBe(137);
    expect(r.signal).toBe("SIGKILL");
  });

  /** segfault。139（= 128 + 11） */
  it("SIGSEGV は 139 と、名前で分かる", async () => {
    const r = await run("node", ["-e", 'process.kill(process.pid, "SIGSEGV")']);

    expect(r.code).toBe(139);
    expect(r.signal).toBe("SIGSEGV");
  });

  /**
   * **出力が 0 バイトでも、殺されたことは分かる。**
   *
   * ここが起票の本体だった — 出力が無いので「実行中」と見分けが付かなかった。
   */
  it("出力が 0 バイトでも殺されたと言える", async () => {
    const r = await run("node", ["-e", 'process.kill(process.pid, "SIGKILL")']);

    expect(r.stdout).toBe("");
    expect(r.stderr).toBe("");
    expect(r.signal).not.toBeNull();
  });

  /** 同期側（`runCapture`）も同じ扱いに揃える */
  it("runCapture でも同じ", () => {
    const r = runCapture("node", [
      "-e",
      'process.kill(process.pid, "SIGKILL")',
    ]);

    expect(r.code).toBe(137);
    expect(r.signal).toBe("SIGKILL");
  });
});

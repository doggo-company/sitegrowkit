import {
  chmodSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

import { globalInstallCheck } from "../src/commands/doctor.js";

const here = dirname(fileURLToPath(import.meta.url));

/**
 * `npm install -g` の行き先へ、**実際に書けるか**（Windows/WSL2 一周 2026-09-04）。
 *
 * ## なぜこの判定が要るのか
 *
 * `docs/WINDOWS_WSL2.md` は Node を nodesource + apt で入れる案内をしている。
 * すると `npm install -g` の行き先は `/usr/lib/node_modules` になるのに、
 * `README_FIRST.md` §4 は **sudo 無しの `npm install -g`** を指示している。
 * **手順どおりにやると必ず転ぶ。** WIN2 の WSL2 で実測した:
 *
 * ```
 * npm error The operation was rejected by your operating system.
 * ```
 *
 * それまで `doctor` は全項目緑で「致命的な問題はありません」と言っていた。
 * 🔴 **緑を見て進んだ人が、次の手で転ぶ。**
 *
 * ## 届く相手（この判定だけでは足りない）
 *
 * `doctor` は `agentcms` のサブコマンドなので、**install-g が転んだ人は
 * これを実行できない**。だから `README_FIRST.md` §4 の直前へ
 * 「うまくいかないときは先にこれを回す」と `npx` の 1 行を置いてある。
 * **判定と案内は 2 つで 1 組。**
 */
describe("グローバル導入先の書き込み（npm.global_writable）", () => {
  const made: string[] = [];

  const temp = (): string => {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-global-"));
    made.push(dir);

    return dir;
  };

  afterEach(() => {
    for (const dir of made.splice(0)) {
      try {
        chmodSync(dir, 0o755);
      } catch {
        // 既に消えている場合は気にしない
      }

      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("書ける導入先なら通る", () => {
    const prefix = temp();
    mkdirSync(join(prefix, "lib", "node_modules"), { recursive: true });

    const result = globalInstallCheck(prefix);

    expect(result.status).toBe("pass");
    expect(result.detail).toContain("node_modules");
  });

  it("診断前からある同名ファイルを残し、検査用ファイルだけを片付ける", () => {
    const prefix = temp();
    const target = join(prefix, "lib", "node_modules");
    mkdirSync(target, { recursive: true });
    const existing = join(target, ".agentcms-doctor-probe");
    writeFileSync(existing, "残すべき利用者のファイル");

    expect(globalInstallCheck(prefix).status).toBe("pass");
    expect(readFileSync(existing, "utf8")).toBe("残すべき利用者のファイル");
    expect(readdirSync(target)).toEqual([".agentcms-doctor-probe"]);
  });

  it.skipIf(process.platform === "win32")("同名のシンボリックリンクがあっても参照先を壊さない", () => {
    // Windows の symlink 権限に依存させず、対応する WSL2/macOS 上で検証する。
    const prefix = temp();
    const target = join(prefix, "lib", "node_modules");
    mkdirSync(target, { recursive: true });
    const original = join(prefix, "original.txt");
    writeFileSync(original, "変更禁止");
    const link = join(target, ".agentcms-doctor-probe");
    symlinkSync(original, link);

    expect(globalInstallCheck(prefix).status).toBe("pass");
    expect(readFileSync(original, "utf8")).toBe("変更禁止");
    expect(readFileSync(link, "utf8")).toBe("変更禁止");
    expect(readdirSync(target)).toEqual([".agentcms-doctor-probe"]);
  });

  /** 🔴 これが本体。書けないなら**注意ではなく失敗**にする */
  it("書けない導入先なら落ちる", () => {
    const prefix = temp();
    mkdirSync(join(prefix, "lib", "node_modules"), { recursive: true });
    chmodSync(join(prefix, "lib", "node_modules"), 0o555);

    const result = globalInstallCheck(prefix);

    expect(result.status).toBe("fail");
  });

  it("落ちたときは、そのまま打てる直し方を出す", () => {
    const prefix = temp();
    mkdirSync(join(prefix, "lib", "node_modules"), { recursive: true });
    chmodSync(join(prefix, "lib", "node_modules"), 0o555);

    const fix = globalInstallCheck(prefix).failure?.fix ?? "";

    expect(fix).toContain("npm config set prefix ~/.npm-global");
    expect(fix).toContain("PATH");
  });

  it("落ちたときは、どの手順で使う場所かを言う", () => {
    const prefix = temp();
    mkdirSync(join(prefix, "lib", "node_modules"), { recursive: true });
    chmodSync(join(prefix, "lib", "node_modules"), 0o555);

    expect(globalInstallCheck(prefix).failure?.why ?? "").toContain(
      "README_FIRST",
    );
  });

  /**
   * `npm config set prefix ~/.npm-global` を設定した直後は、まだ作られていない。
   * **無いことは失敗ではない。** 親をたどって「作れるか」を見る。
   */
  it("導入先がまだ無くても、親が書けるなら通る", () => {
    const prefix = temp();

    expect(globalInstallCheck(prefix).status).toBe("pass");
  });

  it("導入先がどこにも無ければ、失敗ではなく注意にする", () => {
    expect(globalInstallCheck("/nonexistent-agentcms-probe/xyz").status).toBe(
      "warn",
    );
  });

  /**
   * 🔴 **この試験だけは、上の 6 つでは守れないものを守っている。**
   *
   * 判定を「書いて消す」から `accessSync(target, W_OK)` へ弱めても、
   * **上の 6 つは全部緑のままだった**（実測）。`chmod 0555` は `access()` でも
   * 正しく落ちるので、2 つの実装を区別できない。
   *
   * `access()` が嘘をつくのは、そこではない場面である —
   * root_squash の NFS・読み取り専用マウント・ACL・一部の Docker volume。
   * **どれも一時ディレクトリでは再現できない。**
   *
   * 再現できないものを「緑」で覆い隠さない。**実装がどちらを選んでいるかを、
   * ここで直接固定する。** 弱めた実装に差し替えたら、この 1 件が落ちる。
   */
  it("聞くのではなく、書いて消していることを固定する", () => {
    const source = readFileSync(
      join(here, "../src/commands/doctor.ts"),
      "utf8",
    );

    const body = source.slice(source.indexOf("export function globalInstallCheck"));
    const fn = body.slice(0, body.indexOf("\nexport function"));

    expect(fn).toContain("writeFileSync(probe");
    expect(fn).toContain("rmSync(probe)");
    expect(fn).not.toContain("accessSync");
  });
});

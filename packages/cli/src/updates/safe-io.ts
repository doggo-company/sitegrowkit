import { createHash, randomBytes } from "node:crypto";
import {
  closeSync,
  constants as fsConstants,
  fstatSync,
  fsyncSync,
  lstatSync,
  mkdirSync,
  openSync,
  readSync,
  realpathSync,
  renameSync,
  unlinkSync,
  writeSync,
} from "node:fs";
import { join } from "node:path";

/**
 * 更新確認まわりのファイル I/O を、**例外を外へ出さず・上限を実読込で守り・
 * リンクを辿らない**形にまとめる。
 *
 * ## なぜ要るか（独立レビュー 2026-09-10 で再現された欠陥）
 *
 * `writeUpdateCheckRecord()` は tmp 名が `<path>.<pid>.tmp` で予測でき、
 * そこへ symlink を先に置くと `writeFileSync` が**リンク先の無関係なファイルを
 * 書き換えた**（隔離 tmp の sentinel が上書きされることを実測）。
 *
 * 直し方は 1 つに寄せる: **予測できない名前 + 排他的作成（O_EXCL）+ リンクを
 * 追わない（O_NOFOLLOW）**で書き、親ディレクトリと最終保存先の link も拒否する。
 * 失敗したら tmp を片付け、既存ファイルは触らない。
 *
 * 読む側も同じ思想で、`stat → readFileSync` の間に伸びたファイルや、
 * 権限・削除で投げる例外を**安全な失敗 Result** に変える。
 */

export type IoFailureCode =
  | "not_found"
  | "not_a_file"
  | "permission_denied"
  | "too_large"
  | "size_changed"
  | "symlink_refused"
  | "hardlink_refused"
  | "io_error";

export type IoResult<T> =
  ({ ok: true } & T) | { ok: false; code: IoFailureCode; reason: string };

/** errno → 安全な失敗（スタック・raw message を持ち出さない） */
function ioFailure(e: unknown, label: string): IoResult<never> {
  const code = (e as { code?: unknown } | null)?.code;
  if (code === "ENOENT")
    return { ok: false, code: "not_found", reason: `${label}が見つかりません` };
  if (code === "EACCES" || code === "EPERM")
    return {
      ok: false,
      code: "permission_denied",
      reason: `${label}を読む権限がありません`,
    };
  if (code === "EISDIR")
    return {
      ok: false,
      code: "not_a_file",
      reason: `${label}がファイルではありません`,
    };
  if (code === "ELOOP")
    return {
      ok: false,
      code: "symlink_refused",
      reason: `${label}がシンボリックリンクです`,
    };
  return {
    ok: false,
    code: "io_error",
    reason: `${label}を読めません（${typeof code === "string" ? code : "I/O error"}）`,
  };
}

const CHUNK = 64 * 1024;

/**
 * ファイルを **limit bytes まで**読む。limit を超える byte が 1 つでも在れば
 * `too_large`（stat の値ではなく、実際に読めた量で判定する）。
 * 無い・ディレクトリ・権限・途中の I/O エラーはすべて Result で返す。
 */
export function readFileBounded(
  path: string,
  limit: number,
  label = "ファイル",
): IoResult<{ bytes: Buffer }> {
  let fd: number | null = null;
  try {
    fd = openSync(path, fsConstants.O_RDONLY);
    const st = fstatSync(fd);
    if (!st.isFile()) {
      return {
        ok: false,
        code: "not_a_file",
        reason: `${label}がファイルではありません`,
      };
    }
    // 上限の判定は stat の値ではなく **実際に読めた量** だけで行う。
    // （stat で先に弾くと、stat 後に伸びたファイルはこの読込ループしか守れないのに、
    //   その守りが試験で試されなくなる — 変異検査で生き残ったので 1 本にした）
    // limit + 1 byte 目を読んだ時点で止めるので、巨大ファイルを読み切ることはない
    const buf = Buffer.allocUnsafe(limit + 1);
    let total = 0;
    for (;;) {
      const n = readSync(fd, buf, total, buf.length - total, null);
      if (n === 0) break;
      total += n;
      if (total > limit) {
        return {
          ok: false,
          code: "too_large",
          reason: `${label}が大きすぎます（上限 ${limit} bytes を超えました）`,
        };
      }
    }
    return { ok: true, bytes: Buffer.from(buf.subarray(0, total)) };
  } catch (e) {
    return ioFailure(e, label);
  } finally {
    if (fd !== null) {
      try {
        closeSync(fd);
      } catch {
        // close の失敗は結果を変えない
      }
    }
  }
}

/**
 * ファイル全体の sha256 とサイズ。**読めた byte 数が fstat のサイズと違えば失敗**
 * （読んでいる最中に差し替えられた／伸びた／縮んだ）。例外は Result に変える。
 * 一度もメモリに載せない。
 */
export function digestFileSafe(
  path: string,
  label = "アーカイブ",
): IoResult<{ bytes: number; sha256: string }> {
  let fd: number | null = null;
  try {
    fd = openSync(path, fsConstants.O_RDONLY);
    const st = fstatSync(fd);
    if (!st.isFile()) {
      return {
        ok: false,
        code: "not_a_file",
        reason: `${label}がファイルではありません`,
      };
    }
    const hash = createHash("sha256");
    const buf = Buffer.allocUnsafe(CHUNK);
    let total = 0;
    for (;;) {
      const n = readSync(fd, buf, 0, CHUNK, null);
      if (n === 0) break;
      hash.update(buf.subarray(0, n));
      total += n;
      if (total > st.size) break; // 伸びている — 下で size_changed になる
    }
    if (total !== st.size) {
      return {
        ok: false,
        code: "size_changed",
        reason: `${label}のサイズが読み取り中に変わりました（${st.size} → ${total} bytes 以上）`,
      };
    }
    return { ok: true, bytes: total, sha256: hash.digest("hex") };
  } catch (e) {
    return ioFailure(e, label);
  } finally {
    if (fd !== null) {
      try {
        closeSync(fd);
      } catch {
        // close の失敗は結果を変えない
      }
    }
  }
}

export interface PrivateWriteOptions {
  /** 実在するプロジェクトルート。realpath に解決してから使う */
  root: string;
  /** root からの相対ディレクトリ列（例: [".agentcms", "local"]）。無ければ 0700 で作る */
  dirSegments: readonly string[];
  fileName: string;
  content: string | Buffer;
  /** 試験専用: tmp 名の乱数部分を固定して「予め置かれた link」を再現する */
  tmpTokenForTest?: string;
}

/**
 * 非公開の小さなファイルを、**リンクを一切辿らず**原子的に書く。
 *
 * 1. root を realpath に解決（symlink 越しの root で作業しない）
 * 2. 各親ディレクトリを lstat: symlink なら拒否、無ければ 0700 で作って再確認
 * 3. 最終保存先を lstat: symlink / 非 regular / hardlink（nlink > 1）なら拒否。無ければ進む
 * 4. tmp は `.<name>.<16 hex 乱数>.tmp` を **O_CREAT|O_EXCL|O_NOFOLLOW** で作る
 *    （在れば失敗、link は開かない）。0600。書き切って fsync
 * 5. 最終保存先を再度 lstat（3 と同じ条件）してから rename
 *
 * どこで失敗しても tmp は消し、既存ファイルには触れない。例外は Result に変える。
 */
export function writePrivateFileAtomically(
  opts: PrivateWriteOptions,
): IoResult<{ path: string }> {
  let root: string;
  try {
    root = realpathSync(opts.root);
  } catch (e) {
    return ioFailure(e, "プロジェクトルート");
  }
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(opts.fileName)) {
    return {
      ok: false,
      code: "io_error",
      reason: "記録ファイル名が不正です",
    };
  }

  // 2. 親ディレクトリ（link を辿らない）
  let dir = root;
  for (const seg of opts.dirSegments) {
    dir = join(dir, seg);
    const st = lstatOrNull(dir);
    if (st === null) {
      try {
        mkdirSync(dir, { mode: 0o700 });
      } catch (e) {
        return ioFailure(e, `ディレクトリ ${seg}`);
      }
    }
    const after = lstatOrNull(dir);
    if (after === null) {
      return {
        ok: false,
        code: "io_error",
        reason: `ディレクトリ ${seg} を作れませんでした`,
      };
    }
    if (after.isSymbolicLink()) {
      return {
        ok: false,
        code: "symlink_refused",
        reason: `${seg} がシンボリックリンクなので記録を書きません`,
      };
    }
    if (!after.isDirectory()) {
      return {
        ok: false,
        code: "not_a_file",
        reason: `${seg} がディレクトリではありません`,
      };
    }
  }

  const finalPath = join(dir, opts.fileName);
  const finalCheck = refuseLinkedTarget(finalPath, opts.fileName);
  if (finalCheck) return finalCheck;

  // 4. tmp を排他的に作る（link は開かない）
  const token = opts.tmpTokenForTest ?? randomBytes(8).toString("hex");
  const tmpPath = join(dir, `.${opts.fileName}.${token}.tmp`);
  let fd: number | null = null;
  // 排他的に作成できた場合だけ、自分の一時ファイルを片付ける。
  let ownsTmp = false;
  try {
    fd = openSync(
      tmpPath,
      fsConstants.O_WRONLY |
        fsConstants.O_CREAT |
        fsConstants.O_EXCL |
        fsConstants.O_NOFOLLOW,
      0o600,
    );
    ownsTmp = true;
    const data =
      typeof opts.content === "string"
        ? Buffer.from(opts.content, "utf8")
        : opts.content;
    let off = 0;
    while (off < data.byteLength) {
      off += writeSync(fd, data, off, data.byteLength - off, null);
    }
    fsyncSync(fd);
    closeSync(fd);
    fd = null;

    // 5. rename 直前に最終保存先をもう一度見る
    const again = refuseLinkedTarget(finalPath, opts.fileName);
    if (again) {
      cleanup(tmpPath);
      return again;
    }
    renameSync(tmpPath, finalPath);
    return { ok: true, path: finalPath };
  } catch (e) {
    if (fd !== null) {
      try {
        closeSync(fd);
      } catch {
        // 続けて tmp を消す
      }
    }
    if (ownsTmp) cleanup(tmpPath);
    const code = (e as { code?: unknown } | null)?.code;
    if (code === "EEXIST" || code === "ELOOP") {
      return {
        ok: false,
        code: "symlink_refused",
        reason:
          "一時ファイルの場所に既に何かが在るため記録を書きません（リンクや残骸の可能性）",
      };
    }
    return ioFailure(e, "記録ファイル");
  }
}

function lstatOrNull(path: string) {
  try {
    return lstatSync(path);
  } catch {
    return null;
  }
}

/** 最終保存先が link なら拒否の Result、無い／regular file なら null */
function refuseLinkedTarget(
  path: string,
  label: string,
): IoResult<never> | null {
  const st = lstatOrNull(path);
  if (st === null) return null;
  if (st.isSymbolicLink()) {
    return {
      ok: false,
      code: "symlink_refused",
      reason: `${label} がシンボリックリンクなので記録を書きません`,
    };
  }
  if (!st.isFile()) {
    return {
      ok: false,
      code: "not_a_file",
      reason: `${label} が通常のファイルではありません`,
    };
  }
  if (st.nlink > 1) {
    return {
      ok: false,
      code: "hardlink_refused",
      reason: `${label} がハードリンクされているので記録を書きません`,
    };
  }
  return null;
}

function cleanup(tmpPath: string): void {
  // 自分が O_EXCL で作ったものだけが在り得る。link を辿らず unlink する
  const st = lstatOrNull(tmpPath);
  if (st === null || st.isSymbolicLink() || !st.isFile()) return;
  try {
    unlinkSync(tmpPath);
  } catch {
    // 片付けられなくても既存ファイルは無事
  }
}

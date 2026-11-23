import {
  createHash,
  createPrivateKey,
  createPublicKey,
  sign as cryptoSign,
  verify as cryptoVerify,
  type KeyObject,
} from "node:crypto";
import { statSync } from "node:fs";
import { z } from "zod";

import { digestFileSafe, type IoFailureCode } from "./safe-io.js";
import { parseSemver } from "./semver.js";

/**
 * 署名付き Release Manifest（配布物の真正性を示す唯一の機械可読な形）。
 *
 * ## 役割の分離
 *
 * | もの | 何を示すか |
 * | --- | --- |
 * | `SHA256SUMS` / `.sha256` | **壊れていない**こと（転送中の破損） |
 * | この manifest + Ed25519 署名 | **発行者が出したもの**であること（真正性） |
 * | 信頼鍵（PEM） | 誰を発行者と認めるか。**配布物の中には入れない**（別経路で受け取る） |
 *
 * ## 検証の順序（`verifySignedManifest`）
 *
 * 1. サイズ上限（manifest / 署名 / 鍵）
 * 2. 信頼鍵が Ed25519 の公開鍵であること
 * 3. **署名を manifest の元 byte に対して検証**（JSON として読む前）
 * 4. 厳密 schema（未知キー拒否）
 *
 * 署名が通る前の manifest の中身（version / artifact 名）は**一切信頼しない**。
 * 失敗時の診断で読むことはあるが、その値で判定を変えない。
 *
 * この module は発行側 `scripts/sign-release.mjs` と CLI `update check` の両方が
 * 使う単一情報源。schema を片方だけ変えると、もう片方が黙ってずれる。
 */

export const MANIFEST_FORMAT = "sitegrowkit-release-manifest";
export const MANIFEST_FORMAT_VERSION = 1;
export const PRODUCT_NAME = "SiteGrowKit";

/** manifest 本体の上限。実物は 1 KB 未満。桁が違うものは読まない */
export const MAX_MANIFEST_BYTES = 64 * 1024;
/** base64 の Ed25519 署名（88 文字）。改行や BOM の余地を見ても 4 KB で十分 */
export const MAX_SIGNATURE_BYTES = 4 * 1024;
/** SPKI PEM の Ed25519 公開鍵は 113 byte。証明書チェーンなどは受け付けない */
export const MAX_PUBLIC_KEY_BYTES = 16 * 1024;
/** Ed25519 署名の生 byte 長 */
export const ED25519_SIGNATURE_BYTES = 64;

const SEMVER_STRING = z
  .string()
  .min(1)
  .max(256)
  .refine((v) => parseSemver(v) !== null, {
    message: "semver 2.0.0 の形式ではありません",
  });

const NON_NEGATIVE_INT = z.number().int().min(0).max(1_000_000);

/**
 * 署名対象の manifest。**未知キーは拒否**する（strictObject）。
 *
 * - URL や発行日時は**発行側が明示したときだけ**入る。捏造しない
 * - `signing_key_fingerprint` は診断用（「別の鍵で署名されている」を早く言うため）。
 *   信頼の根拠にはならない — 根拠は常に署名検証そのもの
 */
export const releaseManifestSchema = z.strictObject({
  format: z.literal(MANIFEST_FORMAT),
  format_version: z.literal(MANIFEST_FORMAT_VERSION),
  product: z.literal(PRODUCT_NAME),
  version: SEMVER_STRING,
  compatibility: z.strictObject({
    template_schema_version: NON_NEGATIVE_INT,
    agent_context_version: NON_NEGATIVE_INT,
  }),
  artifact: z.strictObject({
    /** ファイル名のみ（path 区切りを含まない） */
    name: z
      .string()
      .min(1)
      .max(255)
      .regex(/^[A-Za-z0-9][A-Za-z0-9._-]*$/, "ファイル名として不正です"),
    sha256: z.string().regex(/^[0-9a-f]{64}$/, "sha256 hex ではありません"),
    bytes: z.number().int().min(1).max(Number.MAX_SAFE_INTEGER),
  }),
  signing_key_fingerprint: z
    .string()
    .regex(/^sha256:[0-9a-f]{64}$/, "鍵指紋の形式が不正です"),
  /** 発行側が `--released-at` で明示したときだけ存在する */
  released_at: z.iso.datetime({ offset: true }).optional(),
});

export type ReleaseManifest = z.infer<typeof releaseManifestSchema>;

/** 公開鍵の指紋（SPKI DER の sha256）。秘密ではないので表示してよい */
export function publicKeyFingerprint(key: KeyObject): string {
  const der = key.export({ type: "spki", format: "der" });
  return `sha256:${createHash("sha256").update(der).digest("hex")}`;
}

export type KeyLoadResult =
  | { ok: true; key: KeyObject; fingerprint: string }
  | { ok: false; reason: string };

/** 信頼鍵（PEM）を読む。Ed25519 の公開鍵以外は拒否する */
export function loadTrustedPublicKey(pem: Buffer | string): KeyLoadResult {
  const buf = typeof pem === "string" ? Buffer.from(pem, "utf8") : pem;
  if (buf.byteLength > MAX_PUBLIC_KEY_BYTES) {
    return {
      ok: false,
      reason: `公開鍵ファイルが大きすぎます（上限 ${MAX_PUBLIC_KEY_BYTES} bytes）`,
    };
  }
  const text = buf.toString("utf8");
  if (/PRIVATE KEY-----/.test(text)) {
    // 秘密鍵を渡されても中身は読まない・表示しない
    return {
      ok: false,
      reason:
        "秘密鍵が渡されました。--public-key には発行者の**公開**鍵（PEM）を指定してください",
    };
  }
  let key: KeyObject;
  try {
    key = createPublicKey({ key: text, format: "pem" });
  } catch {
    return { ok: false, reason: "PEM 形式の公開鍵として読めません" };
  }
  if (key.type !== "public" || key.asymmetricKeyType !== "ed25519") {
    return {
      ok: false,
      reason: `Ed25519 の公開鍵ではありません（検出: ${key.asymmetricKeyType ?? "不明"}）`,
    };
  }
  return { ok: true, key, fingerprint: publicKeyFingerprint(key) };
}

export type PrivateKeyLoadResult =
  | { ok: true; key: KeyObject; publicKey: KeyObject; fingerprint: string }
  | { ok: false; reason: string };

/** 署名鍵（PEM / PKCS#8）を読む。Ed25519 以外は拒否。中身は呼び出し側も表示しない */
export function loadSigningPrivateKey(
  pem: Buffer | string,
): PrivateKeyLoadResult {
  let key: KeyObject;
  try {
    key = createPrivateKey({ key: pem, format: "pem" });
  } catch {
    return { ok: false, reason: "PEM 形式の秘密鍵として読めません" };
  }
  if (key.type !== "private" || key.asymmetricKeyType !== "ed25519") {
    return {
      ok: false,
      reason: `Ed25519 の秘密鍵ではありません（検出: ${key.asymmetricKeyType ?? "不明"}）`,
    };
  }
  const publicKey = createPublicKey(key);
  return {
    ok: true,
    key,
    publicKey,
    fingerprint: publicKeyFingerprint(publicKey),
  };
}

/** 署名文字列（base64 テキスト）→ 生 64 byte。形式が違えば null */
export function decodeSignature(raw: Buffer | string): Buffer | null {
  const buf = typeof raw === "string" ? Buffer.from(raw, "utf8") : raw;
  if (buf.byteLength === 0 || buf.byteLength > MAX_SIGNATURE_BYTES) return null;
  const text = buf
    .toString("utf8")
    .replace(/^\uFEFF/, "")
    .trim();
  if (!/^[A-Za-z0-9+/]+={0,2}$/.test(text)) return null;
  const sig = Buffer.from(text, "base64");
  if (sig.byteLength !== ED25519_SIGNATURE_BYTES) return null;
  // 正規化して同じ文字列に戻ることを要求する（余分な bit を持つ base64 を弾く）
  if (sig.toString("base64") !== text) return null;
  return sig;
}

/** manifest の元 byte に対する Ed25519 detached 署名（base64 テキスト、末尾改行） */
export function signManifestBytes(
  bytes: Buffer,
  privateKey: KeyObject,
): string {
  return `${cryptoSign(null, bytes, privateKey).toString("base64")}\n`;
}

export function verifyManifestBytes(
  bytes: Buffer,
  signature: Buffer,
  publicKey: KeyObject,
): boolean {
  try {
    return cryptoVerify(null, bytes, publicKey, signature);
  } catch {
    return false;
  }
}

/** 発行側が書く manifest の byte 列（この byte 列に署名する） */
export function encodeManifest(manifest: ReleaseManifest): Buffer {
  return Buffer.from(`${JSON.stringify(manifest, null, 2)}\n`, "utf8");
}

export type ParseResult =
  { ok: true; manifest: ReleaseManifest } | { ok: false; reason: string };

/** 厳密 schema で読む。署名検証 **後** に呼ぶこと */
export function parseManifest(bytes: Buffer): ParseResult {
  let json: unknown;
  try {
    json = JSON.parse(bytes.toString("utf8"));
  } catch {
    return { ok: false, reason: "JSON として読めません" };
  }
  const parsed = releaseManifestSchema.safeParse(json);
  if (!parsed.success) {
    const first = parsed.error.issues[0];
    const where = first?.path.length ? first.path.join(".") : "(root)";
    return {
      ok: false,
      reason: `schema 不一致: ${where}: ${first?.message ?? "不明"}`,
    };
  }
  return { ok: true, manifest: parsed.data };
}

export type SignatureFailure =
  | "manifest_too_large"
  | "manifest_empty"
  | "signature_malformed"
  | "public_key_invalid"
  | "signature_invalid"
  | "schema_invalid";

export type VerifySignedManifestResult =
  | {
      ok: true;
      manifest: ReleaseManifest;
      trustedKeyFingerprint: string;
    }
  | {
      ok: false;
      code: SignatureFailure;
      reason: string;
      /** 署名不一致のとき、manifest が別の鍵指紋を名乗っていれば入る（診断のみ） */
      hint?: string;
    };

/**
 * 署名 → schema の順で検証する。**署名が通る前に JSON を信頼しない。**
 * 署名が通らないときに限り、診断のために manifest を読み「別の鍵で署名されたようだ」
 * という hint を出すことがある。hint は判定を変えない。
 */
export function verifySignedManifest(input: {
  manifestBytes: Buffer;
  signatureRaw: Buffer;
  publicKeyPem: Buffer;
}): VerifySignedManifestResult {
  if (input.manifestBytes.byteLength === 0) {
    return { ok: false, code: "manifest_empty", reason: "manifest が空です" };
  }
  if (input.manifestBytes.byteLength > MAX_MANIFEST_BYTES) {
    return {
      ok: false,
      code: "manifest_too_large",
      reason: `manifest が大きすぎます（${input.manifestBytes.byteLength} bytes > 上限 ${MAX_MANIFEST_BYTES}）`,
    };
  }
  const key = loadTrustedPublicKey(input.publicKeyPem);
  if (!key.ok) {
    return { ok: false, code: "public_key_invalid", reason: key.reason };
  }
  const sig = decodeSignature(input.signatureRaw);
  if (!sig) {
    return {
      ok: false,
      code: "signature_malformed",
      reason: `署名ファイルが Ed25519 detached 署名（base64、${ED25519_SIGNATURE_BYTES} bytes）ではありません`,
    };
  }
  if (!verifyManifestBytes(input.manifestBytes, sig, key.key)) {
    let hint: string | undefined;
    const peek = parseManifest(input.manifestBytes);
    if (peek.ok && peek.manifest.signing_key_fingerprint !== key.fingerprint) {
      hint =
        "manifest が名乗る鍵指紋は指定した信頼鍵と一致しません。別の鍵で署名された配布物か、信頼鍵の取り違えの可能性があります（この文言は未検証の manifest を読んだ診断であり、信頼の根拠ではありません）";
    }
    return {
      ok: false,
      code: "signature_invalid",
      reason:
        "署名が一致しません（manifest が改変されている、または指定した信頼鍵で署名されていない）",
      hint,
    };
  }
  const parsed = parseManifest(input.manifestBytes);
  if (!parsed.ok) {
    return { ok: false, code: "schema_invalid", reason: parsed.reason };
  }
  return {
    ok: true,
    manifest: parsed.manifest,
    trustedKeyFingerprint: key.fingerprint,
  };
}

export interface ArchiveDigest {
  bytes: number;
  sha256: string;
}

export type DigestResult =
  | { ok: true; bytes: number; sha256: string }
  | { ok: false; code: IoFailureCode; reason: string };

/**
 * アーカイブのサイズと sha256（メモリに載せない）。
 * 読取失敗・読取中のサイズ変化は **例外ではなく失敗 Result** で返す。
 */
export function digestFile(path: string): DigestResult {
  return digestFileSafe(path, "アーカイブ");
}

export type ArchiveCheck =
  | { status: "verified"; bytes: number; sha256: string }
  | {
      status: "mismatch";
      reason: string;
      expected: ArchiveDigest;
      actual: ArchiveDigest | { bytes: number };
    }
  | { status: "unreadable"; code: IoFailureCode; reason: string };

/**
 * 署名済み manifest の記述と、手元のアーカイブ実体を照合する。
 * サイズが違えば hash を計算せずに不一致（大きなファイルを読み切らない）。
 * 読めない・読取中に変わったものは `unreadable`（成功にはしない）。
 */
export function checkArchiveAgainstManifest(
  archivePath: string,
  manifest: ReleaseManifest,
): ArchiveCheck {
  const expected = {
    bytes: manifest.artifact.bytes,
    sha256: manifest.artifact.sha256,
  };
  let size: number;
  try {
    const st = statSync(archivePath);
    if (!st.isFile()) {
      return {
        status: "unreadable",
        code: "not_a_file",
        reason: "アーカイブがファイルではありません",
      };
    }
    size = st.size;
  } catch (e) {
    const code = (e as { code?: unknown } | null)?.code;
    return {
      status: "unreadable",
      code: code === "ENOENT" ? "not_found" : "io_error",
      reason:
        code === "ENOENT"
          ? "アーカイブが見つかりません"
          : "アーカイブを読めません",
    };
  }
  if (size !== expected.bytes) {
    return {
      status: "mismatch",
      reason: `サイズが一致しません（manifest: ${expected.bytes} bytes / 実体: ${size} bytes）`,
      expected,
      actual: { bytes: size },
    };
  }
  const actual = digestFile(archivePath);
  if (!actual.ok) {
    return { status: "unreadable", code: actual.code, reason: actual.reason };
  }
  if (actual.bytes !== expected.bytes) {
    return {
      status: "mismatch",
      reason: `サイズが一致しません（manifest: ${expected.bytes} bytes / 読めた量: ${actual.bytes} bytes）`,
      expected,
      actual: { bytes: actual.bytes },
    };
  }
  if (actual.sha256 !== expected.sha256) {
    return {
      status: "mismatch",
      reason:
        "sha256 が一致しません（アーカイブが manifest の記述と異なります）",
      expected,
      actual: { bytes: actual.bytes, sha256: actual.sha256 },
    };
  }
  return { status: "verified", bytes: actual.bytes, sha256: actual.sha256 };
}

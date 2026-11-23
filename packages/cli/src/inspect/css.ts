import { existsSync, readFileSync } from "node:fs";
import { dirname, join, normalize, sep } from "node:path";

import type { CssAnalysis, CssFileAnalysis } from "./manifest.js";

/**
 * CSS の決定論的解析（spec §11）。
 * Purge は行わない — 「使われていないように見える」ことを理由に CSS を削除しない。
 */
export function analyzeCss(sourceDir: string, cssFiles: string[]): CssAnalysis {
  return { files: cssFiles.map((f) => analyzeCssFile(sourceDir, f)) };
}

function analyzeCssFile(sourceDir: string, path: string): CssFileAnalysis {
  const text = readFileSync(join(sourceDir, path), "utf8");

  const custom_properties = unique(
    matchAll(text, /--([a-z0-9_-]+)\s*:/gi).map((m) => `--${m[1]}`),
  );

  const media_queries = unique(
    matchAll(text, /@media\s*([^{]+)\{/g).map((m) =>
      (m[1] ?? "").trim().replace(/\s+/g, " "),
    ),
  );

  const font_faces = matchAll(text, /@font-face\s*\{([^}]*)\}/g).map((m) => {
    const block = m[1] ?? "";
    const family = /font-family\s*:\s*['"]?([^;'"]+)/.exec(block)?.[1] ?? null;
    const src = unique(
      matchAll(block, /url\(\s*['"]?([^'")]+?)['"]?\s*\)/g).map(
        (u) => u[1] ?? "",
      ),
    );
    return { family: family?.trim() ?? null, src };
  });

  const imports = unique(
    matchAll(text, /@import\s+(?:url\(\s*)?['"]?([^'")\s;]+)/g).map(
      (m) => m[1] ?? "",
    ),
  );

  const url_refs = unique(
    matchAll(text, /url\(\s*['"]?([^'")]+?)['"]?\s*\)/g).map((m) => m[1] ?? ""),
  )
    .filter((ref) => !ref.startsWith("data:") && !/^(https?:)?\/\//.test(ref))
    .map((ref) => {
      const resolved = resolveRelative(path, ref);
      return {
        ref,
        resolved,
        exists: resolved !== null && existsSync(join(sourceDir, resolved)),
      };
    });

  return {
    path,
    lines: text.split("\n").length,
    bytes: Buffer.byteLength(text),
    custom_properties,
    media_queries,
    font_faces,
    imports,
    url_refs,
  };
}

/** CSS ファイルからの相対参照を source ルート相対へ解決する */
export function resolveRelative(fromFile: string, ref: string): string | null {
  const clean = ref.split("?")[0]?.split("#")[0] ?? ref;
  const resolved = normalize(join(dirname(fromFile), clean))
    .split(sep)
    .join("/");
  return resolved.startsWith("..") ? null : resolved;
}

function matchAll(text: string, re: RegExp): RegExpExecArray[] {
  return [...text.matchAll(re)];
}

function unique(values: string[]): string[] {
  return [...new Set(values)].sort();
}

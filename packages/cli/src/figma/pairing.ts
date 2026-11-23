/**
 * Desktop/Mobile pairing 候補の generic signal 判定（Phase 6.1 実測の一般化）。
 * 名前一致で pairing できない実案件（例: Desktop frame と機種名 Mobile frame）向けに、
 * 特定名称へ依存しない signal で候補を提示する。
 * 自動確定はしない — 候補は REVIEW のまま Human-approved pairing に使う。
 */
import type { FigmaNode } from "./api.js";
import type { PairingCandidate } from "./manifest.js";
import { collectImageRefs, collectTexts } from "./normalize-util.js";
import type { ScreenFrame } from "./screens.js";

/** テキスト一致率がこの値以上のときのみ候補にする（弱い一致を候補として提示しない） */
const MIN_TEXT_OVERLAP = 0.4;

export interface PairingSignature {
  texts: Set<string>;
  imageRefs: Set<string>;
}

export function buildPairingSignature(node: FigmaNode): PairingSignature {
  const texts = new Set(
    collectTexts(node)
      .map((t) => (t.characters ?? "").trim())
      .filter((t) => t.length >= 4),
  );
  return { texts, imageRefs: new Set(collectImageRefs(node)) };
}

function overlapRatio(a: Set<string>, b: Set<string>): number {
  if (a.size === 0 || b.size === 0) return 0;
  let shared = 0;
  for (const v of a) if (b.has(v)) shared++;
  return shared / Math.min(a.size, b.size);
}

/**
 * 単独 breakpoint しか持たない screen 同士の pairing 候補を返す。
 * signal（すべて generic — 特定 frame 名へ依存しない）:
 * - viewport-class: 片方が desktop 幅・もう片方が mobile 幅
 * - shared-text: テキスト内容の一致率
 * - shared-assets: image fill 参照の共有
 */
export function findPairingCandidates(
  self: ScreenFrame,
  selfScreenId: string,
  others: { screenId: string; frame: ScreenFrame }[],
): PairingCandidate[] {
  const selfSig = buildPairingSignature(self.node);
  const out: PairingCandidate[] = [];
  for (const other of others) {
    if (other.screenId === selfScreenId) continue;
    // viewport class が補完関係（desktop ↔ mobile）のときのみ候補
    const pairable =
      (self.breakpoint === "desktop" && other.frame.breakpoint === "mobile") ||
      (self.breakpoint === "mobile" && other.frame.breakpoint === "desktop");
    if (!pairable) continue;
    const otherSig = buildPairingSignature(other.frame.node);
    const textOverlap = overlapRatio(selfSig.texts, otherSig.texts);
    if (textOverlap < MIN_TEXT_OVERLAP) continue;
    const signals = ["viewport-class", "shared-text"];
    if (overlapRatio(selfSig.imageRefs, otherSig.imageRefs) > 0) {
      signals.push("shared-assets");
    }
    out.push({
      screen_id: other.screenId,
      signals,
      text_overlap: Math.round(textOverlap * 100) / 100,
    });
  }
  return out.sort(
    (a, b) =>
      b.text_overlap - a.text_overlap || a.screen_id.localeCompare(b.screen_id),
  );
}

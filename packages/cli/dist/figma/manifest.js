/**
 * Design Manifest v1（SPEC §20–23、ADR-0011 §5）。
 * Raw Figma JSON を AI が読まずに Web 実装を判断できる、決定論的で compact な表現。
 * - Raw の座標 / transform / bookkeeping は持ち込まない
 * - 同一 Figma version + node selection → バイト同一出力（snapshot test で担保）
 */
export {};

/**
 * Figma API 境界（ADR-0011 §2、SPEC §85）。
 * CLI ロジックはこの interface 越しにのみ Figma へ触れる。
 * 本番は HttpFigmaApi、テスト / offline は FixtureFigmaApi が実装する。
 * Phase 4 の RemoteExecutor / XserverApi と同型の Provider Adapter。
 */
export {};

import { composeUserArgs } from "./runtime-user.js";

/**
 * フロントエンド tooling（pnpm）の実行場所（Phase 8.5-A）。
 *
 * 利用者のホストに Node.js / pnpm を要求しないため、pnpm は原則
 * docker compose の `node` service（ワンショット）で実行する。
 * kit 開発・CI など Docker を使わない環境向けに host 実行へフォールバックできる。
 */

export type NodeRuntimeKind = "docker" | "host";

export interface NodeCommand {
  cmd: string;
  args: string[];
  kind: NodeRuntimeKind;
}

/**
 * `pnpm <args>` を実行するためのコマンドを組み立てる。
 *
 * docker 経路では引数配列をそのまま渡す（shell 文字列へ連結しない）。
 * `--rm` によりワンショットのコンテナは実行後に必ず破棄され、orphan を残さない。
 */
export function pnpmCommand(
  root: string,
  args: string[],
  options: { kind?: NodeRuntimeKind; env?: Record<string, string> } = {},
): NodeCommand {
  const kind = options.kind ?? "docker";
  if (kind === "host") {
    return { cmd: "pnpm", args, kind };
  }

  const envArgs = Object.entries(options.env ?? {}).flatMap(([key, value]) => [
    "-e",
    `${key}=${value}`,
  ]);

  return {
    cmd: "docker",
    args: [
      "compose",
      "--profile",
      "tools",
      "run",
      "--rm",
      "-T",
      ...composeUserArgs(root),
      ...envArgs,
      "node",
      "pnpm",
      ...args,
    ],
    kind,
  };
}

/** node service のイメージを事前に用意する（初回の build を進捗表示の中に収めるため） */
export function nodeImageBuildCommand(): { cmd: string; args: string[] } {
  return {
    cmd: "docker",
    args: ["compose", "--profile", "tools", "build", "node"],
  };
}

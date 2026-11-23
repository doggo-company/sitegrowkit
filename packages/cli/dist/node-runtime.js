import { composeUserArgs } from "./runtime-user.js";
/**
 * `pnpm <args>` を実行するためのコマンドを組み立てる。
 *
 * docker 経路では引数配列をそのまま渡す（shell 文字列へ連結しない）。
 * `--rm` によりワンショットのコンテナは実行後に必ず破棄され、orphan を残さない。
 */
export function pnpmCommand(root, args, options = {}) {
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
export function nodeImageBuildCommand() {
    return {
        cmd: "docker",
        args: ["compose", "--profile", "tools", "build", "node"],
    };
}

import { existsSync, mkdirSync, readdirSync, rmSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { pnpmCommand } from "../node-runtime.js";
import { runCapture, runInherit } from "../run.js";
import { copyProjectTree, scanForSecrets, writeReleaseManifest, } from "./artifact.js";
export function buildRoot(projectRoot) {
    return join(projectRoot, ".agentcms", "deploy", "build");
}
export function newReleaseId(now = new Date()) {
    const p = (n) => String(n).padStart(2, "0");
    return (`${now.getFullYear()}${p(now.getMonth() + 1)}${p(now.getDate())}` +
        `${p(now.getHours())}${p(now.getMinutes())}${p(now.getSeconds())}`);
}
/** artifact を組み立てる。戻り値の artifactPath を deploy がアップロードする */
export async function buildArtifact(projectRoot, releaseId) {
    // 1. production アセットビルド（vite）
    // Phase 8.5-A: ホストの Node/pnpm を要求しないため node コンテナで実行する。
    // 生成物（public/build）は従来と同一のため deploy artifact の内容は変わらない。
    const viteCmd = pnpmCommand(projectRoot, ["run", "build"]);
    const vite = await runInherit(viteCmd.cmd, viteCmd.args, projectRoot);
    if (vite !== 0) {
        return {
            ok: false,
            error: {
                what: "vite build が失敗しました",
                why: `exit code ${vite}`,
                fix: "build ログのエラーを修正してください（`pnpm build`）",
            },
        };
    }
    // 2. allow-list コピー（vendor はコピーせず、次段で production 依存のみ install する）
    const dest = join(buildRoot(projectRoot), releaseId);
    copyProjectTree(projectRoot, dest, { skipTopLevel: ["vendor"] });
    mkdirSync(join(dest, "vendor"), { recursive: true });
    // 3. composer install --no-dev（実行系と同条件の Docker PHP で行う）
    const workingDir = `/var/www/html/${relative(projectRoot, dest).split("\\").join("/")}`;
    // Docker Desktop (macOS) のファイル共有同期遅延で稀に展開が ENOENT になるため 1 回だけ再試行する
    const composerArgs = [
        "compose",
        "exec",
        "-T",
        "app",
        "composer",
        "install",
        "--no-dev",
        "--optimize-autoloader",
        "--no-interaction",
        "--no-progress",
        `--working-dir=${workingDir}`,
    ];
    let composer = await runInherit("docker", composerArgs, projectRoot);
    if (composer !== 0) {
        rmSync(join(dest, "vendor"), { recursive: true, force: true });
        mkdirSync(join(dest, "vendor"), { recursive: true });
        await new Promise((res) => setTimeout(res, 3000));
        composer = await runInherit("docker", composerArgs, projectRoot);
    }
    if (composer !== 0) {
        return {
            ok: false,
            error: {
                what: "composer install --no-dev が失敗しました（再試行後）",
                why: `exit code ${composer}（Docker コンテナ内で実行）`,
                fix: "`agentcms dev` でコンテナを起動してから再実行してください",
            },
        };
    }
    // 4. 秘密情報の最終検査（見つかったら即中止）
    const secrets = scanForSecrets(dest);
    if (secrets.length > 0) {
        return {
            ok: false,
            error: {
                what: "artifact に秘密情報らしきファイルが含まれています",
                why: secrets.slice(0, 10).join(", "),
                fix: "該当ファイルを削除し、除外ルール（deploy/artifact.ts）の見直しを検討してください",
            },
        };
    }
    // 5. release メタデータ + tar.gz（symlink は実体化 -h）
    const git = runCapture("git", ["rev-parse", "HEAD"], projectRoot);
    writeReleaseManifest(dest, {
        releaseId,
        gitCommit: git.code === 0 ? git.stdout : "unknown",
        createdAt: new Date().toISOString(),
    });
    const artifactPath = join(buildRoot(projectRoot), `${releaseId}.tar.gz`);
    const tar = runCapture("tar", ["-czhf", artifactPath, "-C", dest, "."]);
    if (tar.code !== 0) {
        return {
            ok: false,
            error: {
                what: "artifact の tar 作成が失敗しました",
                why: tar.stderr,
                fix: "ディスク容量と tar コマンドの有無を確認してください",
            },
        };
    }
    const stats = countTree(dest);
    return { ok: true, artifactPath, stats };
}
function countTree(dir) {
    let fileCount = 0;
    let sizeBytes = 0;
    const walk = (d) => {
        for (const entry of readdirSync(d, { withFileTypes: true })) {
            const abs = join(d, entry.name);
            if (entry.isDirectory())
                walk(abs);
            else if (entry.isFile()) {
                fileCount++;
                sizeBytes += statSync(abs).size;
            }
        }
    };
    walk(dir);
    return { fileCount, sizeBytes };
}
/** 古い build / artifact の掃除（直近 2 世代だけ残す） */
export function cleanupBuilds(projectRoot, keep = 2) {
    const root = buildRoot(projectRoot);
    if (!existsSync(root))
        return;
    const ids = [
        ...new Set(readdirSync(root)
            .filter((e) => /^\d{14}/.test(e))
            .map((e) => e.slice(0, 14))),
    ]
        .sort()
        .reverse();
    for (const id of ids.slice(keep)) {
        rmSync(join(root, id), { recursive: true, force: true });
        rmSync(join(root, `${id}.tar.gz`), { force: true });
    }
}

import { accessSync, constants, existsSync, readFileSync, rmSync, statfsSync, writeFileSync, } from "node:fs";
import { randomUUID } from "node:crypto";
import { join } from "node:path";
import { readEnvValue } from "../credentials.js";
import { emit, step } from "../engine.js";
import { composerState } from "../php-deps.js";
import { isPortFree, PORT_SPECS } from "../ports.js";
import { findProjectRoot } from "../project.js";
import { commandExists, runCapture } from "../run.js";
import { composeUserArgs, containerWriteProbeScript, isDrvfsPath, isWsl, } from "../runtime-user.js";
import { fail, heading, line, ok, warn } from "../ui.js";
import { kitVersion } from "../version.js";
export async function doctor(ctx, options = {}) {
    heading(`agentcms doctor — 環境診断 (SiteGrowKit v${kitVersion()})`);
    step(ctx, "doctor.started");
    const scope = options.scope ?? "all";
    const projectRoot = scope === "environment" ? null : (ctx?.root ?? findProjectRoot());
    const baseDir = options.baseDir ?? projectRoot ?? process.cwd();
    const dockerReady = commandExists("docker") &&
        runCapture("docker", ["info", "--format", "{{.ServerVersion}}"]).code === 0;
    const checks = [
        {
            id: "os.supported",
            name: "OS / アーキテクチャ",
            run: () => osCheck(),
        },
        {
            // project 外の doctor（README_FIRST の最初のコマンド）でも DrvFS を早期警告し、
            // create の前に Linux filesystem へ誘導する（Windows RC2 acceptance feedback）
            id: "filesystem.suitable",
            name: "作業 filesystem",
            run: () => filesystemLocationCheck(baseDir, isWsl()),
        },
        {
            id: "node.version",
            name: "Node.js 20+",
            run: () => {
                const major = Number(process.versions.node.split(".")[0]);
                return major >= 20
                    ? pass("Node.js 20+", `v${process.versions.node}`)
                    : failResult("Node.js 20+", {
                        what: `Node.js が古すぎます（v${process.versions.node}）`,
                        why: "CLI と Vite は Node.js 20 以上を必要とします",
                        fix: "https://nodejs.org/ から LTS をインストールするか、`nvm install --lts` を実行してください",
                    });
            },
        },
        {
            id: "npm.installed",
            name: "npm",
            run: () => commandExists("npm")
                ? pass("npm")
                : warnResult("npm", {
                    what: "npm が見つかりません",
                    why: "SiteGrowKit の導入（npm install -g）に npm を使用します",
                    fix: "Node.js を公式インストーラで入れ直すと npm も導入されます",
                }),
        },
        {
            id: "npm.global_writable",
            name: "グローバル導入先の書き込み",
            run: () => globalInstallCheck(),
        },
        {
            id: "pnpm.installed",
            name: "pnpm",
            run: () => pnpmCheck(projectRoot),
        },
        {
            // Git は利用者の必須依存ではない（Phase 8.5-C.1 / O-12）。
            // secret scan は Git 非依存で動作し、create の git init は無ければ skip される。
            id: "git.installed",
            name: "Git",
            run: () => commandExists("git")
                ? pass("Git", "バージョン管理に使用します")
                : notRequired("Git", "セットアップには不要です（バージョン管理を使う場合のみ導入してください）"),
        },
        {
            id: "docker.installed",
            name: "Docker CLI",
            run: () => commandExists("docker")
                ? pass("Docker CLI")
                : failResult("Docker CLI", {
                    what: "Docker が見つかりません",
                    why: "ローカル実行環境（php / mysql / mailpit）は Docker で動きます",
                    fix: "Docker Desktop をインストールしてください（macOS / Windows+WSL2）: https://www.docker.com/products/docker-desktop/",
                }),
        },
        {
            id: "docker.running",
            name: "Docker daemon",
            run: () => !commandExists("docker")
                ? notRequired("Docker daemon", "Docker CLI が無いため判定不能")
                : runCapture("docker", ["info", "--format", "{{.ServerVersion}}"])
                    .code === 0
                    ? pass("Docker daemon")
                    : failResult("Docker daemon", {
                        what: "Docker daemon に接続できません",
                        why: "Docker Desktop が起動していないか、WSL2 統合が無効です",
                        fix: "Docker Desktop を起動してください。Windows の場合は Settings > Resources > WSL Integration を有効にしてください",
                    }),
        },
        {
            id: "docker.compose",
            name: "Docker Compose v2",
            run: () => !commandExists("docker")
                ? notRequired("Docker Compose v2", "Docker CLI が無いため判定不能")
                : runCapture("docker", ["compose", "version"]).code === 0
                    ? pass("Docker Compose v2")
                    : failResult("Docker Compose v2", {
                        what: "Docker Compose v2 が見つかりません",
                        why: "`docker compose` サブコマンドが利用できません（古い docker-compose v1 のみの環境）",
                        fix: "Docker Desktop を最新版へ更新してください",
                    }),
        },
        {
            id: "php.host",
            name: "PHP (host)",
            run: () => {
                const v = runCapture("php", ["-v"]);
                const version = /PHP (\d+\.\d+\.\d+)/.exec(v.stdout)?.[1];
                // supported minimum: PHP >= 8.3 < 9（Decision 4）。Docker 実行時の既定 runtime は 8.5
                return notRequired("PHP (host)", version
                    ? `PHP はコンテナ内で実行されます（host 検出: ${version} — verify の host fallback に利用可能）`
                    : "PHP はコンテナ内で実行されます（host には不要）");
            },
        },
        {
            id: "composer.host",
            name: "Composer (host)",
            run: () => notRequired("Composer (host)", "composer install はコンテナ内で実行されます（host には不要）"),
        },
        {
            id: "disk.available",
            name: "空き容量",
            run: () => diskCheck(baseDir),
        },
        {
            id: "filesystem.writable",
            name: "ファイルシステム書き込み",
            run: () => {
                // 固定名は既存ファイルやリンクを壊すため、検査専用の名前を排他的に作る。
                const probe = join(baseDir, `.agentcms-doctor-probe-${randomUUID()}`);
                try {
                    writeFileSync(probe, "probe", { encoding: "utf8", flag: "wx", mode: 0o600 });
                    rmSync(probe);
                    return pass("ファイルシステム書き込み");
                }
                catch {
                    return failResult("ファイルシステム書き込み", {
                        what: "カレントディレクトリへ書き込みできません",
                        why: "プロジェクトの生成・実行にはこの場所への書き込み権限が必要です",
                        fix: "権限のあるディレクトリ（例: ホームディレクトリ配下）で実行してください。WSL2 では Linux ファイルシステム側（~/ 配下）を推奨します",
                    });
                }
            },
        },
    ];
    if (projectRoot) {
        checks.push(...projectChecks(projectRoot, dockerReady));
    }
    else if (scope === "all") {
        checks.push({
            id: "project.state",
            name: "CMS プロジェクト",
            run: () => warnResult("CMS プロジェクト", {
                what: "CMS プロジェクトの外で実行しています",
                why: "カレントディレクトリ（の上位）に artisan + docker-compose.yml がありません",
                fix: "環境チェックだけならこのままで問題ありません。`agentcms create <dir>` で新規作成できます",
            }),
        });
    }
    let warns = 0;
    let fails = 0;
    for (const c of checks) {
        const r = await c.run();
        // GUI（Setup Wizard）向け structured check event（Phase 8.5-B: F-A3）。
        // 表示名ではなく安定 ID で分岐できるようにする
        emit(ctx, {
            type: "check",
            id: c.id,
            name: r.name,
            status: r.status === "notrequired" ? "not-required" : r.status,
            detail: r.detail,
            cause: r.failure?.why,
            fix: r.failure?.fix,
            title: r.failure?.title ?? r.failure?.what,
            message: r.failure?.message,
        });
        if (r.status === "pass") {
            ok(r.detail ? `${r.name} — ${r.detail}` : r.name);
        }
        else if (r.status === "notrequired") {
            line(`− ${r.name} — NOT REQUIRED${r.detail ? `（${r.detail}）` : ""}`);
        }
        else if (r.status === "warn" && r.failure) {
            warns++;
            warn(`${r.name}: ${r.failure.what}`);
            line(`    原因: ${r.failure.why}`);
            line(`    対応: ${r.failure.fix}`);
        }
        else if (r.failure) {
            fails++;
            fail(r.failure);
        }
    }
    console.log("");
    if (fails === 0 && warns === 0) {
        ok("すべての診断に合格しました。`agentcms create <dir>`（または `agentcms dev`）で開始できます");
    }
    else if (fails > 0) {
        fail({
            what: `${fails} 件の要修正があります`,
            why: "上記 ✖ の項目が Golden Path（create → dev → verify）を妨げます",
            fix: "各項目の「修正」に従って解決後、もう一度 `agentcms doctor` を実行してください",
        });
    }
    else {
        warn(`${warns} 件の注意があります（上記参照）。致命的な問題はありません`);
    }
    step(ctx, "doctor.completed");
    return fails > 0 ? 1 : 0;
}
/**
 * 空き容量の検査（Phase 8.5-B）。
 * Docker イメージ + vendor + node_modules で数 GB を使うため、
 * セットアップ前に不足を知らせる（診断のみ・システムは変更しない）。
 */
export function diskCheck(dir) {
    const name = "空き容量";
    const free = freeBytes(dir);
    if (free === null) {
        return notRequired(name, "この環境では空き容量を判定できません");
    }
    const gb = free / 1024 ** 3;
    const human = `${gb.toFixed(1)} GB`;
    if (gb >= 10)
        return pass(name, `空き ${human}`);
    if (gb >= 5) {
        return warnResult(name, {
            code: "disk.low",
            what: `空き容量が少なめです（${human}）`,
            why: "Docker イメージと依存パッケージで数 GB を使用します",
            fix: "不要なファイルを削除するか、別のディスクに空きを作ってください",
            title: "保存容量が少なくなっています",
            message: `空き容量は ${human} です。不要なファイルを整理することをおすすめします。`,
        });
    }
    return failResult(name, {
        code: "disk.insufficient",
        what: `空き容量が不足しています（${human}）`,
        why: "Docker イメージと依存パッケージの取得に十分な空きがありません",
        fix: "不要なファイルを削除して 10 GB 以上の空きを確保してから、もう一度確認してください",
        title: "保存容量が足りません",
        message: `空き容量が ${human} しかありません。10 GB 以上の空きを作ってから、もう一度確認してください。`,
        recovery: [{ kind: "recheck", label: "もう一度確認" }],
    });
}
function freeBytes(dir) {
    try {
        // statfs は Node 18.15+ / 全対応 OS で利用可能。失敗する環境では判定をスキップする
        const st = statfsSync(dir);
        return Number(st.bavail) * Number(st.bsize);
    }
    catch {
        return null;
    }
}
/**
 * pnpm はホストに **不要**（Phase 8.5-A: 依存インストールと build は node コンテナで実行）。
 * ホストに存在する場合のみ、HMR（`pnpm dev`）用途として version pin との整合を参考表示する。
 */
function pnpmCheck(projectRoot) {
    if (!commandExists("pnpm")) {
        return {
            name: "pnpm",
            status: "notrequired",
            detail: "フロントエンドの依存インストールと build はコンテナ内で実行されます（host には不要）",
        };
    }
    const pinned = readPinnedPnpm(projectRoot);
    if (!pinned)
        return pass("pnpm", "host にあり（HMR 用・必須ではありません）");
    // project 内で実行すると corepack が pin を解決するため、cwd を projectRoot にして計測する
    const v = runCapture("pnpm", ["--version"], projectRoot ?? undefined).stdout;
    if (!v)
        return pass("pnpm", `pin: ${pinned}（必須ではありません）`);
    if (v === pinned)
        return pass("pnpm", `v${v}（pin と一致・必須ではありません）`);
    // build はコンテナ内の pin された pnpm で行うため、host の差異は致命的ではない
    return pass("pnpm", `host v${v} / project pin ${pinned}（build はコンテナ内の pin された pnpm で実行します）`);
}
function readPinnedPnpm(projectRoot) {
    if (!projectRoot)
        return null;
    try {
        const pkg = JSON.parse(readFileSync(join(projectRoot, "package.json"), "utf8"));
        const m = /^pnpm@(.+)$/.exec(pkg.packageManager ?? "");
        return m?.[1] ?? null;
    }
    catch {
        return null;
    }
}
function projectChecks(projectRoot, dockerReady) {
    const env = existsSync(join(projectRoot, ".env"))
        ? readFileSync(join(projectRoot, ".env"), "utf8")
        : null;
    const checks = [
        {
            id: "project.container_writable",
            name: "Container 書き込み (storage / bootstrap/cache)",
            run: () => containerWritableCheck(projectRoot, dockerReady),
        },
        {
            id: "project.env",
            name: ".env",
            run: () => env !== null
                ? pass(".env")
                : warnResult(".env", {
                    what: ".env がありません",
                    why: "Laravel は .env から設定を読みます（Git には含まれません）",
                    fix: "`agentcms dev` が .env.example から自動生成します（手動: cp .env.example .env）",
                }),
        },
        {
            id: "project.php_deps",
            name: "PHP 依存 (vendor/)",
            run: () => phpDepsCheck(projectRoot),
        },
        {
            id: "project.js_deps",
            name: "JS 依存 (node_modules/)",
            run: () => existsSync(join(projectRoot, "node_modules"))
                ? pass("JS 依存 (node_modules/)")
                : warnResult("JS 依存 (node_modules/)", {
                    what: "node_modules/ がありません",
                    why: "pnpm install が未実行です",
                    fix: "`agentcms dev` が自動インストールします",
                }),
        },
        {
            id: "project.frontend_build",
            name: "フロントエンド build",
            run: () => existsSync(join(projectRoot, "public/build/manifest.json"))
                ? pass("フロントエンド build")
                : warnResult("フロントエンド build", {
                    what: "public/build がありません（管理画面の表示に必要）",
                    why: "pnpm build が未実行です",
                    fix: "`agentcms dev` が自動 build します",
                }),
        },
        {
            id: "project.git",
            name: "Git リポジトリ",
            run: () => existsSync(join(projectRoot, ".git"))
                ? pass("Git リポジトリ")
                : warnResult("Git リポジトリ", {
                    what: "このプロジェクトは Git リポジトリではありません",
                    why: "`agentcms verify` の secret scan は Git 管理対象ファイルを走査します",
                    fix: "`git init && git add -A` を実行してください",
                }),
        },
        {
            id: "project.agent_context",
            name: "AI Agent Context",
            run: () => existsSync(join(projectRoot, ".agentcms/architecture.md")) &&
                existsSync(join(projectRoot, "CLAUDE.md"))
                ? pass("AI Agent Context (.agentcms + CLAUDE.md/AGENTS.md)")
                : warnResult("AI Agent Context", {
                    what: "AI Agent Context が不完全です",
                    why: ".agentcms/ の context または CLAUDE.md / AGENTS.md がありません",
                    fix: "`agentcms sync-agents` を実行してください",
                }),
        },
    ];
    for (const spec of PORT_SPECS) {
        const port = Number((env && readEnvValue(env, spec.key)) ?? spec.preferred);
        checks.push({
            id: `ports.available.${spec.key.toLowerCase()}`,
            name: `ポート ${port} (${spec.name})`,
            run: async () => (await isPortFree(port))
                ? pass(`ポート ${port} (${spec.name})`)
                : warnResult(`ポート ${port} (${spec.name})`, {
                    what: `ポート ${port}（${spec.name}）が使用中です`,
                    why: dockerReady
                        ? "このプロジェクトのコンテナが既に起動中なら正常です。別プロセスの場合は衝突します"
                        : "他のプロセスが同じポートを使用しています",
                    fix: `別プロセスの場合は停止するか、.env の ${spec.key} を変更してください。確認: macOS は \`lsof -i :${port}\` / WSL2・Linux は \`ss -ltnp | grep :${port}\``,
                }),
        });
    }
    // 書き込み検査は storage/ に対しても行う（runtime の実書き込み先）
    checks.push({
        id: "project.storage_writable",
        name: "storage/ 書き込み",
        run: () => {
            try {
                accessSync(join(projectRoot, "storage"), constants.W_OK);
                return pass("storage/ 書き込み");
            }
            catch {
                return failResult("storage/ 書き込み", {
                    what: "storage/ へ書き込みできません",
                    why: "Laravel はログ・キャッシュ・アップロードを storage/ に書き込みます",
                    fix: "ディレクトリの所有者・権限を確認してください（WSL2 では Linux ファイルシステム側を推奨）",
                });
            }
        },
    });
    return checks;
}
/**
 * 作業場所（project root または cwd）の filesystem 検査。
 * WSL2 で /mnt/<drive>/（DrvFS）配下なら、project 作成前でも警告して
 * Linux filesystem へ誘導する。/mnt/wsl 等は DrvFS ではないため警告しない。
 */
/**
 * `npm install -g` の行き先へ、**実際に書けるか**（Windows/WSL2 一周 2026-09-04）。
 *
 * ## なぜ要るのか
 *
 * `docs/WINDOWS_WSL2.md` は Node を nodesource + apt で入れる案内をしている。
 * すると Node は `/usr/bin/node` の system 側に入り、`npm install -g` の行き先は
 * `/usr/lib/node_modules` になる。ところが `README_FIRST.md` §4 は
 * **sudo 無しの `npm install -g`** を指示している。
 *
 * 🔴 **手順どおりにやると必ず転ぶ。** WIN2 の WSL2 で実測した:
 *
 * ```
 * npm error The operation was rejected by your operating system.
 * npm error It is likely you do not have the permissions to access this file
 * ```
 *
 * macOS では Homebrew / volta / nvm でユーザー領域へ入ることが多いので踏みにくく、
 * **Windows/WSL2 でだけ表に出ていた**。
 *
 * ## 見に行くだけにしない
 *
 * 権限は `access()` で聞くだけでは足りない（root_squash・読み取り専用マウント・
 * ACL のいずれでも、聞いた答えと書いた結果が食い違う）。
 * **書いて、消す。** 既存の `filesystem.writable` と同じ形である。
 *
 * ## この判定が届く相手
 *
 * `doctor` は `agentcms` のサブコマンドなので、**install-g が転んだ人は
 * そもそもこれを実行できない**。だから `README_FIRST.md` §4 の直前に
 * 「うまくいかないときは先にこれを回す」と `npx` の 1 行を置いてある。
 * **判定と案内は 2 つで 1 組**で、どちらか片方では届かない。
 */
export function globalInstallCheck(prefixOverride) {
    const name = "グローバル導入先の書き込み";
    if (prefixOverride === undefined && !commandExists("npm")) {
        // npm が無いことは 1 つ上の判定が言う。ここで二重に言わない
        return { name, status: "notrequired", detail: "npm がないため未検査" };
    }
    /*
     * 導入先は既定では npm に聞く。**試験は渡して呼ぶ**
     * （`filesystemLocationCheck(dir, isWsl)` と同じ形。
     *   実際に走っている環境の npm 設定に、判定の緑赤を左右させない）。
     */
    const prefix = prefixOverride !== undefined
        ? { code: 0, stdout: prefixOverride }
        : runCapture("npm", ["config", "get", "prefix"]);
    if (prefix.code !== 0 || prefix.stdout === "") {
        return warnResult(name, {
            what: "npm の導入先を取得できませんでした",
            why: "`npm install -g` がどこへ書くのか分からないため、書けるかを確かめられません",
            fix: "`npm config get prefix` が値を返すか確認してください",
        });
    }
    /*
     * 行き先は `<prefix>/lib/node_modules`（POSIX）。まだ無ければ親をたどる —
     * `npm config set prefix ~/.npm-global` を設定した直後は、まだ作られていない。
     * **無いことは失敗ではない。作れるかどうかが知りたい。**
     */
    const candidates = process.platform === "win32"
        ? [join(prefix.stdout, "node_modules"), prefix.stdout]
        : [
            join(prefix.stdout, "lib", "node_modules"),
            join(prefix.stdout, "lib"),
            prefix.stdout,
        ];
    const target = candidates.find((dir) => existsSync(dir));
    if (target === undefined) {
        return warnResult(name, {
            what: `導入先が見つかりません（${prefix.stdout}）`,
            why: "`npm install -g` の行き先が存在しないため、書けるかを確かめられません",
            fix: "`npm config get prefix` の値が正しいか確認してください",
        });
    }
    // 診断が既存ファイルを上書きしたり、シンボリックリンクを辿ったりしない。
    const probe = join(target, `.agentcms-doctor-probe-${randomUUID()}`);
    try {
        writeFileSync(probe, "probe", { encoding: "utf8", flag: "wx", mode: 0o600 });
        rmSync(probe);
        return pass(name, target);
    }
    catch {
        return failResult(name, {
            what: `グローバル導入先へ書き込みできません（${target}）`,
            why: "`npm install -g agent-cms-kit-*.tgz` が権限エラーで失敗します（README_FIRST §4）",
            fix: [
                "導入先をユーザー領域へ移してください（sudo は不要になります）:",
                "  npm config set prefix ~/.npm-global",
                '  echo \'export PATH="$HOME/.npm-global/bin:$PATH"\' >> ~/.bashrc',
                "  source ~/.bashrc",
            ].join("\n"),
        });
    }
}
export function filesystemLocationCheck(dir, wsl) {
    const name = "作業 filesystem";
    if (wsl && isDrvfsPath(dir)) {
        return warnResult(name, {
            what: "Windows mounted filesystem（/mnt/*）上で作業しています",
            why: "Docker bind mount の性能が大きく低下し、Linux permission 0600 が保証されず認証情報ファイルが広い権限になる可能性があります",
            fix: "~/projects/ など WSL2 の Linux filesystem 上でプロジェクトを作成・展開してください",
        });
    }
    return pass(name);
}
/**
 * PHP 依存（composer install）の完了検査（Phase 8 Stage A RC1 Blocker）。
 * completion condition は vendor/ の存在ではなく vendor/autoload.php。
 * partial vendor/（autoload 欠落）を PASS にしない。
 */
export function phpDepsCheck(projectRoot) {
    const name = "PHP 依存 (vendor/)";
    const state = composerState(projectRoot);
    if (state === "complete")
        return pass(name);
    if (state === "incomplete") {
        return warnResult(name, {
            what: "Composer 依存のインストールが不完全です（vendor/ はあるが vendor/autoload.php がありません）",
            why: "前回の composer install が途中で失敗した可能性があります",
            fix: "`agentcms dev` を再実行してください（不完全な vendor/ を自動で検出し、作り直して composer install を再実行します）",
        });
    }
    return warnResult(name, {
        what: "vendor/ がありません",
        why: "composer install が未実行です",
        fix: "`agentcms dev` がコンテナ内で自動インストールします",
    });
}
/**
 * container 側からの storage / bootstrap/cache 書き込み検査（Windows RC1 Blocker 2）。
 * host が書けても container 実行ユーザーが書けなければ実行時に HTTP 500 になるため、
 * host 検査だけで PASS にしない。コンテナ未起動時は NOT REQUIRED として案内する。
 */
function containerWritableCheck(projectRoot, dockerReady) {
    const name = "Container 書き込み (storage / bootstrap/cache)";
    if (!dockerReady) {
        return notRequired(name, "Docker が利用できないため未検査");
    }
    const running = runCapture("docker", ["compose", "ps", "--status=running", "--quiet"], projectRoot);
    if (running.code !== 0 || running.stdout.trim() === "") {
        return notRequired(name, "コンテナ未起動のため未検査（`agentcms dev` 後に再実行すると検査されます）");
    }
    const probe = runCapture("docker", [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(projectRoot),
        "app",
        "sh",
        "-c",
        containerWriteProbeScript(),
    ], projectRoot);
    if (probe.code === 0)
        return pass(name);
    return failResult(name, {
        what: "コンテナ実行ユーザーがプロジェクトへ書き込めません",
        why: `host のファイル所有者（UID/GID）と container 実行ユーザーが一致していません: ${probe.stdout || probe.stderr}`,
        fix: "`agentcms dev` を再実行してください（HOST_UID/HOST_GID でイメージを再ビルドします）。chmod 777 / sudo chown での回避はしないでください",
    });
}
function osCheck() {
    const platform = process.platform;
    const arch = process.arch;
    if (platform === "darwin")
        return pass("OS / アーキテクチャ", `macOS (${arch})`);
    if (platform === "linux") {
        const wsl = isWsl();
        return pass("OS / アーキテクチャ", wsl
            ? `Linux / WSL2 (${arch})`
            : `Linux (${arch}) — 参考: サポート対象は macOS / Windows 11 + WSL2`);
    }
    if (platform === "win32") {
        return failResult("OS / アーキテクチャ", {
            what: "Windows ネイティブ環境で実行されています",
            why: "サポート対象は WSL2 内での実行です（ネイティブ PowerShell / cmd は未サポート）",
            fix: "WSL2（Ubuntu 等）をインストールし、WSL2 のターミナル内で実行してください。詳細: docs/WINDOWS_WSL2.md",
        });
    }
    return failResult("OS / アーキテクチャ", {
        what: `未検証のプラットフォームです（${platform}）`,
        why: "サポート対象は macOS / Windows 11 + WSL2 です",
        fix: "サポート対象の環境で実行してください",
    });
}
const pass = (name, detail) => ({
    name,
    status: "pass",
    detail,
});
const notRequired = (name, detail) => ({
    name,
    status: "notrequired",
    detail,
});
const warnResult = (name, failure) => ({
    name,
    status: "warn",
    failure,
});
const failResult = (name, failure) => ({
    name,
    status: "fail",
    failure,
});

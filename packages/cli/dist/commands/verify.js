import { existsSync, readFileSync, rmSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { requireProject } from "./dev.js";
import { emit, step } from "../engine.js";
import { pnpmCommand } from "../node-runtime.js";
import { runCapture, runInherit, runInheritCapture } from "../run.js";
import { composeUserArgs } from "../runtime-user.js";
import { fail, heading, info, ok } from "../ui.js";
import { composeProjectMatches, resolveVerifyRuntime, } from "../verify-runtime.js";
/**
 * pest に書かせる JUnit の置き場（project からの相対）。
 *
 * `storage/framework/testing/` は Laravel が test の作業物のために持っている場所で、
 * そこの `.gitignore` が `*` を無視するので**commit に紛れ込まない**。
 */
const JUNIT_RELATIVE = "storage/framework/testing/agentcms-verify-junit.xml";
/**
 * JUnit XML から**落ちたテストの名前**を拾う。
 *
 * ## 人向けの出力を読まない
 *
 * pest の画面出力は版や printer で変わる。**JUnit は PHPUnit 自身が書く**ので、
 * 見た目が変わっても形が変わらない。読む先を機械向けの方に置く。
 *
 * ## 自己終了しない `<testcase>` だけが落ちている
 *
 * 通ったものは `<testcase … />` で閉じる。落ちたものは中に `<failure>` か
 * `<error>` を持つ。`<testcase` で切って、その塊が持っているかを見る。
 */
export function parseFailedTests(xml, cap = 10) {
    const names = [];
    for (const chunk of xml.split("<testcase").slice(1)) {
        if (!/<(failure|error)\b/.test(chunk))
            continue;
        const name = /\bname="([^"]*)"/.exec(chunk)?.[1];
        if (!name)
            continue;
        const suite = /\bclassname="([^"]*)"/.exec(chunk)?.[1];
        // classname は `Tests.Feature.Foo` の形で来る。読む人が探せる形へ戻す
        names.push(suite ? `${suite.replace(/\./g, "\\")}::${name}` : name);
    }
    if (names.length <= cap)
        return names;
    return [...names.slice(0, cap), `… 他 ${names.length - cap} 件`];
}
/** 失敗したテストの一覧を 1 行にする。読めなければ `null`（従来どおりログを見てもらう） */
function failedTestsLine(path) {
    if (!existsSync(path))
        return null;
    try {
        const names = parseFailedTests(readFileSync(path, "utf8"));
        return names.length === 0 ? null : `落ちたテスト: ${names.join(" / ")}`;
    }
    catch {
        // 読めないこと自体でゲートの報告を壊さない
        return null;
    }
}
/**
 * 品質ゲート一括実行。「AI ができたと言った」ではなく機械的検証を完了条件にする。
 * 全ゲートを実行してから結果をまとめて報告する（最初の失敗で止めない）。
 * PHP ゲートは Docker 起動時は Docker、未起動時は互換 host runtime で実行する
 * （Verify Runtime Capability — Phase 6.1 Gap 7。どちらも不可なら WHAT/WHY/FIX で FAIL）。
 */
export async function verify(ctx, options) {
    const root = requireProject(ctx?.root);
    heading("agentcms verify — 品質ゲート");
    step(ctx, "verify.started");
    const runtime = probeRuntime(root);
    if (runtime.kind === "unavailable") {
        fail({ what: runtime.what, why: runtime.why, fix: runtime.fix });
        return 1;
    }
    const php = (args) => runtime.kind === "docker"
        ? {
            cmd: "docker",
            args: [
                "compose",
                "exec",
                "-T",
                ...composeUserArgs(root),
                "app",
                ...args,
            ],
        }
        : { cmd: args[0], args: args.slice(1) };
    info(runtime.kind === "docker"
        ? "PHP runtime: Docker (docker compose exec app)"
        : `PHP runtime: host — ${runtime.note}`);
    // フロントエンド gate も Docker 側で実行する（ホストの Node/pnpm を要求しない — Phase 8.5-A）。
    // Docker が使えない環境（kit 開発 / CI）ではホストの pnpm にフォールバックする。
    const nodeKind = runtime.kind === "docker" ? "docker" : "host";
    const pnpmGate = (args, env) => {
        const c = pnpmCommand(root, args, { kind: nodeKind, env });
        return { cmd: c.cmd, args: c.args };
    };
    info(nodeKind === "docker"
        ? "Node runtime: Docker (docker compose run --rm node)"
        : "Node runtime: host — pnpm");
    // wayfinder TS artifacts（gitignored 生成物）が無いと vue-tsc / vite build が失敗する。
    // Product Contract: host PHP は NOT REQUIRED — 生成は runtime（Docker or 互換 host）側で行う
    if (!existsSync(join(root, "resources/js/wayfinder"))) {
        info("wayfinder TS artifacts を生成します（runtime PHP）");
        const wf = php(["php", "artisan", "wayfinder:generate", "--with-form"]);
        const wfCode = await runInherit(wf.cmd, wf.args, root);
        if (wfCode !== 0) {
            fail({
                what: "wayfinder:generate に失敗しました",
                why: "TypeScript ゲートに必要な生成物（resources/js/{actions,routes,wayfinder}）を用意できませんでした",
                fix: "`agentcms dev` を一度実行してから verify を再実行してください",
            });
            return 1;
        }
    }
    const gates = [
        {
            name: "Pint (format)",
            ...php(["./vendor/bin/pint", "--test"]),
            hint: "`./vendor/bin/pint` で自動整形できます",
        },
        {
            name: "PHPStan",
            ...php([
                "./vendor/bin/phpstan",
                "analyse",
                "--no-progress",
                "--memory-limit=1G",
            ]),
            hint: "型エラーを修正してください。ベースライン追加は最終手段です",
        },
        {
            name: "Pest (tests)",
            /*
             * **`php artisan test` ではなく pest を直接呼ぶ。**
             * artisan は pest を子プロセスで起動するので `-d memory_limit` が届かない。
             * 既定の php.ini（多くの環境で 128M）のままだと、テストが増えるほど
             * **失敗ではなく fatal error** で落ちる。テストが壊れたように見えて、
             * 実際は上限に当たっただけ、という誤診を避ける。
             */
            ...php([
                "php",
                "-d",
                "memory_limit=1G",
                "./vendor/bin/pest",
                "--compact",
                /*
                 * **相対で渡す。** PHP ゲートは Docker の中で走ることがあり、
                 * host の絶対 path は向こうから見えない。project の中なら
                 * bind mount の両側で同じ場所を指す。
                 */
                "--log-junit",
                JUNIT_RELATIVE,
            ]),
            detail: () => failedTestsLine(join(root, JUNIT_RELATIVE)),
            hint: "失敗テストの原因を調査してください（テストを弱めて通すのは禁止）",
        },
        {
            // 読み取り専用。DB を書き換えず、taxonomy-sync も呼ばない（SY-18）
            name: "Taxonomy definitions",
            ...php(["php", "artisan", "agentcms:taxonomy-check"]),
            hint: "content/taxonomies/ の定義を直すか、`php artisan agentcms:taxonomy-sync` で同期してください（この検査は何も変更しません）",
        },
        {
            name: "TypeScript (vue-tsc)",
            ...pnpmGate(["run", "types:check"]),
            hint: "型エラーを修正してください",
        },
        {
            name: "ESLint",
            ...pnpmGate(["run", "lint:check"]),
            hint: "`pnpm run lint` で自動修正できます",
        },
        {
            /*
             * 画面を開かずに決まること（履歴の上限・積み方など）。
             * **開かないと分からないことは Playwright の側**で、同じことを 2 か所で見ない。
             */
            name: "Unit tests (vitest)",
            ...pnpmGate(["run", "test:unit"]),
            hint: "失敗テストの原因を調査してください（テストを弱めて通すのは禁止）",
        },
        {
            name: "Production build (vite)",
            // host php の有無に依らず同一挙動にする（wayfinder は生成済み artifacts を使用）
            ...pnpmGate(["run", "build"], { AGENTCMS_SKIP_WAYFINDER: "1" }),
            hint: "build ログのエラーを確認してください",
        },
        {
            name: "Secret scan",
            // 同梱 runtime でも動くよう、PATH の node ではなく
            // **今このコードを実行している Node 自身**を使う（Phase 8.5-D / D2）
            cmd: process.execPath,
            args: [fileURLToPath(new URL("../secret-scan.js", import.meta.url))],
            hint: "上の Secret scan 出力の修正手順に従ってください（Git の有無に関わらず実行できます。実際に秘密が検出された場合はプロジェクト外へ移し、鍵をローテーション）",
        },
        {
            name: "composer audit",
            ...php(["composer", "audit", "--locked"]),
            hint: "`composer update <pkg>` で脆弱性のある依存を更新してください",
            warnOnly: options?.auditWarnOnly ?? false,
        },
        {
            name: "pnpm audit (prod)",
            ...pnpmGate(["audit", "--prod"]),
            hint: "`pnpm update <pkg>` で脆弱性のある依存を更新してください",
            warnOnly: options?.auditWarnOnly ?? false,
        },
    ];
    /*
     * **前回の残骸を先に消す。** 残っていると、pest が書く前に落ちた回に
     * 前回の失敗名を出してしまう（嘘の方が無情報より悪い）。
     */
    rmSync(join(root, JUNIT_RELATIVE), { force: true });
    const results = [];
    for (const gate of gates) {
        info(`▶ ${gate.name}`);
        // GUI から呼ばれた場合、ゲートの出力は詳細ログへ流し中断も効かせる
        const r = await runInheritCapture(gate.cmd, gate.args, root, {
            env: gate.env,
            signal: ctx?.signal,
            onStdout: (line) => emit(ctx, { type: "log", stream: "stdout", line }),
            onStderr: (line) => emit(ctx, { type: "log", stream: "stderr", line }),
        });
        results.push({ gate, code: r.code, signal: r.signal });
    }
    console.log("");
    heading("結果");
    let failed = 0;
    let warned = 0;
    for (const { gate, code, signal } of results) {
        if (code === 0) {
            ok(gate.name);
            continue;
        }
        // warnOnly gate（setup wizard 中の audit）: 失敗しても blocking しない
        if (gate.warnOnly) {
            warned++;
            emit(ctx, {
                type: "message",
                level: "warn",
                text: `⚠ ${gate.name}: 脆弱性が見つかりました。${gate.hint}`,
            });
            fail({
                what: `${gate.name} で脆弱性を検出（警告のみ・サイトは起動済み）`,
                why: `exit code ${code}（上のログ参照）`,
                fix: gate.hint,
            });
            continue;
        }
        failed++;
        /*
         * **「落ちた」と「殺された」を分ける**（B-3）。
         *
         * OOM killer（SIGKILL）や segfault（SIGSEGV）で死ぬと**出力が 0 バイト**になり、
         * 以前は exit code も 1 に潰れていたので**普通のテスト失敗と同じ顔**をしていた。
         * 「実行中と見分けが付かない」と言われていたのはこれである。
         */
        if (signal) {
            fail({
                what: `${gate.name} が **強制終了**されました（signal ${signal}）`,
                why: signal === "SIGKILL"
                    ? "メモリ不足で OS に落とされた可能性が高いです（出力が途中で切れます）"
                    : "プロセスが異常終了しました（テストの失敗ではありません）",
                fix: signal === "SIGKILL"
                    ? "他の重い処理（隔離スタック・ブラウザ）を止めてから、もう一度実行してください"
                    : "同じゲートを単体で実行し、再現するか確かめてください",
            });
            continue;
        }
        const detail = gate.detail?.();
        fail({
            what: `${gate.name} が失敗`,
            why: detail
                ? `exit code ${code} — ${detail}`
                : `exit code ${code}（上のログ参照）`,
            fix: gate.hint,
        });
    }
    console.log("");
    if (failed === 0 && warned === 0)
        ok("全ゲート通過");
    else if (failed === 0 && warned > 0)
        ok(`全ブロッキングゲート通過（脆弱性警告 ${warned} 件あり）`);
    else
        fail({
            what: `${failed} / ${gates.length} ゲートが失敗しました`,
            why: "上記の各項目を参照してください",
            fix: "修正後にもう一度 `agentcms verify` を実行してください",
        });
    step(ctx, "verify.completed");
    return failed === 0 ? 0 : 1;
}
/**
 * 稼働中コンテナのうち「現在の project に属するもの」の判定材料を集める。
 * compose の project 名が固定でも、別ディレクトリの project の container を
 * 誤帰属しない（identity は compose label の working_dir で確認 — Phase 6.1 実測の一般化）。
 */
function probeDockerIdentity(root) {
    try {
        const ids = runCapture("docker", ["compose", "ps", "--status=running", "--quiet"], root)
            .stdout.split("\n")
            .map((s) => s.trim())
            .filter(Boolean);
        if (ids.length === 0)
            return { ownContainers: false, unrelatedContainers: false };
        const dirs = ids.map((id) => {
            try {
                return runCapture("docker", [
                    "inspect",
                    "--format",
                    '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}',
                    id,
                ], root).stdout.trim();
            }
            catch {
                return null;
            }
        });
        const own = composeProjectMatches(root, dirs);
        return { ownContainers: own, unrelatedContainers: !own };
    }
    catch {
        return { ownContainers: false, unrelatedContainers: false };
    }
}
/** 実行環境を実測して runtime を決める（判定ロジック本体は verify-runtime.ts — unit テスト対象） */
function probeRuntime(root) {
    const docker = probeDockerIdentity(root);
    let hostPhpVersion = null;
    try {
        const out = runCapture("php", ["-v"], root).stdout;
        hostPhpVersion = /PHP (\d+\.\d+\.\d+)/.exec(out)?.[1] ?? null;
    }
    catch {
        hostPhpVersion = null;
    }
    let requiredPhp = null;
    try {
        const composer = JSON.parse(readFileSync(join(root, "composer.json"), "utf8"));
        requiredPhp = composer.require?.["php"] ?? null;
    }
    catch {
        requiredPhp = null;
    }
    return resolveVerifyRuntime({
        dockerRunning: docker.ownContainers,
        unrelatedDockerRunning: docker.unrelatedContainers,
        hostPhpVersion,
        vendorPresent: existsSync(join(root, "vendor/bin/pint")),
        requiredPhp,
    });
}
/** E2E・Lighthouse は起動済み環境が前提のため verify とは別コマンドにしている */
export async function verifyContainersRunning(root) {
    return probeDockerIdentity(root).ownContainers;
}

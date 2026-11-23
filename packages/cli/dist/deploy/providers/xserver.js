import { shq } from "../executor.js";
import { ENTRY_STUB_MARKER } from "../release.js";
import { certificateExpiryCheck, probeCertificateExpiry } from "../tls.js";
import { extractNames, findStatusFor } from "./xserver-api.js";
import { inspectMailEnv } from "./xserver-setup.js";
/**
 * XServer Provider Adapter。
 * XServer 固有の事実（公式マニュアル確認済み）をここへ閉じ込める:
 * - SSH: ポート 10022 / 公開鍵認証のみ
 * - PHP CLI: /usr/bin/php8.x（素の `php` は旧版が起動するため使わない）
 * - Node.js: 非サポート（build はローカルで行い artifact を転送する）
 * - docroot: /home/<ID>/<domain>/public_html 固定
 * - Cron: サーバーパネルから登録（SSH crontab は公式未保証 → 自動変更しない）
 * 出典・詳細: docs/deployment/xserver.md / ADR-0008
 */
/** composer.json の require.php（^8.3）を満たす公式 CLI パス。新しい順 */
export const PHP_BIN_CANDIDATES = [
    "/usr/bin/php8.5",
    "/usr/bin/php8.4",
    "/usr/bin/php8.3",
];
/** Laravel 13 + 本キットの実行に必要な PHP 拡張（php -m で検査） */
export const REQUIRED_PHP_EXTENSIONS = [
    "ctype",
    "curl",
    "dom",
    "fileinfo",
    "gd", // Media 画像最適化
    "mbstring",
    "openssl",
    "pdo_mysql",
    "tokenizer",
    "xml",
    "zip",
];
/** バージョン付き公式パスから PHP CLI を検出する（doctor / deploy setup で共用） */
export async function detectPhpBin(exec, cfg) {
    const candidates = cfg.remote.phpBin !== "auto" ? [cfg.remote.phpBin] : PHP_BIN_CANDIDATES;
    for (const bin of candidates) {
        const r = await exec.exec(`${shq(bin)} -v 2>/dev/null | head -1`);
        const m = /PHP (\d+\.\d+\.\d+)/.exec(r.stdout);
        if (r.code === 0 && m) {
            return { phpBin: bin, phpVersion: m[1], candidates };
        }
    }
    return { candidates };
}
/**
 * リモート環境を診断する。破壊的変更なし（読み取りコマンドのみ）。
 * api が渡された場合は Control Plane（XServer API）でも SSL / DB / Cron を検査する（ADR-0009）。
 */
export async function xserverDoctor(exec, cfg, api = null) {
    const checks = [];
    // 1. SSH 到達性
    const ssh = await exec.exec("echo agentcms-ssh-ok");
    const sshOk = ssh.code === 0 && ssh.stdout.includes("agentcms-ssh-ok");
    checks.push(sshOk
        ? { key: "ssh", name: `SSH 接続 (${exec.label()})`, status: "PASS" }
        : {
            key: "ssh",
            name: "SSH 接続",
            status: "FAIL",
            what: `SSH で ${exec.label()} に接続できません`,
            why: ssh.stderr || `exit code ${ssh.code}`,
            fix: "XServer サーバーパネル > SSH設定 を ON にし、公開鍵を登録してください（ポート 10022 / 公開鍵認証のみ）。国外 IP からはアクセス制限される場合があります。docs/deployment/xserver.md 参照",
        });
    if (!sshOk) {
        // SSH 不通なら以降の検査は不能。FAIL のみ返す
        return {
            capabilities: {
                checks,
                symlinkOk: false,
                currentRelease: null,
            },
        };
    }
    // 2. PHP CLI（バージョン付き公式パスから検出。設定で明示されていればそれを使う）
    const detected = await detectPhpBin(exec, cfg);
    const phpBin = detected.phpBin;
    const phpVersion = detected.phpVersion;
    const candidates = detected.candidates;
    checks.push(phpBin
        ? {
            key: "php",
            name: `PHP CLI (${phpBin} = ${phpVersion})`,
            status: "PASS",
            detected: phpBin,
        }
        : {
            key: "php",
            name: "PHP CLI",
            status: "FAIL",
            what: "PHP 8.3 以上の CLI が見つかりません",
            why: `${candidates.join(", ")} のいずれも実行できませんでした（XServer では素の \`php\` は旧版のため使用しません）`,
            fix: "サーバーパネル > PHP Ver.切替 で対象ドメインを PHP 8.3 以上にし、/usr/bin/php8.x の存在を確認してください。設定 remote.phpBin にパスを明示することもできます",
        });
    // 3. PHP 拡張
    if (phpBin) {
        const r = await exec.exec(`${shq(phpBin)} -m`);
        const loaded = new Set(r.stdout.split("\n").map((l) => l.trim().toLowerCase()));
        const missing = REQUIRED_PHP_EXTENSIONS.filter((e) => !loaded.has(e.toLowerCase()));
        checks.push(missing.length === 0
            ? { key: "php_ext", name: "PHP 拡張（必須すべて）", status: "PASS" }
            : {
                key: "php_ext",
                name: "PHP 拡張",
                status: "FAIL",
                what: `必須 PHP 拡張が不足: ${missing.join(", ")}`,
                why: "Laravel 13 と Media 画像処理の実行に必要です",
                fix: "サーバーパネル > php.ini設定 で拡張を有効化してください。有効化できない場合は XServer サポートへ確認してください",
            });
    }
    // 4. Composer（参考情報。artifact に vendor を同梱するため必須ではない）
    const composer = await exec.exec("composer --version 2>/dev/null || ~/bin/composer --version 2>/dev/null");
    const composerOk = composer.code === 0 && composer.stdout.includes("Composer");
    checks.push({
        key: "composer",
        name: composerOk
            ? `Composer（${composer.stdout.split("\n")[0]}）`
            : "Composer",
        status: composerOk ? "PASS" : "WARN",
        what: composerOk ? undefined : "Composer が見つかりません",
        why: composerOk
            ? undefined
            : "XServer では Composer のプリインストールは保証されていません",
        fix: composerOk
            ? undefined
            : "本キットは vendor/ を artifact に同梱するため必須ではありません（WARN のまま進められます）",
    });
    // 5. MySQL 接続（shared/.env があれば実接続を artisan で検査。無ければ mysql CLI の有無のみ）
    const sharedEnv = `${cfg.remote.appDir}/shared/.env`;
    const envExists = (await exec.exec(`test -f ${shq(sharedEnv)} && echo yes || echo no`))
        .stdout === "yes";
    if (envExists && phpBin) {
        const current = `${cfg.remote.appDir}/current`;
        const hasCurrent = (await exec.exec(`test -L ${shq(current)} && echo yes || echo no`))
            .stdout === "yes";
        if (hasCurrent) {
            const r = await exec.exec(`cd ${shq(current)} && ${shq(phpBin)} artisan db:show --json >/dev/null 2>&1 && echo db-ok || echo db-fail`);
            checks.push(r.stdout.includes("db-ok")
                ? {
                    key: "mysql",
                    name: "MySQL 接続（shared/.env 経由）",
                    status: "PASS",
                }
                : {
                    key: "mysql",
                    name: "MySQL 接続",
                    status: "FAIL",
                    what: "デプロイ済みアプリから MySQL へ接続できません",
                    why: "shared/.env の DB_* 設定が誤っているか、DB が未作成です",
                    fix: "サーバーパネル > MySQL設定 で DB・ユーザーを作成し、shared/.env の DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_HOST を確認してください（現行サーバーは DB_HOST=localhost）",
                });
        }
        else {
            checks.push({
                key: "mysql",
                name: "MySQL 接続",
                status: "WARN",
                what: "未デプロイのため実接続は未検査です",
                why: "接続検査はデプロイ済みアプリの artisan 経由で行います",
                fix: "初回デプロイ後に `agentcms deploy doctor` を再実行すると実接続を検査します",
            });
        }
    }
    else {
        checks.push({
            key: "mysql",
            name: "MySQL 接続",
            status: "WARN",
            what: "shared/.env が未配置のため DB 接続は未検査です",
            why: `${sharedEnv} がありません`,
            fix: "docs/deployment/xserver.md の手順で shared/.env を作成してください（DB はサーバーパネルで作成）",
        });
    }
    // 6. ディレクトリ書き込み・パーミッション
    const appDirW = await exec.exec(`mkdir -p ${shq(cfg.remote.appDir)} 2>/dev/null && test -w ${shq(cfg.remote.appDir)} && echo yes || echo no`);
    checks.push(appDirW.stdout === "yes"
        ? {
            key: "app_dir",
            name: `配置先書き込み (${cfg.remote.appDir})`,
            status: "PASS",
        }
        : {
            key: "app_dir",
            name: "配置先書き込み",
            status: "FAIL",
            what: `${cfg.remote.appDir} に書き込めません`,
            why: "デプロイにはこのディレクトリへの書き込み権限が必要です",
            fix: "remote.appDir をホームディレクトリ配下（例: /home/<ID>/agentcms/staging）に設定してください",
        });
    const docrootW = await exec.exec(`test -d ${shq(cfg.remote.docroot)} && test -w ${shq(cfg.remote.docroot)} && echo yes || echo no`);
    checks.push(docrootW.stdout === "yes"
        ? {
            key: "docroot",
            name: `docroot 書き込み (${cfg.remote.docroot})`,
            status: "PASS",
        }
        : {
            key: "docroot",
            name: "docroot 書き込み",
            status: "FAIL",
            what: `${cfg.remote.docroot} が存在しないか書き込めません`,
            why: "公開ファイル（index.php スタブ・アセット）の配置に必要です",
            fix: "XServer の docroot は /home/<ID>/<ドメイン>/public_html 固定です。対象ドメインをサーバーパネルで追加済みか確認してください",
        });
    // 7. symlink 作成可否（appDir 内で自己完結のテスト。docroot には触れない）
    const sym = await exec.exec(`cd ${shq(cfg.remote.appDir)} && rm -f .agentcms-symlink-test && ln -s . .agentcms-symlink-test && test -L .agentcms-symlink-test && rm -f .agentcms-symlink-test && echo yes || echo no`);
    const symlinkOk = sym.stdout === "yes";
    checks.push(symlinkOk
        ? { key: "symlink", name: "symlink 作成", status: "PASS" }
        : {
            key: "symlink",
            name: "symlink 作成",
            status: "FAIL",
            what: "symlink を作成できません",
            why: "release 切替（current）と storage 共有は symlink を前提としています",
            fix: "SSH で `ln -s` が失敗する場合は XServer サポートへ確認してください",
        });
    // 8. Cron（API があれば登録状況を実測。無ければ手動登録の案内）
    if (api) {
        const crons = await api.cronList();
        const registered = crons.ok &&
            extractNames(crons.data, ["command"]).some((c) => c.includes("schedule:run") && c.includes(cfg.remote.appDir));
        checks.push(registered
            ? {
                key: "cron",
                name: "Cron（schedule:run 登録済み・API 確認）",
                status: "PASS",
            }
            : {
                key: "cron",
                name: "Cron",
                status: "WARN",
                what: crons.ok
                    ? "schedule:run の Cron が未登録です"
                    : `Cron 一覧を API から取得できません（${crons.error ?? "-"}）`,
                why: "Laravel scheduler / DB Queue の駆動に毎分の Cron が必要です",
                fix: "`agentcms deploy setup` で API 登録できます（またはサーバーパネル > Cron設定 へ手動登録）",
            });
    }
    else {
        checks.push({
            key: "cron",
            name: "Cron（手動登録）",
            status: "WARN",
            what: "Cron の登録状況を確認できません（XSERVER_API_KEY 未設定）",
            why: "API キーがあれば登録状況の確認と自動登録ができます（ADR-0009）",
            fix: "`agentcms deploy plan` が表示する Cron コマンドをサーバーパネル > Cron設定 に登録するか、API キーを設定して `agentcms deploy setup` を実行してください",
        });
    }
    // 9. HTTPS 到達性（アプリ URL への外形疎通。デプロイ前は 404 でも可）
    const https = await exec.exec(`curl -s -o /dev/null -w '%{http_code}' --max-time 10 ${shq(cfg.app.url)} || echo curl-fail`);
    const httpsOk = /^[1-5]\d\d$/.test(https.stdout.trim());
    checks.push(httpsOk
        ? {
            key: "https",
            name: `HTTPS 到達 (${cfg.app.url} → ${https.stdout.trim()})`,
            status: "PASS",
        }
        : {
            key: "https",
            name: "HTTPS 到達",
            status: "FAIL",
            what: `${cfg.app.url} へ HTTPS で到達できません`,
            why: https.stdout || "curl が失敗しました",
            fix: api
                ? "`agentcms deploy setup` で無料独自SSL を API 発行できます（またはサーバーパネル > SSL設定）"
                : "サーバーパネル > SSL設定 で無料独自SSL（Let's Encrypt）を有効化してください（反映まで最大1時間）。API キー設定時は `agentcms deploy setup` で自動化できます",
        });
    /*
     * 9a-2. SSL の**有効期限**（P1 ⑪）。
     *
     * API は「発行されているか」しか返さない。**いつ切れるかは証明書が持っている**ので、
     * そちらを読む。API に期限が在るかどうかに賭けない（provider 中立・ADR-0008）。
     *
     * **到達できなければ WARN。** 「測れなかった」を「大丈夫」にしない。
     */
    {
        const fqdn = cfg.app.url.replace(/^https?:\/\//, "").replace(/\/.*$/, "");
        checks.push(certificateExpiryCheck(fqdn, await probeCertificateExpiry(fqdn), new Date()));
    }
    // 9b. Control Plane（XServer API）検査 — SSL 発行状態と DB の存在
    if (api) {
        const fqdn = cfg.app.url.replace(/^https?:\/\//, "").replace(/\/.*$/, "");
        const ssl = await api.sslList();
        const sslStatus = ssl.ok ? findStatusFor(ssl.data, fqdn) : null;
        checks.push(sslStatus === "active"
            ? {
                key: "api_ssl",
                name: `SSL 発行状態（API: active）`,
                status: "PASS",
            }
            : {
                key: "api_ssl",
                name: "SSL 発行状態（API）",
                status: sslStatus === "pending" ? "WARN" : ssl.ok ? "FAIL" : "WARN",
                what: ssl.ok
                    ? `SSL が active ではありません（${sslStatus ?? "未設定"}）`
                    : `SSL 状態を API から取得できません（${ssl.error ?? "-"}）`,
                why: "HTTPS でのアプリ公開に無料独自SSL の発行完了が必要です",
                fix: sslStatus === "pending"
                    ? "発行処理中です。しばらく待って再実行してください"
                    : "`agentcms deploy setup` で発行できます",
            });
    }
    else {
        checks.push({
            key: "api",
            name: "XServer API（Control Plane）",
            status: "WARN",
            what: "XSERVER_API_KEY 未設定のため API 検査・自動セットアップは無効です",
            why: "SSL / DB / Cron の確認と作成が手動（サーバーパネル）になります",
            fix: "XServer アカウント管理画面 > APIキー管理 でキーを発行し、環境変数 XSERVER_API_KEY に設定してください（キーを設定ファイルや Git に書かない）",
        });
    }
    // 10. ディスク空き容量（取得できる範囲で）
    const disk = await exec.exec("df -h ~ 2>/dev/null | tail -1 | awk '{print $4}'");
    checks.push({
        key: "disk",
        name: disk.code === 0 && disk.stdout
            ? `ディスク空き容量（${disk.stdout}）`
            : "ディスク空き容量",
        status: disk.code === 0 && disk.stdout ? "PASS" : "WARN",
        what: disk.code === 0 && disk.stdout
            ? undefined
            : "空き容量を取得できませんでした",
        why: disk.code === 0 && disk.stdout
            ? undefined
            : "df コマンドが利用できません",
        fix: disk.code === 0 && disk.stdout
            ? undefined
            : "サーバーパネルでディスク使用量を確認してください",
    });
    // 11. 現在のデプロイ状態
    const cur = await exec.exec(`readlink ${shq(cfg.remote.appDir + "/current")} 2>/dev/null || echo ''`);
    const currentRelease = cur.stdout
        ? (cur.stdout.split("/").pop() ?? null)
        : null;
    checks.push({
        key: "state",
        name: currentRelease
            ? `現在の release: ${currentRelease}`
            : "現在の release: 未デプロイ",
        status: "PASS",
    });
    /*
     * **メールが本当に送れる形か**（B-41 ②）。
     *
     * ひな形は `setup` の 1 回しか書かれないので、**人が後から `log` へ戻せる**。
     * **毎回見るところが要る** — 本番で `log` のままだと、
     * **問い合わせが 1 通も届かず、しかもエラーが出ない。**
     */
    const envRead = await exec.exec(`cat ${shq(cfg.remote.appDir + "/shared/.env")} 2>/dev/null`);
    checks.push(inspectMailEnv(cfg.target, envRead.code === 0 ? envRead.stdout : null));
    return {
        capabilities: {
            checks,
            phpBin,
            phpVersion,
            composer: composerOk ? "installed" : "missing",
            symlinkOk,
            currentRelease,
        },
    };
}
/** サーバーパネルへ登録する Cron コマンドを生成する（検出済み PHP CLI パスを使用） */
export function xserverCronCommand(cfg, phpBin) {
    return `${phpBin} ${cfg.remote.appDir}/current/artisan schedule:run >/dev/null 2>&1`;
}
/**
 * XServer の DirectoryIndex 優先順（公式仕様）:
 * index.html > index.htm > index.shtml > index.cgi > index.php
 * これらが docroot に残っていると Laravel（index.php）が一切表示されない。
 */
export const DOCROOT_CONFLICT_FILES = [
    "index.html",
    "index.htm",
    "index.shtml",
    "index.cgi",
];
/**
 * **deploy が上書きするファイル**（B-41）。DirectoryIndex の優先順とは別の話で、
 * こちらは「**他人のサイトを消さない**」ための検査である。
 *
 * `publishDocroot()` は `tar -xf` のあと `cp` で `index.php` を置き換える。
 * そして**ファイルの backup はコード全体で 1 行も無い**（backup は DB だけ）。
 * 既存サイトの入口を黙って消してしまうので、**自分のスタブでなければ止める**。
 */
const DOCROOT_OVERWRITTEN_FILES = ["index.php"];
/** XServer 初期生成ページの判定マーカー（実機で確認した安定文字列） */
const XSERVER_DEFAULT_PAGE_MARKER = "Copyright XSERVER Inc.";
/**
 * docroot 内の index.php より優先されるファイルを検査する（読み取りのみ）。
 * XServer 初期生成ページと安全に判定できた index.html のみ退避対象にし、
 * それ以外（ユーザー作成の可能性）は自動削除せず block を返す。
 */
export async function xserverDocrootConflicts(exec, cfg) {
    const out = [];
    for (const file of DOCROOT_CONFLICT_FILES) {
        const path = `${cfg.remote.docroot}/${file}`;
        const exists = await exec.exec(`test -f ${shq(path)} && echo yes || echo no`);
        if (exists.stdout.trim() !== "yes")
            continue;
        if (file === "index.html") {
            const marker = await exec.exec(`grep -q ${shq(XSERVER_DEFAULT_PAGE_MARKER)} ${shq(path)} && echo xserver-default || echo other`);
            if (marker.stdout.trim() === "xserver-default") {
                out.push({
                    file,
                    action: "backup",
                    reason: `XServer 初期生成ページ（マーカー「${XSERVER_DEFAULT_PAGE_MARKER}」を確認）`,
                });
                continue;
            }
        }
        out.push({
            file,
            action: "block",
            reason: "ユーザー作成の可能性があるため自動削除しません。内容を確認し、不要なら手動で退避・削除してから再実行してください",
        });
    }
    // deploy が上書きしてしまうファイル（自分が前回置いたスタブだけは上書きしてよい）
    for (const file of DOCROOT_OVERWRITTEN_FILES) {
        const path = `${cfg.remote.docroot}/${file}`;
        const exists = await exec.exec(`test -f ${shq(path)} && echo yes || echo no`);
        if (exists.stdout.trim() !== "yes")
            continue;
        const marker = await exec.exec(`grep -q ${shq(ENTRY_STUB_MARKER)} ${shq(path)} && echo agentcms-stub || echo other`);
        // 前回の release が置いたスタブ → 上書きしてよいので競合として数えない
        if (marker.stdout.trim() === "agentcms-stub")
            continue;
        out.push({
            file,
            action: "block",
            reason: "deploy が上書きするファイルですが、agentcms が置いたものではありません（既存サイトの入口の可能性）。ファイルの backup は取らないため、内容を確認し、退避・削除してから再実行してください",
        });
    }
    return out;
}
/**
 * backup 対象の競合ファイルを appDir/backups/ へ退避する（削除はしない）。
 * XServer 初期生成の index.html を退避する場合は付随する default_page.png も一緒に退避する。
 */
export async function backupDocrootConflicts(exec, cfg, conflicts, releaseId) {
    const targets = conflicts.filter((c) => c.action === "backup");
    if (targets.length === 0)
        return { ok: true, detail: "退避対象なし" };
    const dest = `${cfg.remote.appDir}/backups`;
    const moves = targets
        .map((c) => `mv ${shq(cfg.remote.docroot + "/" + c.file)} ${shq(`${dest}/docroot-${c.file}.${releaseId}`)}`)
        .join(" && ");
    const png = `test -f ${shq(cfg.remote.docroot + "/default_page.png")} && mv ${shq(cfg.remote.docroot + "/default_page.png")} ${shq(`${dest}/docroot-default_page.png.${releaseId}`)} || true`;
    const r = await exec.exec(`mkdir -p ${shq(dest)} && ${moves} && ${png} && echo conflict-backup-ok`);
    if (!r.stdout.includes("conflict-backup-ok"))
        return { ok: false, detail: `退避失敗: ${r.stderr || r.stdout}` };
    return {
        ok: true,
        detail: `${targets.map((c) => c.file).join(", ")} を ${dest}/ へ退避しました（削除はしていません）`,
    };
}

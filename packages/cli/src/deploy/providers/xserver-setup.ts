import { randomBytes } from "node:crypto";

import type { RemoteExecutor } from "../executor.js";
import { shq } from "../executor.js";
import type { CapabilityCheck, DeployTargetConfig } from "../types.js";
import { extractNames, findStatusFor, type XserverApi } from "./xserver-api.js";
import { xserverCronCommand } from "./xserver.js";

/**
 * XServer Control Plane セットアップ（ADR-0009）。
 * 「サーバーパネルで人間が設定」だった前提条件を API で自動化する:
 * subdomain / 無料SSL / MySQL DB・ユーザー・grant / Cron / PHP バージョン。
 * - 冪等: 既存リソースはスキップ（作成のみ。削除・変更は自動化しない）
 * - DB パスワードはローカル生成し、サーバー上 shared/.env へ SSH 経由でのみ書き込む
 *   （ローカル保存・表示・ログ出力をしない）
 */

export interface SetupAction {
  key: string;
  /** 何をするか（confirm 表示用。秘密情報を含めない） */
  describe: string;
  /** 既に満たされているか */
  satisfied: boolean;
  /** 現状の説明 */
  current: string;
  run: () => Promise<{ ok: boolean; detail: string }>;
}

export interface SetupPlanInput {
  cfg: DeployTargetConfig;
  api: XserverApi;
  exec: RemoteExecutor;
  /** 検出済み PHP CLI（Cron コマンド用）。未検出なら null → Cron 登録は保留 */
  phpBin: string | null;
  log: (msg: string) => void;
}

/**
 * **2 段になる公開接尾辞**（この道具が相手にする範囲）。
 *
 * ▷ **手書きの表は必ず抜ける。** ここに無い接尾辞は「1 段」として扱われ、
 * `example.xx.yy` を「`xx.yy` の下のサブドメイン `example`」と読み違える。
 * **だから `setup` は親ドメインを必ず読み上げる**（違っていたら人が止められる）し、
 * **`api.parentDomain` で上書きできる**。
 *
 * ▷ **JP を全部入れているのは、日本の案件を受ける道具だからである。**
 * `.co.jp` は「たまに来る」ではなく**普通に来る**（実案件がまさにそれ）。
 */
const MULTI_LABEL_SUFFIXES = [
  // 日本（属性型 JP）
  "co.jp",
  "or.jp",
  "ne.jp",
  "ac.jp",
  "ad.jp",
  "ed.jp",
  "go.jp",
  "gr.jp",
  "lg.jp",
  // よく来る他国の 2 段
  "co.uk",
  "org.uk",
  "ac.uk",
  "com.au",
  "net.au",
  "org.au",
  "co.nz",
  "com.br",
  "com.cn",
  "co.kr",
  "com.tw",
  "com.hk",
  "com.sg",
];

/**
 * `cfg.app.url` から FQDN / 親ドメイン / サブドメインラベルを導く。
 *
 * ## ラベル数だけでは決められない
 *
 * `agcms.example.com` は「`example.com` の下の `agcms`」だが、
 * **`midori-zoen.co.jp` は同じ 3 ラベルでもサブドメインではない**。
 * ラベル数で切ると **親を `co.jp` と読み、他人のドメインの下へ作りに行く**。
 *
 * ▷ **実際に踏むところだった**（B-41 ③）。試験は `.com` の 2 例しか無く、
 * **日本の案件なら普通に来る `.co.jp` の例が 1 つも無かった。**
 *
 * ## 迷ったら人に決めさせる
 *
 * `parentOverride`（`api.parentDomain`）が在ればそれを使う。
 * **推測は「表に在るか」でしか判断できない**ので、
 * **呼び手は親ドメインを必ず読み上げる**こと。
 */
export function splitDomain(
  appUrl: string,
  parentOverride?: string,
): {
  fqdn: string;
  parent: string;
  sub: string | null;
} {
  const fqdn = appUrl.replace(/^https?:\/\//, "").replace(/\/.*$/, "");

  // 明示が在ればそれが正。推測しない
  if (parentOverride !== undefined && parentOverride.trim() !== "") {
    const parent = parentOverride.trim();
    const sub =
      fqdn === parent
        ? null
        : fqdn.endsWith(`.${parent}`)
          ? fqdn.slice(0, -(parent.length + 1))
          : null;

    return { fqdn, parent, sub };
  }

  const parts = fqdn.split(".");
  const suffix = MULTI_LABEL_SUFFIXES.find((s) => fqdn.endsWith(`.${s}`));
  // 2 段の接尾辞なら「接尾辞 + 1 ラベル」が契約しているドメイン
  const parentLabels = suffix === undefined ? 2 : suffix.split(".").length + 1;

  if (parts.length > parentLabels) {
    return {
      fqdn,
      parent: parts.slice(parts.length - parentLabels).join("."),
      sub: parts.slice(0, parts.length - parentLabels).join("."),
    };
  }

  return { fqdn, parent: fqdn, sub: null };
}

/** DB suffix（<server-id>_<suffix>）。設定 > サブドメインラベル > "app" */
export function dbSuffix(cfg: DeployTargetConfig): string {
  const conf = cfg.api?.dbSuffix;
  if (conf) return conf;
  const { sub } = splitDomain(cfg.app.url, cfg.api?.parentDomain);
  return (sub ?? "app").replaceAll(/[^a-z0-9]/g, "").slice(0, 12) || "app";
}

/** Laravel APP_KEY をローカル生成（base64:32bytes） */
export function generateAppKey(): string {
  return `base64:${randomBytes(32).toString("base64")}`;
}

/** DB パスワード生成。API 実測制約: 8〜16 文字（16 文字超は 422）。記号なし・シェル安全 */
export function generateDbPassword(): string {
  return randomBytes(12).toString("base64url"); // 16 文字
}

/** PHP バージョン文字列が 8.3 以上か */
export function isPhpVersionOk(version: string | null): boolean {
  if (!version) return false;
  const m = /^(\d+)\.(\d+)/.exec(version);
  if (!m) return false;
  const major = Number(m[1]);
  const minor = Number(m[2]);
  return major > 8 || (major === 8 && minor >= 3);
}

/**
 * セットアップ対象を検出して action リストを作る（この関数自体は読み取りのみ）。
 * 呼び出し側が satisfied=false の action を confirm の上で実行する。
 */
export async function detectSetupActions(
  input: SetupPlanInput,
): Promise<{ actions: SetupAction[]; errors: string[] }> {
  const { cfg, api, exec, phpBin } = input;
  const { fqdn, parent, sub } = splitDomain(cfg.app.url, cfg.api?.parentDomain);
  const errors: string[] = [];
  const actions: SetupAction[] = [];

  // --- subdomain ---
  const subs = await api.subdomainList();
  if (!subs.ok) errors.push(`subdomain 一覧取得失敗: ${subs.error}`);
  const subNames = extractNames(subs.data, [
    "fqdn",
    "subdomain",
    "domain",
    "name",
  ]);
  const subExists = subNames.some((n) => n === fqdn || n === sub);
  if (sub) {
    actions.push({
      key: "subdomain",
      /*
       * **親ドメインを読み上げる。** 推測が外れていたら、ここが唯一の気づける場所である
       * （`MULTI_LABEL_SUFFIXES` の表に無い接尾辞は取り違える）。
       * 違っていたら `api.parentDomain` を書く。
       */
      describe: `サブドメイン ${fqdn} を作成（親ドメイン: ${parent} / docroot: ${cfg.remote.docroot}）`,
      satisfied: subExists,
      current: subExists ? "作成済み" : "未作成",
      run: async () => {
        const r = await api.subdomainCreate({
          domain: parent,
          subdomain: sub,
          document_root: cfg.remote.docroot,
        });
        return {
          ok: r.ok,
          detail: r.ok ? "作成しました" : (r.error ?? "失敗"),
        };
      },
    });
  }

  // --- SSL ---
  const ssl = await api.sslList();
  if (!ssl.ok) errors.push(`SSL 一覧取得失敗: ${ssl.error}`);
  const sslStatus = findStatusFor(ssl.data, fqdn);
  actions.push({
    key: "ssl",
    describe: `無料独自SSL を ${fqdn} に発行（active までポーリング）`,
    satisfied: sslStatus === "active",
    current: sslStatus ?? "未設定",
    run: async () => {
      if (sslStatus !== "pending") {
        const r = await api.sslCreate(fqdn);
        if (!r.ok)
          return { ok: false, detail: r.error ?? "発行リクエスト失敗" };
      }
      // pending → active をポーリング（レート制限配慮で 10 秒間隔・最大 12 回）
      for (let i = 0; i < 12; i++) {
        await new Promise((res) => setTimeout(res, 10_000));
        const list = await api.sslList();
        const st = findStatusFor(list.data, fqdn);
        input.log(`  SSL status: ${st ?? "不明"}（${i + 1}/12）`);
        if (st === "active") return { ok: true, detail: "active になりました" };
        if (st === "failed")
          return {
            ok: false,
            detail: "発行が failed になりました（DNS 設定を確認してください）",
          };
      }
      return {
        ok: false,
        detail:
          "2 分以内に active になりませんでした（発行は継続中の可能性。後で `agentcms deploy doctor` で確認してください）",
      };
    },
  });

  // --- MySQL DB / user / grant ---
  const suffix = dbSuffix(cfg);
  // DB 名の接頭辞はサーバー ID（ssh.user）。api.servername は初期ドメイン形式のため使わない
  const dbName = `${cfg.ssh.user}_${suffix}`;
  const dbUser = `${cfg.ssh.user}_${suffix}`;
  const dbs = await api.dbList();
  if (!dbs.ok) errors.push(`DB 一覧取得失敗: ${dbs.error}`);
  const dbExists = extractNames(dbs.data, [
    "db_name",
    "name",
    "database",
  ]).includes(dbName);
  actions.push({
    key: "db",
    describe: `MySQL データベース ${dbName} を作成`,
    satisfied: dbExists,
    current: dbExists ? "作成済み" : "未作成",
    run: async () => {
      const r = await api.dbCreate({
        name_suffix: suffix,
        memo: "agentcms staging",
      });
      return { ok: r.ok, detail: r.ok ? "作成しました" : (r.error ?? "失敗") };
    },
  });

  const users = await api.dbUserList();
  if (!users.ok) errors.push(`DB ユーザー一覧取得失敗: ${users.error}`);
  const userExists = extractNames(users.data, [
    "db_user",
    "name",
    "user",
  ]).includes(dbUser);
  // パスワードは新規作成時のみ生成し、shared/.env へ書くために保持（表示しない）
  let generatedPassword: string | null = null;
  actions.push({
    key: "db_user",
    describe: `MySQL ユーザー ${dbUser} を作成（パスワードは生成して shared/.env にのみ書き込み）`,
    satisfied: userExists,
    current: userExists ? "作成済み" : "未作成",
    run: async () => {
      generatedPassword = generateDbPassword();
      const r = await api.dbUserCreate({
        name_suffix: suffix,
        password: generatedPassword,
      });
      return { ok: r.ok, detail: r.ok ? "作成しました" : (r.error ?? "失敗") };
    },
  });

  let grantExists = false;
  if (userExists) {
    const grants = await api.dbGrantList(dbUser);
    // 実測: grant 一覧は {"databases":["<db名>"]} の文字列配列（オブジェクト配列ではない）
    grantExists = [
      ...extractNames(grants.data, ["db_name", "name", "database"]),
      ...extractNames(grants.data, []),
    ].includes(dbName);
  }
  actions.push({
    key: "db_grant",
    describe: `ユーザー ${dbUser} へ ${dbName} の権限を付与`,
    satisfied: grantExists,
    current: grantExists ? "付与済み" : "未付与",
    run: async () => {
      const r = await api.dbGrantCreate(dbUser, dbName);
      return { ok: r.ok, detail: r.ok ? "付与しました" : (r.error ?? "失敗") };
    },
  });

  // --- shared/.env（DB 接続情報の書き込み。値はログへ出さない） ---
  const envPath = `${cfg.remote.appDir}/shared/.env`;
  const envCheck = await exec.exec(
    `test -f ${shq(envPath)} && grep -cE '^DB_DATABASE=.+' ${shq(envPath)} || echo 0`,
  );
  const envHasDb = envCheck.stdout.trim().endsWith("1");
  actions.push({
    key: "shared_env",
    describe: `shared/.env を整備（新規作成 or DB_* 設定。APP_KEY はローカル生成）`,
    satisfied: envHasDb,
    current: envHasDb ? "DB 設定済み" : "未設定",
    run: async () => {
      if (!generatedPassword && !userExists) {
        return {
          ok: false,
          detail: "DB ユーザー作成が先に成功している必要があります",
        };
      }
      if (generatedPassword === null) {
        return {
          ok: false,
          detail: `既存 DB ユーザー ${dbUser} のパスワードを API から取得することはできません。サーバーパネルでパスワードを再設定し、shared/.env の DB_PASSWORD を手動更新してください`,
        };
      }
      const result = await writeSharedEnvDb(exec, cfg, {
        dbName,
        dbUser,
        dbPassword: generatedPassword,
      });
      return result;
    },
  });

  // --- DB 接続検証と修復 ---
  // shared/.env の資格情報で実接続を検証。認証失敗なら DB_HOST 同期 + パスワード API 再設定で修復する。
  // XServer の MySQL 5.7 世代は DB_HOST が localhost ではなく専用ホスト（mysqlXXXX.xserver.jp）である点に注意。
  const dbHost = cfg.remote.dbHost ?? "localhost";
  const envPathQ = shq(cfg.remote.appDir + "/shared/.env");
  const connTest =
    `host=$(grep -E '^DB_HOST=' ${envPathQ} | tail -1 | cut -d= -f2-) && ` +
    `pw=$(grep -E '^DB_PASSWORD=' ${envPathQ} | tail -1 | cut -d= -f2-) && ` +
    `MYSQL_PWD="$pw" mysql -h "$host" -u ${shq(dbUser)} ${shq(dbName)} -e 'select 1' >/dev/null 2>&1 && echo dbconn-ok || echo dbconn-fail`;
  let dbConnOk: boolean | null = null;
  if (userExists && envHasDb) {
    const t = await exec.exec(connTest);
    dbConnOk = t.stdout.includes("dbconn-ok");
  }
  actions.push({
    key: "db_password",
    describe: `DB 実接続の検証（失敗時は DB_HOST=${dbHost} を同期し、パスワードを API で再設定）`,
    // 前提未充足（ユーザー未作成 / env 未設定）の場合は上流 action が処理するためここでは満了扱い
    satisfied: dbConnOk !== false,
    current:
      dbConnOk === null
        ? "前提未充足のため未検証（ユーザー作成・env 整備後に自動検証）"
        : dbConnOk
          ? "接続 OK"
          : "接続失敗（DB_HOST またはパスワード不一致）",
    run: async () => {
      const newPassword = generateDbPassword();
      const r = await api.dbUserUpdate(dbUser, newPassword);
      if (!r.ok)
        return { ok: false, detail: r.error ?? "パスワード再設定に失敗" };
      const u = await exec.exec(
        `sed -i "s|^DB_HOST=.*|DB_HOST=${dbHost}|" ${envPathQ} && ` +
          `sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${newPassword}|" ${envPathQ} && ` +
          `chmod 600 ${envPathQ} && ${connTest}`,
      );
      if (!u.stdout.includes("dbconn-ok"))
        return {
          ok: false,
          detail: `DB_HOST=${dbHost} + 新パスワードでも接続できません。MySQL 5.7 世代のサーバーは専用ホスト（サーバーパネル > MySQL設定 の「MySQL ホスト名」）が必要です — staging.json の remote.dbHost に設定してください`,
        };
      return {
        ok: true,
        detail: `DB_HOST=${dbHost} を同期し、パスワードを再設定して実接続を確認しました`,
      };
    },
  });

  // --- Cron ---
  const crons = await api.cronList();
  if (!crons.ok) errors.push(`Cron 一覧取得失敗: ${crons.error}`);
  const cronCmd = phpBin ? xserverCronCommand(cfg, phpBin) : null;
  const cronExists = cronCmd
    ? extractNames(crons.data, ["command"]).some(
        (c) => c.includes("schedule:run") && c.includes(cfg.remote.appDir),
      )
    : false;
  actions.push({
    key: "cron",
    describe: cronCmd
      ? `Cron を毎分で登録: ${cronCmd}`
      : "Cron 登録（PHP CLI 未検出のため保留 — doctor 後に再実行）",
    satisfied: cronExists,
    current: cronExists ? "登録済み" : "未登録",
    run: async () => {
      if (!cronCmd)
        return { ok: false, detail: "PHP CLI 未検出のため登録できません" };
      const r = await api.cronCreate({
        minute: "*",
        hour: "*",
        day: "*",
        month: "*",
        weekday: "*",
        command: cronCmd,
        comment: "agentcms scheduler",
      });
      return { ok: r.ok, detail: r.ok ? "登録しました" : (r.error ?? "失敗") };
    },
  });

  // --- PHP バージョン（fqdn → 親ドメインの順で取得。8.3 未満と判明した場合のみ切替対象） ---
  let phpTarget = fqdn;
  let phpVer: string | null = null;
  for (const domain of [fqdn, parent]) {
    const php = await api.phpVersionGet(domain);
    const v = php.ok
      ? (extractNames(php.data, ["version", "php_version"])[0] ?? null)
      : null;
    if (v) {
      phpTarget = domain;
      phpVer = v;
      break;
    }
  }
  // 取得できない場合は盲目的に切り替えない（SSH 側の PHP CLI 検査が別途あるため WARN 相当で満了扱い）
  const phpKnownTooOld = phpVer !== null && !isPhpVersionOk(phpVer);
  actions.push({
    key: "php",
    describe: `PHP バージョンを 8.3 以上へ切替（対象: ${phpTarget}）`,
    satisfied: !phpKnownTooOld,
    current:
      phpVer ??
      "API から取得不可（切替はスキップ。必要ならサーバーパネル > PHP Ver.切替 で確認）",
    run: async () => {
      const r = await api.phpVersionSet(phpTarget, "8.3");
      return {
        ok: r.ok,
        detail: r.ok ? "8.3 へ切り替えました" : (r.error ?? "失敗"),
      };
    },
  });

  return { actions, errors };
}

/**
 * shared/.env へ DB 接続情報を書き込む。無ければ最小テンプレートから新規作成。
 * 値はコマンド履歴に残るため、リモート側で heredoc（クォート付き）を使い echo しない。
 */
async function writeSharedEnvDb(
  exec: RemoteExecutor,
  cfg: DeployTargetConfig,
  db: { dbName: string; dbUser: string; dbPassword: string },
): Promise<{ ok: boolean; detail: string }> {
  const envPath = `${cfg.remote.appDir}/shared/.env`;
  const exists =
    (await exec.exec(`test -f ${shq(envPath)} && echo yes || echo no`))
      .stdout === "yes";

  if (!exists) {
    const appKey = generateAppKey();
    const content = minimalEnv(cfg, appKey, db);
    const w = await exec.exec(
      `mkdir -p ${shq(cfg.remote.appDir + "/shared")} && cat > ${shq(envPath)} << 'AGENTCMS_ENV_EOS'\n${content}\nAGENTCMS_ENV_EOS\nchmod 600 ${shq(envPath)} && echo env-created`,
    );
    return w.stdout.includes("env-created")
      ? {
          ok: true,
          detail:
            "shared/.env を新規作成しました（APP_KEY / DB_* 設定済み。MAIL_* / TURNSTILE_* / VAPID_* は手動で追記してください）",
        }
      : { ok: false, detail: `作成失敗: ${w.stderr}` };
  }

  // 既存 .env は DB_* 行のみ更新（バックアップを取る）
  const script = [
    `cp ${shq(envPath)} ${shq(envPath + ".bak")}`,
    `chmod 600 ${shq(envPath + ".bak")}`,
    ...Object.entries({
      DB_CONNECTION: "mysql",
      DB_HOST: "localhost",
      DB_PORT: "3306",
      DB_DATABASE: db.dbName,
      DB_USERNAME: db.dbUser,
      DB_PASSWORD: db.dbPassword,
    }).map(
      ([k, v]) =>
        `grep -qE '^${k}=' ${shq(envPath)} && sed -i "s|^${k}=.*|${k}=${v}|" ${shq(envPath)} || printf '%s\\n' '${k}=${v}' >> ${shq(envPath)}`,
    ),
    `echo env-updated`,
  ].join(" && ");
  const w = await exec.exec(script);
  return w.stdout.includes("env-updated")
    ? {
        ok: true,
        detail: "shared/.env の DB_* を更新しました（.env.bak にバックアップ）",
      }
    : { ok: false, detail: `更新失敗: ${w.stderr}` };
}

/**
 * この target は**本番**か。
 *
 * `target` は自由文字列（`.agentcms/deploy/<target>.json` のファイル名）なので、
 * **`production` ちょうどだけを見ない** — `prod` / `production-1` のような名前は普通に来る。
 * **staging と読めるもの以外は本番として扱う**（**強いほう＝安全側**）。
 */
export function isProductionTarget(target: string): boolean {
  return !/^(staging|stg|test|dev|local|preview)\b/i.test(target.trim());
}

/**
 * `deploy setup` が初回に書く `shared/.env`。
 *
 * ▷ **`APP_TIMEZONE=Asia/Tokyo` を落とさない**（直し 5 回目）。無いと Laravel の既定 UTC で動き、
 * 編集画面で「10:00」に予約したページが日本時間の 19:00 まで出ない（管理画面も UTC で読むので、画面の上では食い違いが見えない）。
 */
export function minimalEnv(
  cfg: DeployTargetConfig,
  appKey: string,
  db: { dbName: string; dbUser: string; dbPassword: string },
): string {
  const prod = isProductionTarget(cfg.target);

  /*
   * 🔴 **本番では `MAIL_MAILER=log` にしない**（B-41 ②）。
   *
   * `log` は**送ったつもりで 1 通も届かない**。しかも**エラーも出ない**ので、
   * 「問い合わせが来ない」としてしか現れず、**来ないことは誰も気づけない**。
   *
   * 本番は `smtp` にして、**設定が空なら送信時に失敗させる** — 失敗は
   * `admin_notification_error` に残り、管理画面で読める。**黙って捨てるより騒ぐほうがよい。**
   *
   * staging は `log` のままでよい（**送らないのが意図**である）。
   */
  const mail = prod
    ? `MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="\${APP_NAME}"
MAIL_ADMIN_ADDRESS=`
    : `MAIL_MAILER=log
MAIL_FROM_ADDRESS=staging@example.test
MAIL_FROM_NAME="\${APP_NAME}"
MAIL_ADMIN_ADDRESS=
MAIL_STAGING_REDIRECT_TO=`;

  return `# agentcms deploy setup が生成した ${cfg.target} の .env（不足キーは手動で追記）
APP_NAME="Agent CMS${prod ? "" : " (staging)"}"
APP_ENV=${cfg.target}
APP_KEY=${appKey}
APP_DEBUG=false
APP_URL=${cfg.app.url}
APP_LOCALE=ja
APP_FALLBACK_LOCALE=ja
APP_TIMEZONE=Asia/Tokyo
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=${cfg.remote.dbHost ?? "localhost"}
DB_PORT=3306
DB_DATABASE=${db.dbName}
DB_USERNAME=${db.dbUser}
DB_PASSWORD=${db.dbPassword}
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
${mail}
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
VAPID_SUBJECT=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=`;
}

/**
 * `shared/.env` のメール設定が、その target で**本当に送れる形**か。
 *
 * ▷ **設定を直すだけでは足りない。** ひな形は最初の 1 回しか書かれず、
 * **人が後から `log` へ戻せる**。**毎回見るところが要る。**
 *
 * ▷ **本番で `log` は FAIL。** 送ったつもりで 1 通も届かず、**エラーも出ない** —
 * 「問い合わせが来ない」としてしか現れない。
 * **差出人が空 / `example.` も FAIL**（多くの受信側が弾くので、届かない側に倒れる）。
 *
 * ▷ **staging の `log` は PASS。** 送らないのが意図である。
 *
 * @param envText `shared/.env` の中身。読めなかったときは null
 */
export function inspectMailEnv(
  target: string,
  envText: string | null,
): CapabilityCheck {
  const name = "メール送信の設定（shared/.env）";

  if (envText === null) {
    return {
      key: "mail",
      name,
      status: "WARN",
      what: "shared/.env を読めませんでした",
      why: "setup 前か、権限で読めない可能性があります",
      fix: "`agentcms deploy setup` を実行してから、もう一度 doctor を回してください",
    };
  }

  const read = (key: string): string => {
    const line = envText
      .split("\n")
      .find((l) => l.trim().startsWith(`${key}=`));

    return (line ?? "").slice(`${key}=`.length + (line ?? "").indexOf(key))
      .replace(/^["']|["']$/g, "")
      .trim();
  };

  const mailer = read("MAIL_MAILER");
  const from = read("MAIL_FROM_ADDRESS").replace(/^["']|["']$/g, "");

  if (!isProductionTarget(target)) {
    return {
      key: "mail",
      name: `${name}（${target} / ${mailer || "未設定"}）`,
      status: "PASS",
      detected: mailer,
    };
  }

  if (mailer === "log" || mailer === "") {
    return {
      key: "mail",
      name,
      status: "FAIL",
      detected: mailer,
      what: `本番なのに MAIL_MAILER=${mailer || "（未設定）"} です`,
      why: "log は送ったつもりで 1 通も届かず、エラーも出ません（問い合わせが来ないとしてしか現れません）",
      fix: "shared/.env の MAIL_MAILER を smtp にし、MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD を埋めてください",
    };
  }

  if (from === "" || /(^|@)example\./.test(from)) {
    return {
      key: "mail",
      name,
      status: "FAIL",
      detected: from,
      what: `本番の差出人が ${from === "" ? "空です" : `${from} です`}`,
      why: "差出人が無い / 例示用のドメインだと、多くの受信側が弾きます（届かない側に倒れます）",
      fix: "shared/.env の MAIL_FROM_ADDRESS を、実際に送れるアドレスにしてください",
    };
  }

  return {
    key: "mail",
    name: `${name}（${mailer} / ${from}）`,
    status: "PASS",
    detected: `${mailer} ${from}`,
  };
}

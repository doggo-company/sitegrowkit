/**
 * Deployment の Provider 非依存型定義。
 * XServer 固有の値・検出ロジックは providers/xserver.ts に置く（Core へ埋め込まない）。
 */

/** デプロイ先 1 件の設定（.agentcms/deploy/<target>.json）。秘密情報は含めない */
export interface DeployTargetConfig {
  /** Provider Adapter 名。"xserver"（共用サーバー）か "vps"（Docker の入った Linux VPS・ADR-0021） */
  provider: string;
  /** "staging" | "production" 等。Phase 4 で deploy 可能なのは staging のみ */
  target: string;
  ssh: {
    host: string;
    port: number;
    user: string;
    /** 秘密鍵パス（~ 展開可）。省略時は ssh config / agent に委ねる */
    identityFile?: string;
  };
  remote: {
    /** アプリ配置ルート（releases/ shared/ current を置く場所。docroot の外） */
    appDir: string;
    /** Web 公開ディレクトリ（XServer: /home/<user>/<domain>/public_html） */
    docroot: string;
    /** PHP CLI パス。"auto" なら doctor が検出した値を使う */
    phpBin: string;
    /**
     * MySQL 接続ホスト。省略時 localhost。
     * XServer の MySQL 5.7 世代サーバーは専用ホスト（mysqlXXXX.xserver.jp、
     * サーバーパネル > MySQL設定 に表示）が必要（実測知見・ADR-0009）
     */
    dbHost?: string;
    /**
     * PHP（artisan）を流すコンテナの名前。provider "vps" が設定から決める（書かない）。
     * 無ければ ssh の先のシェルで直接流す
     */
    container?: string;
  };
  app: {
    /** 公開 URL（https://...）。health check / verify staging の基点 */
    url: string;
    healthPath: string;
  };
  /** 保持する旧 release 数（rollback point）。既定 3 */
  keepReleases: number;
  /**
   * XServer API（Control Plane, ADR-0009）の任意設定。
   * API キーはここに書かない（環境変数 XSERVER_API_KEY のみ）。
   */
  api?: {
    /** API パスの {servername}。省略時は ssh.user */
    servername?: string;
    /** MySQL DB / ユーザー名の suffix（<servername>_<suffix>）。省略時はサブドメインラベル */
    dbSuffix?: string;
    /**
     * 契約しているドメイン（サブドメインの親）。**省略時は URL から推測する。**
     *
     * ▷ **推測は当たらないことがある。** `example.co.jp` のような**2 段の公開接尾辞**は、
     * ラベル数だけでは「サブドメイン付き」と見分けられない
     * （`splitDomain()` が知っている表に無い接尾辞は取り違える）。
     * **`deploy setup` が「親ドメイン ◯◯ の下に ◯◯ を作る」と必ず読み上げる**ので、
     * 違っていたらそこで止めて、この値を書く。
     */
    parentDomain?: string;
  };
  /**
   * provider "vps" の設定（ADR-0021）。`remote` は書かなくてよい（ここから決まる）。
   * 秘密はここにも書かない — DB パスワード・APP_KEY はサーバーの上で生成し、
   * サーバーの `shared/.env` にだけ置く。
   */
  vps?: {
    /** サイト名（英小文字・数字・ハイフン 2〜32 文字）。コンテナ・DB・置き場所の名前になる */
    site: string;
    /** サーバーの置き場所。既定 /srv/agentcms */
    root?: string;
    /**
     * 証明書。"auto"（既定）は Caddy が Let's Encrypt から取って自動で更新する。
     * "internal" は Caddy の自前の認証局（試験・社内向け。ブラウザは警告を出す）
     */
    tls?: "auto" | "internal";
  };
}

export type CheckStatus = "PASS" | "WARN" | "FAIL";

/** doctor の 1 検査結果。FAIL は「何が・なぜ・どう直すか」を必ず持つ */
export interface CapabilityCheck {
  /** capability キー（ssh, php, composer, mysql, ...） */
  key: string;
  name: string;
  status: CheckStatus;
  /** 検出値（PHP バージョン・パス等）。表示とキャッシュに使う */
  detected?: string;
  what?: string;
  why?: string;
  fix?: string;
}

/** doctor が検出した環境能力。plan / deploy がこれを前提に動く */
export interface Capabilities {
  checks: CapabilityCheck[];
  /** 検出済み PHP CLI パス（例: /usr/bin/php8.3） */
  phpBin?: string;
  phpVersion?: string;
  composer?: "installed" | "missing";
  /** symlink が docroot で有効か（release 方式の分岐点） */
  symlinkOk: boolean;
  /** 現在の deployment 状態（current の指す先。未デプロイなら null） */
  currentRelease: string | null;
}

export function worstStatus(checks: CapabilityCheck[]): CheckStatus {
  if (checks.some((c) => c.status === "FAIL")) return "FAIL";
  if (checks.some((c) => c.status === "WARN")) return "WARN";
  return "PASS";
}

/** 公開ディレクトリ戦略。symlink 可否の capability detection で決まる */
export type PublicStrategy =
  | "docroot_symlink" // docroot 自体を current/public への symlink に置換
  | "entry_stub" // docroot は実ディレクトリのまま、index.php スタブ + 公開アセット同期
  | "caddy_root"; // VPS: Caddy が current/public を直接配信し、PHP は symlink を解いてから渡す

export interface DeployPlan {
  target: string;
  provider: string;
  releaseId: string;
  appUrl: string;
  /** アップロードする artifact（相対パスの要約と件数・サイズ） */
  artifact: {
    file: string;
    fileCount: number;
    sizeBytes: number;
    /** 除外を確認できるようにする（.env / node_modules / tests 等） */
    excluded: string[];
  };
  environment: {
    appEnv: string;
    appDebug: string;
    /** 値は出さない。サーバー側 shared/.env に存在するキー名のみ */
    sharedEnvKeys: string[];
  };
  database: {
    /** `php artisan migrate --pending` 相当の一覧 */
    pendingMigrations: string[];
    /** 一覧の取得元（リモート実測 / ローカル一覧 / 初回全件） */
    source: string;
    /** drop/削除系を含むか（含む場合 staging でも明示） */
    destructive: boolean;
  };
  storageStrategy: string;
  publicStrategy: PublicStrategy;
  /**
   * docroot 内で index.php より DirectoryIndex 優先度が高いファイルの扱い
   * （XServer: index.html > index.htm > index.shtml > index.cgi > index.php）
   */
  docrootActions: string[];
  symlinkChanges: string[];
  cronChanges: string[];
  backup: {
    database: string;
    rollbackPoint: string;
  };
  healthChecks: string[];
}

/** deploy 実行の 1 ステップ結果（journal / idempotency 判断に使う） */
export interface StepResult {
  step: string;
  ok: boolean;
  detail?: string;
}

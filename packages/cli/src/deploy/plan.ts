import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";

import { estimateProjectTree, EXCLUDED_SUMMARY } from "./artifact.js";
import { newReleaseId } from "./build.js";
import type { RemoteExecutor } from "./executor.js";
import { shq } from "./executor.js";
import {
  xserverCronCommand,
  xserverDocrootConflicts,
} from "./providers/xserver.js";
import type { Capabilities, DeployPlan, DeployTargetConfig } from "./types.js";
import { inDir } from "./release.js";
import { vpsNames } from "./providers/vps-assets.js";

/**
 * Deployment Plan の生成。実サーバーへは読み取りアクセスのみ
 * （exec が null の場合は完全オフラインで、リモート依存項目を「未接続」と明示する）。
 * 秘密情報（.env の値・パスワード類）は一切含めない。
 */
export async function buildPlan(
  projectRoot: string,
  cfg: DeployTargetConfig,
  exec: RemoteExecutor | null,
  caps: Capabilities | null,
): Promise<DeployPlan> {
  const estimate = estimateProjectTree(projectRoot);
  const phpBin = caps?.phpBin ?? cfg.remote.phpBin;

  // 環境キー（リモート shared/.env のキー名のみ。値は読まない）
  let sharedEnvKeys: string[] = [];
  if (exec) {
    const r = await exec.exec(
      `grep -oE '^[A-Za-z0-9_]+=' ${shq(cfg.remote.appDir + "/shared/.env")} 2>/dev/null | sed 's/=$//'`,
    );
    if (r.code === 0 && r.stdout)
      sharedEnvKeys = r.stdout.split("\n").filter(Boolean);
  }

  // pending migrations: デプロイ済みなら remote の migrate:status、未デプロイ・未接続ならローカル一覧
  let pending: string[] = [];
  let pendingSource =
    "ローカル migration ファイル一覧（未接続のため全件を表示）";
  if (exec && caps?.currentRelease && phpBin !== "auto") {
    const r = await exec.exec(
      inDir(
        cfg,
        cfg.remote.appDir + "/current",
        `${shq(phpBin)} artisan migrate:status --pending 2>/dev/null`,
      ),
    );
    if (r.code === 0) {
      pending = r.stdout
        .split("\n")
        .map((l) => l.trim())
        .filter((l) => /^\d{4}_\d{2}_\d{2}/.test(l))
        .map((l) => (l.split(/\s+/)[0] ?? "").replaceAll(".", ""))
        .filter(Boolean);
      pendingSource = "リモート migrate:status --pending";
    }
  } else {
    pending = localMigrations(projectRoot);
    if (exec && !caps?.currentRelease)
      pendingSource = "初回デプロイ（全 migration が実行対象）";
  }

  // docroot の DirectoryIndex 競合（XServer: index.html 等が index.php より優先される）
  let docrootActions: string[] = [
    "未接続のため未検査（デプロイ時に検査し、ユーザー作成の可能性があるファイルがあれば停止します）",
  ];
  const vps = cfg.provider === "vps" ? vpsNames(cfg) : null;
  if (vps) {
    docrootActions = [
      `${vps.root}/edge/sites/${vps.site}.caddy に ${vps.domain} の行（別の場所で caddy validate してから差し替え・reload が落ちたら元へ戻す）`,
      `同じドメインを別のサイトが使っていれば止める（何も変えない）`,
    ];
  } else if (exec) {
    const conflicts = await xserverDocrootConflicts(exec, cfg);
    docrootActions =
      conflicts.length === 0
        ? [
            "競合なし（index.html / index.htm / index.shtml / index.cgi は存在しない）",
          ]
        : conflicts.map((c) =>
            c.action === "backup"
              ? `${c.file}: ${c.reason} → deploy 時に ${cfg.remote.appDir}/backups/ へ退避（削除しない）`
              : `${c.file}: ${c.reason} → デプロイを停止します`,
          );
  }

  return {
    target: cfg.target,
    provider: cfg.provider,
    releaseId: `${newReleaseId()}（実行時に採番し直します）`,
    appUrl: cfg.app.url,
    artifact: {
      file: `.agentcms/deploy/build/<releaseId>.tar.gz`,
      fileCount: estimate.fileCount,
      sizeBytes: estimate.sizeBytes,
      excluded: EXCLUDED_SUMMARY,
    },
    environment: {
      appEnv: cfg.target,
      appDebug: "false（deploy 前に shared/.env を検査し、違えば中止）",
      sharedEnvKeys,
    },
    database: {
      pendingMigrations: pending,
      source: pendingSource,
      destructive: hasDestructiveMigration(projectRoot, pending),
    },
    storageStrategy:
      "shared/storage を release 間で共有（release の storage は shared への symlink）",
    publicStrategy: vps ? "site_web" : "entry_stub",
    docrootActions,
    symlinkChanges: [
      `${cfg.remote.appDir}/current -> releases/<releaseId>`,
      `releases/<releaseId>/storage -> shared/storage`,
      `releases/<releaseId>/.env -> shared/.env`,
      vps
        ? `releases/<releaseId>/public/storage -> shared/storage/app/public`
        : `${cfg.remote.docroot}/storage -> shared/storage/app/public`,
    ],
    cronChanges: vps
      ? [
          `cron は使わない: コンテナ ${vps.schedulerContainer} が毎分 current で schedule:run を流す`,
        ]
      : caps?.checks.find((c) => c.key === "cron")?.status === "PASS"
        ? ["登録済み（deploy による変更なし）"]
        : [
            `未登録: ${
              phpBin !== "auto"
                ? xserverCronCommand(cfg, phpBin)
                : "（`agentcms deploy doctor` で PHP CLI 検出後に確定）"
            } — \`agentcms deploy setup\` で API 登録、またはサーバーパネル > Cron設定 へ手動登録（deploy 自体は Cron を変更しない）`,
          ],
    backup: {
      database: `${cfg.remote.appDir}/backups/<releaseId>-db.sql.gz（mysqldump${vps ? "（MySQL のコンテナの中）" : ""} + gzip 整合性・サイズ検証。失敗時は deploy 中止）`,
      rollbackPoint: caps?.currentRelease
        ? `現行 release ${caps.currentRelease}（current 切り替えのみで復帰可能）`
        : "初回デプロイのため旧 release なし（rollback は DB バックアップのみ）",
    },
    healthChecks: [
      "TOP (HTTPS 200 / debug 出力なし)",
      "health endpoint (DB / storage 書き込み)",
      "admin ログイン画面",
      "build アセット配信",
      "Contact フォーム表示 + Turnstile",
      "PWA manifest / SW (scope /admin) / 公開サイト非影響",
      "セキュリティヘッダー",
      ".env / vendor / ソース非公開",
      cfg.target === "staging"
        ? "staging noindex (robots.txt + X-Robots-Tag)"
        : `${cfg.target}: noindex が残っていないこと (robots.txt + X-Robots-Tag)`,
    ],
  };
}

/** ローカルの migration ファイル一覧（app 本体 + 全モジュール） */
export function localMigrations(projectRoot: string): string[] {
  const dirs = [join(projectRoot, "database", "migrations")];
  const modulesDir = join(projectRoot, "modules");
  if (existsSync(modulesDir)) {
    for (const m of readdirSync(modulesDir)) {
      const d = join(modulesDir, m, "database", "migrations");
      if (existsSync(d)) dirs.push(d);
    }
  }
  const out: string[] = [];
  for (const d of dirs) {
    for (const f of readdirSync(d)) {
      if (f.endsWith(".php")) out.push(f.replace(/\.php$/, ""));
    }
  }
  return out.sort();
}

/** 破壊的 migration の検出（drop / truncate 系のヒューリスティック） */
export function hasDestructiveMigration(
  projectRoot: string,
  migrationNames: string[],
): boolean {
  const nameSet = new Set(migrationNames);
  const dirs = [join(projectRoot, "database", "migrations")];
  const modulesDir = join(projectRoot, "modules");
  if (existsSync(modulesDir)) {
    for (const m of readdirSync(modulesDir)) {
      const d = join(modulesDir, m, "database", "migrations");
      if (existsSync(d)) dirs.push(d);
    }
  }
  for (const d of dirs) {
    for (const f of readdirSync(d)) {
      if (!f.endsWith(".php")) continue;
      if (!nameSet.has(f.replace(/\.php$/, ""))) continue;
      const src = readFileSync(join(d, f), "utf8");
      // down() 内の drop は対象外にしたいが、確実な構文解析はしない。
      // up() の範囲だけを大まかに切り出して drop 系を探す
      const upBody =
        /function up\(\)[^{]*\{([\s\S]*?)\n    \}/.exec(src)?.[1] ?? "";
      if (/dropColumn|dropIfExists|dropForeign|->drop\(|truncate/i.test(upBody))
        return true;
    }
  }
  return false;
}

/**
 * **別のテンプレートから来た生成物**を見つける（B-41 ⑥ / B-40 の機械検査）。
 *
 * ## なぜ要るのか
 *
 * 取り込みの生成物は 1 行目に **`fingerprint <hash>`** を持つ。
 * **その hash が、この案件の取り込み記録（`.agentcms/manifest/template.json`）の
 * fingerprint と違えば、別のテンプレートから来たもの**である。
 *
 * ▷ **実際に在った**（B-40・2026-08-28）: 実案件の `/about` `/service` が
 * **kit の見本（Green Leaf）のサンプル本文のまま**だった。
 * 「小さなデザインスタジオという設定のサンプルサイトです」が
 * **本番へ出た瞬間に indexed される**ところだった。
 *
 * ▷ **人は気づけない。** ナビから辿れず、**sitemap にだけ載っていた**。
 * 見た目を見て回っても出てこない。**hash を突き合わせるしかない。**
 *
 * ## 名前で判定しない
 *
 * 「Green Leaf の fingerprint は `2ec20387b570`」と**定数で持たない**。
 * 見本が変われば古くなるし、**見本以外から混ざった場合に見つけられない**。
 * **「この案件のものか、そうでないか」**だけで見る。
 *
 * @returns 食い違ったファイル（プロジェクト相対）と、その fingerprint
 */
export function foreignTemplateFiles(
  projectRoot: string,
): { path: string; fingerprint: string }[] {
  const manifestPath = join(
    projectRoot,
    ".agentcms",
    "manifest",
    "template.json",
  );

  /*
   * **記録が無い / 読めないときは「判定しない」。**
   *
   * ▷ **下の `try/catch` でも同じ結果になる**（読めなければ throw して `[]` へ落ちる）。
   * 変異で確かめたところ、**この 2 つの guard を外しても試験は緑のまま**だった —
   * **同じ壊れ方を 2 つの仕組みが受けている**からである。
   *
   * **試験が足りないのではない**（「記録が無い」「記録が壊れている」の**振る舞い**は
   * どちらも固定してある）。**guard が二重なだけ**である。
   * 残しているのは、**例外を制御フローに使わない**ため — `catch` は最後の受け皿で、
   * **意図はここに書く**。
   */
  if (!existsSync(manifestPath)) return [];

  let own: string;
  try {
    const raw = JSON.parse(readFileSync(manifestPath, "utf8")) as {
      fingerprint?: { total?: unknown };
    };
    const total = raw.fingerprint?.total;

    // 記録が読めない＝「無い」ことにしない。**判定しないで黙る**
    if (typeof total !== "string" || total === "") return [];
    own = total.slice(0, 12);
  } catch {
    return [];
  }

  const out: { path: string; fingerprint: string }[] = [];
  const stamp =
    /generated by agentcms template apply[^\n]*fingerprint ([0-9a-f]+)/;

  const walk = (dir: string): void => {
    if (!existsSync(dir)) return;

    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);

      if (entry.isDirectory()) {
        walk(full);
        continue;
      }
      if (!entry.name.endsWith(".blade.php")) continue;

      // 1 行目だけ見る（生成物の署名は必ず先頭に在る）
      const head = readFileSync(full, "utf8").split("\n", 1)[0] ?? "";
      const hit = stamp.exec(head);

      if (hit === null) continue;

      const fp = hit[1] ?? "";

      if (fp !== own) {
        out.push({ path: relative(projectRoot, full), fingerprint: fp });
      }
    }
  };

  walk(join(projectRoot, "resources", "views"));
  walk(join(projectRoot, "modules"));

  return out.sort((a, b) => a.path.localeCompare(b.path));
}

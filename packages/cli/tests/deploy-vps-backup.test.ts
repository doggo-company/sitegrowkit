import { spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import {
  backupCheck,
  parseBackupStatus,
  siteFsCommand,
} from "../src/deploy/providers/vps.js";
import {
  backupScript,
  siteCompose,
  vpsNames,
} from "../src/deploy/providers/vps-assets.js";
import type { DeployTargetConfig } from "../src/deploy/types.js";

/**
 * 夜のバックアップ（サーバーの backup のコンテナ）と、サイトのコンテナの一時領域（ADR-0021）。
 *
 * 台本は**本当に流す**（mysql・mysqldump だけ偽物）。見るのは流した結果:
 * control/<site>/backup に何が残ったか・status.json に何が書かれたか。
 */

const run = process.platform === "win32" ? describe.skip : describe;

function cfg(over: Partial<NonNullable<DeployTargetConfig["vps"]>> = {}): DeployTargetConfig {
  return {
    provider: "vps",
    target: "staging",
    ssh: { host: "h", port: 22, user: "deploy" },
    remote: { appDir: "/srv/agentcms/sites/shop-a", docroot: "", phpBin: "php", dbHost: "agentcms-mysql" },
    app: { url: "https://a.example.com", healthPath: "/healthz" },
    keepReleases: 3,
    vps: { site: "shop-a", ...over },
  };
}

describe("サイトのコンテナは根を読むだけにし、一時領域は大きさを決めた tmpfs", () => {
  const y = siteCompose(vpsNames(cfg()), { uid: "1000", gid: "1001" });
  const svc = (name: string) => {
    const i = y.indexOf(`\n  ${name}:\n`);
    const rest = y.slice(i + 1);
    const j = rest.search(/\n {2}[a-z]+:\n|\nnetworks:\n/);
    return rest.slice(0, j);
  };

  it("app・web・scheduler の根は読むだけ・/tmp は 64MB・/dev/shm は 16MB", () => {
    for (const k of ["app", "web", "scheduler"]) {
      const s = svc(k);
      expect(s).toContain("read_only: true");
      expect(s).toContain("- /tmp:size=64m,mode=1777,nosuid,nodev,noexec");
      expect(s).toContain('shm_size: "16m"');
    }
  });

  it("PHP-FPM のソケットの置き場所は 1MB の tmpfs（サーバーのディスクの control に書かせない）", () => {
    expect(svc("app")).toContain("- run:/run/agentcms");
    expect(svc("web")).toContain("- run:/run/agentcms");
    expect(y).not.toContain("/run:/run/agentcms");
    expect(y).toMatch(/volumes:\n {2}run:\n {4}driver: local\n {4}driver_opts:\n {6}type: tmpfs\n {6}device: tmpfs\n {6}o: "size=1m,mode=0700,uid=1000,gid=1001,nosuid,nodev,noexec"/);
  });

  it("使い捨てのコンテナも根は読むだけ・/tmp は大きさを決めた tmpfs", () => {
    const c = siteFsCommand(vpsNames(cfg()), "echo hi");
    expect(c).toContain("--read-only --tmpfs /tmp:size=64m,mode=1777,nosuid,nodev,noexec ");
  });
});

describe("backup のコンテナ（サーバーが取る夜のバックアップ）", () => {
  const n = vpsNames(cfg({ backup: { at: "17:30", keep: 3, maxMB: 100 } }));
  const y = siteCompose(n, { uid: "1000", gid: "1001" });
  const b = y.slice(y.indexOf("\n  backup:\n"), y.indexOf("\nnetworks:\n"));

  it("サイトのコードを動かさず（MySQL の公式イメージ）、サイトの置き場所は読むだけ、書くのは control だけ", () => {
    expect(b).toContain("image: mysql:8.4");
    expect(b).not.toContain("agentcms-php");
    expect(b).toContain("- /srv/agentcms/sites/shop-a:/site:ro");
    expect(b).toMatch(/- \/srv\/agentcms\/control\/shop-a\/backup:\/agentcms\/backup$/m);
    expect(b).toContain("- /srv/agentcms/control/shop-a/backup.sh:/agentcms/backup.sh:ro");
    expect(b).toContain("- /srv/agentcms/control/shop-a/db-password:/agentcms/db-password:ro");
    // サイトのコンテナには control の backup・台本・パスワードを入れない
    const site = y.slice(0, y.indexOf("\n  backup:\n"));
    expect(site).not.toMatch(/control\/shop-a\/(backup|db-password)/);
  });

  it("権限なし・根は読むだけ・上限つき・秘密を環境変数で渡さない", () => {
    expect(b).toContain("read_only: true");
    expect(b).toContain("cap_drop: [ALL]");
    expect(b).toContain('security_opt: ["no-new-privileges:true"]');
    expect(b).toContain("mem_limit: 512m");
    expect(b).toContain("pids_limit: 64");
    expect(b).toContain('max-size: "10m"');
    expect(b).toContain('AGENTCMS_BACKUP_AT: "17:30"');
    expect(b).toContain('AGENTCMS_BACKUP_KEEP: "3"');
    expect(b).toContain(`AGENTCMS_BACKUP_MAX_BYTES: "${100 * 1048576}"`);
    expect(b).not.toMatch(/PASSWORD|PWD|MYSQL_ROOT/);
  });
});

describe("status.json の読み方と doctor の行", () => {
  const n = vpsNames(cfg());
  const b64 = (o: unknown) => Buffer.from(JSON.stringify(o)).toString("base64");
  const ok = {
    version: 1, state: "ok", error: null, id: "20261124030000", startedAt: 1_800_000_000,
    finishedAt: 1_800_000_060, lastSuccessAt: 1_800_000_060, lastSuccessId: "20261124030000", dbBytes: 2048, filesBytes: 4096,
  };
  const f = (status: string, now = 1_800_000_100, container = "running", deployed = true) => backupCheck(n, { status, container, now, deployed });

  it("成功なら PASS、失敗・止まっている・途中で止まった・コンテナが動いていない・記録が無いは WARN", () => {
    expect(f(b64(ok))?.status).toBe("PASS");
    const failed = f(b64({ ...ok, state: "failed", error: "db-auth" }));
    expect(failed?.status).toBe("WARN");
    expect(failed?.what).toContain("db-auth");
    expect(f(b64(ok), 1_800_000_060 + 31 * 3600)?.what).toContain("30 時間以上");
    expect(f(b64({ ...ok, state: "running", startedAt: 1_800_000_000 }), 1_800_000_000 + 4 * 3600)?.what).toContain("終わっていません");
    expect(f(b64(ok), 1_800_000_100, "exited")?.what).toContain("動いていません");
    expect(f("none")?.status).toBe("WARN");
    expect(f("none", 0, "", false)).toBeNull();
    expect(f("odd")?.what).toContain("形が違います");
  });

  it("形の違う記録は信じない（決まった言葉以外の失敗・大きすぎる・先の数）", () => {
    expect(parseBackupStatus(b64(ok))?.state).toBe("ok");
    expect(parseBackupStatus(b64({ ...ok, state: "failed", error: "<script>" }))).toBeNull();
    expect(parseBackupStatus(b64({ ...ok, version: 2 }))).toBeNull();
    expect(parseBackupStatus(b64({ ...ok, id: "x" }))).toBeNull();
    expect(parseBackupStatus(Buffer.from("x".repeat(5000)).toString("base64"))).toBeNull();
    expect(parseBackupStatus(b64({ ...ok, lastSuccessAt: 9e15 }))?.lastSuccessAt).toBeNull();
  });
});

// ---------------------------------------------------------------- 台本を流す

let root = "";
const FAKE_MYSQL = `#!/bin/bash
# SELECT 1 で入れるか。FAKE_AUTH=ng なら 1045
[ "\${FAKE_AUTH:-ok}" = ok ] || { echo "ERROR 1045 (28000): Access denied for user" >&2; exit 1; }
echo 1
`;
const FAKE_DUMP = `#!/bin/bash
[ "\${FAKE_DUMP:-ok}" = ok ] || { echo "mysqldump: Got error: 2013" >&2; exit 2; }
echo "CREATE TABLE \\\`users\\\` (id int);"
for i in $(seq 1 \${FAKE_ROWS:-50}); do echo "INSERT INTO users VALUES ($i, '$RANDOM$RANDOM');"; done
`;

function setup() {
  root = mkdtempSync(join(tmpdir(), "agentcms-vps-backup-"));
  const bin = join(root, "bin");
  mkdirSync(bin);
  writeFileSync(join(bin, "mysql"), FAKE_MYSQL);
  writeFileSync(join(bin, "mysqldump"), FAKE_DUMP);
  chmodSync(join(bin, "mysql"), 0o755);
  chmodSync(join(bin, "mysqldump"), 0o755);
  const site = join(root, "site");
  mkdirSync(join(site, "shared/storage/app/public/media"), { recursive: true });
  mkdirSync(join(site, "shared/storage/app/private/backups"), { recursive: true });
  mkdirSync(join(site, "shared/storage/app/private/backup-status"), { recursive: true });
  writeFileSync(join(site, "shared/storage/app/public/media/a.jpg"), "image-bytes");
  writeFileSync(join(site, "shared/storage/app/private/backups/app.zip"), "app-zip");
  writeFileSync(join(site, "shared/storage/app/private/backup-status/auto.json"), "{}");
  mkdirSync(join(root, "backup"));
  mkdirSync(join(root, "hostlock"));
  writeFileSync(join(root, "pw"), "secretpassword123\n");
  writeFileSync(join(root, "backup.sh"), backupScript(vpsNames(cfg())));
}

function runBackup(env: Record<string, string> = {}) {
  const r = spawnSync("bash", [join(root, "backup.sh"), "now"], {
    encoding: "utf8",
    env: {
      ...process.env,
      PATH: `${join(root, "bin")}:${process.env.PATH}`,
      AGENTCMS_BACKUP_DIR: join(root, "backup"),
      AGENTCMS_BACKUP_SITE: join(root, "site"),
      AGENTCMS_BACKUP_PWFILE: join(root, "pw"),
      AGENTCMS_BACKUP_LOCK: join(root, "lock"),
      AGENTCMS_BACKUP_HOSTLOCK: join(root, "hostlock"),
      AGENTCMS_BACKUP_HOSTLOCK_WAIT: "2",
      AGENTCMS_BACKUP_KEEP: "3",
      AGENTCMS_BACKUP_MAX_BYTES: String(10 * 1048576),
      // 手元のディスクの埋まり具合に左右されないよう、空けておく割合は 0 にする（no-space の試験は別に見る）
      AGENTCMS_BACKUP_RESERVE_PCT: "0",
      ...env,
    },
  });
  const raw = existsSync(join(root, "backup/status.json"))
    ? readFileSync(join(root, "backup/status.json"), "utf8")
    : "";
  return {
    code: r.status,
    stderr: r.stderr,
    status: raw ? (JSON.parse(raw) as Record<string, unknown>) : null,
    files: readdirSync(join(root, "backup")).sort(),
  };
}
const sleep1 = () => spawnSync("sleep", ["1.1"]);

run("夜のバックアップの台本", () => {
  beforeEach(setup);
  afterEach(() => rmSync(root, { recursive: true, force: true }));

  it("DB とファイルを取り、checksum を残し、成否を status.json に書く（アプリの zip と記録は入れない）", () => {
    const r = runBackup();
    expect(r.code).toBe(0);
    expect(r.status?.state).toBe("ok");
    const id = String(r.status?.lastSuccessId);
    expect(id).toMatch(/^\d{14}$/);
    expect(r.files).toEqual([`${id}-db.sql.gz`, `${id}-files.tar.gz`, `${id}.sha256`, "status.json"]);
    expect(parseBackupStatus(Buffer.from(JSON.stringify(r.status)).toString("base64"))?.dbBytes).toBeGreaterThan(100);
    const check = spawnSync("sha256sum", ["-c", `${id}.sha256`], { cwd: join(root, "backup"), encoding: "utf8" });
    expect(check.status).toBe(0);
    const list = spawnSync("tar", ["-tzf", join(root, "backup", `${id}-files.tar.gz`)], { encoding: "utf8" }).stdout;
    expect(list).toContain("storage/app/public/media/a.jpg");
    expect(list).not.toContain("private/backups");
    expect(list).not.toContain("backup-status");
    const dump = spawnSync("sh", ["-c", `gunzip -c '${join(root, "backup", `${id}-db.sql.gz`)}'`], { encoding: "utf8" }).stdout;
    expect(dump).toContain("CREATE TABLE");
  });

  it("DB に入れなければ「db-auth」と書き、最後の成功は前の回のまま・作りかけを残さない", () => {
    const first = runBackup();
    sleep1();
    const r = runBackup({ FAKE_AUTH: "ng" });
    expect(r.code).toBe(1);
    expect(r.status).toMatchObject({ state: "failed", error: "db-auth", lastSuccessId: first.status?.lastSuccessId });
    expect(r.files.filter((f) => f.startsWith("."))).toEqual([]);
  });

  it("mysqldump が落ちたら「db-dump」", () => {
    const r = runBackup({ FAKE_DUMP: "ng" });
    expect(r.status).toMatchObject({ state: "failed", error: "db-dump", lastSuccessAt: null });
  });

  it("上限を超えたら取らずに「too-large」・作りかけを残さない", () => {
    writeFileSync(join(root, "site/shared/storage/app/public/big.bin"), Buffer.from(Array.from({ length: 300_000 }, () => Math.floor(Math.random() * 256))));
    const r = runBackup({ AGENTCMS_BACKUP_MAX_BYTES: "102400" });
    expect(r.status).toMatchObject({ state: "failed", error: "too-large" });
    expect(r.files).toEqual(["status.json"]);
  });

  it("shared・storage・app がリンクなら取らない（辿って別の場所を写さない）・中のリンクはリンクのまま", () => {
    const other = join(root, "other");
    mkdirSync(join(other, "app"), { recursive: true });
    writeFileSync(join(other, "app", "secret.txt"), "OTHER-SITE-SECRET");
    rmSync(join(root, "site/shared/storage"), { recursive: true });
    symlinkSync(other, join(root, "site/shared/storage"));
    expect(runBackup().status).toMatchObject({ state: "failed", error: "files-link" });

    rmSync(join(root, "site/shared/storage"));
    mkdirSync(join(root, "site/shared/storage/app/public"), { recursive: true });
    symlinkSync(join(other, "app", "secret.txt"), join(root, "site/shared/storage/app/public/leak"));
    sleep1();
    const r = runBackup();
    expect(r.status?.state).toBe("ok");
    const tgz = join(root, "backup", `${r.status?.lastSuccessId}-files.tar.gz`);
    const raw = spawnSync("sh", ["-c", `gunzip -c '${tgz}'`], { encoding: "utf8" }).stdout;
    expect(raw).not.toContain("OTHER-SITE-SECRET");
  });

  it("パスワードのファイルが空なら「no-db-password」・空きが足りなければ「no-space」", () => {
    writeFileSync(join(root, "pw"), "");
    expect(runBackup().status).toMatchObject({ state: "failed", error: "no-db-password" });
    writeFileSync(join(root, "pw"), "x");
    expect(runBackup({ AGENTCMS_BACKUP_MAX_BYTES: "999999999999" }).status).toMatchObject({ state: "failed", error: "no-space" });
    // 書いたあとも決めた割合が空いたまま残らないなら取らない（ほかのサイトと MySQL の分を残す）
    expect(runBackup({ AGENTCMS_BACKUP_RESERVE_PCT: "89" }).status).toMatchObject({ state: "failed", error: "no-space" });
  });

  it("新しい順に keep 個だけ残す", () => {
    const ids: string[] = [];
    for (let i = 0; i < 4; i++) {
      ids.push(String(runBackup().status?.lastSuccessId));
      sleep1();
    }
    const r = runBackup({ AGENTCMS_BACKUP_KEEP: "2" });
    const kept = r.files.filter((f) => f.endsWith("-db.sql.gz")).map((f) => f.slice(0, 14));
    expect(kept).toEqual([ids[3], String(r.status?.lastSuccessId)].sort());
  }, 30_000);

  it("同時に 2 つは動かない（このサイトの鍵・サーバーの全サイトで分け合う鍵）・鍵の置き場所が無ければ黙らず失敗と書く", () => {
    mkdirSync(join(root, "lock"));
    expect(runBackup().code).toBe(3);
    rmSync(join(root, "lock"), { recursive: true });
    // ほかのサイトの回が動いている（サーバーの鍵がある）→ 待って、取れなければ取らない（空きの確かめが重ならない）
    mkdirSync(join(root, "hostlock", "held"));
    const busy = runBackup();
    expect(busy.code).toBe(3);
    expect(busy.status).toBeNull();
    rmSync(join(root, "hostlock", "held"), { recursive: true });
    expect(runBackup().status?.state).toBe("ok");
    expect(existsSync(join(root, "hostlock", "held"))).toBe(false);
    rmSync(join(root, "hostlock"), { recursive: true });
    sleep1();
    expect(runBackup().status).toMatchObject({ state: "failed", error: "write" });
  });

  it("tar が「読んでいる間に変わった」（終了コード 1）でも成功にしない・作りかけ（エラーの出力も）を残さない", () => {
    // 偽の tar: 大量のエラーを出して 1 で終わる（中身は本物の tar で作る）
    const realTar = spawnSync("sh", ["-c", "command -v tar"], { encoding: "utf8" }).stdout.trim();
    writeFileSync(join(root, "bin", "tar"), `#!/bin/bash\nfor i in $(seq 1 5000); do echo "tar: storage/app/x$i: file changed as we read it" >&2; done\n${realTar} "$@"\nexit 1\n`);
    chmodSync(join(root, "bin", "tar"), 0o755);
    const r = runBackup();
    expect(r.status).toMatchObject({ state: "failed", error: "files" });
    expect(r.files).toEqual(["status.json"]);
  });
});

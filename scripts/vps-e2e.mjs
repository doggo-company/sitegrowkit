#!/usr/bin/env node
/**
 * VPS へ出す道の通し試験（手で流す・kit-verify には入れない・ADR-0021）。
 *
 * 本物の VPS を契約せずに、手元の Docker に「VPS の代わり」を立てる:
 * ssh で入れる Linux（docker:dind に sshd と一般の利用者 deploy を足したもの）。
 * その中で Docker が動くので、agentcms deploy は本物の VPS と同じ道を通る。
 *
 * 使い方（Docker と、`agentcms dev` で動いているプロジェクトが要る）:
 *
 *   node scripts/vps-e2e.mjs --project <プロジェクト> [--cli packages/cli/dist/index.js] [--keep]
 *
 * 確かめること（どれか 1 つでも外れたら exit 1）:
 *  1. setup（2 回目は何もしない）→ doctor → deploy → https で 200
 *  2. 更新が画面に出る（静的ファイルと PHP の両方）→ rollback で前の版に戻る
 *  3. health が落ちる版を出すと、自動で前の版へ戻る
 *  4. 2 つ目のサイトを足しても 1 つ目は 200 のまま。2 つ目の DB 利用者は 1 つ目の DB を読めない
 *  5. 同じドメイン・同じサイト名の 3 つ目は、何も変えずに止まる（1 つ目は 200 のまま）
 *  6. サーバーで生成した秘密（APP_KEY・DB パスワード・MySQL root）が、CLI の出力・
 *     コンテナの設定（docker inspect）・compose/Caddy のファイルのどこにも出ていない
 *
 * 後片付け: 立てた「VPS の代わり」（とその中身）だけを消す（--keep なら残す）。
 * プロジェクトに足した試験用のファイルと設定も元に戻す。手元の ~/.ssh は触らない。
 */
import { execFileSync, spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { createServer } from "node:net";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = fileURLToPath(new URL(".", import.meta.url));
const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : fallback;
};
const project = opt("--project") && resolve(opt("--project"));
const cli = resolve(
  opt("--cli", join(here, "..", "packages", "cli", "dist", "index.js")),
);
const keep = args.includes("--keep");
const NAME = opt("--name", "sgk-vps-e2e");
if (!project || !existsSync(join(project, "artisan"))) {
  console.error(
    "--project <agentcms dev で動いているプロジェクト> を渡してください",
  );
  process.exit(2);
}

const work = mkdtempSync(join(tmpdir(), "sgk-vps-e2e-"));
const logs = [];
let failures = 0;
const touched = []; // [path, 元の中身 or null]

function step(msg) {
  console.log(`\n=== ${msg}`);
}
function check(cond, msg) {
  console.log(`${cond ? "  ✔" : "  ✘"} ${msg}`);
  if (!cond) failures++;
  return cond;
}
function sh(cmd, a, o = {}) {
  return spawnSync(cmd, a, { encoding: "utf8", maxBuffer: 64 << 20, ...o });
}
function freePort() {
  return new Promise((res) => {
    const s = createServer();
    s.listen(0, "127.0.0.1", () => {
      const p = s.address().port;
      s.close(() => res(p));
    });
  });
}
function writeTouched(path, content) {
  if (!touched.some(([p]) => p === path))
    touched.push([path, existsSync(path) ? readFileSync(path, "utf8") : null]);
  mkdirSync(join(path, ".."), { recursive: true });
  writeFileSync(path, content);
}

// ---------------------------------------------------------------- 「VPS の代わり」
const ports = {
  ssh: await freePort(),
  http: await freePort(),
  https: await freePort(),
};
const key = join(work, "key");
sh("ssh-keygen", ["-q", "-t", "ed25519", "-N", "", "-f", key, "-C", "vps-e2e"]);
const knownHosts = join(work, "known_hosts");
const ctx = join(work, "standin");
mkdirSync(ctx);
writeFileSync(join(ctx, "authorized_keys"), readFileSync(`${key}.pub`));
writeFileSync(
  join(ctx, "Dockerfile"),
  `FROM docker:28-dind
RUN apk add --no-cache openssh bash tar gzip coreutils \\
 && adduser -D -s /bin/bash deploy && passwd -u deploy 2>/dev/null || true
RUN (getent group docker || addgroup docker) && addgroup deploy docker && ssh-keygen -A \\
 && printf "PasswordAuthentication no\\nPermitRootLogin no\\nAllowUsers deploy\\n" >> /etc/ssh/sshd_config
COPY authorized_keys /home/deploy/.ssh/authorized_keys
RUN chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys
ENTRYPOINT ["/bin/sh","-c","/usr/sbin/sshd && exec dockerd-entrypoint.sh"]
`,
);

function cleanup() {
  for (const [p, c] of touched.reverse()) {
    if (c === null) rmSync(p, { force: true });
    else writeFileSync(p, c);
  }
  if (!keep) {
    sh("docker", ["rm", "-f", "-v", NAME]);
    rmSync(work, { recursive: true, force: true });
  } else {
    console.log(
      `\n--keep: ${NAME} を残しました（ssh -p ${ports.ssh} -i ${key} deploy@127.0.0.1）`,
    );
  }
}
process.on("SIGINT", () => {
  cleanup();
  process.exit(130);
});

step(
  `「VPS の代わり」を立てる（${NAME}・ssh ${ports.ssh} / https ${ports.https}）`,
);
if (sh("docker", ["inspect", NAME]).status === 0) {
  console.error(
    `${NAME} は既にあります。消すか --name で別の名前にしてください（ほかのコンテナには触らない）`,
  );
  process.exit(2);
}
let r = sh("docker", ["build", "-q", "-t", `${NAME}:image`, ctx]);
if (!check(r.status === 0, "image を作れた")) {
  console.error(r.stderr);
  cleanup();
  process.exit(1);
}
r = sh("docker", [
  "run",
  "-d",
  "--privileged",
  "--name",
  NAME,
  "-e",
  "DOCKER_TLS_CERTDIR=",
  "-p",
  `127.0.0.1:${ports.ssh}:22`,
  "-p",
  `127.0.0.1:${ports.http}:80`,
  "-p",
  `127.0.0.1:${ports.https}:443`,
  `${NAME}:image`,
]);
check(r.status === 0, "起動した");
for (let i = 0; i < 60; i++) {
  if (sh("docker", ["exec", NAME, "docker", "info"]).status === 0) break;
  await new Promise((res) => setTimeout(res, 1000));
}
// docs/deployment/vps.md §2 の「一度だけ root で」と同じ
sh("docker", [
  "exec",
  NAME,
  "install",
  "-d",
  "-o",
  "deploy",
  "-g",
  "deploy",
  "/srv/agentcms",
]);
// ホスト鍵を 1 回だけ受け入れる（xserver.md §1 と同じ）。手元の ~/.ssh/known_hosts は使わない
const hostKey = sh("docker", [
  "exec",
  NAME,
  "cat",
  "/etc/ssh/ssh_host_ed25519_key.pub",
]).stdout.trim();
writeFileSync(knownHosts, `[127.0.0.1]:${ports.ssh} ${hostKey}\n`);

// CLI の ssh / scp に試験用の known_hosts を持たせる包み（CLI のコードは変えない）
const bin = join(work, "bin");
mkdirSync(bin);
for (const tool of ["ssh", "scp"]) {
  const real = execFileSync("sh", ["-c", `command -v ${tool}`], {
    encoding: "utf8",
  }).trim();
  writeFileSync(
    join(bin, tool),
    `#!/bin/sh\nexec ${real} -o UserKnownHostsFile=${knownHosts} -o StrictHostKeyChecking=yes "$@"\n`,
  );
  chmodSync(join(bin, tool), 0o755);
}
function remote(command) {
  return sh(join(bin, "ssh"), [
    "-p",
    String(ports.ssh),
    "-i",
    key,
    "-o",
    "BatchMode=yes",
    "deploy@127.0.0.1",
    command,
  ]);
}

/** 台本を標準入力で流す（秘密を引数に載せない） */
function remoteIn(script, input = "") {
  return sh(
    join(bin, "ssh"),
    [
      "-p",
      String(ports.ssh),
      "-i",
      key,
      "-o",
      "BatchMode=yes",
      "deploy@127.0.0.1",
      "bash -s",
    ],
    { input: input ? `${script}\n${input}` : script },
  );
}
// 試験の間ずっと、サーバーの全プロセスの引数を 0.2 秒ごとに書き残す（終わってから秘密が無いかを見る）
sh("docker", [
  "exec",
  "-d",
  NAME,
  "sh",
  "-c",
  "while :; do ps -eo args >> /tmp/ps-sample.log; sleep 0.2; done",
]);

// ---------------------------------------------------------------- 設定と CLI
const caFile = join(work, "caddy-root.crt");
function deployConfig(target, site, host) {
  writeTouched(
    join(project, ".agentcms", "deploy", `${target}.json`),
    JSON.stringify(
      {
        provider: "vps",
        target,
        ssh: {
          host: "127.0.0.1",
          port: ports.ssh,
          user: "deploy",
          identityFile: key,
        },
        app: { url: `https://${host}:${ports.https}`, healthPath: "/healthz" },
        keepReleases: 3,
        vps: { site, tls: "internal" },
      },
      null,
      2,
    ),
  );
}
function agentcms(label, a) {
  const env = { ...process.env, PATH: `${bin}:${process.env.PATH}` };
  if (existsSync(caFile)) env.NODE_EXTRA_CA_CERTS = caFile;
  const res = sh(process.execPath, [cli, ...a], {
    cwd: project,
    env,
    timeout: 60 * 60_000,
  });
  const out = `${res.stdout}\n${res.stderr}`;
  logs.push(
    `### ${label}: agentcms ${a.join(" ")}  → exit ${res.status}\n${out}`,
  );
  const last = out.trim().split("\n").slice(-3).join(" / ");
  console.log(
    `  $ agentcms ${a.join(" ")} → exit ${res.status}  …${last.slice(-200)}`,
  );
  return { code: res.status, out };
}
async function get(host, path) {
  const res = sh(
    process.execPath,
    [
      "-e",
      `
    fetch(${JSON.stringify(`https://${host}:${ports.https}${path}`)}, { redirect: "manual" })
      .then(async (r) => console.log(JSON.stringify({ status: r.status, body: (await r.text()).slice(0, 400) })))
      .catch((e) => console.log(JSON.stringify({ status: 0, body: String(e.cause ?? e) })));
  `,
    ],
    { env: { ...process.env, NODE_EXTRA_CA_CERTS: caFile } },
  );
  try {
    return JSON.parse(res.stdout.trim().split("\n").pop());
  } catch {
    return { status: 0, body: res.stderr };
  }
}
function release(v) {
  writeTouched(join(project, "public", "e2e-version.txt"), `${v}\n`);
  writeTouched(
    join(project, "public", "e2e-version.php"),
    `<?php echo 'php-${v}';\n`,
  );
}

const S1 = { target: "staging", site: "e2e-one", host: "site1.localhost" };
const S2 = { target: "stg-two", site: "e2e-two", host: "site2.localhost" };

try {
  // ------------------------------------------------------------ 1. setup → deploy
  step("1. サイト 1: setup → doctor → deploy");
  deployConfig(S1.target, S1.site, S1.host);
  release("v1");
  let a = agentcms("setup-1", ["deploy", "setup", "-t", S1.target, "-y"]);
  if (!check(a.code === 0, "setup が exit 0")) throw new Error("setup failed");
  a = agentcms("setup-1-again", ["deploy", "setup", "-t", S1.target, "-y"]);
  check(
    a.code === 0 && a.out.includes("準備は済んでいます"),
    "2 回目の setup は何もしない",
  );
  a = agentcms("doctor-1", ["deploy", "doctor", "-t", S1.target]);
  check(a.code === 0, "doctor が exit 0");

  // Caddy の自前の認証局（tls internal）の root を、手元の fetch が信じられるようにする
  remote("docker exec agentcms-caddy caddy trust >/dev/null 2>&1; true");
  for (let i = 0; i < 20 && !existsSync(caFile); i++) {
    const ca = remote(
      "docker exec agentcms-caddy cat /data/caddy/pki/authorities/local/root.crt",
    );
    if (ca.status === 0 && ca.stdout.includes("BEGIN CERTIFICATE"))
      writeFileSync(caFile, ca.stdout);
    else await new Promise((res) => setTimeout(res, 1000));
  }
  check(existsSync(caFile), "Caddy の root 証明書を取れた（試験だけで使う）");

  a = agentcms("deploy-1", ["deploy", "staging", "-t", S1.target, "-y"]);
  check(a.code === 0, "deploy が exit 0");
  let g = await get(S1.host, "/");
  check(g.status === 200, `https://${S1.host}/ が 200（${g.status}）`);
  g = await get(S1.host, "/healthz");
  check(
    g.status === 200 && g.body.includes('"ok":true'),
    `/healthz が ok（${g.body.slice(0, 80)}）`,
  );
  check(
    (await get(S1.host, "/e2e-version.txt")).body.trim() === "v1",
    "静的ファイルが v1",
  );
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v1",
    "PHP が v1",
  );

  // ------------------------------------------------------------ 1b. 管理者
  step("1b. 管理者を作ってログインできる（docs/deployment/vps.md §6 の手順）");
  const adminEmail = "admin@example.test";
  const adminPassword = `A${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}9!`;
  // 1 行目でパスワードを読み、2 行目がそのパスワード（bash -s は台本を 1 行ずつ読む）
  const seed = remoteIn(
    `IFS= read -r ADMIN_PASSWORD; export ADMIN_PASSWORD
${adminPassword}
docker exec -e ADMIN_EMAIL=${adminEmail} -e ADMIN_PASSWORD -e APP_CONFIG_CACHE=/tmp/agentcms-seed-config.php -w /srv/agentcms/sites/${S1.site}/current agentcms-${S1.site}-app php artisan db:seed --class=AdminUserSeeder --force 2>&1
echo "rc=$?"
exit 0`,
  );
  check(
    seed.stdout.includes("rc=0"),
    `AdminUserSeeder が exit 0（${seed.stdout.trim().split("\n")[0]}）`,
  );
  const login = sh(
    process.execPath,
    [
      "-e",
      `
      const base = ${JSON.stringify(`https://${S1.host}:${ports.https}`)};
      (async () => {
        const r1 = await fetch(base + "/login", { redirect: "manual" });
        const jar = r1.headers.getSetCookie().map((c) => c.split(";")[0]);
        const xsrf = decodeURIComponent((jar.find((c) => c.startsWith("XSRF-TOKEN=")) ?? "=").split("=").slice(1).join("="));
        const post = (password) => fetch(base + "/login", {
          method: "POST", redirect: "manual",
          headers: { cookie: jar.join("; "), "x-xsrf-token": xsrf, "content-type": "application/x-www-form-urlencoded", accept: "text/html" },
          body: new URLSearchParams({ email: process.env.E2E_EMAIL, password }),
        });
        const bad = await post("wrong-password");
        const good = await post(process.env.E2E_PASSWORD);
        console.log(JSON.stringify({ bad: [bad.status, bad.headers.get("location")], good: [good.status, good.headers.get("location")] }));
      })().catch((e) => console.log(JSON.stringify({ error: String(e) })));
    `,
    ],
    {
      env: {
        ...process.env,
        NODE_EXTRA_CA_CERTS: caFile,
        E2E_EMAIL: adminEmail,
        E2E_PASSWORD: adminPassword,
      },
    },
  );
  let lr = {};
  try {
    lr = JSON.parse(login.stdout.trim().split("\n").pop());
  } catch {
    lr = { error: login.stderr };
  }
  check(
    lr.bad?.[0] === 302 &&
      /\/login$/.test(lr.bad?.[1] ?? "") &&
      lr.good?.[0] === 302 &&
      !/\/login$/.test(lr.good?.[1] ?? "/login"),
    `違うパスワードは /login へ戻され、正しいパスワードでは先へ進む（${JSON.stringify(lr)}）`,
  );

  // ------------------------------------------------------------ 2. 更新 → rollback
  step("2. 更新 → rollback");
  await new Promise((res) => setTimeout(res, 1100)); // release id は秒単位
  release("v2");
  a = agentcms("deploy-2", ["deploy", "staging", "-t", S1.target, "-y"]);
  check(a.code === 0, "2 回目の deploy が exit 0");
  check(
    (await get(S1.host, "/e2e-version.txt")).body.trim() === "v2",
    "静的ファイルが v2 に変わった",
  );
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v2",
    "PHP も v2 に変わった（古いコードが残らない）",
  );
  a = agentcms("rollback", ["deploy", "rollback", "-t", S1.target, "-y"]);
  check(a.code === 0, "rollback が exit 0");
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v1",
    "rollback で PHP が v1 に戻った",
  );
  check((await get(S1.host, "/")).status === 200, "rollback のあとも / が 200");

  // ------------------------------------------------------------ 3. 落ちる版 → 自動で戻る
  step("3. health が落ちる版を出す → 自動で前の版へ戻る");
  await new Promise((res) => setTimeout(res, 1100));
  release("v3-broken");
  const healthCtl = join(
    project,
    "app",
    "Http",
    "Controllers",
    "HealthController.php",
  );
  const orig = readFileSync(healthCtl, "utf8");
  writeTouched(
    healthCtl,
    orig.replace(
      /public function __invoke\([^)]*\)[^{]*\{/,
      (m) => `${m}\n        abort(503); // vps-e2e: わざと落とす`,
    ),
  );
  check(
    readFileSync(healthCtl, "utf8").includes("abort(503)"),
    "health をわざと落とした",
  );
  a = agentcms("deploy-broken", ["deploy", "staging", "-t", S1.target, "-y"]);
  check(
    a.code !== 0 && a.out.includes("旧 release へ切り戻しました"),
    "deploy は失敗し、自動で切り戻した",
  );
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v1",
    "前の版（v1）のまま",
  );
  check(
    (await get(S1.host, "/healthz")).status === 200,
    "/healthz は 200 のまま",
  );
  writeFileSync(healthCtl, orig);

  step(
    "3b. migration が落ちる版 → 切り替えず、その版は rollback の候補にも残らない",
  );
  await new Promise((res) => setTimeout(res, 1100));
  release("v3b-migfail");
  const failMig = join(
    project,
    "database",
    "migrations",
    "2099_01_01_000000_vps_e2e_fail.php",
  );
  writeTouched(
    failMig,
    "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration {\n    public function up(): void { throw new RuntimeException('vps-e2e: わざと落とす'); }\n    public function down(): void {}\n};\n",
  );
  a = agentcms("deploy-migfail", ["deploy", "staging", "-t", S1.target, "-y"]);
  check(
    a.code !== 0 && a.out.includes("migration 失敗"),
    "migration で止まった（exit≠0）",
  );
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v1",
    "切り替えていない（v1 のまま）",
  );
  rmSync(failMig, { force: true });
  await new Promise((res) => setTimeout(res, 1100));
  release("v4");
  a = agentcms("deploy-4", ["deploy", "staging", "-t", S1.target, "-y"]);
  check(a.code === 0, "直した版（v4）の deploy が exit 0");
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v4",
    "v4 が出た",
  );
  const unmarked = remote(
    `cd /srv/agentcms/sites/${S1.site}/releases && for d in *; do [ -f "$d/.agentcms-healthy" ] || echo "$d"; done`,
  ).stdout.trim();
  check(
    unmarked === "",
    `health まで通らなかった release（v3・v3b）は片付けられた（残り: ${unmarked || "なし"}）`,
  );
  a = agentcms("rollback-2", ["deploy", "rollback", "-t", S1.target, "-y"]);
  const afterRb = (await get(S1.host, "/e2e-version.php")).body.trim();
  check(
    a.code === 0 && afterRb === "php-v2",
    `rollback は health まで通った 1 つ前（v2）へ戻る（${afterRb}）`,
  );

  // ------------------------------------------------------------ 4. 2 つ目のサイト
  step("4. サイト 2 を足す（1 つ目は壊れない・DB は分かれている）");
  deployConfig(S2.target, S2.site, S2.host);
  release("v1-two");
  a = agentcms("setup-2", ["deploy", "setup", "-t", S2.target, "-y"]);
  check(a.code === 0, "サイト 2 の setup が exit 0");
  a = agentcms("deploy-two", [
    "deploy",
    "staging",
    "-t",
    S2.target,
    "--confirm-target",
    S2.target,
    "-y",
  ]);
  check(a.code === 0, "サイト 2 の deploy が exit 0");
  check(
    (await get(S2.host, "/e2e-version.php")).body.trim() === "php-v1-two",
    "サイト 2 が自分の版を返す",
  );
  check(
    (await get(S1.host, "/e2e-version.php")).body.trim() === "php-v2",
    "サイト 1 は自分の版（v2）のまま",
  );
  check(
    (await get(S1.host, "/healthz")).status === 200,
    "サイト 1 の /healthz は 200 のまま",
  );
  const cross = remote(
    `cd /srv/agentcms/sites/${S2.site} && grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2- | docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" mysql -h 127.0.0.1 -u sgk-e2e-two -D sgk-e2e-one -e "SELECT COUNT(*) FROM users"' 2>&1; echo "rc=$?"`,
  );
  check(
    /denied/i.test(cross.stdout) && !cross.stdout.includes("rc=0"),
    `サイト 2 の DB 利用者はサイト 1 の DB を読めない（${cross.stdout.trim().split("\n")[0]}）`,
  );
  const own = remote(
    `cd /srv/agentcms/sites/${S2.site} && grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2- | docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" mysql -h 127.0.0.1 -u sgk-e2e-two -D sgk-e2e-two -N -e "SELECT COUNT(*) FROM migrations"' 2>&1; echo "rc=$?"`,
  );
  check(
    own.stdout.includes("rc=0"),
    "サイト 2 の DB 利用者は自分の DB は読める",
  );

  // ------------------------------------------------------------ 5. ぶつかる 3 つ目
  step("5. 同じドメイン・同じサイト名の 3 つ目は止まる（何も変えない）");
  const before = remote(
    "cat /srv/agentcms/edge/sites/*.caddy | sha256sum",
  ).stdout;
  deployConfig("stg-dup", "e2e-dup", S1.host);
  a = agentcms("deploy-dup-domain", [
    "deploy",
    "staging",
    "-t",
    "stg-dup",
    "--confirm-target",
    "stg-dup",
    "-y",
  ]);
  check(
    a.code !== 0 && a.out.includes("e2e-one"),
    "同じドメインは「サイト e2e-one が使っています」で止まる",
  );
  deployConfig("stg-same", S1.site, "other.localhost");
  a = agentcms("deploy-dup-site", [
    "deploy",
    "staging",
    "-t",
    "stg-same",
    "--confirm-target",
    "stg-same",
    "-y",
  ]);
  check(
    a.code !== 0 && a.out.includes(S1.host),
    "同じサイト名・別ドメインは止まる",
  );
  check(
    remote("cat /srv/agentcms/edge/sites/*.caddy | sha256sum").stdout ===
      before,
    "Caddy の行は 1 行も変わっていない",
  );
  check(
    (await get(S1.host, "/healthz")).status === 200,
    "サイト 1 は 200 のまま",
  );

  // ------------------------------------------------------------ 6. アプリの backup がコンテナで動く
  step(
    "6. アプリの backup（毎日の自動 backup と同じ道）がサイトのコンテナで動く",
  );
  const bk = remote(
    `docker exec -w /srv/agentcms/sites/${S1.site}/current agentcms-${S1.site}-app php artisan agentcms:backup 2>&1; echo "rc=$?"`,
  );
  check(
    bk.stdout.includes("rc=0"),
    `php artisan agentcms:backup が exit 0（${bk.stdout.trim().split("\n").slice(-2, -1)[0] ?? ""}）`,
  );
  const sched = remote(
    `docker inspect -f '{{.State.Running}}' agentcms-${S1.site}-scheduler`,
  );
  check(sched.stdout.trim() === "true", "scheduler のコンテナが動いている");

  step(
    "6b. DB をバックアップから戻せる（docs/deployment/vps.md §8 の手順そのまま）",
  );
  const sql = (q) =>
    remote(
      `cd /srv/agentcms/sites/${S1.site} && grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2- | docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec mysql -h 127.0.0.1 -u sgk-e2e-one -D sgk-e2e-one -N -e "$1"' sh ${JSON.stringify(q)} 2>&1`,
    ).stdout.trim();
  const rows = Number(sql("SELECT COUNT(*) FROM migrations"));
  sql("CREATE TABLE e2e_after_backup (id INT)");
  sql("DELETE FROM migrations");
  check(
    Number(sql("SELECT COUNT(*) FROM migrations")) === 0 &&
      sql("SHOW TABLES LIKE 'e2e_after_backup'") === "e2e_after_backup",
    `バックアップの後に表を足し、migrations を空にした（${rows} → 0 行）`,
  );
  const docs = readFileSync(
    join(here, "..", "docs", "deployment", "vps.md"),
    "utf8",
  );
  const sec = docs.slice(docs.indexOf("## 8."), docs.indexOf("## 9."));
  const restoreScript = /```bash\n([\s\S]*?)```/.exec(sec)?.[1] ?? "";
  check(
    restoreScript.includes("DROP DATABASE"),
    "§8 のスクリプトを手順書から取り出せた（手を加えずに流す）",
  );
  remote(
    `cat > /srv/agentcms/restore-db.sh << 'E2E_EOS'\n${restoreScript}E2E_EOS`,
  );
  const restore = (id) =>
    remote(
      `cd /srv/agentcms && bash restore-db.sh ${S1.site} ${id} 2>&1; echo "rc=$?"`,
    ).stdout;
  const appUp = () =>
    remote(
      `docker inspect -f '{{.State.Running}}' agentcms-${S1.site}-app`,
    ).stdout.trim() === "true";

  // 壊す前に止まること（無い・壊れている）。アプリは動いたまま、DB はそのまま
  let out = restore("29990101000000");
  check(
    !out.includes("rc=0") && out.includes("バックアップがありません"),
    "無い番号では何も変えずに止まる",
  );
  check(
    appUp() && Number(sql("SELECT COUNT(*) FROM migrations")) === 0,
    "アプリは動いたまま・DB はそのまま",
  );
  remote(
    `cd /srv/agentcms/sites/${S1.site} && printf 'not a gzip' > backups/29990101000001-db.sql.gz`,
  );
  out = restore("29990101000001");
  check(
    !out.includes("rc=0"),
    "壊れたバックアップでは何も変えずに止まる（gzip -t）",
  );
  check(
    appUp() &&
      sql("SHOW TABLES LIKE 'e2e_after_backup'") === "e2e_after_backup",
    "アプリは動いたまま・DB はそのまま",
  );
  remote(
    `rm -f /srv/agentcms/sites/${S1.site}/backups/29990101000001-db.sql.gz`,
  );

  // deploy・rollback と同時に動かない（同じ鍵）
  remote(
    `mkdir /srv/agentcms/sites/${S1.site}/.agentcms-deploy.lock && echo "e2e-hold" > /srv/agentcms/sites/${S1.site}/.agentcms-deploy.lock/info`,
  );
  out = restore(
    remote(
      `cd /srv/agentcms/sites/${S1.site}/backups && ls | grep -E '^[0-9]{14}-db.sql.gz$' | tail -1 | cut -c1-14`,
    ).stdout.trim(),
  );
  check(
    !out.includes("rc=0") && out.includes("e2e-hold"),
    "deploy などが動いている間は、何も変えずに止まる",
  );
  check(
    appUp() && Number(sql("SELECT COUNT(*) FROM migrations")) === 0,
    "アプリは動いたまま・DB はそのまま",
  );
  remote(`rm -r /srv/agentcms/sites/${S1.site}/.agentcms-deploy.lock`);

  // 本物のバックアップで戻す
  const curBefore = remote(
    `basename "$(readlink /srv/agentcms/sites/${S1.site}/current)"`,
  ).stdout.trim();
  const id = remote(
    `cd /srv/agentcms/sites/${S1.site}/backups && ls | grep -E '^[0-9]{14}-db.sql.gz$' | tail -1 | cut -c1-14`,
  ).stdout.trim();
  const rel = remote(
    `cat /srv/agentcms/sites/${S1.site}/backups/${id}-db.release`,
  ).stdout.trim();
  out = restore(id);
  check(
    out.includes("rc=0"),
    `§8 のスクリプトで ${id} から戻した（${out.trim().split("\n").slice(-2, -1)[0] ?? ""}）`,
  );
  check(
    rows > 0 && Number(sql("SELECT COUNT(*) FROM migrations")) === rows,
    `migrations が ${rows} 行に戻った`,
  );
  check(
    sql("SHOW TABLES LIKE 'e2e_after_backup'") === "",
    "バックアップの後に足した表は消えた",
  );
  const cur = remote(
    `basename "$(readlink /srv/agentcms/sites/${S1.site}/current)"`,
  ).stdout.trim();
  check(
    /^\d{14}$/.test(rel) && cur === rel,
    `コードも DB を取ったときの release（${rel}）に合わせた（current=${cur}）`,
  );
  check(
    remote(
      `ls /srv/agentcms/sites/${S1.site}/backups | grep -c '^before-restore-.*-db.sql.gz$'`,
    ).stdout.trim() === "1",
    "戻す前の DB を backups/before-restore-* に取っておいた",
  );
  const waitHealthy = async () => {
    let hz = 0;
    for (let i = 0; i < 20 && hz !== 200; i++) {
      hz = (await get(S1.host, "/healthz")).status;
      if (hz !== 200) await new Promise((res) => setTimeout(res, 1000));
    }
    return hz;
  };
  check(
    (await waitHealthy()) === 200,
    "戻してアプリを起こしたあと /healthz は 200",
  );

  // 戻したのを取り消す（before-restore-<時刻> を渡す）→ DB もコードも戻す前へ
  const undo = remote(
    `cd /srv/agentcms/sites/${S1.site}/backups && ls | grep -E '^before-restore-[0-9]{14}-db.sql.gz$' | tail -1 | sed 's/-db.sql.gz$//'`,
  ).stdout.trim();
  out = restore(undo);
  check(out.includes("rc=0"), `取り消し（${undo}）も通った`);
  check(
    Number(sql("SELECT COUNT(*) FROM migrations")) === 0 &&
      sql("SHOW TABLES LIKE 'e2e_after_backup'") === "e2e_after_backup",
    "DB は戻す前（表あり・migrations 0 行）に戻った",
  );
  const curUndo = remote(
    `basename "$(readlink /srv/agentcms/sites/${S1.site}/current)"`,
  ).stdout.trim();
  check(
    curUndo === curBefore,
    `コードも戻す前の release（${curBefore}）に戻った（current=${curUndo}）`,
  );
  // 試験の続きのために、もう一度バックアップから戻しておく
  out = restore(id);
  check(
    out.includes("rc=0") && (await waitHealthy()) === 200,
    "もう一度戻して、/healthz は 200",
  );

  // ------------------------------------------------------------ 6c. サイトの分離
  step("6c. サイトの分離（別のサイトから PHP-FPM にもファイルにも届かない）");
  const probe = remote(
    `docker exec agentcms-${S2.site}-app php -r 'var_dump(@fsockopen("agentcms-${S1.site}-app", 9000, $e, $m, 3));' 2>&1`,
  ).stdout.trim();
  check(
    probe.includes("bool(false)"),
    `サイト 2 からサイト 1 の 9000 へはつながらない（${probe}）`,
  );
  const sock = remote(
    `test -S /srv/agentcms/sites/${S1.site}/run/php-fpm.sock && echo socket`,
  ).stdout.trim();
  check(
    sock === "socket",
    "サイト 1 の PHP-FPM は置き場所の中のソケットで待っている",
  );
  const seen = remote(
    `docker exec agentcms-${S2.site}-app sh -c 'ls /srv/agentcms/sites/${S1.site} 2>&1 || true'`,
  ).stdout.trim();
  check(
    /No such file/.test(seen),
    `サイト 2 のコンテナからサイト 1 の置き場所は見えない（${seen.split("\n")[0]}）`,
  );

  // あるサイトの中に、別のサイトの秘密を指す symlink を置かれても、配信されない
  const s2pw = remote(
    `grep -E '^DB_PASSWORD=' /srv/agentcms/sites/${S2.site}/shared/.env | cut -d= -f2-`,
  ).stdout.trim();
  remote(
    `ln -sfn /srv/agentcms/sites/${S2.site}/shared/.env /srv/agentcms/sites/${S1.site}/shared/storage/app/public/leak.txt && ` +
      `ln -sfn /srv/agentcms/sites/${S2.site}/current/bootstrap/cache/config.php /srv/agentcms/sites/${S1.site}/shared/storage/app/public/leak2.txt`,
  );
  for (const f of ["leak.txt", "leak2.txt"]) {
    const g = await get(S1.host, `/storage/${f}`);
    check(
      g.status !== 200 && s2pw.length >= 16 && !g.body.includes(s2pw),
      `サイト 1 に置いた symlink（${f}）からサイト 2 の秘密は配信されない（${g.status}）`,
    );
  }
  remote(
    `rm -f /srv/agentcms/sites/${S1.site}/shared/storage/app/public/leak.txt /srv/agentcms/sites/${S1.site}/shared/storage/app/public/leak2.txt`,
  );

  // ------------------------------------------------------------ 6d. edge をそろえる
  step(
    "6d. edge の compose が古い形なら、setup がそろえる（サイトは 200 のまま戻る）",
  );
  remote("printf '# 古い形のつもり\\n' >> /srv/agentcms/edge/compose.yml");
  a = agentcms("setup-upgrade", ["deploy", "setup", "-t", S1.target, "-y"]);
  check(
    a.code === 0 && a.out.includes("edge_upgrade"),
    "setup が edge をそろえた（exit 0）",
  );
  check(
    remote(
      "test -f /srv/agentcms/edge/compose.yml.prev && ! grep -q '古い形のつもり' /srv/agentcms/edge/compose.yml && echo ok",
    ).stdout.trim() === "ok",
    "前の compose は compose.yml.prev に残り、今の compose はキットの形",
  );
  a = agentcms("setup-after-upgrade", [
    "deploy",
    "setup",
    "-t",
    S1.target,
    "-y",
  ]);
  check(
    a.code === 0 && a.out.includes("準備は済んでいます"),
    "そろえたあとの setup は何もしない",
  );
  check((await waitHealthy()) === 200, "サイト 1 は 200 に戻る");
  let s2ok = 0;
  for (let i = 0; i < 20 && s2ok !== 200; i++) {
    s2ok = (await get(S2.host, "/healthz")).status;
    if (s2ok !== 200) await new Promise((res) => setTimeout(res, 1000));
  }
  check(s2ok === 200, "サイト 2 も 200 に戻る");

  // ------------------------------------------------------------ 7. 秘密が漏れていない
  step("7. サーバーで生成した秘密が、出力・コンテナ設定・ファイルに出ていない");
  const secrets = [];
  for (const s of [S1.site, S2.site]) {
    const env = remote(
      `grep -E '^(APP_KEY|DB_PASSWORD)=' /srv/agentcms/sites/${s}/shared/.env`,
    ).stdout;
    for (const line of env.split("\n")) {
      const v = line.slice(line.indexOf("=") + 1).trim();
      if (v.length >= 16) secrets.push(v);
    }
  }
  secrets.push(adminPassword);
  const rootPw = remote(
    "cat /srv/agentcms/edge/secrets/mysql-root",
  ).stdout.trim();
  if (rootPw) secrets.push(rootPw);
  check(
    secrets.length === 6,
    `秘密を 6 つ読めた（APP_KEY×2・DB パスワード×2・管理者・MySQL root）: ${secrets.length}`,
  );
  const envMode = remote(
    `stat -c '%a' /srv/agentcms/sites/${S1.site}/shared/.env /srv/agentcms/edge/secrets/mysql-root`,
  )
    .stdout.trim()
    .split("\n");
  check(
    envMode.every((m) => m === "600"),
    `.env と root パスワードは 600（${envMode.join(",")}）`,
  );
  const surfaces = {
    "CLI の出力（全部）": logs.join("\n"),
    "docker inspect（全コンテナ）": remote("docker inspect $(docker ps -aq)")
      .stdout,
    "compose・Caddy のファイル": remote(
      "cat /srv/agentcms/edge/compose.yml /srv/agentcms/edge/Caddyfile /srv/agentcms/edge/sites/*.caddy /srv/agentcms/sites/*/compose.yml",
    ).stdout,
    "サーバーの ps（試験の間ずっと 0.2 秒ごと）": sh("docker", [
      "exec",
      NAME,
      "cat",
      "/tmp/ps-sample.log",
    ]).stdout,
  };
  for (const [name, text] of Object.entries(surfaces)) {
    const hits = secrets.filter((s) => text.includes(s)).length;
    check(
      text.length > 0 && hits === 0,
      `${name} に秘密が 0 件（${hits} 件・${text.length} 文字を見た）`,
    );
  }
} catch (e) {
  check(false, `途中で止まった: ${e.message}`);
} finally {
  const logFile = opt("--log");
  if (logFile) writeFileSync(logFile, logs.join("\n\n"));
  cleanup();
}

console.log(`\n${failures === 0 ? "通った" : `${failures} 件が外れた`}`);
process.exit(failures === 0 ? 0 : 1);

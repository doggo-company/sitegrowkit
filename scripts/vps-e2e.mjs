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
 *  6. サイトのコンテナの中から置いた値（shared/.env.pending）やリンク（compose.yml・web の設定・
 *     ソケットの置き場所・印・記録・releases・backups・tmp）が、MySQL の root の SQL・ほかのサイト・
 *     サーバーのファイルに届かない（見張りの DB が消えない・権限が足されない・リンクの先が変わらない・
 *     MySQL の root パスワードがサイトのコンテナに見えない）
 *  6f. サイトのコンテナの一時領域（/tmp・/dev/shm・ソケットの置き場所・書ける層）は大きさが決まっていて、
 *     サーバーのディスク（Docker の置き場所・control）を埋められない
 *  6g. サイトの中から夜のバックアップの成功を装えない（成否はサーバーの backup のコンテナが control に書き、
 *     doctor はそちらを読む。サイトが DB のパスワードを変えて落とすと「失敗」と出る・上限・リンクを辿らない）
 *  6h. 置き場所に書ける者がコードを書き換えると doctor が気づき、rollback は送った artifact から作り直す
 *  6i. サイトのコンテナ（app・scheduler）は、コード・bootstrap/cache・shared/.env・置き場所を書き換えられない
 *     （書けるのは shared/storage と /tmp だけ）。出し直し・戻しのあとに、コンテナが書いた物も、
 *     コンテナの中に残したプロセスも、storage に置いたメンテナンスの印（PHP）も残らない。
 *     管理画面での画像の追加・プロフィールの保存は動く
 *  7. サーバーで生成した秘密（APP_KEY・DB パスワード・MySQL root）が、CLI の出力・
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
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

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
    `#!/bin/sh\n${tool === "scp" ? 'if [ -n "$E2E_SCP_FAIL" ]; then echo "e2e: 送信をわざと落とす" >&2; exit 1; fi\n' : ""}exec ${real} -o UserKnownHostsFile=${knownHosts} -o StrictHostKeyChecking=yes "$@"\n`,
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
function agentcms(label, a, extraEnv = {}) {
  const env = {
    ...process.env,
    ...extraEnv,
    PATH: `${bin}:${process.env.PATH}`,
  };
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
    a.out.includes("送った artifact から作り直しました"),
    "rollback は戻す先を、置き場所の中の物ではなく送った artifact（control の中）から作り直した",
  );
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
    a.code !== 0 && a.out.includes("旧 release へ切り戻しました") && a.out.includes("送った artifact から作り直しました"),
    "deploy は失敗し、自動で切り戻した（戻す先は置き場所の中の物ではなく、送った artifact から作り直した）",
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
    `mkdir /srv/agentcms/control/${S1.site}/deploy.lock && echo "e2e-hold" > /srv/agentcms/control/${S1.site}/deploy.lock/info`,
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
  remote(`rm -r /srv/agentcms/control/${S1.site}/deploy.lock`);

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
  const epochBefore = Number(sql("SELECT COALESCE(MAX(session_epoch), 0) FROM users"));
  out = restore(id);
  check(
    out.includes("rc=0"),
    `§8 のスクリプトで ${id} から戻した（${out.trim().split("\n").slice(-2, -1)[0] ?? ""}）`,
  );
  const epochAfter = Number(sql("SELECT COALESCE(MAX(session_epoch), 0) FROM users"));
  check(
    Number.isInteger(epochBefore) && epochAfter > epochBefore && sql("SELECT COUNT(*) FROM users WHERE remember_token IS NOT NULL") === "0",
    `戻したあと、全員のログインを切った（session_epoch ${epochBefore} → ${epochAfter}・remember_token なし）`,
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

  // バックアップの中身はサイトが書き換えられる。MySQL のクライアントのコマンド（\! でシェル・system）を
  // 仕込んでも、MySQL のコンテナ（root のパスワードと全サイトのデータがある）で動かない
  const relNow = remote(
    `basename "$(readlink /srv/agentcms/sites/${S1.site}/current)"`,
  ).stdout.trim();
  remote(
    `cd /srv/agentcms/sites/${S1.site}/backups && printf '%s\\n' 'CREATE TABLE e2e_evil (id INT);' '\\! touch /tmp/sgk-e2e-pwned' 'system touch /tmp/sgk-e2e-pwned2' | gzip > 29990101000003-db.sql.gz && echo ${relNow} > 29990101000003-db.release`,
  );
  out = restore("29990101000003");
  const pwned = remote(
    "docker exec agentcms-mysql sh -c 'ls /tmp/sgk-e2e-pwned* 2>/dev/null | wc -l'",
  ).stdout.trim();
  check(
    !out.includes("rc=0") && pwned === "0",
    `クライアントのコマンドを仕込んだバックアップは戻さずに止まり、MySQL のコンテナで何も動かない（rc≠0・作られたファイル ${pwned} 件）`,
  );
  const undo2 = remote(
    `cd /srv/agentcms/sites/${S1.site}/backups && ls | grep -E '^before-restore-[0-9]{14}-db.sql.gz$' | tail -1 | sed 's/-db.sql.gz$//'`,
  ).stdout.trim();
  out = restore(undo2);
  check(
    out.includes("rc=0") &&
      Number(sql("SELECT COUNT(*) FROM migrations")) === rows &&
      (await waitHealthy()) === 200,
    `戻す前（${undo2}）へ戻して、/healthz は 200`,
  );
  remote(
    `rm -f /srv/agentcms/sites/${S1.site}/backups/29990101000003-db.*`,
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
  const probeWeb = remote(
    `docker exec agentcms-${S2.site}-app php -r 'var_dump(@fsockopen("agentcms-${S1.site}-web", 8080, $e, $m, 3));' 2>&1`,
  ).stdout.trim();
  check(
    probeWeb.includes("bool(false)"),
    `サイト 2 からサイト 1 の web（8080）へ直接つながらない（Caddy を通さずに X-Forwarded-For を偽れない）（${probeWeb}）`,
  );
  const lim = remote(
    `docker inspect -f '{{.HostConfig.Memory}} {{.HostConfig.PidsLimit}} {{.HostConfig.NanoCpus}} {{index .HostConfig.LogConfig.Config "max-size"}}' agentcms-${S1.site}-app agentcms-${S1.site}-web agentcms-${S1.site}-scheduler`,
  )
    .stdout.trim()
    .split("\n");
  check(
    lim.length === 3 && lim.every((l) => l === "805306368 256 1000000000 10m"),
    `サイトのコンテナに上限（メモリ 768MB・プロセス 256・CPU 1・ログ 10MB）（${lim.join(" / ")}）`,
  );
  const sock = remote(
    `docker exec agentcms-${S1.site}-web test -S /run/agentcms/php-fpm.sock && echo socket; test -e /srv/agentcms/control/${S1.site}/run && echo host-run; docker volume inspect -f '{{index .Options "type"}}' agentcms-site-${S1.site}_run`,
  ).stdout.trim();
  check(
    sock === "socket\ntmpfs",
    `サイト 1 の PHP-FPM は、大きさを決めた tmpfs のソケットで待っている（サーバーのディスクの control/run は無い）（${sock.replace(/\n/g, " ")}）`,
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
    "6d. 前の形から移る（送信が落ちても止まらない・出し直してから edge をそろえる）",
  );
  // 前の形（Caddy がサイトのファイルを直接配信し、PHP-FPM のソケットへつなぐ）を作る:
  // edge の Caddy にサイトの置き場所を見せ、サイト 1 の行を前の形にして、サイト 1 の web を止める。
  // （前の形のソケットはサイトの置き場所の中にあった。いまの PHP-FPM は control の中で待つので、
  //   試験の前の形の行はそちらへつなぐ。確かめたいのは edge を移る道で、ソケットの場所ではない）
  const kitCompose = remote("cat /srv/agentcms/edge/compose.yml").stdout;
  const oldCompose = kitCompose
    .replace(/\n    labels:\n      agentcms\.root: "[^"]*"/, "")
    .replace(
      "      - ./sites:/etc/caddy/sites:ro\n",
      "      - ./sites:/etc/caddy/sites:ro\n      - /srv/agentcms/sites:/srv/agentcms/sites:ro\n" +
        `      - agentcms-site-${S1.site}_run:/srv/agentcms/control/${S1.site}/run:ro\n`,
    )
    .replace(
      "\n  mysql-data:\n",
      `\n  mysql-data:\n  agentcms-site-${S1.site}_run:\n    external: true\n`,
    );
  const oldSite = `# agentcms-domain: ${S1.host}\n${S1.host} {\n\ttls internal\n\troot * /srv/agentcms/sites/${S1.site}/current/public\n\tencode zstd gzip\n\tphp_fastcgi unix//srv/agentcms/control/${S1.site}/run/php-fpm.sock {\n\t\tresolve_root_symlink\n\t}\n\tfile_server\n}\n`;
  const made = remote(
    `cd /srv/agentcms/edge && cp compose.yml compose.yml.e2e-new && cat > compose.yml << 'E2E_EOS'\n${oldCompose}E2E_EOS\n` +
      `cat > sites/${S1.site}.caddy << 'E2E_EOS'\n${oldSite}E2E_EOS\n` +
      `docker compose up -d --quiet-pull >/dev/null 2>&1 && sleep 2 && ` +
      `docker exec agentcms-caddy caddy reload --adapter caddyfile --config /etc/caddy/Caddyfile >/dev/null 2>&1 && ` +
      `docker stop agentcms-${S1.site}-web >/dev/null && echo old-ready`,
  );
  check(
    made.stdout.includes("old-ready") && oldCompose !== kitCompose,
    "前の形の edge とサイト 1 の行を作った",
  );
  check(
    (await waitHealthy()) === 200,
    "前の形（Caddy が直接配信・サイト 1 の web は止めた）でサイト 1 は 200",
  );

  // 前の形の行が残っていると、setup は edge を変えずに止まる
  a = agentcms("setup-old-sites", ["deploy", "setup", "-t", S1.target, "-y"]);
  check(
    a.code !== 0 && a.out.includes(`${S1.site}.caddy`),
    "前の形のサイトの行があると、edge を変えずに止まる",
  );
  check(
    remote(
      "grep -c '/srv/agentcms/sites:/srv/agentcms/sites:ro' /srv/agentcms/edge/compose.yml",
    ).stdout.trim() === "1",
    "edge の compose は変わっていない",
  );
  check((await waitHealthy()) === 200, "サイト 1 は 200 のまま");

  // 移る途中で送信が落ちても、入口は前の形のまま（サイトは止まらない）
  await new Promise((res) => setTimeout(res, 1100));
  a = agentcms(
    "deploy-migrate-scp-fail",
    ["deploy", "staging", "-t", S1.target, "-y"],
    { E2E_SCP_FAIL: "1" },
  );
  check(
    a.code !== 0 && a.out.includes("scp 失敗"),
    "送信が落ちて deploy は止まった",
  );
  check(
    remote(
      `grep -c php_fastcgi /srv/agentcms/edge/sites/${S1.site}.caddy`,
    ).stdout.trim() === "1",
    "Caddy の行は前の形のまま",
  );
  check(
    (await waitHealthy()) === 200,
    "サイト 1 は 200 のまま（入口が止まっていない）",
  );

  // 今のキットで出し直すと、サイトの web 経由に移る
  await new Promise((res) => setTimeout(res, 1100));
  a = agentcms("deploy-migrate", ["deploy", "staging", "-t", S1.target, "-y"]);
  check(a.code === 0, "今のキットで出し直した（exit 0）");
  check(
    remote(
      `grep -c 'reverse_proxy agentcms-${S1.site}-web:8080' /srv/agentcms/edge/sites/${S1.site}.caddy`,
    ).stdout.trim() === "1",
    "Caddy の行はサイトの web への転送になった",
  );
  check((await waitHealthy()) === 200, "サイト 1 は 200");
  a = agentcms("setup-upgrade", ["deploy", "setup", "-t", S1.target, "-y"]);
  check(
    a.code === 0 && a.out.includes("edge_upgrade"),
    "setup が edge をそろえた（exit 0）",
  );
  check(
    remote(
      "test -f /srv/agentcms/edge/compose.yml.prev && ! grep -q '/srv/agentcms/sites:/srv/agentcms/sites' /srv/agentcms/edge/compose.yml && cmp -s /srv/agentcms/edge/compose.yml /srv/agentcms/edge/compose.yml.e2e-new && echo ok",
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

  // ------------------------------------------------------------ 6e. サイトの中から特権の処理へ届かない
  // サイトの置き場所に書ける者（前の版のキットのサイトのコンテナなど）が置いた値や
  // リンクを、setup・deploy（docker グループの利用者・MySQL の root）が信じないこと。
  step(
    "6e. サイトの中に置いた値・リンクが、MySQL の root・ほかのサイト・サーバーのファイルに届かない",
  );
  // SQL は標準入力で渡す（バッククォートをシェルに解かせない）
  const rootQ = (q) => {
    const r = sh(
      join(bin, "ssh"),
      [
        "-p",
        String(ports.ssh),
        "-i",
        key,
        "-o",
        "BatchMode=yes",
        "deploy@127.0.0.1",
        `docker exec -i agentcms-mysql sh -c 'MYSQL_PWD="$(cat /run/agentcms/mysql-root)" exec mysql -uroot -N' 2>&1`,
      ],
      { input: `${q};\n` },
    );
    return `${r.stdout}`.trim();
  };
  // サイト 2 のコンテナ（app・scheduler）の中で流す（＝サイトの PHP ができること）
  const inS2c = (service, script, input = "") =>
    sh(
      join(bin, "ssh"),
      [
        "-p",
        String(ports.ssh),
        "-i",
        key,
        "-o",
        "BatchMode=yes",
        "deploy@127.0.0.1",
        // 台本は単引用符で包む（サーバーのシェルに $ を解かせない）
        `docker exec -i -w /srv/agentcms/sites/${S2.site} agentcms-${S2.site}-${service} sh -c '${script.replaceAll("'", `'\\''`)}'`,
      ],
      { input },
    );
  const inS2 = (script, input = "") => inS2c("app", script, input);
  // サイト 2 の置き場所に書ける者（前の版のキットのサイトのコンテナ・手作業の誤りなど）が置く。
  // いまのサイトのコンテナは storage の外を書けない（6i で確かめる）が、サーバーの処理が
  // 置き場所の中の物を信じないことは、それとは別に確かめ続ける
  const plantS2 = (script, input = "") =>
    sh(
      join(bin, "ssh"),
      [
        "-p",
        String(ports.ssh),
        "-i",
        key,
        "-o",
        "BatchMode=yes",
        "deploy@127.0.0.1",
        `docker run --rm -i --pull never --network none --user "$(id -u):$(id -g)" -v /srv/agentcms/sites/${S2.site}:/srv/agentcms/sites/${S2.site} -w /srv/agentcms/sites/${S2.site} --entrypoint sh agentcms-php:8.5-2 -c '${script.replaceAll("'", `'\\''`)}'`,
      ],
      { input },
    );
  const s2 = `/srv/agentcms/sites/${S2.site}`;
  const s1 = `/srv/agentcms/sites/${S1.site}`;
  rootQ(
    "CREATE DATABASE IF NOT EXISTS `sgk-e2e-canary`; CREATE TABLE IF NOT EXISTS `sgk-e2e-canary`.t (x INT)",
  );
  check(
    rootQ(
      "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='sgk-e2e-canary'",
    ) === "1",
    "見張りの DB（sgk-e2e-canary）を root で作った",
  );

  // (a) shared/.env.pending の値を、setup が MySQL の root の SQL に入れない
  const payload =
    "x'; DROP DATABASE `sgk-e2e-canary`; GRANT SELECT ON `sgk-e2e-one`.* TO 'sgk-e2e-two'@'%'; -- ";
  const planted = plantS2(
    "set -e; cd shared; mv .env .env.e2e-saved; cat > .env.pending; echo planted",
    `DB_PASSWORD=${payload}\nAPP_KEY=base64:${Buffer.alloc(32, 7).toString("base64")}\n`,
  );
  check(
    planted.stdout.includes("planted"),
    "置き場所に書ける者が、サイト 2 の shared/.env を外し、.env.pending を置けた（前の版のキットではサイトのコンテナができたこと）",
  );
  a = agentcms("setup-poisoned", ["deploy", "setup", "-t", S2.target, "-y"]);
  console.log(`    （setup の終了コード ${a.code}）`);
  check(
    rootQ(
      "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='sgk-e2e-canary'",
    ) === "1",
    "見張りの DB は消えていない（.env.pending の値が root の SQL に入っていない）",
  );
  const grants = rootQ("SHOW GRANTS FOR 'sgk-e2e-two'@'%'");
  check(
    !grants.includes("sgk-e2e-one"),
    `サイト 2 の利用者にサイト 1 の DB の権限が足されていない（${grants.replace(/\n/g, " / ").slice(0, 160)}）`,
  );
  plantS2(
    "cd shared && rm -f .env.pending && mv .env.e2e-saved .env && echo restored",
  );
  const conns = rootQ(
    "SELECT CONCAT(user, '=', max_user_connections) FROM mysql.user WHERE user LIKE 'sgk-e2e-%' ORDER BY user",
  ).replace(/\n/g, " ");
  check(
    conns === "sgk-e2e-one=30 sgk-e2e-two=30",
    `サイトの DB 利用者に同時接続の上限 30（共有の MySQL の接続枠を 1 サイトで使い切らない）（${conns}）`,
  );
  const crossAfter = remote(
    `cd ${s2} && grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2- | docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" mysql -h 127.0.0.1 -u sgk-e2e-two -D sgk-e2e-one -N -e "SELECT COUNT(*) FROM users"' 2>&1; echo "rc=$?"`,
  );
  check(
    !crossAfter.stdout.includes("rc=0"),
    `その後もサイト 2 の利用者はサイト 1 の DB を読めない（${crossAfter.stdout.trim().split("\n")[0]}）`,
  );

  // (b) 置き場所の中のファイルをリンクに差し替えても、deploy がサーバーのファイルを書き換えない・
  //     サーバーの秘密をサイトのコンテナに見せない
  remote(
    "mkdir -p /srv/agentcms/e2e-canary/tmpdir && for f in marker compose nginx history; do echo keep > /srv/agentcms/e2e-canary/$f; chmod 600 /srv/agentcms/e2e-canary/$f; done",
  );
  const canarySum = () =>
    remote(
      "cd /srv/agentcms/e2e-canary && ls -la tmpdir | wc -l && stat -c '%a %s' marker compose nginx history && cat marker compose nginx history | sha256sum",
    ).stdout.trim();
  const before6e = canarySum();
  const linked = plantS2(
    [
      "set -e",
      "for f in .agentcms-site compose.yml nginx.conf releases.history run; do if [ -e $f ] || [ -L $f ]; then mv $f $f.e2e-saved; fi; done",
      "ln -s /srv/agentcms/e2e-canary/marker .agentcms-site",
      "ln -s /srv/agentcms/e2e-canary/compose compose.yml",
      "ln -s /srv/agentcms/e2e-canary/nginx nginx.conf",
      "ln -s /srv/agentcms/e2e-canary/history releases.history",
      "ln -s /srv/agentcms/edge/secrets run",
      "echo linked",
    ].join("; "),
  );
  check(
    linked.stdout.includes("linked"),
    `置き場所に書ける者が、サイト 2 の置き場所のファイル・ディレクトリをサーバーの物へのリンクに差し替えた${linked.stdout.includes("linked") ? "" : `（${`${linked.stdout}${linked.stderr}`.trim().slice(-200)}）`}`,
  );
  // 前の版で作った利用者（上限なし）にも、deploy が接続の上限を付け直す
  rootQ("ALTER USER 'sgk-e2e-two'@'%' WITH MAX_USER_CONNECTIONS 0");
  release("v3-two");
  a = agentcms("deploy-linked", [
    "deploy",
    "staging",
    "-t",
    S2.target,
    "--confirm-target",
    S2.target,
    "-y",
  ]);
  console.log(`    （deploy の終了コード ${a.code}）`);
  check(
    canarySum() === before6e,
    "リンクの先（サーバーのファイル 4 つ）は変わっていない",
  );
  const conn2 = rootQ(
    "SELECT max_user_connections FROM mysql.user WHERE user='sgk-e2e-two'",
  );
  check(
    conn2 === "30",
    `上限の無かった利用者にも、deploy が同時接続の上限 30 を付け直した（${conn2}）`,
  );
  const rootPwNow = remote(
    "cat /srv/agentcms/edge/secrets/mysql-root",
  ).stdout.trim();
  remote(
    `docker restart agentcms-${S2.site}-app >/dev/null 2>&1; sleep 2`,
  );
  const seenRoot = remote(
    `docker exec agentcms-${S2.site}-app sh -c 'cat /run/agentcms/mysql-root 2>/dev/null; ls /run/agentcms 2>&1'`,
  ).stdout;
  check(
    rootPwNow.length >= 16 && !seenRoot.includes(rootPwNow),
    `サイト 2 のコンテナを起こし直しても、MySQL の root パスワードは見えない（/run/agentcms: ${seenRoot.replace(rootPwNow, "<root の値>").trim().split("\n").slice(-3).join(" ")}）`,
  );
  check(
    (await get(S1.host, "/healthz")).status === 200,
    "サイト 1 の /healthz は 200 のまま",
  );
  plantS2(
    [
      "rm -f .agentcms-site compose.yml nginx.conf releases.history run",
      "for f in .agentcms-site compose.yml nginx.conf releases.history run; do [ -e $f.e2e-saved ] && mv $f.e2e-saved $f || true; done",
      "echo unlinked",
    ].join("; "),
  );

  // (c) releases・backups・tmp をほかのサイト・サーバーの物へのリンクに差し替えても、そこへ書かない・消さない
  const s1List = () =>
    remote(
      `ls -1 ${s1}/releases ${s1}/backups /srv/agentcms/e2e-canary/tmpdir | sha256sum`,
    ).stdout.trim();
  const s1Before = s1List();
  const linked2 = plantS2(
    [
      "set -e",
      "mv releases releases.e2e-saved",
      "mv backups backups.e2e-saved",
      "mv tmp tmp.e2e-saved",
      `ln -s ${s1}/releases releases`,
      `ln -s ${s1}/backups backups`,
      "ln -s /srv/agentcms/e2e-canary/tmpdir tmp",
      "echo linked",
    ].join("; "),
  );
  check(
    linked2.stdout.includes("linked"),
    `${linked2.stdout.includes("linked") ? "" : `（${`${linked2.stdout}${linked2.stderr}`.trim().slice(-200)}）`}` +
    "サイト 2 の releases・backups・tmp をサイト 1・サーバーの物へのリンクに差し替えた",
  );
  release("v4-two");
  a = agentcms("deploy-linked2", [
    "deploy",
    "staging",
    "-t",
    S2.target,
    "--confirm-target",
    S2.target,
    "-y",
  ]);
  console.log(`    （deploy の終了コード ${a.code}）`);
  check(
    s1List() === s1Before,
    "サイト 1 の releases・backups とサーバーの一時ディレクトリは変わっていない（書かれも消されもしない）",
  );
  check(
    (await get(S1.host, "/healthz")).status === 200,
    "サイト 1 の /healthz は 200 のまま",
  );
  plantS2(
    "rm -f releases backups tmp; mv releases.e2e-saved releases; mv backups.e2e-saved backups; mv tmp.e2e-saved tmp; echo unlinked",
  );
  release("v5-two");
  a = agentcms("deploy-after-6e", [
    "deploy",
    "staging",
    "-t",
    S2.target,
    "--confirm-target",
    S2.target,
    "-y",
  ]);
  check(a.code === 0, "リンクを戻したあと、サイト 2 は普通に deploy できる");
  check(
    (await get(S2.host, "/e2e-version.php")).body.trim() === "php-v5-two",
    "サイト 2 が新しい版を返す",
  );
  // (d) サイトの置き場所を読む使い捨てのコンテナは、サイトが置いた FIFO で待たされても、
  //     サーバーの上の時間の上限で終わって消える（手元の CLI の時間切れに頼らない）
  const { siteFsCommand } = await import(
    pathToFileURL(join(dirname(cli), "deploy", "providers", "vps.js")).href
  );
  const { vpsNames } = await import(
    pathToFileURL(join(dirname(cli), "deploy", "providers", "vps-assets.js"))
      .href
  );
  plantS2("mkfifo backups/e2e-fifo && echo made");
  const psBefore = remote("docker ps -aq | wc -l").stdout.trim();
  const t0 = Date.now();
  const fifo = remote(
    `${siteFsCommand(vpsNames({ vps: { site: S2.site }, app: { url: `https://${S2.host}` } }), "head -c 32 backups/e2e-fifo", { timeoutSec: 3 })} < /dev/null; echo "rc=$?"`,
  ).stdout;
  const waited = Math.round((Date.now() - t0) / 1000);
  const psAfter = remote("docker ps -aq | wc -l").stdout.trim();
  const fifoRc = Number(/rc=(\d+)/.exec(fifo)?.[1] ?? -1);
  check(
    // 125〜127 は docker が動かせなかった（待っていない）。実際に 3 秒待ってから切られたことを見る
    fifoRc > 0 &&
      ![125, 126, 127].includes(fifoRc) &&
      waited >= 3 &&
      waited < 30 &&
      psAfter === psBefore,
    `サイトが置いた FIFO を読まされても、使い捨てのコンテナはサーバーの上で 3 秒で終わって消えた（${waited} 秒・rc=${fifoRc}・コンテナの数 ${psBefore} → ${psAfter}）`,
  );
  plantS2("rm -f backups/e2e-fifo");
  rootQ("DROP DATABASE IF EXISTS `sgk-e2e-canary`");

  // ------------------------------------------------------------ 6f. 一時領域でサーバーのディスクを埋められない
  // サイトの PHP（app・scheduler）は、自分の置き場所の外にも書ける場所を持ちうる: コンテナの書ける層
  // （/tmp・/var/tmp など＝Docker の置き場所＝MySQL と同じディスク）と、PHP-FPM のソケットの置き場所。
  // どれも大きさを決めた場所だけにして、サーバーのディスクを使わせない
  step(
    "6f. サイトのコンテナの一時領域（/tmp・ソケットの置き場所・書ける層）で、サーバーのディスクを埋められない",
  );
  const inC = (container, script) =>
    remote(
      `docker exec -w /srv/agentcms/sites/${S2.site} ${container} sh -c '${script.replaceAll("'", `'\\''`)}' 2>&1`,
    );
  const ctlKb = () =>
    Number(
      remote(`du -sk /srv/agentcms/control/${S2.site} | cut -f1`).stdout.trim(),
    );
  const ctlBefore = ctlKb();
  for (const k of ["app", "scheduler"]) {
    const c = `agentcms-${S2.site}-${k}`;
    // 書けた量は消す前に測る（書けた分がディスクに残っている間に、書ける層とサーバーの側を見る）
    const fill = inC(
      c,
      [
        "w() { dd if=/dev/zero of=$1 bs=1048576 count=$2 2>/dev/null; printf \"%s=%s \" $1 $(($(wc -c < $1 2>/dev/null || echo 0) / 1048576)); }",
        "w /tmp/e2e-fill 200",
        "w /var/tmp/e2e-fill 50",
        "w /run/agentcms/e2e-fill 50",
        "w /dev/shm/e2e-fill 100",
        "w /e2e-fill 20",
        "echo",
      ].join("; "),
    ).stdout.trim();
    const mb = (p) => Number(new RegExp(`(?:^|\\s)${p}=(\\d+)`).exec(fill)?.[1] ?? -1);
    const rw = Number(
      remote(`docker inspect --size -f '{{.SizeRw}}' ${c}`).stdout.trim(),
    );
    const ctlNow = ctlKb();
    inC(
      c,
      "rm -f /tmp/e2e-fill /var/tmp/e2e-fill /run/agentcms/e2e-fill /dev/shm/e2e-fill /e2e-fill",
    );
    check(
      mb("/tmp/e2e-fill") >= 0 && mb("/tmp/e2e-fill") <= 64,
      `${k}: /tmp に 200MB 書こうとしても 64MB までで止まる（書けた ${mb("/tmp/e2e-fill")}MB）`,
    );
    check(
      mb("/var/tmp/e2e-fill") <= 0 && mb("/e2e-fill") <= 0,
      `${k}: /var/tmp・/ には書けない（書けた ${mb("/var/tmp/e2e-fill")}MB・${mb("/e2e-fill")}MB）`,
    );
    check(
      mb("/run/agentcms/e2e-fill") <= 1 && mb("/dev/shm/e2e-fill") <= 16,
      `${k}: ソケットの置き場所は 1MB・/dev/shm は 16MB までで止まる（書けた ${mb("/run/agentcms/e2e-fill")}MB・${mb("/dev/shm/e2e-fill")}MB）`,
    );
    check(
      Number.isFinite(rw) && rw >= 0 && rw < 16 * 1048576,
      `${k}: 書いている最中も、コンテナの書ける層（Docker の置き場所のディスク）は 16MB 未満（${Math.round(rw / 1048576)}MB）`,
    );
    check(
      Number.isFinite(ctlNow) && ctlNow - ctlBefore < 4096,
      `${k}: 書いている最中も、サーバーの control/${S2.site} は増えない（${ctlBefore}KB → ${ctlNow}KB）`,
    );
  }
  check(
    (await get(S2.host, "/healthz")).status === 200,
    "書き込みのあと、サイト 2 の /healthz は 200",
  );

  // ------------------------------------------------------------ 6g. 夜のバックアップの成功を装えない
  // 成否はサイトの手が届かない control/<site>/backup にサーバー（サイトのコードを使わない別のコンテナ）が書き、
  // doctor はそちらを読む。サイトが自分の置き場所に「成功」の記録と zip を置いても、何も変わらない
  step(
    "6g. サイトの中から夜のバックアップの成功を装えない（成否はサーバーが control に書く）",
  );
  const bkDir = `/srv/agentcms/control/${S2.site}/backup`;
  const bkC = `agentcms-${S2.site}-backup`;
  const bkStatus = () => {
    const raw = remote(`cat ${bkDir}/status.json 2>/dev/null`).stdout.trim();
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  };
  const bkNow = (extra = "") =>
    remote(
      `docker exec ${extra} ${bkC} bash /agentcms/backup.sh now 2>&1; echo "rc=$?"`,
    ).stdout;
  // 立ち上がったときに 1 回取る（記録が無いとき）
  let st0 = null;
  for (let i = 0; i < 60 && st0?.state !== "ok"; i++) {
    st0 = bkStatus();
    if (st0?.state !== "ok") await new Promise((res) => setTimeout(res, 2000));
  }
  check(
    st0?.state === "ok" && /^\d{14}$/.test(st0?.lastSuccessId ?? ""),
    `サーバーのバックアップのコンテナが、立ち上がって最初の 1 回を取った（${JSON.stringify(st0)?.slice(0, 200)}）`,
  );
  const firstId = st0?.lastSuccessId ?? "x";
  check(
    remote(
      `cd ${bkDir} && gzip -t ${firstId}-db.sql.gz && gzip -t ${firstId}-files.tar.gz && sha256sum -c --quiet ${firstId}.sha256 && echo sums-ok`,
    ).stdout.trim() === "sums-ok",
    `control/${S2.site}/backup に DB とファイルの写しがあり、checksum が合う（${firstId}・最初の回は migration の前に取るので表は無い）`,
  );
  check(
    /No such file/.test(
      inS2("ls /srv/agentcms/control 2>&1; ls /agentcms 2>&1").stdout,
    ),
    "サイトのコンテナから control（サーバーのバックアップと成否の記録）は見えない",
  );
  // 乗っ取られたサイトができること: 自分の DB のパスワードを変えてサーバーのバックアップを落とし、
  // アプリの記録（auto.json）に「成功」を書き、本物らしい zip を置く
  const s2pwNow = remote(
    `grep -E '^DB_PASSWORD=' ${s2}/shared/.env | cut -d= -f2-`,
  ).stdout.trim();
  const evilPw = `E2e${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`.slice(0, 32);
  // パスワードは標準入力で渡す（コマンドの引数・環境変数の代入をコマンド行に載せると ps に出る）
  const alter = (from, to) =>
    inS2(
      `IFS= read -r FROM; IFS= read -r TO; export FROM TO; php -r '$p = new PDO("mysql:host=agentcms-mysql;dbname=sgk-${S2.site}", "sgk-${S2.site}", getenv("FROM")); $p->exec("ALTER USER CURRENT_USER() IDENTIFIED BY \\x27" . getenv("TO") . "\\x27"); echo "altered";' 2>&1`,
      `${from}\n${to}\n`,
    ).stdout;
  check(
    alter(s2pwNow, evilPw).includes("altered"),
    "サイト 2 の中から、自分の DB の利用者のパスワードを変えた（サイトの PHP ができること）",
  );
  const nowSec = Math.floor(Date.now() / 1000);
  const forged = inS2(
    [
      "set -e",
      "d=shared/storage/app/private",
      "mkdir -p $d/backup-status $d/backups",
      `printf '%s' '{"version":1,"state":"ok","started_at":${nowSec - 60},"finished_at":${nowSec},"backup":"e2e-forged","stage":null,"reason":null,"last_success_at":${nowSec},"armed_at":null}' > $d/backup-status/auto.json`,
      "head -c 65536 /dev/urandom > $d/backups/e2e-forged.zip",
      "echo forged",
    ].join("; "),
  ).stdout;
  check(
    forged.includes("forged"),
    "サイト 2 の中から、アプリの記録（auto.json）に「成功」を書き、zip を置いた",
  );
  const appView = remote(
    `docker exec -w ${s2}/current agentcms-${S2.site}-app php artisan agentcms:backup-offsite --list 2>&1; echo "rc=$?"`,
  ).stdout;
  console.log(
    `    （参考: サイトの中の表示（アプリのコード）は乗っ取られたサイトの言い分になる: ${appView.replace(/\s+/g, " ").slice(-160)}）`,
  );
  out = bkNow();
  const stBad = bkStatus();
  check(
    !out.includes("rc=0") &&
      stBad?.state === "failed" &&
      stBad?.error === "db-auth" &&
      stBad?.lastSuccessId === firstId,
    `サーバーのバックアップは「失敗（db-auth）」と書き、最後の成功は前の回のまま（${JSON.stringify(stBad)?.slice(0, 200)}）`,
  );
  a = agentcms("doctor-backup-failed", ["deploy", "doctor", "-t", S2.target]);
  check(
    /WARN[\s\S]{0,400}夜のバックアップ|夜のバックアップ[\s\S]{0,400}失敗/.test(a.out) &&
      a.out.includes("db-auth"),
    "doctor は control の記録を読み、夜のバックアップの失敗を出す（サイトの auto.json の「成功」は使わない）",
  );
  // 記録をサイトの中から書けない（コンテナに入っていない）・サーバーの記録はサイトの文を含まない
  check(
    !JSON.stringify(stBad ?? {}).includes("e2e-forged"),
    "サーバーの記録に、サイトが書いた文（e2e-forged）は入っていない",
  );
  check(
    alter(evilPw, s2pwNow).includes("altered"),
    "パスワードを元に戻した",
  );
  // 大きすぎる中身は、決めた上限で止まり、途中のファイルを残さない（サーバーのディスクを埋めない）
  inS2(
    "head -c 3145728 /dev/urandom > shared/storage/app/public/e2e-big.bin && echo big",
  );
  out = bkNow("-e AGENTCMS_BACKUP_MAX_BYTES=1048576");
  const stBig = bkStatus();
  const leftovers = remote(
    `ls -A ${bkDir} | grep -vE '^([0-9]{14}-(db.sql|files.tar).gz|[0-9]{14}.sha256|status.json)$' | wc -l`,
  ).stdout.trim();
  check(
    !out.includes("rc=0") && stBig?.error === "too-large" && leftovers === "0",
    `上限（1MB）を超える中身は「too-large」で止まり、作りかけを残さない（${stBig?.error}・残り ${leftovers} 件）`,
  );
  // サイトが置いたリンクは辿らない（リンクのまま写す・中身は入らない）
  inS2(
    "rm -f shared/storage/app/public/e2e-big.bin; ln -sfn /srv/agentcms/edge/secrets/mysql-root shared/storage/app/public/e2e-link; ln -sfn /agentcms/db-password shared/storage/app/public/e2e-link2; echo linked",
  );
  out = bkNow();
  const stOk = bkStatus();
  const okId = stOk?.lastSuccessId ?? "x";
  const rootPwBk = remote(
    "cat /srv/agentcms/edge/secrets/mysql-root",
  ).stdout.trim();
  const inTar = remote(
    `cd ${bkDir} && tar -tzvf ${okId}-files.tar.gz | grep -c 'e2e-link.* -> ' ; zcat ${okId}-files.tar.gz | grep -c -F -e ${JSON.stringify(rootPwBk)} -e ${JSON.stringify(s2pwNow)} || true; zcat ${okId}-db.sql.gz | grep -c 'CREATE TABLE'`,
  )
    .stdout.trim()
    .split("\n");
  check(
    out.includes("rc=0") &&
      stOk?.state === "ok" &&
      okId !== firstId &&
      inTar[0] === "2" &&
      inTar[1] === "0" &&
      Number(inTar[2]) > 0,
    `元に戻すと取れる（DB の表 ${inTar[2]} 個）。サイトが置いたリンクはリンクのまま写し、先の秘密は入らない（${okId}・リンク ${inTar[0]} 件・秘密 ${inTar[1]} 件）`,
  );
  inS2(
    "rm -f shared/storage/app/public/e2e-link shared/storage/app/public/e2e-link2 shared/storage/app/private/backups/e2e-forged.zip shared/storage/app/private/backup-status/auto.json",
  );
  // サーバーの写しから DB を戻す（§8 のスクリプトに server-<時刻>。サイトの置き場所を通さない）
  const sqlS2 = (q) =>
    remote(
      `cd ${s2} && grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2- | docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec mysql -h 127.0.0.1 -u sgk-e2e-two -D sgk-e2e-two -N -e "$1"' sh ${JSON.stringify(q)} 2>&1`,
    ).stdout.trim();
  sqlS2("CREATE TABLE e2e_after_srv (id INT)");
  check(sqlS2("SHOW TABLES LIKE 'e2e_after_srv'") === "e2e_after_srv", "サーバーの写しの後に、サイト 2 の DB に表を足した");
  out = remote(
    `cd /srv/agentcms && bash restore-db.sh ${S2.site} server-${okId} 2>&1; echo "rc=$?"`,
  ).stdout;
  let s2hz = 0;
  for (let i = 0; i < 20 && s2hz !== 200; i++) {
    s2hz = (await get(S2.host, "/healthz")).status;
    if (s2hz !== 200) await new Promise((res) => setTimeout(res, 1000));
  }
  check(
    out.includes("rc=0") && sqlS2("SHOW TABLES LIKE 'e2e_after_srv'") === "" && s2hz === 200,
    `§8 のスクリプトに server-${okId} を渡すと、サーバーの写しから戻した（足した表は消え、/healthz は ${s2hz}）（${out.trim().split("\n").slice(-2, -1)[0] ?? ""}）`,
  );
  remote(`cp ${bkDir}/${okId}.sha256 /tmp/e2e-sha && sed -i 's/^./0/' ${bkDir}/${okId}.sha256`);
  out = remote(
    `cd /srv/agentcms && bash restore-db.sh ${S2.site} server-${okId} 2>&1; echo "rc=$?"`,
  ).stdout;
  remote(`cp /tmp/e2e-sha ${bkDir}/${okId}.sha256 && rm -f /tmp/e2e-sha`);
  check(
    !out.includes("rc=0") && out.includes("checksum") && (await get(S2.host, "/healthz")).status === 200,
    "checksum の合わない写しでは、何も変えずに止まる（アプリは動いたまま）",
  );

  // ------------------------------------------------------------ 6h. 公開中のコードの書き換えに気づく・rollback は送った物から作り直す
  step(
    "6h. 置き場所に書ける者がコードを書き換えると doctor が気づき、rollback は送った artifact から作り直す",
  );
  a = agentcms("doctor-clean-code", ["deploy", "doctor", "-t", S2.target]);
  check(
    /公開中のコード[^\n]*\n?[^\n]*送った物と同じ/.test(a.out) || (a.out.includes("公開中のコード") && !a.out.includes("違います")),
    "書き換える前の doctor は、公開中のコードを送った物と同じと言う",
  );
  // 置き場所に書ける者（前の版のキットでは乗っ取られたサイトのコンテナ）が、いまの release と戻し先の古い release の両方にバックドアを置く
  const tampered = plantS2(
    [
      "set -e",
      "for d in releases/*/; do printf '%s' '<?php echo \"e2e-backdoor\";' > \"$d\"public/e2e-backdoor.php; done",
      "printf '%s' '<?php echo \"php-tampered\";' > current/public/e2e-version.php",
      "echo tampered",
    ].join("; "),
  ).stdout;
  check(
    tampered.includes("tampered") && (await get(S2.host, "/e2e-backdoor.php")).body.includes("e2e-backdoor"),
    "置き場所に書ける者が、サイト 2 のいまの release と古い release にバックドアを置き、いまのコードを書き換えた（配信されている）",
  );
  a = agentcms("doctor-tampered", ["deploy", "doctor", "-t", S2.target]);
  check(
    /中身の違い 1 か所・足された物 1 個/.test(a.out),
    `doctor は、公開中のコードが送った物と違うことに気づいた（${(/中身の違い[^）]*）/.exec(a.out) ?? ["見つからない"])[0]}）`,
  );
  a = agentcms("rollback-tampered", ["deploy", "rollback", "-t", S2.target, "--confirm-target", S2.target, "-y"]);
  const afterRb2 = await get(S2.host, "/e2e-version.php");
  check(
    a.code === 0 && a.out.includes("送った artifact から作り直しました") &&
      (await get(S2.host, "/e2e-backdoor.php")).status === 404 &&
      /^php-v\d-two$/.test(afterRb2.body.trim()),
    `rollback は戻し先を送った物から作り直したので、古い release に置いたバックドアは消えた（/e2e-version.php: ${afterRb2.body.trim().slice(0, 40)}）`,
  );
  a = agentcms("doctor-after-rollback", ["deploy", "doctor", "-t", S2.target]);
  check(
    !/中身の違い/.test(a.out) && a.out.includes("公開中のコード"),
    "戻したあとの doctor は、公開中のコードを送った物と同じと言う",
  );
  a = agentcms("doctor-backup-ok", ["deploy", "doctor", "-t", S2.target]);
  check(
    a.code === 0 && /夜のバックアップ/.test(a.out) && !a.out.includes("db-auth"),
    "直ったあとの doctor は、夜のバックアップを PASS にする",
  );
  check(
    (await waitHealthy()) === 200 && (await get(S2.host, "/healthz")).status === 200,
    "サイト 1・2 の /healthz は 200",
  );

  // ------------------------------------------------------------ 6i. サイトのコンテナはコードと .env を書き換えられない
  // 乗っ取られたサイトのコンテナ（app・scheduler）が自分のコード・.env・置き場所を書き換えられると、
  // 運用者が出し直しても戻しても、乗っ取りが残る。書けるのは shared/storage だけであること・
  // 出し直し・戻しのあとに、コンテナが書いた物と、コンテナの中に残したプロセスが残らないこと
  step(
    "6i. サイトのコンテナ（app・scheduler）は、コード・.env・置き場所を書き換えられない（書けるのは storage だけ）。出し直し・戻しのあとに残らない",
  );
  const tryWrites = (service) => {
    const r = inS2c(
      service,
      [
        `w() { if ( eval "$2" ) >/dev/null 2>&1; then echo "$1=written"; else echo "$1=denied"; fi; }`,
        `w code 'printf "%s" "<?php echo \\"e2e-6i-${service}\\";" > current/public/e2e-6i-${service}.php'`,
        `w bootcache 'printf x > current/bootstrap/cache/e2e-6i-${service}.php'`,
        `w env 'printf "\\nE2E_6I_MARK=${service}\\n" >> shared/.env'`,
        `w releases 'mkdir releases/e2e-6i-${service}'`,
        `w siteroot 'printf x > e2e-6i-${service}'`,
        `w backups 'printf x > backups/e2e-6i-${service}'`,
        `w storage 'printf ok > shared/storage/app/e2e-6i-${service} && rm -f shared/storage/app/e2e-6i-${service}'`,
        `w views 'printf x > shared/storage/framework/views/e2e-6i-${service}.txt && rm -f shared/storage/framework/views/e2e-6i-${service}.txt'`,
        `w logs 'printf "" >> shared/storage/logs/e2e-6i-${service}.log && rm -f shared/storage/logs/e2e-6i-${service}.log'`,
        `w tmp 'printf x > /tmp/e2e-6i && rm -f /tmp/e2e-6i'`,
      ].join("\n"),
    );
    return Object.fromEntries(
      `${r.stdout}`
        .trim()
        .split("\n")
        .filter((l) => l.includes("="))
        .map((l) => l.split("=")),
    );
  };
  for (const service of ["app", "scheduler"]) {
    const w = tryWrites(service);
    const denied = ["code", "bootcache", "env", "releases", "siteroot", "backups"];
    const allowed = ["storage", "views", "logs", "tmp"];
    check(
      denied.every((k) => w[k] === "denied"),
      `サイト 2 の ${service} のコンテナから、いまのコード・bootstrap/cache・shared/.env・releases・置き場所の根・backups に書けない（${denied.map((k) => `${k}=${w[k] ?? "?"}`).join(" ")}）`,
    );
    check(
      allowed.every((k) => w[k] === "written"),
      `${service} のコンテナから、shared/storage（app・framework/views・logs）と /tmp には書ける（${allowed.map((k) => `${k}=${w[k] ?? "?"}`).join(" ")}）`,
    );
  }
  check(
    (await get(S2.host, "/e2e-6i-app.php")).status === 404,
    "コンテナが書こうとしたコード（/e2e-6i-app.php）は配信されていない",
  );
  // storage（書ける場所）に、PHP が最初に読み込むメンテナンスの印を置く（サイトの PHP ができること）
  const maint = inS2(
    `printf '%s' '<?php echo "e2e-maint-backdoor"; exit;' > shared/storage/framework/maintenance.php && echo put`,
  ).stdout;
  const maintServed = (await get(S2.host, "/")).body.includes("e2e-maint-backdoor");
  check(
    maint.includes("put") && maintServed,
    `サイト 2 の app のコンテナから storage にメンテナンスの印を置け、どのページでも読み込まれる（置いた=${maint.includes("put")}・配信=${maintServed}）`,
  );
  a = agentcms("doctor-maint", ["deploy", "doctor", "-t", S2.target]);
  check(
    a.out.includes("メンテナンスの印"),
    "doctor はメンテナンスの印に気づく",
  );
  // コンテナの中にプロセスを残す（サイトの PHP が裏で起こせること）
  for (const service of ["app", "scheduler"])
    remote(`docker exec -d agentcms-${S2.site}-${service} sleep 3601`);
  const lingering = () =>
    ["app", "scheduler"]
      .map((s) => remote(`docker top agentcms-${S2.site}-${s} 2>/dev/null | grep -c 'sleep 3601' || true`).stdout.trim())
      .join(",");
  console.log(`    （残したプロセス app,scheduler: ${lingering()}）`);
  const envMarks = () =>
    remote(`grep -c '^E2E_6I_MARK=' ${s2}/shared/.env || true`).stdout.trim();
  await new Promise((res) => setTimeout(res, 1100));
  release("v6-two");
  a = agentcms("deploy-6i", [
    "deploy",
    "staging",
    "-t",
    S2.target,
    "--confirm-target",
    S2.target,
    "-y",
  ]);
  check(
    a.code === 0 && (await get(S2.host, "/e2e-version.php")).body.trim() === "php-v6-two",
    "出し直し（deploy）が exit 0 で、新しい版を返す",
  );
  check(
    envMarks() === "0",
    `出し直したあとの shared/.env に、コンテナが書いた行は無い（${envMarks()} 行）`,
  );
  check(
    lingering() === "0,0",
    `出し直したあと、コンテナの中に残したプロセスは消えている（app,scheduler: ${lingering()}）`,
  );
  check(
    !(await get(S2.host, "/")).body.includes("e2e-maint-backdoor") &&
      remote(`test -e ${s2}/shared/storage/framework/maintenance.php && echo left || echo gone`).stdout.trim() === "gone",
    "出し直したあと、storage に置いたメンテナンスの印は消え、読み込まれない",
  );
  // 出し直したあとのコードにも書けない
  const w2 = tryWrites("app");
  check(
    w2.code === "denied" && w2.bootcache === "denied" && w2.env === "denied",
    `出し直したあとのコード・bootstrap/cache・.env にも書けない（code=${w2.code} bootcache=${w2.bootcache} env=${w2.env}）`,
  );
  for (const service of ["app", "scheduler"])
    remote(`docker exec -d agentcms-${S2.site}-${service} sleep 3601`);
  a = agentcms("rollback-6i", ["deploy", "rollback", "-t", S2.target, "--confirm-target", S2.target, "-y"]);
  const afterRb3 = (await get(S2.host, "/e2e-version.php")).body.trim();
  check(
    a.code === 0 && /^php-v\d-two$/.test(afterRb3) && afterRb3 !== "php-v6-two",
    `戻し（rollback）が exit 0 で、前の版を返す（${afterRb3.slice(0, 40)}）`,
  );
  check(
    envMarks() === "0" && lingering() === "0,0",
    `戻したあとも shared/.env にコンテナが書いた行は無く、残したプロセスも無い（.env ${envMarks()} 行・プロセス ${lingering()}）`,
  );
  // 書き込みが要る所が壊れていない: 管理画面でメディアを上げる（storage に書く）・プロフィールを保存する（DB）
  const adminRun = sh(
    process.execPath,
    [
      "-e",
      `
      const base = ${JSON.stringify(`https://${S1.host}:${ports.https}`)};
      const jar = new Map();
      const keep = (r) => { for (const c of r.headers.getSetCookie()) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar.set(kv.slice(0, i), kv.slice(i + 1)); } return r; };
      const cookie = () => [...jar].map(([k, v]) => k + "=" + v).join("; ");
      const xsrf = () => decodeURIComponent(jar.get("XSRF-TOKEN") ?? "");
      const req = async (path, init = {}) => keep(await fetch(base + path, { redirect: "manual", ...init, headers: { cookie: cookie(), "x-xsrf-token": xsrf(), ...(init.headers ?? {}) } }));
      (async () => {
        await req("/login");
        const login = await req("/login", { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded", accept: "text/html" }, body: new URLSearchParams({ email: process.env.E2E_EMAIL, password: process.env.E2E_PASSWORD }) });
        const png = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGM4UaFBEmIY1TCqYfhqAAADaGgQ43GRdgAAAABJRU5ErkJggg==", "base64");
        const fd = new FormData();
        fd.append("file", new Blob([png], { type: "image/png" }), "e2e-6i.png");
        fd.append("name", "e2e-6i");
        const up = await req("/admin/media", { method: "POST", headers: { accept: "application/json" }, body: fd });
        const upBody = await up.text();
        let url = null;
        try { url = JSON.parse(upBody).data?.media?.url ?? null; } catch {}
        let file = 0;
        if (url) file = (await fetch(new URL(url, base))).status;
        const prof = await req("/settings/profile", { method: "PATCH", headers: { "content-type": "application/x-www-form-urlencoded", accept: "text/html" }, body: new URLSearchParams({ name: "E2E 6i Admin", email: process.env.E2E_EMAIL }) });
        console.log(JSON.stringify({ login: login.status, upload: up.status, uploadBody: upBody.slice(0, 160), url, file, profile: [prof.status, prof.headers.get("location")] }));
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
  let ar = {};
  try {
    ar = JSON.parse(adminRun.stdout.trim().split("\n").pop());
  } catch {
    ar = { error: adminRun.stderr.slice(-300) };
  }
  check(
    ar.login === 302 && ar.upload === 201 && ar.file === 200,
    `サイト 1 の管理画面で画像を上げられ（${ar.upload}）、上げた画像を配信できる（${ar.file}）（${JSON.stringify(ar).slice(0, 240)}）`,
  );
  const profName = remote(
    `cd ${s1} && grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2- | docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec mysql -h 127.0.0.1 -u sgk-e2e-one -D sgk-e2e-one -N -e "SELECT name FROM users WHERE email=\\"${adminEmail}\\""' 2>&1`,
  ).stdout.trim();
  check(
    ar.profile?.[0] === 302 && /\/settings\/profile$/.test(ar.profile?.[1] ?? "") && profName === "E2E 6i Admin",
    `サイト 1 の管理画面でプロフィールを保存できる（${JSON.stringify(ar.profile)}・DB: ${profName}）`,
  );
  const roErrors = remote(
    `cat ${s1}/shared/storage/logs/*.log ${s2}/shared/storage/logs/*.log 2>/dev/null | grep -ciE 'read-only file system|failed to open stream: permission denied|must be present and writable' || true; for s in ${S1.site} ${S2.site}; do for c in app scheduler; do docker logs --since 30m agentcms-$s-$c 2>&1; done; done | grep -ciE 'read-only file system|permission denied|must be present and writable' || true`,
  )
    .stdout.trim()
    .split("\n");
  check(
    roErrors.every((n) => n === "0"),
    `アプリのログ・app と scheduler のコンテナのログに、書けなかったエラーが 0 件（アプリ ${roErrors[0]}・コンテナ ${roErrors[1]}）`,
  );
  check(
    (await waitHealthy()) === 200 && (await get(S2.host, "/healthz")).status === 200,
    "サイト 1・2 の /healthz は 200",
  );

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
      "cat /srv/agentcms/edge/compose.yml /srv/agentcms/edge/Caddyfile /srv/agentcms/edge/sites/*.caddy /srv/agentcms/control/*/compose.yml /srv/agentcms/control/*/nginx.conf",
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

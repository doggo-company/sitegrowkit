/**
 * Setup Wizard の画面（Phase 8.5-B）。
 *
 * 方針:
 *  - 画面の state は backend の snapshot が権威。ここでは進行判定を持たない
 *  - DOM への挿入は textContent のみ（innerHTML は使わない）
 *  - 外部リソースを読み込まない（同梱アセットのみ）
 */

const CSRF_HEADER = "x-agentcms-setup";

const el = {
  screens: new Map(),
  details: document.querySelector('[data-bind="details"]'),
  detailsLog: document.querySelector('[data-bind="details-log"]'),
  status: document.querySelector('[data-bind="status"]'),
  checks: document.querySelector('[data-bind="checks"]'),
  stages: document.querySelector('[data-bind="stages"]'),
  environmentSummary: document.querySelector(
    '[data-bind="environment-summary"]',
  ),
  resumeSite: document.querySelector('[data-bind="resume-site"]'),
  credentials: document.querySelector('[data-bind="credentials"]'),
  credentialBox: document.querySelector('[data-bind="credential-box"]'),
  credentialEmail: document.querySelector('[data-bind="credential-email"]'),
  credentialPassword: document.querySelector(
    '[data-bind="credential-password"]',
  ),
  credentialStatus: document.querySelector('[data-bind="credential-status"]'),
  readyUrls: document.querySelector('[data-bind="ready-urls"]'),
  auditWarning: document.querySelector('[data-bind="audit-warning"]'),
  auditWarningText: document.querySelector('[data-bind="audit-warning-text"]'),
  errorTitle: document.querySelector('[data-bind="error-title"]'),
  errorMessage: document.querySelector('[data-bind="error-message"]'),
  errorActions: document.querySelector('[data-bind="error-actions"]'),
};

for (const node of document.querySelectorAll("[data-screen]")) {
  el.screens.set(node.dataset.screen, node);
}

let snapshot = null;
/**
 * 管理画面のログイン情報（UX 一周 3 周目 N1）。READY で 1 回だけ取りに行き、ここに持つ。
 * セットアップ役は数分で自分から終わるので、あとから取りに行けなくても画面からは消さない。
 */
let credential = null;
let credentialRequested = false;
let detailsOpen = false;
let locationTouched = false;
let projectNameTouched = false;

async function post(path, body) {
  const res = await fetch(path, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      [CSRF_HEADER]: "1",
    },
    body: JSON.stringify(body ?? {}),
  });
  const json = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, json };
}

function setScreen(phase) {
  for (const [name, node] of el.screens) node.hidden = name !== phase;
}

function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
}

function listItem(mark, markClass, title, note) {
  const li = document.createElement("li");
  const marker = document.createElement("span");
  marker.className = `mark ${markClass}`;
  marker.textContent = mark;
  const body = document.createElement("div");
  body.className = "item__body";
  const heading = document.createElement("div");
  heading.className = "item__title";
  heading.textContent = title;
  body.appendChild(heading);
  if (note) {
    const noteEl = document.createElement("div");
    noteEl.className = "item__note";
    noteEl.textContent = note;
    body.appendChild(noteEl);
  }
  li.appendChild(marker);
  li.appendChild(body);
  return li;
}

const CHECK_MARK = {
  pass: ["✓", "mark--pass"],
  warn: ["!", "mark--warn"],
  fail: ["×", "mark--fail"],
  "not-required": ["–", "mark--pending"],
};

function renderChecks(checks) {
  clear(el.checks);
  for (const check of checks) {
    if (!check.visible || check.status === "not-required") continue;
    const [mark, cls] = CHECK_MARK[check.status] ?? CHECK_MARK.warn;
    el.checks.appendChild(
      listItem(
        mark,
        cls,
        check.title ?? check.name,
        check.status === "pass"
          ? ""
          : (check.message ?? check.cause ?? check.detail ?? ""),
      ),
    );
  }
}

const STAGE_MARK = {
  done: ["✓", "mark--pass"],
  running: ["…", "mark--pending"],
  pending: ["・", "mark--pending"],
};

function renderStages(stages) {
  clear(el.stages);
  for (const stage of stages) {
    const [mark, cls] = STAGE_MARK[stage.status];
    const li = listItem(mark, cls, stage.label, "");
    li.dataset.status = stage.status;
    el.stages.appendChild(li);
  }
}

function renderEnvironment(next) {
  renderChecks(next.checks);
  const failed = next.checks.filter((c) => c.status === "fail");
  const busy = next.busy;
  el.environmentSummary.textContent = busy
    ? "確認しています…"
    : failed.length === 0
      ? "問題は見つかりませんでした。次へ進めます。"
      : "続けるには、下の項目を解決してください。";
  const continueBtn = document.querySelector(
    '[data-action="environment-continue"]',
  );
  continueBtn.disabled = busy || failed.length > 0;
  document.querySelector('[data-action="environment-recheck"]').disabled = busy;
}

/**
 * 完了画面を開いている間は、セットアップ役を終わらせない（UX 一周 8 周目 R8-1）。
 *
 * セットアップ役は、操作が 3 分途切れると自分で終わる（server.ts の READY_MS）。ログイン情報を控えて
 * いるうちに 3 分たつと、「管理画面を開く」が届かず、ログイン画面が開いていた（完了画面は
 * 「ログインした状態で開きます」と言っているのに）。画面が見えている間だけ 1 分ごとに知らせる。
 * 見えていない（タブを閉じた・裏にある）なら知らせないので、今までどおり終わる。60 分で止める。
 */
let keepAliveTimer = null;
function keepAliveWhileVisible() {
  if (keepAliveTimer !== null) return;
  const startedAt = Date.now();
  keepAliveTimer = setInterval(() => {
    if (Date.now() - startedAt > 60 * 60_000) {
      clearInterval(keepAliveTimer);
      return;
    }
    if (document.visibilityState !== "visible") return;
    fetch("/api/state", { cache: "no-store" }).catch(() => {});
  }, 60_000);
}

function renderReady(ready) {
  keepAliveWhileVisible();
  const map = {
    "open-public": ready?.publicUrl,
    "open-admin": ready?.loginUrl,
    "open-folder": ready?.projectPath,
  };
  for (const [action, value] of Object.entries(map)) {
    const btn = document.querySelector(`[data-action="${action}"]`);
    // 動かないボタンは出さない（対象が無いものは非表示）
    btn.hidden = !value;
  }
  el.credentials.textContent = ready?.credentialsPath
    ? `ログイン情報は、プロジェクトフォルダ内の ${ready.credentialsPath} にも保存されています（名前が「.」で始まるフォルダは、Mac の Finder では隠れています。⌘ + Shift + .〔ピリオド〕で表示されます）。`
    : "";
  loadCredential();
  // ボタンが開けない環境でも辿れるよう、URL を文字でも出す
  const urls = [];
  if (ready?.publicUrl) urls.push(`サイト: ${ready.publicUrl}`);
  if (ready?.loginUrl) urls.push(`管理画面: ${ready.loginUrl}`);
  el.readyUrls.textContent = urls.join("　／　");
  el.readyUrls.hidden = urls.length === 0;
  // 依存の脆弱性は止めずに知らせる（サイトは動いている）
  const warnings = ready?.auditWarnings ?? [];
  el.auditWarning.hidden = warnings.length === 0;
  el.auditWarningText.textContent =
    warnings.length === 0
      ? ""
      : `サイトは動いています。ただし、サイトが使っている部品に、公開されている安全上の注意が見つかりました。本番に公開する前に、部品の更新をおすすめします。内容は、プロジェクトフォルダで agentcms verify を実行すると確認できます。`;
  renderAgents(ready?.agents);
}

async function loadCredential() {
  if (credentialRequested) {
    renderCredential();
    return;
  }
  credentialRequested = true;
  try {
    const res = await fetch("/api/credentials", { cache: "no-store" });
    const json = await res.json().catch(() => ({}));
    if (res.ok && typeof json.email === "string") {
      credential = { email: json.email, password: json.password };
    }
  } catch {
    credential = null;
  }
  renderCredential();
}

function renderCredential() {
  el.credentialBox.hidden = credential === null;
  el.credentialEmail.textContent = credential?.email ?? "";
  el.credentialPassword.textContent = credential?.password ?? "";
}

async function copyCredential(kind) {
  const value = credential?.[kind];
  if (!value) return;
  const label = kind === "email" ? "メールアドレス" : "パスワード";
  try {
    await navigator.clipboard.writeText(value);
    el.credentialStatus.textContent = `${label}をコピーしました。`;
  } catch {
    el.credentialStatus.textContent = `コピーできませんでした。${label}の文字を選んでコピーしてください。`;
  }
}

/**
 * 「管理画面を開く」（UX 一周 3 周目 N1）。
 *
 * 1 回きりのログインリンクを作ってもらい、**この画面と同じブラウザ**で開く。
 * 作れなかったら（セットアップ役が終わった・ローカルでない等）、いつものログイン画面を開く。
 * 新しいタブは押した瞬間に開いておく（待ってから開くとポップアップとして止められる）。
 */
async function openAdmin() {
  const loginUrl = snapshot?.ready?.loginUrl;
  const tab = window.open("about:blank", "_blank");
  let target = loginUrl;
  try {
    const { ok, json } = await post("/api/login-link");
    if (ok && json.ok && typeof json.url === "string") target = json.url;
  } catch {
    // ログイン画面へ（下）
  }
  if (!target) {
    tab?.close();
    return;
  }
  if (tab) {
    tab.opener = null;
    tab.location.href = target;
    return;
  }
  window.location.href = target;
}

/**
 * AI エージェント連携（Phase 9.1B / STEP 13）。
 * 利用者に Terminal / JSON 手編集を求めず、ボタン 1 つで設定を書き出す。
 */
function renderAgents(agents) {
  const section = document.querySelector('[data-bind="agents"]');
  const list = document.querySelector('[data-bind="agent-list"]');
  section.hidden = !agents || agents.length === 0;
  if (section.hidden) return;

  clear(list);
  for (const agent of agents) {
    const row = document.createElement("li");
    row.className = "agents__item";

    const name = document.createElement("span");
    name.className = "agents__name";
    name.textContent = agent.label;

    const generate = document.createElement("button");
    generate.className = "btn btn--small";
    generate.textContent = "設定を生成";
    generate.addEventListener("click", () => generateAgentConfig(agent.id));

    const copy = document.createElement("button");
    copy.className = "btn btn--small";
    copy.textContent = "内容をコピー";
    copy.addEventListener("click", () => copyAgentConfig(agent.id));

    row.append(name, generate, copy);
    list.appendChild(row);
  }
}

function agentStatus(text) {
  document.querySelector('[data-bind="agent-status"]').textContent = text;
}

async function generateAgentConfig(id) {
  agentStatus("設定ファイルを作成しています…");
  const { json } = await post("/api/agent-config/write", { client: id });
  agentStatus(
    json && json.ok
      ? `${json.file} を保存しました。AI エージェントを開き直すと使えます。`
      : "設定ファイルを保存できませんでした。",
  );
}

async function copyAgentConfig(id) {
  const { json } = await post("/api/agent-config/render", { client: id });
  if (!json || !json.ok || typeof json.content !== "string") {
    agentStatus("設定内容を取得できませんでした。");
    return;
  }
  try {
    await navigator.clipboard.writeText(json.content);
    agentStatus("設定内容をコピーしました。");
  } catch {
    agentStatus(json.content);
  }
}

function renderError(failure) {
  el.errorTitle.textContent = failure?.title ?? "問題が発生しました";
  el.errorMessage.textContent = failure?.message ?? "";
  clear(el.errorActions);
  for (const action of failure?.recovery ?? []) {
    const btn = document.createElement("button");
    btn.className = action.kind === "retry" ? "btn btn--primary" : "btn";
    btn.textContent = action.label;
    btn.addEventListener("click", () => runRecovery(action));
    el.errorActions.appendChild(btn);
  }
}

async function runRecovery(action) {
  if (action.kind === "showDetails") {
    detailsOpen = true;
    await loadDetails();
    return;
  }
  if (action.kind === "recheck") {
    await post("/api/environment/recheck");
    return;
  }
  if (action.kind === "retry") {
    // 作成済みなら続きから、未作成なら入力画面へ戻す
    // （作成済みで入力画面へ戻すと、同名フォルダが既にあるため必ず失敗する）
    if (snapshot?.canResume) {
      await post("/api/resume");
    } else {
      await post("/api/environment/continue");
    }
  }
}

function render(next) {
  snapshot = next;
  setScreen(next.phase);

  if (next.phase === "ENVIRONMENT") renderEnvironment(next);
  if (next.phase === "SETTING_UP") {
    renderStages(next.stages);
    document.querySelector('[data-action="cancel"]').disabled = !next.busy;
  }
  if (next.phase === "READY") renderReady(next.ready);
  if (next.phase === "ERROR") renderError(next.failure);
  if (next.phase === "RESUMABLE" && next.resumable) {
    el.resumeSite.textContent = next.resumable.siteName;
    document.querySelector('[data-bind="resume-path"]').textContent =
      next.resumable.projectPath;
  }

  if (next.phase === "PROJECT" && !locationTouched) {
    document.querySelector("#location").value = next.defaultLocation;
  }
  const pickBtn = document.querySelector('[data-action="pick-folder"]');
  pickBtn.hidden = !next.pickerAvailable;

  el.details.hidden = !detailsOpen;
}

function showFieldErrors(errors) {
  for (const node of document.querySelectorAll("[data-error]")) {
    node.hidden = true;
    node.textContent = "";
  }
  for (const error of errors ?? []) {
    const node = document.querySelector(`[data-error="${error.field}"]`);
    if (!node) continue;
    node.textContent = error.message;
    node.hidden = false;
  }
}

async function loadDetails() {
  const res = await fetch("/api/logs");
  const json = await res.json().catch(() => ({ lines: [] }));
  el.detailsLog.textContent = json.lines.join("\n");
  el.details.hidden = false;
  el.detailsLog.scrollTop = el.detailsLog.scrollHeight;
}

function connect() {
  const source = new EventSource("/api/events");
  // READY は terminal state で、セットアップ役は用済みになると自分で終了する。
  // EventSource は既定で自動再接続するため、終了後は接続拒否を延々と繰り返し、
  // コンソールがエラーで埋まる。READY 以降は再接続をやめる。
  source.addEventListener("error", () => {
    if (snapshot && snapshot.phase === "READY") source.close();
  });
  source.addEventListener("message", (event) => {
    const payload = JSON.parse(event.data);
    if (payload.type === "snapshot") {
      render(payload.snapshot);
      return;
    }
    if (payload.type === "message") {
      el.status.textContent = payload.text;
      return;
    }
    if (payload.type === "log" && detailsOpen) {
      el.detailsLog.textContent += `${payload.line}\n`;
      el.detailsLog.scrollTop = el.detailsLog.scrollHeight;
    }
  });
}

/*
 * 押したボタンに、送っている間の印を付ける（UX 一周 15 周目 T2）。
 * 次の画面が出るまでの 0.2〜0.5 秒、画面が何も変わらなかった。
 */
const BUSY_ACTIONS = new Set([
  "environment-start",
  "environment-recheck",
  "environment-continue",
  "resume",
  "resume-discard-confirm",
  "start",
  "pick-folder",
]);

document.addEventListener(
  "click",
  (event) => {
    const target = event.target.closest("[data-action]");
    if (!target || !BUSY_ACTIONS.has(target.dataset.action)) return;
    target.setAttribute("aria-busy", "true");
    // 次の画面に替わらなかったとき（入力の誤りなど）のために、少しあとで外す
    setTimeout(() => target.removeAttribute("aria-busy"), 4000);
  },
  true,
);

document.addEventListener("click", async (event) => {
  const target = event.target.closest("[data-action]");
  if (!target) return;
  const action = target.dataset.action;

  if (action === "environment-start")
    return void post("/api/environment/start");
  if (action === "environment-recheck")
    return void post("/api/environment/recheck");
  if (action === "environment-continue")
    return void post("/api/environment/continue");
  if (action === "resume") return void post("/api/resume");
  if (action === "resume-discard") {
    document.querySelector('[data-bind="discard-confirmation"]').hidden = false;
    document.querySelector('[data-action="resume-discard-confirm"]').focus();
    return;
  }
  if (action === "resume-discard-back") {
    document.querySelector('[data-bind="discard-confirmation"]').hidden = true;
    document.querySelector('[data-action="resume-discard"]').focus();
    return;
  }
  if (action === "resume-discard-confirm")
    return void post("/api/resume/discard");
  if (action === "cancel") return void post("/api/cancel");

  if (action === "toggle-details") {
    detailsOpen = !detailsOpen;
    target.textContent = detailsOpen ? "詳細を隠す" : "詳細を表示";
    if (detailsOpen) await loadDetails();
    else el.details.hidden = true;
    return;
  }

  if (action === "pick-folder") {
    const { json } = await post("/api/pick-folder");
    if (json.ok && json.path) {
      locationTouched = true;
      document.querySelector("#location").value = json.path;
      showFieldErrors([]);
      // 場所を変えたら、そこに無い名前で候補を出し直す
      void suggestName();
    }
    return;
  }

  if (action === "start") {
    const payload = {
      siteName: document.querySelector("#siteName").value,
      projectName: document.querySelector("#projectName").value,
      location: document.querySelector("#location").value,
    };
    const { ok, json } = await post("/api/start", payload);
    target.removeAttribute("aria-busy");
    if (!ok) showFieldErrors(json.errors);
    else showFieldErrors([]);
    return;
  }

  if (action === "open-public")
    return void post("/api/open", { target: "publicUrl" });
  if (action === "open-admin") return void openAdmin();
  if (action === "copy-email") return void copyCredential("email");
  if (action === "copy-password") return void copyCredential("password");
  if (action === "open-folder")
    return void post("/api/open", { target: "projectPath" });
});

// サイト名からフォルダ名を提案する（利用者が自分で編集したら上書きしない）
/** 返事がこれより遅ければ「読みの辞書を準備しています…」を出す（UX 一周 21 周目 Y3: 初回 11.3 秒、何も出なかった） */
const READING_NOTICE_MS = 300;
let suggestSeq = 0;
async function suggestName() {
  if (projectNameTouched) return;
  const seq = ++suggestSeq;
  const notice = document.querySelector("#projectNameReading");
  const timer = setTimeout(() => {
    if (seq === suggestSeq) notice.hidden = false;
  }, READING_NOTICE_MS);
  try {
    const { json } = await post("/api/suggest-name", {
      siteName: document.querySelector("#siteName").value,
      location: document.querySelector("#location").value,
    });
    // 後から打った方の返事を、先に打った方の返事で上書きしない
    if (seq !== suggestSeq || projectNameTouched) return;
    document.querySelector("#projectName").value = json.projectName ?? "";
  } finally {
    clearTimeout(timer);
    if (seq === suggestSeq) notice.hidden = true;
  }
}

document.querySelector("#siteName").addEventListener("input", suggestName);

document.querySelector("#projectName").addEventListener("input", () => {
  projectNameTouched = true;
});

connect();

import { existsSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";

import type { ApplyJournal } from "../importer/apply.js";
import {
  advanceJournal,
  applyEnvAdditions,
  buildApplyRecord,
  classifyFiles,
  clearJournal,
  hashContent,
  isSafeProjectTarget,
  journalPath,
  loadApplyRecord,
  patchContactServerConstants,
  POST_WRITE_WIRING,
  pruneStaleFiles,
  readJournal,
  restoreFromJournal,
  rollbackJournal,
  saveApplyRecord,
  writeFiles,
  writeJournal,
  writeSchemaChoicesOverride,
  writeSchemaOverride,
} from "../importer/apply.js";
import {
  applyImportedPages,
  describeImportedPages,
} from "../importer/apply-pages.js";
import { generateFromPlan } from "../importer/generate.js";
import {
  componentTypeOf,
  importedPruneCandidates,
  layoutViewOf,
  protectedTargets,
} from "../importer/prune-guard.js";
import {
  emptyCatalog,
  matchLabel,
  matchTerm,
  validatePlanChanges,
  type TaxonomyChange,
} from "../importer/plan-contract.js";
import type { TemplatePlan } from "../importer/plan-types.js";
import {
  loadProjectSchemas,
  SchemaContractError,
  type SchemaMap,
} from "../importer/project-schemas.js";
import { createTerms, fetchTaxonomyCatalog } from "../importer/term-bridge.js";
import { createContentTypes } from "../importer/type-bridge.js";
import { fetchImportedUsage } from "../importer/usage-bridge.js";
import { fingerprintSource } from "../inspect/fingerprint.js";
import type { Manifest } from "../inspect/manifest.js";
import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { runCapture } from "../run.js";
import { fail, failAndExit, heading, info, ok, warn } from "../ui.js";

interface Options {
  source?: string;
  force?: boolean;
  dryRun?: boolean;
}

/**
 * 承認済み Plan を基にプロジェクトへ変換を適用する。
 * - source fingerprint 不一致 → 拒否（stale plan — spec §9）
 * - 既存ファイルとの conflict → STOP（--force で backup の上で上書き — spec §20）
 * - 同一 Plan の再適用 → no-op（spec §21）
 */
export async function templateApply(opts: Options): Promise<number> {
  heading("agentcms template apply");

  const agentcms = findAgentcmsDir();
  const projectRoot = findProjectRoot();
  if (!agentcms || !projectRoot) {
    failAndExit({
      what: "SiteGrowKit のプロジェクトが見つかりません",
      why: ".agentcms/ またはプロジェクトルート（artisan / docker-compose.yml）を解決できませんでした",
      fix: "SiteGrowKit のプロジェクト内で実行してください",
    });
  }

  const manifestPath = join(agentcms, "manifest", "template.json");
  const planPath = join(agentcms, "manifest", "template-plan.json");
  for (const [name, path, cmd] of [
    ["Manifest", manifestPath, "agentcms template inspect <dir>"],
    ["Plan", planPath, "agentcms template plan"],
  ] as const) {
    if (!existsSync(path)) {
      failAndExit({
        what: `${name} が見つかりません`,
        why: `${path} が存在しません`,
        fix: `先に \`${cmd}\` を実行してください`,
      });
    }
  }

  const manifest = JSON.parse(readFileSync(manifestPath, "utf8")) as Manifest;
  const planJson = readFileSync(planPath, "utf8");
  const planRaw: unknown = JSON.parse(planJson);

  // **型注釈は検証ではない。** ここから先はファイルと DB を触るので、
  // 未知の変更種別・壊れた型・登録されていない名前をここで落とす
  let schemas;

  try {
    schemas = loadProjectSchemas(projectRoot);
  } catch (e) {
    if (e instanceof SchemaContractError) {
      failAndExit({
        what: "コンテンツ定義を読み取れないため中止しました（何も変更していません）",
        why: `${e.file}: ${e.message}`,
        fix: "content/schemas/ の定義を直してから apply し直してください",
      });
    }
    throw e;
  }

  const issues = validatePlanChanges(planRaw, { schemas });

  if (issues.length > 0) {
    for (const issue of issues) warn(`plan ${issue.path}: ${issue.message}`);
    failAndExit({
      what: "Plan の内容を検証できませんでした（何も変更していません）",
      why: `${issues.length} 件の問題があります（古い形式・改変・未知の変更種別）`,
      fix: "`agentcms template plan` を実行して Plan を作り直してください",
    });
  }

  const plan = planRaw as TemplatePlan;

  // ---- source 解決と fingerprint 検証（stale plan 拒否） ----
  const sourceDir = resolve(opts.source ?? plan.source);
  if (!existsSync(sourceDir)) {
    failAndExit({
      what: `source テンプレート ${plan.source} が見つかりません`,
      why: `${sourceDir} が存在しません（inspect 実行時と作業ディレクトリが異なる可能性）`,
      fix: "--source <dir> で元テンプレートのパスを指定してください",
    });
  }
  if (plan.manifest_fingerprint !== manifest.fingerprint.total) {
    failAndExit({
      what: "Plan が現在の Manifest と一致しません（stale plan）",
      why: `plan fingerprint ${plan.manifest_fingerprint.slice(0, 12)}… ≠ manifest ${manifest.fingerprint.total.slice(0, 12)}…`,
      fix: "`agentcms template plan` を再実行して Plan を作り直してください",
    });
  }
  const currentFingerprint = fingerprintSource(
    sourceDir,
    manifest.fingerprint.files.map((f) => f.path),
  );
  const filesChanged =
    currentFingerprint.total !== manifest.fingerprint.total ||
    manifest.fingerprint.files.some((f) => {
      const now = currentFingerprint.files.find((c) => c.path === f.path);
      return !now || now.sha256 !== f.sha256;
    });
  if (filesChanged) {
    failAndExit({
      what: "inspect 後に source テンプレートが変更されています（stale plan）",
      why: `fingerprint 不一致: manifest ${manifest.fingerprint.total.slice(0, 12)}… ≠ 現在 ${currentFingerprint.total.slice(0, 12)}…`,
      fix: "`agentcms template inspect` → `agentcms template plan` を再実行してから apply してください（古い Plan をそのまま適用してはいけません）",
    });
  }

  // ---- git working tree の確認（警告のみ。conflict 判定が実防御） ----
  const gitStatus = runCapture(
    "git",
    ["status", "--porcelain"],
    projectRoot,
  ).stdout.trim();
  if (gitStatus.length > 0) {
    warn(
      "git working tree に未コミットの変更があります。apply 前のコミットを推奨します",
    );
  }

  // ---- 生成 + conflict 判定 ----
  /*
   * 一覧ブロックの元になれる型 = **この案件に在る型 ＋ この実行で作る型**（R03 / B-42）。
   * ここに無い型を binding に持つブロックはページに入れない（入れると
   * `import-page` が n 個目で止まり、下書きが半端なまま残る）。
   */
  const knownTypes = new Set<string>([
    ...Object.keys(schemas),
    ...plan.content_types.map((t) => t.id),
  ]);
  const result = generateFromPlan(sourceDir, manifest, plan, { knownTypes });
  const recordPath = join(agentcms, "manifest", "template-apply.json");
  const previous = loadApplyRecord(recordPath);

  // ページにできなかった理由と、編集できないまま置くブロックは**先に**言う（dry-run でも本番でも同じ）
  for (const note of result.importedPageNotes) warn(`page: ${note}`);
  for (const page of result.importedPages) {
    for (const name of page.passthrough) {
      info(
        `page: ページ [${page.slug}] の ${name} は原本の Blade のまま置きます（画面からは編集できません）`,
      );
    }
  }

  // ---- 前回の中断を先に片づける（**新しい apply を重ねない**） ----
  const recovery = await recoverJournal(
    journalPath(agentcms),
    projectRoot,
    recordPath,
    hashContent(planJson),
    () =>
      buildApplyRecord(
        result,
        manifest.fingerprint.total,
        hashContent(planJson),
        /*
         * **前回の記録にあるページの結果を落とさない**（Codex 指摘 ③）。
         * 復旧で記録を組み直すとき `pages` を捨てると、途中失敗（`failed`）の記録が消え、
         * 次の実行が残った下書きを「運用者のもの」と読み違える
         */
        previous?.pages,
        /*
         * **残した部品・枠（retained）も落とさない**（Codex 続き (2)）。
         * 復旧の時点では CMS に使用状況を聞かないので、前回の記録にある取り込みの部品・枠で
         * **いまも同じ中身で在るもの**を引き継ぐ。消えているものは引き継がない（中断した実行が
         * prune した = 使われていなかった）。
         */
        stillPresentImportedFiles(projectRoot, previous),
      ),
    opts.dryRun === true,
  );

  for (const line of recovery.notes) info(line);

  if (recovery.stop) {
    fail({
      what: "前回の apply が途中で終わっており、そのままでは続けられません（何も変更していません）",
      why: recovery.stop,
      fix: ".agentcms/manifest/template-apply-journal.json の内容と分類項目の状態を確認してください",
    });
    return 1;
  }

  // 復旧で状態が変わったら、**この実行はここで終える**。
  // 既に読み終えた記録・分類結果は復旧前のものなので、そのまま続けると
  // 古い前提で書き込むことになる
  if (recovery.changed) {
    ok(
      "前回の中断を復旧しました。もう一度 `agentcms template apply` を実行してください",
    );

    return 0;
  }

  const states = classifyFiles(projectRoot, result.files, previous);

  const conflicts = states.filter(
    (s) => s.state === "foreign" || s.state === "ours-modified",
  );
  const changes = states.filter((s) => s.state !== "unchanged");
  const backupDir = join(agentcms, "manifest", "backup");
  const currentTargets = new Set(result.files.map((f) => f.target));

  info(`生成対象: ${result.files.length} ファイル（変更 ${changes.length}）`);

  // Project isolation: 前回 apply の生成物で新 Plan に無いものを整理する
  const reportPrune = (): void => {
    const prune = pruneStaleFiles(
      projectRoot,
      previous,
      currentTargets,
      backupDir,
      protection.protect,
    );
    for (const t of prune.restored)
      info(`prune(restore): ${t} — 旧 import の生成物を import 前の状態へ復元`);
    for (const t of prune.deleted)
      info(`prune(delete): ${t} — 旧 import の生成物（新 Plan に無い）を削除`);
    for (const t of prune.kept)
      warn(`prune(skip): ${t} — apply 後に手動変更されているため残置`);
  };

  // 分類項目の提案。**generate されたファイルが 0 でも取りこぼさない**
  const termProposals = (plan.taxonomy_changes ?? []).filter(
    (c): c is TaxonomyChange => c.change === "add_terms",
  );

  // ［#23］以前はここに「no-op 分岐」があり、ファイル変更が無いときだけ
  // env / routes / contact / override を **復旧記録なしで**書いていた。
  // 変更は変更なので、記録の外で書いてよい理由が無い。分岐ごと消し、
  // 通常の経路（記録 → 変更 → DB → 記録保存）へ一本化する。

  if (conflicts.length > 0 && !opts.force) {
    console.log("");
    for (const c of conflicts) {
      /*
       * 🔴 **何と衝突しているのかを言う**（B-60）。
       *
       * 「conflict です」だけだと、**また `--force` で越える**。
       * kit が置いた見本を直したものなら、**利用者は「自分の直しを残すか、
       * 捨てて apply させるか」を選んでいる**と分かる必要がある。
       */
      warn(
        c.wasSample === true
          ? `conflict [kit の見本を手で直したもの]: ${c.file.target}`
          : `conflict [${c.state === "foreign" ? "既存ファイル" : "apply 後に手動変更"}]: ${c.file.target}`,
      );
    }

    const sampleCount = conflicts.filter((c) => c.wasSample === true).length;

    fail({
      what: `既存ファイルとの conflict が ${conflicts.length} 件あります（STOP）`,
      why: "既存の Project コードを無警告で上書きしません（spec §20）",
      fix:
        sampleCount === 0
          ? "内容を確認のうえ `agentcms template apply --force` で上書きしてください（原本は .agentcms/manifest/backup/ へ退避されます）"
          : `うち ${sampleCount} 件は **kit が置いた見本を手で直したもの**です。` +
            "その直しを残したいなら、別の名前へ移してから apply してください。" +
            "捨ててよいなら `agentcms template apply --force` で上書きできます" +
            "（原本は .agentcms/manifest/backup/ へ退避されます）",
    });
    return 1;
  }

  /*
   * ---- 使用中の部品・枠の preflight（read-only。Codex 続き ②）----
   *
   * 別テンプレートの再取り込みでは、前回の `imported_*` 部品と `layouts/preserved/*` が
   * 消される・書き直される。**それを使っているページが在れば公開面が壊れる**ので、
   * 1 つも書く前に CMS へ聞き、使われているものは消さず・書き直さない（記録には前回の hash で残す）。
   * 確認できなければ止まる — 「読めない」を「未使用」と読むと、使われている部品を消す。
   * 初回の apply（前回の記録が無い）は聞かない: 守るべき前回の生成物が無い。
   */
  const candidates =
    previous === null
      ? { components: [], layouts: [] }
      : importedPruneCandidates(previous, currentTargets);
  let protection = {
    protect: new Set<string>(),
    usedBy: new Map<string, string[]>(),
  };

  if (candidates.components.length > 0 || candidates.layouts.length > 0) {
    const usage = await fetchImportedUsage(
      candidates.components,
      candidates.layouts,
      { root: projectRoot },
    );

    if (usage.kind === "unknown") {
      fail({
        what: "取り込んだ部品・枠がどのページに使われているか確認できないため中止しました（何も変更していません）",
        why: usage.message,
        fix: "CMS を起動してから apply し直してください（使われている部品を消さないための確認です）",
      });

      return 1;
    }

    protection = protectedTargets(usage.usage, [
      ...(previous?.files.map((f) => f.target) ?? []),
      ...currentTargets,
    ]);
  }

  /*
   * 使用中の target は **書き直さない**（新しい Plan に在って中身が変わる場合）。
   * 取り込んだページの枠を、別テンプレートの枠で黙って差し替えない。
   * 記録には前回の hash のまま残す（次回も同じ判断ができるように）。
   */
  const frozen = states.filter(
    (s) =>
      protection.protect.has(s.file.target) &&
      s.state !== "new" &&
      s.state !== "unchanged",
  );
  const writeStates = states.filter((s) => !frozen.includes(s));
  const retainedRecords = (previous?.files ?? []).filter(
    (f) =>
      protection.protect.has(f.target) &&
      (!currentTargets.has(f.target) ||
        frozen.some((s) => s.file.target === f.target)),
  );

  for (const [target, slugs] of protection.usedBy) {
    info(
      `retain: ${target} — ページ [${slugs.join(", ")}] が使用中のため${currentTargets.has(target) ? "書き換えず" : "消さず"}に残します`,
    );
  }

  /*
   * 🔴 **部品と枠だけ残しても、CSS / 画像 / JS が別テンプレートのものに置き換われば
   * 既存ページの意匠は壊れる**（Codex 続き (1)）。部品がどの資産に依存しているかを
   * 安全に判別する手段は無い（CSS のセレクタは class に、画像は markup に散る）。
   * だから **使用中のページが在り、かつ資産（`public/imported/`）が消える・変わる**再取り込みは、
   * 1 バイトも書く前に全体を拒否する。部分的に守って危険を残さない。
   */
  if (protection.protect.size > 0) {
    const assetChanges = [
      ...(previous?.files ?? [])
        .map((f) => f.target)
        .filter((t) => isImportedAsset(t) && !currentTargets.has(t))
        .map((t) => `${t}（削除）`),
      ...states
        .filter(
          (s) =>
            isImportedAsset(s.file.target) &&
            s.state !== "new" &&
            s.state !== "unchanged",
        )
        .map((s) => `${s.file.target}（変更）`),
    ];

    if (assetChanges.length > 0) {
      const pagesInUse = [...new Set([...protection.usedBy.values()].flat())].sort();

      fail({
        what: "取り込みで作ったページが使う資産を置き換える再取り込みのため中止しました（何も変更していません）",
        why:
          `ページ [${pagesInUse.join(", ")}] が前回の取り込みの部品・枠を使っており、` +
          `この Plan はその意匠が依存する資産を変えます: ${assetChanges.slice(0, 8).join(", ")}` +
          (assetChanges.length > 8 ? ` ほか ${assetChanges.length - 8} 件` : ""),
        fix:
          "部品と枠だけ残しても CSS / 画像 / JS が変われば意匠が壊れるため、部分的には適用しません。" +
          "同じテンプレートの資産を変えない Plan にするか、そのページを使わない別の project へ取り込んでください",
      });

      return 1;
    }
  }

  // ---- 分類項目の preflight（read-only。ここで止めればまだ何も変えていない） ----
  // 同じ Plan を適用済みなら、「提案は absent なのに DB は active」は
  // **こちらが作った結果**であって食い違いではない
  const alreadyApplied = previous?.plan_sha256 === hashContent(planJson);
  const preflight = await preflightTerms(
    termProposals,
    projectRoot,
    alreadyApplied,
  );

  for (const line of preflight.notes) info(line);

  if (preflight.stop) {
    fail({
      what: "分類項目の状態を確認できないため中止しました（何も変更していません）",
      why: preflight.stop,
      fix: "CMS を起動して `agentcms template plan` を実行し直してから apply してください",
    });
    return 1;
  }

  if (opts.dryRun) {
    for (const s of changes) {
      if (frozen.includes(s)) {
        info(`would retain [in use]: ${s.file.target}（書き換えません）`);
        continue;
      }

      info(`would write [${s.state}]: ${s.file.target}`);
    }
    for (const t of preflight.create)
      info(`would create term: ${t.taxonomyKey}.${t.slug}（${t.name}）`);
    for (const l of describeImportedPages(
      result.importedPages,
      previous?.pages ?? [],
    ))
      info(l);
    ok("dry-run のため書き込みは行いませんでした（ファイル・DB とも 0 件）");
    return 0;
  }

  // ---- 復旧記録（**変更を始める前に**保存する） ----
  const journalFile = journalPath(agentcms);

  // **これから変わり得るものを全部**入れる。1 つでも漏れると、そこだけ元へ戻せない
  const journalTargets = [
    ...states.map((s) => s.file.target),
    ...schemaOverrideTargets(result, plan),
    ...(previous?.files.map((f) => f.target) ?? []), // prune 対象
    // --force のときの退避先（.agentcms/manifest/backup/ 配下）も変更対象
    ...states
      .filter((st) => st.state === "foreign" || st.state === "ours-modified")
      .map((st) => `.agentcms/manifest/backup/${st.file.target}`),
    "routes/web.php",
    "modules/Contact/Http/ContactController.php",
    ".env",
    ".env.example",
    ".agentcms/manifest/template-apply.json",
  ];

  // **危険な対象を黙って除外しない。** 除外しても writeFiles / prune は
  // その対象を書いたり消したりできてしまう。記録できないなら apply しない
  const unsafe = journalTargets.filter(
    (t) => !isSafeProjectTarget(projectRoot, t),
  );

  if (unsafe.length > 0) {
    fail({
      what: "安全に記録できない対象があるため中止しました（何も変更していません）",
      why: `プロジェクトの外を指す対象: ${unsafe.join(", ")}`,
      fix: "Plan と前回の適用記録の内容を確認してください",
    });

    return 1;
  }
  let journal: ApplyJournal;

  try {
    journal = writeJournal(
      journalFile,
      projectRoot,
      journalTargets,
      preflight.create.map((t) => ({
        taxonomy: t.taxonomyKey,
        slug: t.slug,
        name: t.name,
      })),
      hashContent(planJson),
      new Date().toISOString(),
    );
  } catch (e) {
    // **まだ 1 つも変更していない。** 戻せない対象があるまま始めない
    fail({
      what: "変更を始められません（何も変更していません）",
      why: e instanceof Error ? e.message : String(e),
      fix: "対象のファイルとリンクの状態を確認してから apply し直してください",
    });

    return 1;
  }

  // ---- ファイルの変更（**ここから先の失敗はすべて元へ戻す**） ----
  let written: string[] = [];

  /**
   * ファイルを元へ戻して失敗として終える。
   *
   * **戻し切れたら復旧記録も消す。** 残すと、あとから誰かが同じ分類項目を
   * 作ったときに、次回の復旧が「前回は commit 済みだった」と読み違え、
   * 既に戻したファイルに対して適用記録だけを保存してしまう。
   *
   * ここを通るのは **DB を呼んでいない**か、**確実に巻き戻った**ときだけ。
   * commit されたか分からないときは呼ばない（記録を残して次回に判断させる）。
   */
  const rollback = (what: string, why: string): number => {
    const outcome = rollbackJournal(projectRoot, journalFile, journal);

    if (outcome.failed.length > 0) {
      // 戻せなかったものがある。記録は残して次回の判断材料にする
      fail({
        what,
        why,
        fix: `次のファイルは戻せませんでした: ${outcome.failed.join(", ")}（.agentcms/manifest/template-apply-journal.json を確認してください）`,
      });

      return 1;
    }

    if (!outcome.cleared) {
      fail({
        what,
        why: `${why}（ファイルは元に戻しましたが、復旧記録を削除できませんでした: ${outcome.error ?? "理由不明"}）`,
        fix: ".agentcms/manifest/template-apply-journal.json を手で削除してから apply し直してください",
      });

      return 1;
    }

    fail({
      what,
      why,
      fix: "原因を解消してから apply し直してください（ファイルは元に戻しました）",
    });

    return 1;
  };

  try {
    const result_ = writeFiles(projectRoot, writeStates, backupDir);

    written = result_.written;

    for (const t of result_.backedUp)
      info(`backup: ${t} → .agentcms/manifest/backup/`);
    for (const t of result_.written) info(`write: ${t}`);

    reportPrune();
    applySchemaChanges(projectRoot, result, plan, schemas);

    /*
     * 生成した target が自分の配線を呼ぶ（指摘 34。真偽値を別に持ち回らない）。
     *
     * 見るのは `written` ではなく**この適用が持つ target 全部**である。
     * 中身が同じで書き直さなかった回にも配線は要る
     * （`web.php` の require だけ人が消した状態から再適用すると戻らなくなる）。
     * 配線側は冪等で、既に在れば `changed: false` を返す。
     */
    for (const { file } of states) {
      const target = file.target;
      const wire = POST_WRITE_WIRING[target];

      if (wire === undefined) continue;

      const r = wire(projectRoot);

      info(r.message(r.changed));
    }

    if (result.contactConstants.length > 0) {
      const r = patchContactServerConstants(
        projectRoot,
        result.contactConstants,
      );
      info(
        r.changed
          ? `contact: server constant (${result.contactConstants.map((c) => c.key).join(", ")}) を ContactController へ適用`
          : "contact: server constant は適用済み",
      );
    }

    for (const env of applyEnvAdditions(projectRoot, result.envAdditions)) {
      if (env.action !== "kept")
        info(`env(${env.action}): ${env.file} ${env.key}`);
    }
  } catch (e) {
    return rollback(
      "ファイルの変更に失敗したため、すべて元へ戻しました",
      e instanceof Error ? e.message : String(e),
    );
  }

  journal = advanceJournal(journalFile, journal, "files_written", projectRoot);

  /** この実行で作った投稿タイプ（完了時の案内に使う） */
  let createdTypes: string[] = [];

  /*
   * ---- 投稿タイプの新設（R-13 Stage 2）----
   *
   * **ファイルより後・分類項目より前。** 型は migration を伴う不可逆な変更なので、
   * 戻せるもの（ファイル）を全部済ませてから触る。
   *
   * **巻き戻さない。** 途中で失敗しても作れた分は残し、どこまで作れたかを言う。
   * 再実行は相手側が「既に在る型を飛ばす」ので、続きから進む。
   */
  if (plan.content_types.length > 0) {
    const types = await createContentTypes(plan.content_types, {
      root: projectRoot,
    });

    createdTypes = types.created;

    for (const id of types.created) info(`content type: [${id}] を作成`);
    for (const id of types.skipped)
      info(`content type: [${id}] は既に在ります`);

    if (types.kind !== "ok") {
      /*
       * **1 つでも作れていたら（あるいは作れたか分からなければ）phase を進める。**
       *
       * 進めないと、次の実行の復旧が「ファイルだけ変えた」と読んで
       * **ファイルを巻き戻す** — 型は残ったままなので、
       * 「型だけ在って置き場が無い」状態を自分で作ることになる。
       */
      if (types.kind === "unknown" || types.created.length > 0) {
        journal = advanceJournal(
          journalFile,
          journal,
          "types_created",
          projectRoot,
        );
      }

      const madeSoFar =
        types.created.length > 0
          ? `作成済み: ${types.created.join(", ")}（中身は未投入）。`
          : "作成された投稿タイプはありません。";

      fail({
        what: "投稿タイプの作成が途中で止まりました（作ったものは戻しません）",
        why: `${types.message ?? "理由を確認できませんでした"} — ${madeSoFar}`,
        fix: "原因を解消してから `agentcms template apply` を再実行してください（既に在る型は飛ばして続きから進みます）",
      });

      return 1;
    }
  }

  journal = advanceJournal(journalFile, journal, "types_created", projectRoot);

  // ---- 分類項目（1 トランザクション。全件成功か 0 件か） ----
  if (preflight.create.length > 0) {
    const created = await createTerms(preflight.create, { root: projectRoot });

    if (created.kind === "definite_rollback") {
      // CMS が構造化された失敗を返した = **DB は確実に巻き戻っている**
      return rollback(
        "分類項目を作成できなかったため、ファイルの変更も元へ戻しました",
        `${created.message}（分類項目は作成されていません）`,
      );
    }

    if (created.kind === "commit_unknown") {
      // **commit されたか分からない。** ここでファイルを戻すと
      // 「DB だけ残る」状態を自分で作ることになる。触らずに止める
      fail({
        what: "分類項目が作成されたかどうか確認できませんでした（自動では戻しません）",
        why: created.message,
        fix: "CMS の状態を確認したうえで `agentcms template apply` を再実行してください（実際の状態を見て続きを判断します）",
      });

      return 1;
    }

    for (const term of created.created)
      info(`term: ${term.taxonomy}.${term.slug}（${term.name}）を作成`);
  }

  // **作成が 0 件でもここまで来たら「DB の段は終わっている」。**
  // 分類項目を作ったかどうかに関係なく phase を進める。
  // files_written のまま残すと、次回の復旧が「DB は未確定」と読み違え、
  // 既に適用済みとして表示したファイルを巻き戻してしまう
  journal = advanceJournal(journalFile, journal, "db_committed", projectRoot);

  /*
   * ---- ページ（R03 / B-42）----
   *
   * 部品（ファイル）と一覧の元（型）が揃った**あと**。ここで失敗しても
   * ファイルも DB も戻さない — 正しく在るものを戻す理由が無い。
   * 結果は apply 記録の `pages` に残し、再実行が `kept` の意味を判断できるようにする。
   * **達成できなかったページがあれば、この実行は失敗で終える**（記録は保存する）。
   */
  const pagesOutcome = await applyImportedPages(
    result.importedPages,
    previous?.pages ?? [],
    { root: projectRoot },
  );

  for (const l of pagesOutcome.lines) {
    if (l.level === "warn") warn(l.text);
    else if (l.level === "info") info(l.text);
  }

  try {
    saveApplyRecord(
      recordPath,
      buildApplyRecord(
        result,
        manifest.fingerprint.total,
        hashContent(planJson),
        pagesOutcome.records,
        retainedRecords,
      ),
    );
  } catch (e) {
    // ここまで来ると **DB の段は確定している**（作成 0 件のこともある）。
    // 分類項目を作った可能性がある以上、ファイルを戻すと「DB だけ残る」状態を
    // 自分で作ることになるので戻さない。復旧記録を db_committed のまま残し、
    // 次回 apply が実際の状態を見て記録を締め直す
    fail({
      what: "適用記録を保存できませんでした（変更自体は適用済みです）",
      why: e instanceof Error ? e.message : String(e),
      fix: "書き込み権限を確認して `agentcms template apply` を再実行してください（再実行しても分類項目は作り直しません）",
    });

    return 1;
  }

  // 記録まで終わってはじめて復旧記録を捨てる
  advanceJournal(journalFile, journal, "recorded", projectRoot);
  clearJournal(journalFile);

  if (!pagesOutcome.ok) {
    /*
     * **ファイルと DB は適用済み。ページだけが達成できていない。**
     * 成功にしない — 緑にすると「トップは静的な Blade のまま・編集しても反映されない」が
     * 誰にも気づかれずに残る。
     */
    fail({
      what: "ページを起こせなかったため、apply は失敗で終えました（ファイルと分類項目は適用済み・記録も保存しました）",
      why: pagesOutcome.lines
        .filter((l) => l.level === "fail")
        .map((l) => l.text)
        .join(" / "),
      fix: "上の指示どおりに直してから `agentcms template apply` を再実行してください（適用済みのファイルは書き直されません）",
    });

    return 1;
  }

  console.log("");
  if (
    written.length === 0 &&
    pagesOutcome.records.every((r) => r.action === "kept" && !r.front_set)
  ) {
    ok("ファイル変更なし（適用済み — no-op）");
    return 0;
  }
  ok(
    written.length === 0
      ? "ファイル変更なし（ページの状態だけを確かめました）"
      : `適用完了: ${written.length} ファイル`,
  );

  /*
   * **生成した物に応じて出し分ける**（R-13）。
   *
   * seeder は**取り込む中身があったときだけ**生成される。無いのに
   * `db:seed --class=TemplateContentSeeder` を案内すると、そのとおりにやった人は
   * `BindingResolutionException` を踏む。**書いてあるとおりにやると止まる案内**は、
   * 案内が無いより悪い（指摘 32 と同じ形）。
   */
  const seeded = written.some((f) =>
    f.endsWith("database/seeders/TemplateContentSeeder.php"),
  );

  info("次のステップ:");

  const steps: string[] = [];

  if (seeded) {
    steps.push(
      "php artisan db:seed --class='Database\\Seeders\\TemplateContentSeeder'  # 取り込んだ内容の投入",
    );
  }

  /*
   * **型を作った回は、もう一度 plan を回してもらう**（R-13 Stage 2）。
   *
   * 型が無い時点では「選択肢を足す」提案を出せない（定義が無いものは検証できない）。
   * 作ったあとに plan を回すと、取り込んだ値（カテゴリなど）を
   * その型へ反映する提案が出る。言わないと、**取り込んだ値が選択肢に無いまま**残り、
   * 管理画面で開いたときに初めて気づくことになる。
   */
  if (createdTypes.length > 0) {
    steps.push(
      `agentcms template plan  # 作った投稿タイプ（${createdTypes.join(", ")}）へ、取り込んだ値を反映する提案が出ます`,
    );
  }

  /*
   * **トップをページにした回は、編集の入口を言う**（R03 / B-42）。
   * 作っただけでは「Blade を直す」習慣のまま進む人がいる。編集してよい場所を 1 行で示す。
   */
  for (const page of pagesOutcome.records) {
    if (page.action !== "created" && !page.front_set) continue;

    steps.push(
      `管理画面 > ページ > 「${page.title}」で本文を編集して公開  # ${page.front_set ? "/ と " : ""}/${page.slug} に反映されます（生成した Blade は直さない）`,
    );
  }

  steps.push("agentcms template verify  # 変換結果の検証");

  steps.forEach((step, i) => info(`  ${i + 1}. ${step}`));

  if (!seeded) {
    info("     （CMS へ取り込んだ内容が無いので、seeder は生成していません）");
  }

  return 0;
}

/**
 * 前回の記録にある取り込みの部品・枠のうち、いまも同じ中身で在るもの（復旧の closeout で引き継ぐ）。
 */
function stillPresentImportedFiles(
  projectRoot: string,
  previous: ReturnType<typeof loadApplyRecord>,
): ReturnType<typeof loadApplyRecord> extends infer R
  ? R extends { files: infer F }
    ? F
    : never
  : never {
  type Files = NonNullable<ReturnType<typeof loadApplyRecord>>["files"];
  const out: Files = [];

  for (const f of previous?.files ?? []) {
    if (componentTypeOf(f.target) === null && layoutViewOf(f.target) === null)
      continue;

    const abs = join(projectRoot, f.target);

    if (!existsSync(abs)) continue;
    if (hashContent(readFileSync(abs)) !== f.sha256) continue;

    out.push(f);
  }

  return out as never;
}

/** 取り込みが写した資産（CSS / 画像 / JS）。取り込んだページの意匠はこれに依存する */
function isImportedAsset(target: string): boolean {
  return target.startsWith("public/imported/");
}

/** override を書く先も復旧の対象にする（base は触らないが override は変わる） */
function schemaOverrideTargets(
  result: { schemaPatches: { schema: string }[] },
  plan: TemplatePlan,
): string[] {
  const ids = new Set<string>();

  for (const patch of result.schemaPatches) ids.add(patch.schema);
  for (const change of plan.schema_changes) {
    if (change.change === "make_optional") ids.add(change.schema);
  }

  return [...ids].map((id) => `content/schemas/overrides/${id}.json`);
}

export interface Preflight {
  /** 実際に作る項目（active exact match は含まない） */
  create: { taxonomyKey: string; name: string; slug: string }[];
  notes: string[];
  /** 中止の理由。null なら続行してよい */
  stop: string | null;
}

/**
 * 分類項目の **適用直前の再確認**（§1-D / §1-E）。
 *
 * Plan を作ってから apply するまでの間に、誰かが同じ項目を作ったり、
 * archive したり、名前を変えたりしているかもしれない。
 * plan の時点の判断をそのまま信じて作ると、重複や取り違えが起きる。
 *
 * **read-only。** ここで止まれば、ファイルも DB も 1 件も変わっていない。
 */
export async function preflightTerms(
  proposals: TaxonomyChange[],
  projectRoot: string,
  alreadyApplied: boolean,
): Promise<Preflight> {
  const preflight: Preflight = { create: [], notes: [], stop: null };

  if (proposals.length === 0) return preflight;

  const keys = [...new Set(proposals.map((p) => p.taxonomy))].sort();
  const catalog = await fetchTaxonomyCatalog(keys, { root: projectRoot });

  for (const proposal of proposals) {
    const label = `${proposal.taxonomy}「${proposal.source_value}」`;

    // **未解決の提案を「作らないから成功」にしない。**
    // アーカイブ済みとの衝突も、slug を決められない値も、確認できない状態も、
    // Plan には解決方法が書かれていない。人が決めるまで進めない
    if (proposal.match.kind === "archived") {
      preflight.stop = `${label}: 同じ slug の項目がアーカイブ済みです。復帰させるか別の slug にするかを決めてください`;

      return preflight;
    }

    if (proposal.match.kind === "undetermined") {
      preflight.stop = `${label}: ${proposal.match.reason}`;

      return preflight;
    }

    const now = matchTerm(catalog, proposal.taxonomy, proposal.proposed_slug);

    if (now.kind === "undetermined") {
      preflight.stop = `${label}: ${now.reason}`;

      return preflight;
    }

    if (now.kind === "archived") {
      preflight.stop = `${label}: 同じ slug [${now.slug}] の項目がアーカイブ済みです。復帰させるか別の slug にするかを決めてください`;

      return preflight;
    }

    // ---- 既存を使う提案: **作らないが、いまも同じものかを確かめる** ----
    if (proposal.match.kind === "active") {
      if (now.kind !== "active") {
        preflight.stop = `${label}: 使うはずだった項目が見つかりません（${matchLabel(proposal.match)} → ${matchLabel(now)}）`;

        return preflight;
      }

      if (now.term_id !== proposal.match.term_id) {
        preflight.stop = `${label}: 同じ slug の項目が別のもの（${now.term_id}）に入れ替わっています`;

        return preflight;
      }

      // **名前は比べない。** 照合の基準は分類内の canonical slug 完全一致（IM-13）で、
      // 取り込んだ表記（PRESS）と既存の名前（Press）が違うのは正常な再利用。
      // ここで名前まで要求すると、正しい再利用が止まってしまう
      preflight.notes.push(`term: ${label} は既存を利用します（作成しません）`);

      continue;
    }

    // ---- ここから先は「新規作成の提案」だけ ----
    if (
      proposal.confidence !== "CONFIDENT" ||
      proposal.proposed_slug === null
    ) {
      preflight.stop = `${label}: 新規作成の提案として確定していません（人の確認が必要です）`;

      return preflight;
    }

    if (now.kind === "active") {
      // 同じ Plan を適用済みなら、これは **こちらが作った結果**
      const appliedByUs =
        alreadyApplied &&
        catalogName(catalog, proposal.taxonomy, now.slug) ===
          proposal.proposed_name;

      if (!appliedByUs) {
        preflight.stop = `${label}: Plan を作ったあとに同じ slug の項目が作られています`;

        return preflight;
      }

      preflight.notes.push(`term: ${label} は適用済みです（作成しません）`);

      continue;
    }

    preflight.create.push({
      taxonomyKey: proposal.taxonomy,
      name: proposal.proposed_name,
      slug: proposal.proposed_slug,
    });
  }

  return preflight;
}

/** schema の変更（選択肢の追加 / optional 化）を **override へ**適用する */
function applySchemaChanges(
  projectRoot: string,
  result: {
    schemaPatches: { schema: string; field: string; values: string[] }[];
  },
  plan: TemplatePlan,
  schemas: SchemaMap,
): void {
  for (const patch of result.schemaPatches) {
    // **base の content/schemas/<id>.json は触らない。** project の override へ加算する
    const { changed, path } = writeSchemaChoicesOverride(
      projectRoot,
      schemas[patch.schema],
      patch.field,
      patch.values,
    );

    info(
      changed
        ? `schema override: ${path} — ${patch.schema}.${patch.field} の選択肢へ ${patch.values.join(", ")} を追加`
        : `schema override: ${patch.schema}.${patch.field} は適用済み`,
    );
  }

  for (const change of plan.schema_changes.filter(
    (c) => c.change === "make_optional" && c.confidence === "CONFIDENT",
  )) {
    const { changed, path } = writeSchemaOverride(
      projectRoot,
      schemas[change.schema],
      change.field,
      { required: false },
    );

    info(
      changed
        ? `schema override: ${path} — ${change.schema}.${change.field} を optional 化`
        : `schema override: ${change.schema}.${change.field} は適用済み`,
    );
  }
}

/**
 * 前回の apply が途中で終わっていたら、**新しい変更を重ねる前に**片づける。
 *
 * どこで落ちたかで意味が違うので、phase と「作る予定だった項目の現在の状態」で判断する。
 *
 * | phase | 意味 | 判断 |
 * | --- | --- | --- |
 * | prepared | **DB を呼ぶ前**に落ちた | 必ずファイルを戻す。項目が見えたら外部の作成なので止める |
 * | files_written | DB を呼んだ前後が分からない | 予定の項目が全部あれば完了扱い、0 件なら戻す、それ以外は止める |
 * | types_created | 投稿タイプまで作った | **何も戻さない**（記録だけ捨てて再実行を促す） |
 * | db_committed | 記録だけ未保存 | 記録を締める |
 * | recorded | 消し忘れ | 捨てる |
 *
 * `prepared` で closeout してはいけない。この phase では **DB を 1 度も呼んでいない**ので、
 * 項目が存在するなら別の誰かが作ったということであり、こちらの完了ではない。
 */
interface Recovery {
  notes: string[];
  stop: string | null;
  /** 復旧でファイルや記録を変えたか。変えたらこの実行は続けない */
  changed: boolean;
}

async function recoverJournal(
  journalFile: string,
  projectRoot: string,
  recordPath: string,
  planSha256: string,
  buildRecord: () => ReturnType<typeof buildApplyRecord>,
  dryRun: boolean,
): Promise<{ notes: string[]; stop: string | null; changed: boolean }> {
  const journal = readJournal(journalFile, projectRoot);

  if (!journal) {
    // 壊れた記録が残っている場合は、それ自体が判断材料にならない
    if (existsSync(journalFile)) {
      return {
        notes: [],
        changed: false,
        stop: "前回の復旧記録を読み取れません（改変・破損の可能性）",
      };
    }

    return { notes: [], stop: null, changed: false };
  }

  // **dry-run では 1 バイトも書かない。** 復旧も書き込みなので行わない
  if (dryRun) {
    return {
      notes: [],
      changed: false,
      stop: `前回の apply が phase [${journal.phase}] で終わっています。dry-run では復旧しません（先に dry-run 無しで実行してください）`,
    };
  }

  const notes = [
    `前回の apply が phase [${journal.phase}] で終わっています。先に片づけます`,
  ];

  if (journal.phase === "recorded") {
    clearJournal(journalFile);

    return { notes, stop: null, changed: true };
  }

  /** 記録を締めて復旧を終える。**別 Plan の記録は書かない** */
  const closeout = (): Recovery => {
    if (journal.plan_sha256 !== planSha256) {
      return {
        notes,
        changed: false,
        stop: "前回の中断は別の Plan によるものです。その Plan の記録を今の Plan で締めることはできません",
      };
    }

    try {
      saveApplyRecord(recordPath, buildRecord());
    } catch (e) {
      return {
        notes,
        changed: false,
        stop: `適用記録を保存できませんでした: ${e instanceof Error ? e.message : String(e)}`,
      };
    }

    try {
      clearJournal(journalFile);
    } catch (e) {
      return {
        notes,
        changed: true,
        stop: `復旧記録を削除できませんでした: ${e instanceof Error ? e.message : String(e)}`,
      };
    }

    notes.push("前回の変更は適用済みでした。記録を保存して復旧を完了しました");

    return { notes, stop: null, changed: true };
  };

  /** ファイルを戻して復旧を終える */
  const rollback = (reason: string): Recovery => {
    const restored = restoreFromJournal(projectRoot, journal);

    if (restored.failed.length > 0) {
      return {
        notes,
        changed: true,
        stop: `前回の変更を戻せませんでした: ${restored.failed.join(", ")}`,
      };
    }

    try {
      clearJournal(journalFile);
    } catch (e) {
      return {
        notes,
        changed: true,
        stop: `復旧記録を削除できませんでした: ${e instanceof Error ? e.message : String(e)}`,
      };
    }

    notes.push(reason);

    return { notes, stop: null, changed: true };
  };

  if (journal.phase === "db_committed") return closeout();

  /*
   * **投稿タイプまで作った所で落ちた場合は、何も戻さない**（R-13 Stage 2 / 裁定 (ii)）。
   *
   * 型は migration を伴う不可逆な変更で、削除は作成の裏返しではない。
   * ここでファイルを戻すと「**型だけ在って中身の置き場が無い**」状態を自分で作る。
   *
   * ▷ ファイルは残したまま復旧記録だけ捨て、**もう一度 apply してもらう**。
   *   同じ内容のファイルは書き直されず、既に在る型は飛ばされ、分類項目から続く。
   */
  if (journal.phase === "types_created") {
    try {
      clearJournal(journalFile);
    } catch (e) {
      return {
        notes,
        changed: true,
        stop: `復旧記録を削除できませんでした: ${e instanceof Error ? e.message : String(e)}`,
      };
    }

    notes.push(
      "前回は投稿タイプの作成まで進んでいました。作った投稿タイプは残し（中身は未投入）、ファイルもそのままにしました",
    );

    return { notes, stop: null, changed: true };
  }

  if (journal.terms.length === 0) {
    return rollback("前回の途中変更を元へ戻しました");
  }

  const keys = [...new Set(journal.terms.map((t) => t.taxonomy))].sort();
  const catalog = await fetchTaxonomyCatalog(keys, { root: projectRoot });
  let active = 0;

  for (const term of journal.terms) {
    const match = matchTerm(catalog, term.taxonomy, term.slug);

    if (match.kind === "undetermined") {
      return {
        notes,
        changed: false,
        stop: `${term.taxonomy}.${term.slug}: ${match.reason}`,
      };
    }

    if (match.kind === "archived") {
      return {
        notes,
        changed: false,
        stop: `${term.taxonomy}.${term.slug}: アーカイブ済みの項目と衝突しています`,
      };
    }

    if (match.kind === "active") {
      // **名前まで一致して初めて「自分が作ったもの」とみなす。**
      // slug だけ同じ別物を、自分の成果と取り違えない
      const found = catalog.terms[term.taxonomy]?.find(
        (t) => t.slug === term.slug,
      );

      if (found?.name !== term.name) {
        return {
          notes,
          changed: false,
          stop: `${term.taxonomy}.${term.slug}: 既存の項目名（${found?.name ?? "不明"}）が記録（${term.name}）と違います`,
        };
      }

      active++;
    }
  }

  // prepared は **DB を 1 度も呼んでいない**。項目が見えるなら外部の作成
  if (journal.phase === "prepared") {
    if (active > 0) {
      return {
        notes,
        changed: false,
        stop: `DB を呼ぶ前に中断していますが、作成予定だった項目のうち ${active} 件が既に存在します（別の経路で作られた可能性）`,
      };
    }

    return rollback("前回の途中変更を元へ戻しました（DB は呼ばれていません）");
  }

  if (active === journal.terms.length) return closeout();

  if (active > 0) {
    return {
      notes,
      changed: false,
      stop: `作成予定だった ${journal.terms.length} 件のうち ${active} 件だけが存在します。手で確認してください`,
    };
  }

  return rollback(
    "前回の途中変更を元へ戻しました（分類項目は作成されていませんでした）",
  );
}

/** catalog から slug に対応する名前を引く（取り違え防止の照合用） */
function catalogName(
  catalog: ReturnType<typeof emptyCatalog>,
  taxonomy: string,
  slug: string,
): string | undefined {
  return catalog.terms[taxonomy]?.find((t) => t.slug === slug)?.name;
}

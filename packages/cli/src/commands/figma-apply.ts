/**
 * agentcms figma apply（SPEC §60–66）。
 * 承認済み Plan を SiteGrowKit のプロジェクト へ適用する。**Figma へは書き込まない（Read Only）。**
 * - stale Plan 拒否: Plan ↔ Manifest fingerprint + 現在の Figma version（metadata 1 call のみ）
 * - conflict STOP（--force で backup 後上書き）/ 再 apply は no-op / provenance 記録
 * - Phase 5 の apply 基盤（classifyFiles / writeFiles / Schema Override）を再利用
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";

import type { GeneratedFile } from "../importer/generate.js";
import {
  advanceJournal,
  type ApplyJournal,
  buildApplyRecord,
  classifyFiles,
  clearJournal,
  hashContent,
  loadApplyRecord,
  patchRoutesRequire,
  readJournal,
  rollbackJournal,
  writeSchemaChoicesOverride,
  pruneStaleFiles,
  saveApplyRecord,
  writeFiles,
  writeJournal,
  writeSchemaOverride,
} from "../importer/apply.js";
import {
  loadProjectSchemas,
  SchemaContractError,
} from "../importer/project-schemas.js";
import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import type { FigmaApi } from "../figma/api.js";
import { loadFigmaConfig } from "../figma/config.js";
import { generateFromFigmaPlan } from "../figma/generate.js";
import type { DesignManifest } from "../figma/manifest.js";
import type { FigmaPlan } from "../figma/plan-types.js";
import { diagnoseApiFailure, resolveFigmaApi } from "../figma/provider.js";
import {
  loadRegistry,
  registryPath,
  saveRegistry,
  upsertRegistryEntry,
} from "../figma/registry.js";
import { figmaManifestPath } from "./figma-inspect.js";
import { figmaPlanPath } from "./figma-plan.js";

export function figmaApplyRecordPath(agentcmsDir: string): string {
  return join(agentcmsDir, "manifest", "figma-apply.json");
}

export async function figmaApply(
  opts: { dryRun?: boolean; force?: boolean } = {},
): Promise<number> {
  const agentcmsDir = findAgentcmsDir();
  const projectRoot = findProjectRoot();
  if (!agentcmsDir || !projectRoot) {
    fail({
      what: "figma apply を実行できません",
      why: ".agentcms/ またはプロジェクトルートが見つかりません",
      fix: "SiteGrowKit のプロジェクト内で実行してください",
    });
    return 1;
  }

  heading("figma apply");

  // ---- 前回の中断を **何よりも先に**片づける ----
  // ここが後ろにあると、前回書きかけた生成物が「apply 外で作られたファイル」に
  // 見えて conflict で止まり、**復旧そのものに辿り着けなくなる**。
  // Figma 経路は DB を触らないので、中断は常にファイルだけの問題になる。
  const journalFile = figmaJournalPath(agentcmsDir);
  const recovery = recoverFigmaJournal(journalFile, projectRoot, {
    dryRun: opts.dryRun === true,
  });

  for (const note of recovery.notes) {
    (note.level === "warn" ? warn : info)(note.text);
  }

  if (recovery.stop) {
    fail({
      what: "前回の apply が途中で終わっています（何も変更していません）",
      why: recovery.stop,
      fix: ".agentcms/manifest/figma-apply-journal.json の内容を確認してください",
    });
    return 1;
  }

  if (recovery.restored) {
    ok(
      "前回の中断を復旧しました。もう一度 `agentcms figma apply` を実行してください",
    );
    return 0;
  }

  // ---- Manifest / Plan の読込と stale 検査（SPEC §23/§64） ----
  const manifestPath = figmaManifestPath(agentcmsDir);
  const planPath = figmaPlanPath(agentcmsDir);
  if (!existsSync(manifestPath) || !existsSync(planPath)) {
    fail({
      what: "Manifest / Plan がありません",
      why: `${manifestPath} または ${planPath} が存在しません`,
      fix: "agentcms figma inspect → figma plan の順に実行してください",
    });
    return 1;
  }
  const manifest = JSON.parse(
    readFileSync(manifestPath, "utf8"),
  ) as DesignManifest;
  const planJson = readFileSync(planPath, "utf8");
  const plan = JSON.parse(planJson) as FigmaPlan;
  if (plan.manifest_fingerprint !== manifest.source.fingerprint) {
    fail({
      what: "Plan が現在の Design Manifest と一致しません（stale plan）",
      why: `plan fingerprint ${plan.manifest_fingerprint.slice(0, 12)}… ≠ manifest ${manifest.source.fingerprint.slice(0, 12)}…`,
      fix: "agentcms figma plan を再実行し、内容を確認してから apply してください",
    });
    return 1;
  }

  // ---- 現在の Figma version 確認（metadata 1 call のみ。inspect 後の Figma 変更を拒否） ----
  const apiRes = resolveFigmaApi();
  if ("error" in apiRes) {
    fail(apiRes.error);
    return 1;
  }
  const api = apiRes.api;
  const { config } = loadFigmaConfig(agentcmsDir);
  if (config) {
    const metaRes = await api.getFileMeta(config.file_key);
    if (!metaRes.ok) {
      fail(diagnoseApiFailure("Figma version の確認", metaRes));
      return 1;
    }
    if (metaRes.data.version !== manifest.source.figma_version) {
      fail({
        what: "Figma が inspect 後に更新されています（stale plan）",
        why: `Figma version ${metaRes.data.version} ≠ Manifest ${manifest.source.figma_version}`,
        fix: "agentcms figma inspect → figma diff で変更内容を確認し、figma plan からやり直してください（自動再適用はしない: SPEC §69）",
      });
      return 1;
    }
  }

  // ---- Git working tree の確認（SPEC §64） ----
  try {
    const dirty = execFileSync("git", ["status", "--porcelain"], {
      cwd: projectRoot,
      encoding: "utf8",
    })
      .split("\n")
      .filter(Boolean);
    if (dirty.length > 0) {
      warn(
        `Git working tree に未コミットの変更が ${dirty.length} 件あります。apply 前の commit を推奨します`,
      );
    }
  } catch {
    // git が無い環境（テスト workspace 等）は続行
  }

  // ---- コンテンツ定義を **書き込みの前に**読む ----
  // 以前は override を書く直前に読んでいたため、定義が壊れていると
  // ファイル・route まで適用してから失敗し、途中の状態が残っていた。
  // 定義を根拠に override を書く以上、読めないなら **1 つも変更しない**
  let figmaSchemas;

  try {
    figmaSchemas = loadProjectSchemas(projectRoot);
  } catch (e) {
    if (e instanceof SchemaContractError) {
      fail({
        what: "コンテンツ定義を読み取れないため中止しました（何も変更していません）",
        why: `${e.file}: ${e.message}`,
        fix: "content/schemas/ の定義を直してから apply し直してください",
      });

      return 1;
    }

    throw e;
  }

  // ---- 生成 + asset ダウンロード（Reference-Based のみ） ----
  const result = generateFromFigmaPlan(manifest, plan);
  const files: GeneratedFile[] = [...result.files];
  if (result.downloads.length > 0 && config) {
    const downloaded = await downloadAssets(
      api,
      config.file_key,
      result.downloads,
    );
    if ("error" in downloaded) {
      fail(downloaded.error);
      return 1;
    }
    files.push(...downloaded.files);
  }

  // ---- conflict 判定（Phase 5 と同じ状態機械） ----
  const recordPath = figmaApplyRecordPath(agentcmsDir);
  const previous = loadApplyRecord(recordPath);
  const states = classifyFiles(projectRoot, files, previous);
  const conflicts = states.filter(
    (s) => s.state === "foreign" || s.state === "ours-modified",
  );
  const summary = {
    new: states.filter((s) => s.state === "new").length,
    unchanged: states.filter((s) => s.state === "unchanged").length,
    ours: states.filter((s) => s.state === "ours").length,
  };
  info(
    `生成 ${files.length} ファイル（new ${summary.new} / unchanged ${summary.unchanged} / 更新 ${summary.ours} / conflict ${conflicts.length}）`,
  );
  if (opts.dryRun) {
    for (const s of states) {
      info(`${s.state.padEnd(14)} ${s.file.target}`);
    }
    ok("dry-run のため書き込みは行いませんでした");
    return 0;
  }
  if (conflicts.length > 0 && !opts.force) {
    fail({
      what: "既存ファイルとの conflict を検出したため STOP しました",
      why: conflicts
        .map(
          (c) =>
            `${c.file.target}（${c.state === "foreign" ? "apply 外で作成" : "apply 後に手動変更"}）`,
        )
        .join(", "),
      fix: "内容を確認し、上書きしてよければ --force（原本は .agentcms/manifest/backup-figma/ へ退避されます）",
    });
    return 1;
  }

  // ---- 書き込み ----
  const backupDir = join(agentcmsDir, "manifest", "backup-figma");

  // **変更を始める前に**、変わり得るものを全部控える。
  // 1 つでも控え損ねると、そこだけ元へ戻せない
  let journal: ApplyJournal;

  try {
    journal = writeJournal(
      journalFile,
      projectRoot,
      mutationTargets({
        projectRoot,
        agentcmsDir,
        states,
        previous,
        plan,
        schemaPatches: result.schemaPatches,
        recordPath,
      }),
      [], // Figma 経路は DB を触らない
      hashContent(planJson),
      new Date().toISOString(),
    );
  } catch (e) {
    // まだ 1 つも変更していない。戻せない対象があるまま始めない
    fail({
      what: "変更を始められません（何も変更していません）",
      why: e instanceof Error ? e.message : String(e),
      fix: "対象のファイルとリンクの状態を確認してから apply し直してください",
    });
    return 1;
  }

  /** 変更を元へ戻して失敗として終える */
  const rollback = (what: string, why: string): number => {
    const outcome = rollbackJournal(projectRoot, journalFile, journal);

    if (outcome.failed.length > 0) {
      fail({
        what,
        why,
        fix: `次のファイルは戻せませんでした: ${outcome.failed.join(", ")}（.agentcms/manifest/figma-apply-journal.json を確認してください）`,
      });

      return 1;
    }

    if (!outcome.cleared) {
      fail({
        what,
        why: `${why}（ファイルは元に戻しましたが、復旧記録を削除できませんでした: ${outcome.error ?? "理由不明"}）`,
        fix: ".agentcms/manifest/figma-apply-journal.json を手で削除してから apply し直してください",
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

  let written: string[] = [];
  let backedUp: string[] = [];
  let prune: ReturnType<typeof pruneStaleFiles> = {
    deleted: [],
    restored: [],
    kept: [],
    retained: [],
  };

  try {
    ({ written, backedUp } = writeFiles(projectRoot, states, backupDir));
    prune = pruneStaleFiles(
      projectRoot,
      previous,
      new Set(files.map((f) => f.target)),
      backupDir,
    );

    // routes/web.php へ require 追記（冪等）
    if (result.figmaRoutes) {
      const patched = patchRoutesRequire(
        projectRoot,
        "figma.php",
        "// agentcms figma apply — Figma import 画面の route（ADR-0011）",
      );
      if (patched.changed) info(`routes/web.php へ require を追記しました`);
    }

    // Project Scope Schema Override（ADR-0010 Rev.1 の機構を再利用: SPEC §63）
    for (const o of plan.schema_overrides) {
      const r = writeSchemaOverride(
        projectRoot,
        figmaSchemas[o.schema],
        o.field,
        o.patch,
      );
      if (r.changed)
        info(
          `schema override: ${o.schema}.${o.field} ← ${JSON.stringify(o.patch)}`,
        );
    }

    // select choices の追加（不足カテゴリのみ・冪等）
    for (const p of result.schemaPatches) {
      // **base の content/schemas/<id>.json は触らない**（Template Import と同じ扱い）。
      // kit が配った定義を import 結果で汚すと、更新時に衝突し、
      // どれが標準でどれが取り込み結果かも区別できなくなる
      const r = writeSchemaChoicesOverride(
        projectRoot,
        figmaSchemas[p.schema],
        p.field,
        p.values,
      );
      if (r.changed)
        info(
          `schema choices 追加: ${p.schema}.${p.field} += ${p.values.join(", ")}`,
        );
    }

    // Component Registry を mapped へ昇格（SPEC §33–34）
    const registry = loadRegistry(agentcmsDir);

    for (const c of plan.components) {
      const component = manifest.components.find(
        (m) => m.component_id === c.component_id,
      );
      if (!component) continue;
      const target = c.existing_target ?? c.target;
      upsertRegistryEntry(
        registry,
        component,
        { type: target ? "blade" : null, target },
        c.action === "skip" ? "review" : "mapped",
      );
    }
    saveRegistry(agentcmsDir, registry);

    // provenance（SPEC §66）
    saveApplyRecord(
      recordPath,
      buildApplyRecord(
        { files } as Parameters<typeof buildApplyRecord>[0],
        manifest.source.fingerprint,
        hashContent(planJson),
      ),
    );

    // 「全部書けた」と印を付けるところまでが適用の一部。
    // ここが失敗したまま先へ進むと、**次回の起動時に何を信じるか**が決まらない
    advanceJournal(journalFile, journal, "recorded", projectRoot);
  } catch (e) {
    // **後段のどこで落ちても**、生成物だけが残る状態にしない
    return rollback(
      "適用に失敗したため、変更をすべて元へ戻しました",
      e instanceof Error ? e.message : String(e),
    );
  }

  // ---- ここから先は適用済み。**もうファイルは戻さない** ----
  // 復旧記録の後始末に失敗しても、書けたものを巻き戻すのは誤り。
  // 記録は phase=recorded のまま残し、次回の起動時に片づける
  try {
    clearJournal(journalFile);
  } catch (e) {
    fail({
      what: "適用は完了しましたが、復旧記録を削除できませんでした",
      why: e instanceof Error ? e.message : String(e),
      fix: ".agentcms/manifest/figma-apply-journal.json を手で削除してください（次回の apply でも片づきます。ファイルは適用済みのまま保持しています）",
    });

    return 1;
  }

  if (backedUp.length > 0) warn(`backup: ${backedUp.join(", ")}`);
  if (prune.deleted.length > 0)
    info(`旧生成物を削除: ${prune.deleted.join(", ")}`);
  ok(
    written.length === 0
      ? "変更なし（no-op）— 同一 Plan の再 apply です"
      : `${written.length} ファイルを書き込みました`,
  );
  info("Figma への書き込みは行っていません（Read Only: SPEC §6）");
  info(
    "次: php artisan db:seed --class=Database\\\\Seeders\\\\FigmaContentSeeder（seed 使用時）→ agentcms figma verify",
  );
  return 0;
}

async function downloadAssets(
  api: FigmaApi,
  fileKey: string,
  downloads: { image_ref: string; target: string; source: string }[],
): Promise<
  { files: GeneratedFile[] } | { error: ReturnType<typeof diagnoseApiFailure> }
> {
  const fillsRes = await api.getImageFills(fileKey);
  if (!fillsRes.ok) {
    return { error: diagnoseApiFailure("image fills の取得", fillsRes) };
  }
  const urls = fillsRes.data.meta.images;
  const files: GeneratedFile[] = [];
  for (const d of downloads) {
    const url = urls[d.image_ref];
    if (!url) {
      warn(`image fill URL がありません: ${d.image_ref}（スキップ）`);
      continue;
    }
    const dl = await api.download(url);
    if (!dl.ok) {
      warn(`asset ダウンロード失敗: ${d.image_ref}（${dl.status}）— スキップ`);
      continue;
    }
    files.push({ target: d.target, content: dl.data, source: d.source });
  }
  return { files };
}

/** Figma 専用の復旧記録。Template Import とは **別のファイル**にする */
function figmaJournalPath(agentcmsDir: string): string {
  return join(agentcmsDir, "manifest", "figma-apply-journal.json");
}

/**
 * この apply で変わり得るものを全部並べる。
 *
 * 1 つでも漏れると、そこだけ元へ戻せない。
 * 生成物だけでなく、**退避先・整理対象・追記するファイル・記録**まで数える。
 */
function mutationTargets(input: {
  projectRoot: string;
  agentcmsDir: string;
  states: ReturnType<typeof classifyFiles>;
  previous: ReturnType<typeof loadApplyRecord>;
  plan: FigmaPlan;
  schemaPatches: { schema: string }[];
  recordPath: string;
}): string[] {
  const rel = (abs: string): string => relative(input.projectRoot, abs);
  const targets = new Set<string>();

  for (const state of input.states) {
    targets.add(state.file.target);

    // --force のときの退避先も書き換わる
    if (state.state === "foreign" || state.state === "ours-modified") {
      targets.add(`.agentcms/manifest/backup-figma/${state.file.target}`);
    }
  }

  // 前回の生成物（prune で消えたり復元されたりする）
  for (const file of input.previous?.files ?? []) targets.add(file.target);

  targets.add("routes/web.php");

  // schema override の書き込み先（override と choices の両方）
  for (const id of [
    ...input.plan.schema_overrides.map((o) => o.schema),
    ...input.schemaPatches.map((p) => p.schema),
  ]) {
    targets.add(`content/schemas/overrides/${id}.json`);
  }

  targets.add(rel(registryPath(input.agentcmsDir)));
  targets.add(rel(input.recordPath));

  return [...targets];
}

interface RecoveryNote {
  level: "info" | "warn";
  text: string;
}

interface RecoveryOutcome {
  notes: RecoveryNote[];
  /** 続行できない理由。あれば失敗として終える */
  stop: string | null;
  /** 途中変更を戻した。**その実行では続けない**（再実行を促す） */
  restored: boolean;
}

/**
 * 前回の中断を片づける。
 *
 * Figma 経路は DB を触らないので、判断は phase だけで足りる。
 *
 * | phase | 判断 |
 * | --- | --- |
 * | recorded | 適用は終わっていた。記録だけ捨てて **そのまま続行** |
 * | それ以前 | ファイルを元へ戻し、**その実行は続けない** |
 *
 * Plan が変わっていても同じで、**前回の途中結果の上に新しい apply を重ねない**。
 *
 * dry-run は「書かない」と言っている以上、後片付けも復元もしない。
 * ただし黙って無視すると、次の apply が止まる理由が分からなくなるので **知らせる**。
 */
function recoverFigmaJournal(
  journalFile: string,
  projectRoot: string,
  opts: { dryRun: boolean },
): RecoveryOutcome {
  const journal = readJournal(journalFile, projectRoot);

  if (!journal) {
    // 壊れた記録が残っているなら、それ自体が判断材料にならない
    if (existsSync(journalFile)) {
      const why = "前回の復旧記録を読み取れません（改変・破損の可能性）";

      return opts.dryRun
        ? {
            notes: [{ level: "warn", text: `${why}。dry-run では触りません` }],
            stop: null,
            restored: false,
          }
        : { notes: [], stop: why, restored: false };
    }

    return { notes: [], stop: null, restored: false };
  }

  const found: RecoveryNote = {
    level: "info",
    text: `前回の apply が phase [${journal.phase}] で終わっています。先に片づけます`,
  };

  if (opts.dryRun) {
    return {
      notes: [
        {
          level: "warn",
          text: `前回の apply が phase [${journal.phase}] のまま残っています（dry-run のため何も変更していません。次の apply が先に復旧します）`,
        },
      ],
      stop: null,
      restored: false,
    };
  }

  if (journal.phase === "recorded") {
    // 適用そのものは終わっていた。記録の消し忘れなので、戻さず捨てるだけ
    try {
      clearJournal(journalFile);
    } catch (e) {
      return {
        notes: [found],
        restored: false,
        stop: `前回の復旧記録を削除できません: ${e instanceof Error ? e.message : String(e)}`,
      };
    }

    return {
      notes: [found, { level: "info", text: "前回の復旧記録を片づけました" }],
      stop: null,
      restored: false,
    };
  }

  const outcome = rollbackJournal(projectRoot, journalFile, journal);

  if (outcome.failed.length > 0) {
    return {
      notes: [found],
      restored: false,
      stop: `前回の変更を戻せませんでした: ${outcome.failed.join(", ")}`,
    };
  }

  if (!outcome.cleared) {
    return {
      notes: [found],
      restored: false,
      stop: `復旧記録を削除できませんでした: ${outcome.error ?? "理由不明"}`,
    };
  }

  return {
    notes: [found, { level: "info", text: "前回の途中変更を元へ戻しました" }],
    stop: null,
    restored: true,
  };
}

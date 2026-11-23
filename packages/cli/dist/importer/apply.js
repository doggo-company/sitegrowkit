import { createHash, randomBytes } from "node:crypto";
import { chmodSync, closeSync, existsSync, fsyncSync, lstatSync, mkdirSync, openSync, readFileSync, renameSync, rmSync, writeFileSync, } from "node:fs";
import { basename, dirname, isAbsolute, join, resolve, sep } from "node:path";
import { BUNDLED_SAMPLES_PATH, isUntouchedSample, wasBundledSample, } from "./bundled-samples.js";
import { canonicalSlugCandidate, classifyValueTarget, TAXONOMY_KEY_MAX, TERM_NAME_MAX, } from "./plan-contract.js";
/**
 * 生成物の現状を provenance と突き合わせる（figma verify の生成物 hash ゲート本体）。
 * - 生成時 hash と一致 → matched
 * - interpretation 記録があり、その hash と一致 → interpreted（正式工程として PASS）
 * - それ以外 → broken（勝手な変更 / 欠落 / 記録後の再変更）
 */
export function evaluateGeneratedFiles(projectRoot, record) {
    const interp = record.interpretation;
    const interpValid = interp !== undefined &&
        interp.manifest_fingerprint === record.manifest_fingerprint &&
        interp.plan_sha256 === record.plan_sha256;
    const interpByTarget = new Map((interpValid ? interp.files : []).map((f) => [f.target, f]));
    const out = {
        matched: [],
        interpreted: [],
        broken: [],
    };
    for (const f of record.files) {
        const abs = join(projectRoot, f.target);
        if (!existsSync(abs)) {
            out.broken.push({ target: f.target, why: "生成物が存在しません" });
            continue;
        }
        const current = hashContent(readFileSync(abs));
        if (current === f.sha256) {
            out.matched.push(f.target);
            continue;
        }
        const tracked = interpByTarget.get(f.target);
        if (tracked && current === tracked.interpreted_sha256) {
            out.interpreted.push(f.target);
            continue;
        }
        out.broken.push({
            target: f.target,
            why: interp && !interpValid
                ? "interpretation 記録が stale（Manifest / Plan が変わっています）"
                : tracked
                    ? "interpretation 記録後にさらに変更されています"
                    : "承認されていない変更です（figma interpret record で承認済み interpretation として記録できます）",
        });
    }
    return out;
}
/**
 * 現状の生成物 hash を「承認済み AI interpretation」として記録する。
 * record.files に無いファイルは対象外（provenance の対象は deterministic 生成物のみ）。
 */
export function recordInterpretation(projectRoot, record) {
    const files = [];
    const interpreted = [];
    const unchanged = [];
    const missing = [];
    for (const f of record.files) {
        const abs = join(projectRoot, f.target);
        if (!existsSync(abs)) {
            missing.push(f.target);
            continue;
        }
        const current = hashContent(readFileSync(abs));
        if (current === f.sha256) {
            unchanged.push(f.target);
            continue;
        }
        files.push({
            target: f.target,
            generated_sha256: f.sha256,
            interpreted_sha256: current,
        });
        interpreted.push(f.target);
    }
    const next = {
        ...record,
        interpretation: {
            status: "interpreted",
            recorded_at: new Date().toISOString(),
            manifest_fingerprint: record.manifest_fingerprint,
            plan_sha256: record.plan_sha256,
            files: files.sort((a, b) => a.target.localeCompare(b.target)),
        },
    };
    return { record: next, interpreted, unchanged, missing };
}
export function hashContent(content) {
    return createHash("sha256").update(content).digest("hex");
}
/** 各出力ファイルの状態を判定する（spec §20–21） */
export function classifyFiles(projectRoot, files, previous) {
    const recorded = new Map((previous?.files ?? []).map((f) => [f.target, f.sha256]));
    /*
     * 🔴 **kit が置いた見本は、利用者のものではない**（B-60）。
     *
     * `template/` には**別案件の生成物**が入っているので、初回 apply では
     * 「記録に無い既存ファイル」= `foreign` に落ち、**利用者は初回から
     * `--force` を打つことになる。**一度そう覚えたら、**2 回目に出た
     * 本物の警告も同じ手で越える。**
     *
     * ▷ **中身が同梱時のままのものだけ**を飲む。**手が入っていたら
     *   conflict のまま**にする（`--force` で消えるのは利用者の直しである）。
     */
    const samples = readBundledSamples(projectRoot);
    return files.map((file) => {
        const abs = join(projectRoot, file.target);
        if (!existsSync(abs))
            return { file, state: "new" };
        const body = readFileSync(abs);
        const current = hashContent(body);
        if (current === hashContent(file.content))
            return { file, state: "unchanged" };
        const prev = recorded.get(file.target);
        if (prev === undefined) {
            // 触っていない見本なら、上書きしてよい（利用者のものではない）
            if (isUntouchedSample(samples, file.target, body)) {
                return { file, state: "ours" };
            }
            /*
             * 見本だったが**手が入っている**。conflict のままにするが、
             * **「これは kit の見本です」と言えるようにする** —
             * 「conflict です」だけだと、また `--force` で越える。
             */
            return wasBundledSample(samples, file.target)
                ? { file, state: "foreign", wasSample: true }
                : { file, state: "foreign" };
        }
        if (prev === current)
            return { file, state: "ours" };
        return { file, state: "ours-modified" };
    });
}
/**
 * 同梱の見本の一覧を読む。**無ければ空**（古い案件・自分で作った案件）。
 */
function readBundledSamples(projectRoot) {
    const path = join(projectRoot, BUNDLED_SAMPLES_PATH);
    if (!existsSync(path))
        return [];
    try {
        const parsed = JSON.parse(readFileSync(path, "utf-8"));
        return Array.isArray(parsed) ? parsed : [];
    }
    catch {
        // 読めないなら**在ると思わない**。飲み込む方へ倒さない
        return [];
    }
}
/** conflict ファイルの原本を backup へ退避してから書き込む */
export function writeFiles(projectRoot, states, backupDir) {
    const written = [];
    const backedUp = [];
    for (const { file, state } of states) {
        if (state === "unchanged")
            continue;
        const abs = join(projectRoot, file.target);
        if (state === "foreign" || state === "ours-modified") {
            const backupPath = join(backupDir, file.target);
            mkdirSync(dirname(backupPath), { recursive: true });
            writeFileSync(backupPath, readFileSync(abs));
            backedUp.push(file.target);
        }
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, file.content);
        written.push(file.target);
    }
    return { written, backedUp };
}
/**
 * 選択肢の追加を **project scope の override として**書く（Phase 9.3B / #23・IM-7〜IM-9）。
 *
 * ## base を書き換えない
 *
 * 以前は `content/schemas/<id>.json` を正規表現で直接書き換えていた。
 * これは kit が配った定義そのものを import 結果で汚す行為で、
 *
 * - 次に kit を更新すると衝突する
 * - どれが標準でどれが取り込み結果か、あとから区別できない
 * - 別のテンプレートを import すると前の値が混ざったまま残る
 *
 * という壊れ方をする。**base は 1 バイトも触らない。**
 *
 * ## なぜ override に「全部」書くのか
 *
 * `SchemaRegistry` の field override は属性の上書き（`array_merge`）なので、
 * override 側の `choices` は base を **置き換える**。だから override には
 * 「base の選択肢 + 追加分」を、**base の順序を保ったまま**書く。
 * 結果として効くのは加算で、既存の選択肢は消えも並び替わりもしない。
 *
 * 大文字小文字が違うだけの値は **別の値**として扱う（勝手にまとめない）。
 */
export function writeSchemaChoicesOverride(projectRoot, schema, field, values) {
    // **呼び出し元の検証だけに頼らない。** ここは実際にファイルを書く場所なので、
    // 「本当に選択肢を持つ field か」を自分でも確かめる
    const target = classifyValueTarget(schema, field);
    if (!schema || target.kind !== "select") {
        throw new SchemaWriteError(`${schema?.id ?? "?"}.${field} は選択肢を持つ field ではありません`);
    }
    if (values.length === 0 ||
        values.some((v) => typeof v !== "string" || v === "")) {
        throw new SchemaWriteError(`${schema.id}.${field} へ追加する値が不正です`);
    }
    const path = overridePath(projectRoot, schema.id);
    const current = readOverride(path, schema.id);
    const existing = current.fields[field]?.choices;
    // 壊れた override を **黙って直さない**。直すとその場で値が消える
    if (existing !== undefined && !isStringList(existing)) {
        throw new SchemaWriteError(`${path}: ${field}.choices が文字列の並びではありません`);
    }
    // いま効いている選択肢 = override があればそれ、無ければ base
    const effective = existing === undefined ? target.choices : existing;
    const missing = values.filter((v) => !effective.includes(v));
    if (missing.length === 0) {
        return { changed: false, path, choices: effective };
    }
    const next = [...effective, ...missing];
    current.fields[field] = { ...current.fields[field], choices: next };
    writeJsonAtomically(path, current);
    return { changed: true, path, choices: next };
}
/** override を書けないときの失敗。呼び出し側は変更を巻き戻して止まる */
export class SchemaWriteError extends Error {
    constructor(message) {
        super(message);
        this.name = "SchemaWriteError";
    }
}
/**
 * 既存の override を読む。**壊れていたら直さずに失敗させる。**
 *
 * 「読めない部分を捨てて書き戻す」と、その瞬間に元の内容が消える。
 * 消えたことは差分を見ないと気づけない。
 */
function readOverride(path, schemaId) {
    if (!existsSync(path))
        return { id: schemaId, fields: {} };
    let parsed;
    try {
        parsed = JSON.parse(readFileSync(path, "utf8"));
    }
    catch {
        throw new SchemaWriteError(`${path}: JSON として読み取れません`);
    }
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
        throw new SchemaWriteError(`${path}: JSON オブジェクトではありません`);
    }
    const raw = parsed;
    if (raw.id !== undefined && raw.id !== schemaId) {
        throw new SchemaWriteError(`${path}: id [${String(raw.id)}] が対象 schema [${schemaId}] と違います`);
    }
    const fields = raw.fields ?? {};
    if (!fields || typeof fields !== "object" || Array.isArray(fields)) {
        throw new SchemaWriteError(`${path}: fields がオブジェクトではありません`);
    }
    for (const [key, patch] of Object.entries(fields)) {
        if (!patch || typeof patch !== "object" || Array.isArray(patch)) {
            throw new SchemaWriteError(`${path}: fields[${key}] がオブジェクトではありません`);
        }
    }
    return {
        id: schemaId,
        fields: fields,
    };
}
function isStringList(value) {
    return Array.isArray(value) && value.every((v) => typeof v === "string");
}
/**
 * JSON を **途中で壊れない形で**書く。
 *
 * 直接上書きすると、書いている最中に落ちたときに壊れた JSON が残る。
 * 同じディレクトリへ一時ファイルを作って rename すれば、
 * 読み手からは「前の内容」か「新しい内容」のどちらかしか見えない。
 */
export function writeJsonAtomically(path, value, mode = 0o644) {
    const dir = dirname(path);
    mkdirSync(dir, { recursive: true });
    const temp = join(dir, `.${basename(path)}.${randomBytes(8).toString("hex")}.tmp`);
    try {
        const handle = openSync(temp, "wx", mode);
        try {
            writeFileSync(handle, `${JSON.stringify(value, null, 2)}\n`, "utf8");
            fsyncSync(handle);
        }
        finally {
            closeSync(handle);
        }
        // 既存ファイルの権限が緩くても、意図した権限で置き換える
        chmodSync(temp, mode);
        renameSync(temp, path);
    }
    catch (e) {
        try {
            if (existsSync(temp))
                rmSync(temp);
        }
        catch {
            // 後片付けの失敗で元の失敗を隠さない
        }
        throw e;
    }
}
/**
 * Project isolation（ADR-0010 Rev.1）: 前回 apply の生成物のうち新しい Plan に含まれない
 * ファイルを整理する。別テンプレートを import したとき、旧テンプレートのページ・asset が
 * プロジェクトに残って公開され続ける事故を防ぐ。
 * - modules/ 配下（モジュール基準ビュー）: backup があれば import 前の状態へ復元
 * - それ以外（ページ view / layout / component / asset / seeder）: 削除
 * - apply 後に手動変更されたファイル: 触らず残置（勝手に消さない）
 */
export function pruneStaleFiles(projectRoot, previous, currentTargets, backupDir, 
/**
 * 消してはいけない target（ページが使っている部品・枠 — `imported-usage` の答え）。
 * 🔴 **登録の外れた部品は描かれず、枠は kit 既定へ落ちる。**別テンプレートを再取り込み
 * したとき、前の取り込みで作ったページを黙って壊さない
 */
protect = new Set()) {
    const result = {
        deleted: [],
        restored: [],
        kept: [],
        retained: [],
    };
    if (!previous)
        return result;
    for (const f of previous.files) {
        if (currentTargets.has(f.target))
            continue;
        if (protect.has(f.target)) {
            result.retained.push(f.target);
            continue;
        }
        const abs = join(projectRoot, f.target);
        if (!existsSync(abs))
            continue;
        const current = hashContent(readFileSync(abs));
        if (current !== f.sha256) {
            result.kept.push(f.target);
            continue;
        }
        /*
         * 🔴 **`modules/` は消さない。**
         *
         * `modules/` は PROJECT OWNED（`docs/UPGRADING.md`）で、**利用者のものである**。
         * 新しい Plan に無いからといって、こちらが消してよいものではない。
         *
         * ▷ **実際に壊した**（2026-08-28・実案件）。新しい Plan は news を
         *   `resources/views/public/news/index.blade.php` へ置く前提なので、
         *   `modules/News/views/index.blade.php` を「旧 Plan の生成物」として消した。
         *   ところが `routes/public.php` は **module が居ればそちらへ繋ぐ**ので、
         *   **消した瞬間に `/news` が 500 になった。**
         *
         * ▷ **backup が在るときだけ戻す**形だったので、backup が無い経路では消えていた。
         *   **在る／無いで挙動を変えない。**`modules/` は常に残す。
         *
         * ▷ 🔴 **これは止血である。**同じ原本から 2 枚（module 側と新 Plan 側）
         *   生成されている状態はそのまま残る。**本来の直し方は route の側**で、
         *   `docs/DUPLICATED-JUDGMENTS.md` の D-9 に書いた。
         */
        if (f.target.startsWith("modules/")) {
            const backup = join(backupDir, f.target);
            if (existsSync(backup)) {
                writeFileSync(abs, readFileSync(backup));
                result.restored.push(f.target);
                continue;
            }
            result.kept.push(f.target);
            continue;
        }
        rmSync(abs);
        result.deleted.push(f.target);
    }
    result.deleted.sort();
    result.restored.sort();
    result.kept.sort();
    result.retained.sort();
    return result;
}
/**
 * Project Scope Schema Override（ADR-0010 Rev.1）を書き込む（冪等マージ）。
 * Core / kit 標準の schema JSON は変更せず、content/schemas/overrides/<id>.json に
 * フィールド属性の上書き（例: required: false）だけを置く。
 */
export function writeSchemaOverride(projectRoot, schema, field, patch) {
    // 未検証の文字列をパスへ連結しない。**schema は読み込み済みの contract だけ**
    if (!schema) {
        throw new SchemaWriteError("対象の schema を読み込めていません");
    }
    if (!schema.fields.some((f) => f.key === field)) {
        throw new SchemaWriteError(`${schema.id} に field [${field}] はありません`);
    }
    for (const [key, value] of Object.entries(patch)) {
        if (!OVERRIDE_PATCH_KEYS.includes(key)) {
            throw new SchemaWriteError(`override に使えない属性です: ${key}`);
        }
        if (typeof value !== "boolean") {
            throw new SchemaWriteError(`override の ${key} は true / false だけです`);
        }
    }
    const path = overridePath(projectRoot, schema.id);
    const current = readOverride(path, schema.id);
    const before = JSON.stringify(current.fields[field] ?? null);
    current.fields[field] = { ...current.fields[field], ...patch };
    if (JSON.stringify(current.fields[field]) === before) {
        return { changed: false, path };
    }
    writeJsonAtomically(path, current);
    return { changed: true, path };
}
/** override で上書きしてよい属性（いまは optional 化だけ） */
const OVERRIDE_PATCH_KEYS = ["required"];
/**
 * override の保存先。**プロジェクト内の固定の場所**に限る。
 *
 * schema id は読み込み済みの contract から来るが、パスを組み立てる直前に
 * もう一度形を確かめる（`../` や区切り文字が混ざった id を掴まない）。
 * 途中や対象が symlink なら書かない — 外のファイルを書き換えてしまう。
 */
function overridePath(projectRoot, schemaId) {
    if (!/^[a-z][a-z0-9_]*$/.test(schemaId)) {
        throw new SchemaWriteError(`schema id が不正です: ${schemaId}`);
    }
    const relative = `content/schemas/overrides/${schemaId}.json`;
    if (!isSafeProjectTarget(projectRoot, relative)) {
        throw new SchemaWriteError(`書き込み先が不正です: ${relative}`);
    }
    if (symlinkOffenders(projectRoot, [relative]).length > 0) {
        throw new SchemaWriteError(`書き込み先に symlink が含まれています: ${relative}`);
    }
    return join(projectRoot, relative);
}
/** routes/web.php へ routes/<file> の require を追記する（冪等） */
/**
 * **生成したら配線する**、の対応表（監査 指摘 34）。
 *
 * 以前は「`routes/imported.php` を作ったか」を `GenerateResult` の真偽値で持ち回り、
 * apply がそれを見て `web.php` へ require を追記していた。
 * **ファイルの生成と配線が別々に動ける**ので、真偽値だけを false にしても
 * ファイルは作られたまま試験は全緑 — 利用者の手元でだけ
 * 「route を呼んでいるのに登録されていない」例外になる（片肺）。
 *
 * ▷ 真偽値をやめ、**書いた target そのものが配線を呼ぶ**。
 *   生成を消せば配線も消える。配線だけを消すことはできない。
 */
export const POST_WRITE_WIRING = {
    "routes/imported.php": (projectRoot) => ({
        ...patchRoutesRequire(projectRoot),
        message: (changed) => changed
            ? "routes: web.php へ routes/imported.php の require を追記"
            : "routes: web.php の require は適用済み",
    }),
};
export function patchRoutesRequire(projectRoot, file = "imported.php", comment = "// agentcms template apply — 標準規約外ページの route（ADR-0010 Rev.1）") {
    const path = join(projectRoot, "routes", "web.php");
    const text = readFileSync(path, "utf8");
    const line = `require __DIR__.'/${file}';`;
    if (text.includes(line))
        return { changed: false, path };
    const next = `${text.trimEnd()}\n\n${comment}\n${line}\n`;
    writeFileSync(path, next, "utf8");
    return { changed: true, path };
}
/**
 * Contact の server-side constant mapping（ADR-0010 Rev.1）。
 * HTML に存在しない必須フィールドを UI へ追加せず（hidden input も使わず）、
 * バリデーションから除外してサーバ側で固定値を保存する。schema は変更しない。
 */
export function patchContactServerConstants(projectRoot, constants) {
    const path = join(projectRoot, "modules", "Contact", "Http", "ContactController.php");
    const text = readFileSync(path, "utf8");
    const marker = "server-side constant mapping";
    if (text.includes(marker))
        return { changed: false, path };
    const anchor = /\$validated = \$request->validate\(\s*SchemaRules::for\(\$schemas->get\('contact'\), 'contact_submissions'\),?\s*\);/;
    if (!anchor.test(text)) {
        throw new Error("ContactController.php にバリデーション anchor が見つかりません（server constant を手動で適用してください）");
    }
    const unsets = constants
        .map((c) => `        unset($rules['${phpEscape(c.key)}']);`)
        .join("\n");
    const assigns = constants
        .map((c) => `        $validated['${phpEscape(c.key)}'] = '${phpEscape(c.value)}';`)
        .join("\n");
    const replacement = `// agentcms template apply — server-side constant mapping（ADR-0010 Rev.1）:
        // HTML に存在しないフィールドは UI へ追加せずサーバ側で固定値を保存する（schema 変更なし）
        $rules = SchemaRules::for($schemas->get('contact'), 'contact_submissions');
${unsets}
        $validated = $request->validate($rules);
${assigns}`;
    writeFileSync(path, text.replace(anchor, replacement), "utf8");
    return { changed: true, path };
}
function phpEscape(v) {
    return v.replaceAll("\\", "\\\\").replaceAll("'", "\\'");
}
/** .env / .env.example へ CSP 追加オリジン等を追記（既存キーはオリジンをマージ） */
export function applyEnvAdditions(projectRoot, additions) {
    const results = [];
    for (const envFile of [".env", ".env.example"]) {
        const path = join(projectRoot, envFile);
        if (!existsSync(path))
            continue;
        let text = readFileSync(path, "utf8");
        let appendedHeader = text.includes("# agentcms template apply");
        for (const [key, value] of Object.entries(additions)) {
            const lineRe = new RegExp(`^${key}=(.*)$`, "m");
            const match = lineRe.exec(text);
            if (!match) {
                if (!appendedHeader) {
                    text = `${text.trimEnd()}\n\n# agentcms template apply — テンプレート外部依存の CSP 許可（ADR-0010）\n`;
                    appendedHeader = true;
                }
                text = `${text.trimEnd()}\n${key}=${value}\n`;
                results.push({ file: envFile, key, action: "added" });
                continue;
            }
            const current = (match[1] ?? "").split(",").filter(Boolean);
            const missing = value.split(",").filter((v) => !current.includes(v));
            if (missing.length === 0) {
                results.push({ file: envFile, key, action: "kept" });
                continue;
            }
            const merged = [...current, ...missing].join(",");
            text = text.replace(lineRe, `${key}=${merged}`);
            results.push({ file: envFile, key, action: "merged" });
        }
        writeFileSync(path, text, "utf8");
    }
    return results;
}
export function loadApplyRecord(recordPath) {
    if (!existsSync(recordPath))
        return null;
    try {
        return JSON.parse(readFileSync(recordPath, "utf8"));
    }
    catch {
        return null;
    }
}
export function saveApplyRecord(recordPath, record) {
    mkdirSync(dirname(recordPath), { recursive: true });
    writeFileSync(recordPath, `${JSON.stringify(record, null, 2)}\n`, "utf8");
}
export function buildApplyRecord(result, manifestFingerprint, planSha256, pages, 
/**
 * 前回の生成物のうち、ページが使っているため残したもの（`PruneResult.retained`）。
 * 記録から落とすと次回の prune が見ないので、**hash ごと持ち越す**
 */
retained = []) {
    const generated = new Set(result.files.map((f) => f.target));
    const record = {
        version: 1,
        manifest_fingerprint: manifestFingerprint,
        plan_sha256: planSha256,
        applied_at: new Date().toISOString(),
        files: [
            ...result.files.map((f) => ({
                target: f.target,
                sha256: hashContent(f.content),
                source: f.source,
            })),
            ...retained.filter((f) => !generated.has(f.target)),
        ].sort((a, b) => a.target.localeCompare(b.target)),
    };
    // **書かなかった回は項目ごと出さない**（「起こしていない」と「空だった」を同じ形にしない）
    if (pages !== undefined && pages.length > 0)
        record.pages = pages;
    return record;
}
const JOURNAL_KEYS = [
    "version",
    "phase",
    "started_at",
    "plan_sha256",
    "files",
    "terms",
];
export function journalPath(agentcmsDir) {
    return join(agentcmsDir, "manifest", "template-apply-journal.json");
}
/**
 * project の中を指す相対パスか。
 *
 * ジャーナルは JSON ファイルなので、書き換えれば `../` や絶対パスを
 * 「復元対象」にできてしまう。**復元は書き込みと削除**なので、
 * ここを通さないものは 1 つも触らない。
 */
export function isSafeProjectTarget(projectRoot, target) {
    if (typeof target !== "string" || target === "")
        return false;
    if (target.includes("\0"))
        return false;
    if (isAbsolute(target))
        return false;
    if (target.split(/[\\/]+/).includes(".."))
        return false;
    const root = resolve(projectRoot);
    const abs = resolve(root, target);
    return abs === root ? false : abs.startsWith(root + sep);
}
/**
 * 対象そのもの、または途中のディレクトリが symlink になっていないか調べる。
 *
 * symlink を辿ると、**プロジェクトの外のファイルを退避・上書き・削除**してしまう。
 * 「存在しない」ことにして続行すると、復元のときに実体を消す側へ回る。
 * だから見つけたら **止める**（黙って避けない）。
 *
 * @returns symlink が絡む対象。空なら安全
 */
export function symlinkOffenders(projectRoot, targets) {
    const root = resolve(projectRoot);
    const offenders = [];
    for (const target of targets) {
        const segments = target.split(/[\\/]+/).filter(Boolean);
        let current = root;
        for (const segment of segments) {
            current = join(current, segment);
            try {
                if (lstatSync(current).isSymbolicLink()) {
                    offenders.push(target);
                    break;
                }
            }
            catch {
                // 存在しない = これ以上たどれない（新規作成される対象）
                break;
            }
        }
    }
    return [...new Set(offenders)].sort();
}
/**
 * ジャーナルを保存する。**書き込みを 1 つも行う前に**呼ぶこと。
 *
 * 中身は base64 で持つ（バイナリ asset も戻せるようにするため）。
 */
export function writeJournal(path, projectRoot, targets, terms, planSha256, now) {
    const unique = [...new Set(targets)].sort();
    for (const target of unique) {
        if (!isSafeProjectTarget(projectRoot, target)) {
            throw new Error(`復旧記録に使えない対象です: ${target}`);
        }
    }
    const offenders = symlinkOffenders(projectRoot, unique);
    if (offenders.length > 0) {
        throw new Error(`symlink を含む対象があるため中止しました: ${offenders.join(", ")}`);
    }
    const journal = {
        version: 1,
        phase: "prepared",
        started_at: now,
        plan_sha256: planSha256,
        files: unique.map((target) => {
            const abs = join(projectRoot, target);
            // symlink はここへ来る前に弾いている（symlinkOffenders）
            if (!existsSync(abs))
                return { target, content: null };
            // 通常のファイル以外（ディレクトリ等）は退避も復元もできない。
            // 「戻せないものを変更しようとしている」ので、始める前に止める
            if (!lstatSync(abs).isFile()) {
                throw new Error(`通常のファイルではないため復旧できません: ${target}`);
            }
            return { target, content: readFileSync(abs).toString("base64") };
        }),
        terms,
    };
    saveJournal(path, journal, projectRoot);
    return journal;
}
/**
 * `base` から見て、パス自身または途中のディレクトリが symlink か。
 *
 * **base より上は見ない。** macOS の `/var` のようにシステム側が symlink を
 * 使っているのは普通のことで、それを危険と判断すると何も書けなくなる。
 * 守りたいのは「プロジェクトの中に置かれた symlink」なので、そこだけ見る。
 */
export function pathTouchesSymlink(path, base) {
    const target = resolve(path);
    const root = resolve(base);
    if (target === root || !target.startsWith(root + sep))
        return false;
    return symlinkOffenders(root, [target.slice(root.length + 1)]).length > 0;
}
export function saveJournal(path, journal, projectRoot) {
    if (pathTouchesSymlink(path, projectRoot)) {
        throw new Error(`復旧記録のパスに symlink が含まれています: ${path}`);
    }
    // 変更前のファイル（.env を含み得る）が入るので、他人に読ませない。
    // **復旧の根拠そのもの**なので、途中で壊れない書き方をする
    writeJsonAtomically(path, journal, 0o600);
}
export function advanceJournal(path, journal, phase, projectRoot) {
    const next = { ...journal, phase };
    saveJournal(path, next, projectRoot);
    return next;
}
/**
 * ジャーナルの内容へ戻す。
 *
 * 変更前に存在しなかったファイルは削除し、存在したものは同じバイト列へ戻す。
 * 戻せなかったものは呼び出し側へ返す（黙って成功にしない）。
 */
export function restoreFromJournal(projectRoot, journal) {
    const restored = [];
    const failed = [];
    for (const file of journal.files) {
        if (!isSafeProjectTarget(projectRoot, file.target)) {
            failed.push(file.target);
            continue;
        }
        // 復元のときも親をたどり直す（apply 中に symlink が作られている可能性）
        if (symlinkOffenders(projectRoot, [file.target]).length > 0) {
            failed.push(file.target);
            continue;
        }
        const abs = join(projectRoot, file.target);
        try {
            if (file.content === null) {
                if (existsSync(abs))
                    rmSync(abs);
            }
            else {
                mkdirSync(dirname(abs), { recursive: true });
                writeFileSync(abs, Buffer.from(file.content, "base64"));
            }
            restored.push(file.target);
        }
        catch {
            failed.push(file.target);
        }
    }
    return { restored: restored.sort(), failed: failed.sort() };
}
/**
 * ジャーナルを読む。**形が少しでも違えば null**（壊れた記録で復元しない）。
 */
export function readJournal(path, projectRoot) {
    if (!existsSync(path))
        return null;
    // 記録そのものが symlink 経由なら、中身を信用できない
    if (pathTouchesSymlink(path, projectRoot))
        return null;
    let parsed;
    try {
        parsed = JSON.parse(readFileSync(path, "utf8"));
    }
    catch {
        return null;
    }
    if (!parsed || typeof parsed !== "object")
        return null;
    const j = parsed;
    const phases = [
        "prepared",
        "files_written",
        "types_created",
        "db_committed",
        "recorded",
    ];
    // 知らないキーがあるということは、こちらが書いた記録ではない
    for (const key of Object.keys(j)) {
        if (!JOURNAL_KEYS.includes(key))
            return null;
    }
    if (j.version !== 1)
        return null;
    if (typeof j.phase !== "string" || !phases.includes(j.phase))
        return null;
    if (typeof j.started_at !== "string")
        return null;
    if (typeof j.plan_sha256 !== "string")
        return null;
    if (!Array.isArray(j.files) || !Array.isArray(j.terms))
        return null;
    const files = [];
    const seen = new Set();
    for (const raw of j.files) {
        if (!raw || typeof raw !== "object")
            return null;
        const file = raw;
        for (const key of Object.keys(file)) {
            if (!["target", "content"].includes(key))
                return null;
        }
        if (typeof file.target !== "string")
            return null;
        if (!isSafeProjectTarget(projectRoot, file.target))
            return null;
        if (seen.has(file.target))
            return null;
        seen.add(file.target);
        if (file.content !== null) {
            if (typeof file.content !== "string" || !isBase64(file.content))
                return null;
        }
        files.push({ target: file.target, content: file.content });
    }
    const terms = [];
    const seenTerms = new Set();
    for (const raw of j.terms) {
        if (!raw || typeof raw !== "object" || Array.isArray(raw))
            return null;
        const term = raw;
        // 改変された記録から、そのまま巨大な問い合わせを流さない
        for (const key of Object.keys(term)) {
            if (!["taxonomy", "slug", "name"].includes(key))
                return null;
        }
        if (typeof term.taxonomy !== "string" ||
            !/^[a-z][a-z0-9_]*$/.test(term.taxonomy) ||
            [...term.taxonomy].length > TAXONOMY_KEY_MAX) {
            return null;
        }
        if (typeof term.slug !== "string" ||
            canonicalSlugCandidate(term.slug) !== term.slug) {
            return null;
        }
        if (typeof term.name !== "string" ||
            term.name.trim() === "" ||
            [...term.name].length > TERM_NAME_MAX) {
            return null;
        }
        const key = `${term.taxonomy}\u0000${term.slug}`;
        if (seenTerms.has(key))
            return null;
        seenTerms.add(key);
        terms.push({ taxonomy: term.taxonomy, slug: term.slug, name: term.name });
    }
    return {
        version: 1,
        phase: j.phase,
        started_at: j.started_at,
        plan_sha256: j.plan_sha256,
        files,
        terms,
    };
}
function isBase64(value) {
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(value))
        return false;
    try {
        return (Buffer.from(value, "base64").toString("base64").replace(/=+$/, "") ===
            value.replace(/=+$/, ""));
    }
    catch {
        return false;
    }
}
/**
 * 変更を元へ戻し、**戻し切れたら復旧記録も消す**。
 *
 * 記録を残したままにすると、あとから誰かが同じ分類項目を作ったときに、
 * 次回の復旧が「前回は commit 済みだった」と読み違える。
 * 戻せなかったものがあるときだけ記録を残し、人が判断できるようにする。
 *
 * 呼んでよいのは **DB を呼んでいない**か、**確実に巻き戻った**ときだけ。
 * commit されたか分からないときに呼ぶと、DB だけ残る状態を自分で作る。
 */
export function rollbackJournal(projectRoot, journalFile, journal) {
    const { restored, failed } = restoreFromJournal(projectRoot, journal);
    if (failed.length > 0) {
        return { restored, failed, cleared: false };
    }
    try {
        clearJournal(journalFile);
    }
    catch (e) {
        return {
            restored,
            failed,
            cleared: false,
            error: e instanceof Error ? e.message : String(e),
        };
    }
    return { restored, failed, cleared: true };
}
export function clearJournal(path) {
    if (existsSync(path))
        rmSync(path);
}

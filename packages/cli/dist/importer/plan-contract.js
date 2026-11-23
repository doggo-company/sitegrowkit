/**
 * Plan の contract（Phase 9.3B / #23・SPEC §U.1〜§U.4）。
 *
 * ## なぜ 1 か所に置くか
 *
 * 「この値は select の選択肢なのか、分類の項目なのか」を plan 側と apply 側で
 * 別々に書くと、**必ずどちらかがずれる**。ずれた瞬間に
 * 「plan では選択肢の追加と言ったのに、apply は分類を作る」が起きる。
 * 判定・検証・パスの安全確認をここへ集め、両方が同じものを呼ぶ。
 *
 * ## 判定の根拠は ContentSchema contract だけ
 *
 * 名前が「category」だから、works だから、といった推測はしない。
 * - `fields[]` に key があり `type: "select"` → 選択肢（add_choices）
 * - `taxonomies[]` に key の宣言がある → 分類（add_terms）
 * - どちらでもない → **unknown**。提案も作成もしない
 */
/**
 * この key に入る値が「選択肢」なのか「分類項目」なのかを決める。
 *
 * select と taxonomy の両方に同じ key があるのは contract の矛盾なので、
 * 勝手にどちらかを選ばず unknown にする（人間の判断へ渡す）。
 */
export function classifyValueTarget(schema, key) {
    if (!schema) {
        return {
            kind: "unknown",
            key,
            reason: "対象の schema を読み込めなかったため、値の行き先を判定できません",
        };
    }
    const field = schema.fields.find((f) => f.key === key);
    const taxonomy = (schema.taxonomies ?? []).find((t) => t.key === key);
    if (field && taxonomy) {
        return {
            kind: "unknown",
            key,
            reason: `schema [${schema.id}] が [${key}] を field と分類の両方で宣言しています。どちらとして扱うかを人間が決める必要があります`,
        };
    }
    if (taxonomy) {
        return { kind: "taxonomy", key, multiple: taxonomy.multiple === true };
    }
    if (!field) {
        return {
            kind: "unknown",
            key,
            reason: `schema [${schema.id}] に field も分類宣言も [${key}] が無いため、値の行き先を判定できません`,
        };
    }
    if (field.type !== "select") {
        return {
            kind: "unknown",
            key,
            reason: `schema [${schema.id}] の [${key}] は ${field.type} 型で、選択肢も分類も持ちません`,
        };
    }
    return { kind: "select", key, choices: field.choices ?? [] };
}
/**
 * 分類項目の slug 候補。
 *
 * ここで作るのは **「一意に決まるときだけ」**。日本語や記号だけの値から
 * 適当な文字列をひねり出すと、あとから誰も由来を辿れない項目ができる。
 * 決められないときは null を返し、呼び出し側が REVIEW にする。
 *
 * 規則は TermSlug（core）と同じ: 小文字・英数字とハイフン・前後と連続のハイフン無し。
 */
export function canonicalSlugCandidate(value) {
    const trimmed = value.trim().toLowerCase();
    if (trimmed === "")
        return null;
    // ASCII 英数字とハイフン・アンダースコア・空白だけで構成されているか。
    // 日本語や記号が含まれるものは「機械的に一意へ落とせない」として扱う
    if (!/^[a-z0-9]+(?:[\s_-]+[a-z0-9]+)*$/.test(trimmed))
        return null;
    const slug = trimmed.replace(/[\s_]+/g, "-");
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug))
        return null;
    // **CMS 側の上限を超えるものを提案しない。** ここで通すと、
    // 「CONFIDENT な提案」として承認されたあと apply で必ず失敗する
    return [...slug].length <= TERM_SLUG_MAX ? slug : null;
}
/** PHP の `TermSlug::MAX_LENGTH` と同じ */
export const TERM_SLUG_MAX = 120;
/** PHP の `TaxonomyKey::MAX_LENGTH` と同じ */
export const TAXONOMY_KEY_MAX = 64;
/** PHP の `CreateTerm::NAME_MAX` と同じ */
export const TERM_NAME_MAX = 120;
export function emptyCatalog() {
    return { terms: {}, failures: {} };
}
/**
 * 既存項目との照合。**同一分類内の canonical slug 完全一致だけ**を reuse 候補にする。
 *
 * 表示名の大小・全半角・類似語では一致させない。似ているという理由で
 * 別物を同じ項目にまとめると、あとから分離できない。
 */
export function matchTerm(catalog, taxonomyKey, slug) {
    const failure = catalog.failures[taxonomyKey];
    if (failure !== undefined) {
        return { kind: "undetermined", reason: failure };
    }
    const terms = catalog.terms[taxonomyKey];
    if (terms === undefined) {
        return {
            kind: "undetermined",
            reason: `分類 [${taxonomyKey}] の既存項目を取得していないため、重複の有無を判断できません`,
        };
    }
    if (slug === null) {
        return {
            kind: "undetermined",
            reason: "slug を一意に決められないため、既存項目と照合できません",
        };
    }
    const hit = terms.find((t) => t.slug === slug);
    if (!hit)
        return { kind: "absent" };
    return hit.archived
        ? { kind: "archived", term_id: hit.id, slug: hit.slug }
        : { kind: "active", term_id: hit.id, slug: hit.slug };
}
/** 提案 1 件の日本語ラベル（JSON / Markdown / CLI 出力で同じ語を使う） */
export function matchLabel(match) {
    switch (match.kind) {
        case "active":
            return "既存を利用";
        case "absent":
            return "提案（新規作成）";
        case "archived":
            return "アーカイブ済みと衝突";
        case "undetermined":
            return "未確認";
    }
}
// ─────────────────────────────────────────────────────────────
// Plan の検証（外部 JSON を型注釈だけで信用しない — §1-F）
// ─────────────────────────────────────────────────────────────
const SCHEMA_CHANGES = ["add_choices", "make_optional"];
const TAXONOMY_CHANGES = ["add_terms"];
const CONFIDENCES = ["CONFIDENT", "REVIEW", "UNKNOWN"];
/** schema id / taxonomy key に使ってよい形。パス区切りも `..` も入らない */
const IDENTIFIER = /^[a-z][a-z0-9_]*$/;
/**
 * 現在の Plan 形式。
 *
 * v1 は `taxonomy_changes`、v2 は `content_types` という概念を持たないので解釈しない。
 * 古い形式を新しいものとして読むと、**意思決定が「無い」のか「その時代に無かった」のかを
 * 区別できない**（v2 の `seeds` は形が違うので、seed が 0 件に見える）。
 */
export const PLAN_VERSION = 3;
/**
 * Plan の変更宣言を検証する。
 *
 * `as TemplatePlan` は **型を書いただけ**で、中身は外から来た JSON のまま。
 * apply が使う値は全部ここで確かめる。確かめ漏れたものが、そのまま
 * ファイルと DB へ届く。
 *
 * 名前の形だけでなく **実際の schema と突き合わせる**。
 * 「add_choices の対象が本当に select なのか」「add_terms の分類を
 * その schema が本当に宣言しているのか」まで見ないと、
 * plan を書き換えるだけで選択肢を分類にすり替えられる。
 */
export function validatePlanChanges(plan, known) {
    const issues = [];
    if (!plan || typeof plan !== "object" || Array.isArray(plan)) {
        return [
            { path: "plan", message: "Plan が JSON オブジェクトではありません" },
        ];
    }
    const record = plan;
    if (record.version !== PLAN_VERSION) {
        return [
            {
                path: "version",
                message: record.version === 1
                    ? "この Plan は古い形式（v1）です。分類項目の扱いが決まっていないため解釈しません"
                    : record.version === 2
                        ? "この Plan は古い形式（v2）です。投稿タイプの新設と seed の形が変わっているため解釈しません（`agentcms template plan` で作り直してください）"
                        : `対応していない Plan version です: ${String(record.version)}`,
            },
        ];
    }
    issues.push(...validateContentTypes(record.content_types));
    issues.push(...validateSchemaChanges(record.schema_changes, known));
    issues.push(...validateTaxonomyChanges(record.taxonomy_changes, known));
    return issues;
}
/** plan が持ってよい preset（`ContentTypeBuilderPresets::presets()` と同じ id） */
const PRESETS = ["blank", "post", "news", "works"];
const CONTENT_TYPE_KEYS = ["id", "label", "preset", "confidence", "reason"];
/** 作った型の表示名の上限（`ContentTypeDraft::LABEL_MAX` と同じ） */
const LABEL_MAX = 60;
/**
 * 「新しく作る投稿タイプ」の検証（R-13 Stage 2）。
 *
 * ここで通ったものが**そのまま migration とテーブル作成になる**ので、
 * 入口を絞る。項目定義は持たない（PHP 側が preset から展開する）。
 */
function validateContentTypes(raw) {
    if (raw === undefined)
        return [];
    if (!Array.isArray(raw)) {
        return [{ path: "content_types", message: "配列ではありません" }];
    }
    const issues = [];
    const seen = new Set();
    raw.forEach((entry, i) => {
        const at = `content_types[${i}]`;
        if (!entry || typeof entry !== "object" || Array.isArray(entry)) {
            issues.push({ path: at, message: "オブジェクトではありません" });
            return;
        }
        const c = entry;
        for (const key of Object.keys(c)) {
            if (!CONTENT_TYPE_KEYS.includes(key)) {
                issues.push({ path: at, message: `未知のキーがあります: ${key}` });
            }
        }
        if (typeof c.id !== "string" || !IDENTIFIER.test(c.id)) {
            issues.push({ path: at, message: `id が不正です: ${String(c.id)}` });
            return;
        }
        if (seen.has(c.id)) {
            issues.push({ path: at, message: `同じ id が 2 度あります: ${c.id}` });
        }
        seen.add(c.id);
        /*
         * **既に在る型を書いてよい**（R-13 10 件目の残余）。
         *
         * この配列は「作る型」ではなく「**この取り込みが使う CMS 管理の型**」である。
         * 在るものを弾くと、2 度目以降の plan から消えて
         * **公開の後付けが二度と走らない**（型は在るのに公開面が 404 のまま）。
         * 「作る／公開する／何もしない」は apply が実物を見て決める。
         */
        if (typeof c.label !== "string" ||
            c.label.trim() === "" ||
            [...c.label].length > LABEL_MAX) {
            issues.push({ path: at, message: "label がありません（60 文字まで）" });
        }
        if (!PRESETS.includes(c.preset)) {
            issues.push({
                path: at,
                message: `未知の構成（preset）です: ${String(c.preset)}`,
            });
        }
        if (!CONFIDENCES.includes(c.confidence)) {
            issues.push({
                path: at,
                message: `confidence が不正です: ${String(c.confidence)}`,
            });
        }
        /*
         * **理由に「ID は変えられない」が書いてあること**（裁定の追加要件）。
         * 承認する人は、そこを知ったうえで承認する必要がある。
         */
        if (typeof c.reason !== "string" || c.reason === "") {
            issues.push({ path: at, message: "reason がありません" });
        }
        else if (!c.reason.includes("変更できません")) {
            issues.push({
                path: at,
                message: "reason に「ID はあとから変更できません」旨がありません",
            });
        }
    });
    return issues;
}
function validateSchemaChanges(raw, known) {
    if (raw === undefined)
        return [];
    if (!Array.isArray(raw)) {
        return [{ path: "schema_changes", message: "配列ではありません" }];
    }
    const issues = [];
    raw.forEach((change, i) => {
        const at = `schema_changes[${i}]`;
        if (!change || typeof change !== "object" || Array.isArray(change)) {
            issues.push({ path: at, message: "オブジェクトではありません" });
            return;
        }
        const c = change;
        for (const key of Object.keys(c)) {
            if (!SCHEMA_CHANGE_KEYS.includes(key)) {
                issues.push({ path: at, message: `未知のキーがあります: ${key}` });
            }
        }
        if (!SCHEMA_CHANGES.includes(c.change)) {
            issues.push({
                path: at,
                message: `未知の変更種別です: ${String(c.change)}`,
            });
            return;
        }
        if (c.scope !== "project") {
            issues.push({ path: at, message: "scope は project だけです" });
        }
        if (!CONFIDENCES.includes(c.confidence)) {
            issues.push({
                path: at,
                message: `confidence が不正です: ${String(c.confidence)}`,
            });
        }
        if (typeof c.reason !== "string" || c.reason === "") {
            issues.push({ path: at, message: "reason がありません" });
        }
        const schema = typeof c.schema === "string" &&
            IDENTIFIER.test(c.schema) &&
            [...c.schema].length <= TAXONOMY_KEY_MAX
            ? known.schemas[c.schema]
            : undefined;
        if (!schema) {
            issues.push({
                path: at,
                message: `schema [${String(c.schema)}] はこのプロジェクトに登録されていません`,
            });
            return;
        }
        if (typeof c.field !== "string" || !IDENTIFIER.test(c.field)) {
            issues.push({
                path: at,
                message: `field 名が不正です: ${String(c.field)}`,
            });
            return;
        }
        const target = classifyValueTarget(schema, c.field);
        if (c.change === "add_choices") {
            // **選択肢の追加は select にしか許さない。** 分類へ選択肢は生やせない
            if (target.kind !== "select") {
                issues.push({
                    path: at,
                    message: `${c.schema}.${c.field} は選択肢を持つ field ではありません`,
                });
            }
            if (!isStringArray(c.values) || c.values.length === 0) {
                issues.push({
                    path: at,
                    message: "values が文字列の配列ではありません",
                });
            }
            else if (new Set(c.values).size !== c.values.length) {
                issues.push({ path: at, message: "values に重複があります" });
            }
            return;
        }
        // make_optional は既存の field にしか掛けられない（未知 field を新設させない）
        if (!schema.fields.some((f) => f.key === c.field)) {
            issues.push({
                path: at,
                message: `${c.schema} に field [${c.field}] はありません`,
            });
        }
    });
    return issues;
}
const TAXONOMY_CHANGE_KEYS = [
    "change",
    "scope",
    "schema",
    "taxonomy",
    "source_value",
    "occurrences",
    "proposed_name",
    "proposed_slug",
    "match",
    "confidence",
    "reason",
];
const SCHEMA_CHANGE_KEYS = [
    "schema",
    "field",
    "change",
    "scope",
    "values",
    "confidence",
    "reason",
];
function validateTaxonomyChanges(raw, known) {
    if (raw === undefined)
        return [];
    if (!Array.isArray(raw)) {
        return [{ path: "taxonomy_changes", message: "配列ではありません" }];
    }
    const issues = [];
    const seen = new Set();
    raw.forEach((change, i) => {
        const at = `taxonomy_changes[${i}]`;
        if (!change || typeof change !== "object" || Array.isArray(change)) {
            issues.push({ path: at, message: "オブジェクトではありません" });
            return;
        }
        const c = change;
        for (const key of Object.keys(c)) {
            if (!TAXONOMY_CHANGE_KEYS.includes(key)) {
                issues.push({ path: at, message: `未知のキーがあります: ${key}` });
            }
        }
        if (!TAXONOMY_CHANGES.includes(c.change)) {
            issues.push({
                path: at,
                message: `未知の変更種別です: ${String(c.change)}`,
            });
            return;
        }
        if (c.scope !== "project") {
            issues.push({ path: at, message: "scope は project だけです" });
        }
        if (!CONFIDENCES.includes(c.confidence)) {
            issues.push({
                path: at,
                message: `confidence が不正です: ${String(c.confidence)}`,
            });
        }
        if (typeof c.reason !== "string" || c.reason === "") {
            issues.push({ path: at, message: "reason がありません" });
        }
        // 名前は CMS 側の contract（trim 後 1〜120 文字・制御文字なし）と同じ範囲だけ通す
        for (const key of ["source_value", "proposed_name"]) {
            const value = c[key];
            if (typeof value !== "string") {
                issues.push({ path: at, message: `${key} がありません` });
                continue;
            }
            const trimmed = value.trim();
            if (trimmed === "" || [...trimmed].length > TERM_NAME_MAX) {
                issues.push({
                    path: at,
                    message: `${key} は 1〜${TERM_NAME_MAX} 文字で指定してください`,
                });
            }
            if (/[\u0000-\u001f\u007f]/.test(value)) {
                issues.push({ path: at, message: `${key} に制御文字が含まれています` });
            }
        }
        // 生成規則では取り込んだ値をそのまま名前にする。
        // 食い違っていたら **Plan が書き換えられている**（別名で作らせる改変）
        if (typeof c.source_value === "string" &&
            typeof c.proposed_name === "string" &&
            c.source_value !== c.proposed_name) {
            issues.push({
                path: at,
                message: "proposed_name は取り込んだ値と同じでなければなりません",
            });
        }
        if (typeof c.occurrences !== "number" ||
            !Number.isInteger(c.occurrences) ||
            c.occurrences < 1) {
            issues.push({
                path: at,
                message: "occurrences が 1 以上の整数ではありません",
            });
        }
        const schema = typeof c.schema === "string" &&
            IDENTIFIER.test(c.schema) &&
            [...c.schema].length <= TAXONOMY_KEY_MAX
            ? known.schemas[c.schema]
            : undefined;
        if (!schema) {
            issues.push({
                path: at,
                message: `schema [${String(c.schema)}] はこのプロジェクトに登録されていません`,
            });
            return;
        }
        if (typeof c.taxonomy !== "string" ||
            !IDENTIFIER.test(c.taxonomy) ||
            [...c.taxonomy].length > TAXONOMY_KEY_MAX) {
            issues.push({
                path: at,
                message: `taxonomy 名が不正です: ${String(c.taxonomy)}`,
            });
            return;
        }
        // **その schema が実際に宣言している分類だけ。** 宣言の無い分類へ項目を作らない
        const target = classifyValueTarget(schema, c.taxonomy);
        if (target.kind !== "taxonomy") {
            issues.push({
                path: at,
                message: `schema [${schema.id}] は分類 [${c.taxonomy}] を宣言していません`,
            });
        }
        if (c.proposed_slug !== null &&
            (typeof c.proposed_slug !== "string" ||
                canonicalSlugCandidate(c.proposed_slug) !== c.proposed_slug)) {
            issues.push({
                path: at,
                message: `proposed_slug が canonical ではありません: ${String(c.proposed_slug)}`,
            });
        }
        // **同じ分類に同じ slug の提案が 2 つある Plan は成立しない。**
        // 由来の値が違っても canonical slug が同じなら作れるのは 1 つだけ
        if (typeof c.proposed_slug === "string") {
            const key = `${c.taxonomy}\u0000${c.proposed_slug}`;
            if (seen.has(key)) {
                issues.push({
                    path: at,
                    message: `分類 [${c.taxonomy}] に slug [${c.proposed_slug}] の提案が重複しています`,
                });
            }
            seen.add(key);
        }
        issues.push(...validateMatch(at, c.match, c.proposed_slug));
        issues.push(...validateConfidence(at, c.match, c.confidence, c.proposed_slug));
    });
    return issues;
}
/**
 * 照合結果と確度の整合。
 *
 * **自動で作ってよいのは「新規作成の提案 + slug が決まっている + CONFIDENT」だけ。**
 * ここが緩いと、`REVIEW` と書かれた提案（人の判断待ち）が
 * そのまま DB に作られてしまう。schema の変更で
 * 「REVIEW は自動適用しない」としているのと同じ扱いにする。
 */
function validateConfidence(at, match, confidence, proposedSlug) {
    const kind = match?.kind;
    if (kind === "absent") {
        if (confidence !== "CONFIDENT") {
            return [
                {
                    path: at,
                    message: "新規作成の提案は CONFIDENT でなければなりません",
                },
            ];
        }
        return typeof proposedSlug === "string"
            ? []
            : [{ path: at, message: "slug の無い提案は新規作成にできません" }];
    }
    // 既存利用・アーカイブ衝突・未確認は **自動適用しない**
    return confidence === "CONFIDENT"
        ? [
            {
                path: at,
                message: "作成しない提案を CONFIDENT にはできません（人の判断が要ります）",
            },
        ]
        : [];
}
/** 照合結果は apply の分岐そのもの。variant ごとに必要なものを確かめる */
function validateMatch(at, raw, proposedSlug) {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
        return [{ path: at, message: "match の形式が不正です" }];
    }
    const match = raw;
    switch (match.kind) {
        case "absent":
            // 新規作成の提案には slug が要る（無いなら undetermined であるべき）
            return typeof proposedSlug === "string"
                ? []
                : [{ path: at, message: "slug の無い提案を新規作成にはできません" }];
        case "active":
        case "archived":
            if (typeof match.term_id !== "string" || match.term_id === "") {
                return [{ path: at, message: "match.term_id がありません" }];
            }
            if (typeof match.slug !== "string" || match.slug !== proposedSlug) {
                return [
                    { path: at, message: "match.slug が提案の slug と一致しません" },
                ];
            }
            return [];
        case "undetermined":
            return typeof match.reason === "string" && match.reason !== ""
                ? []
                : [{ path: at, message: "match.reason がありません" }];
        default:
            return [
                { path: at, message: `未知の match です: ${String(match.kind)}` },
            ];
    }
}
function isStringArray(value) {
    return Array.isArray(value) && value.every((v) => typeof v === "string");
}

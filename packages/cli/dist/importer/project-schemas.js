/**
 * 生成プロジェクトの ContentSchema を **実際の contract として**読む
 * （Phase 9.3B / #23・SPEC §U.1）。
 *
 * ## なぜ override をマージするのか
 *
 * `content/schemas/<id>.json` だけを読むと、それは「kit が配った既定値」であって
 * **そのプロジェクトで実際に効いている contract ではない**。
 * CMS 側（`SchemaRegistry`）は `overrides/<id>.json` を重ねてから使うので、
 * import 側が base だけを見ていると 2 つの食い違いが起きる。
 *
 * - 選択肢を override へ追加したのに、次の plan がまた同じ値を「不足」と言う（冪等でない）
 * - プロジェクトが override で分類を宣言していても、import は select だと思い込む
 *
 * どちらも「読む場所が足りない」ことが原因なので、ここで同じ規則で重ねる。
 *
 * ## 重ね方も、拒み方も SchemaRegistry と同じ
 *
 * - `fields`: key が一致する field の属性を上書き（`array_merge` 相当）
 * - `taxonomies`: **key 単位**で追加または置換（配列の位置では重ねない）
 *
 * そして **壊れた定義は無視しない**。
 * 「あとで verify が見つける」では遅い。plan は読み取った contract を根拠に
 * 「選択肢を足す」「分類項目を作る」を決めるので、contract が不完全なまま進むと
 * **間違った変更を提案し、それが承認されて適用される**。
 * CMS 側（`SchemaRegistry`）が例外にするものは、ここでも同じように失敗させる。
 */
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
/** contract を読めなかったときの失敗。呼び出し側は変更 0 で止まる */
export class SchemaContractError extends Error {
    file;
    constructor(message, file) {
        super(message);
        this.file = file;
        this.name = "SchemaContractError";
    }
}
/** taxonomy 宣言に使えるキー（PHP の TaxonomyDeclaration::ALLOWED_KEYS と一致） */
const TAXONOMY_DECLARATION_KEYS = ["key", "multiple", "required"];
/** taxonomy key / schema id の文法（PHP の TaxonomyKey::PATTERN と一致） */
const KEY_PATTERN = /^[a-z][a-z0-9_]*$/;
/** PHP の `TaxonomyKey::MAX_LENGTH` と同じ */
const KEY_MAX_LENGTH = 64;
/** PHP の `Field::TYPES` と同じ。ここに無い型は受け付けない */
const FIELD_TYPES = [
    "text",
    "textarea",
    "richtext",
    "number",
    "url",
    "email",
    "tel",
    "date",
    "datetime",
    "boolean",
    "select",
    "radio",
    "checkbox",
    "image",
    "images",
    "file",
    "media",
    "relation",
    "relations",
    // R-5。ページを 1 つ指す（値は pages.id）
    "page",
    "group",
    "repeater",
    "slug",
    "status",
];
/** PHP の `Field::NEEDS_CHOICES` */
const NEEDS_CHOICES = ["select", "radio", "checkbox"];
/** PHP の `Field::NEEDS_FIELDS` */
const NEEDS_FIELDS = ["group", "repeater"];
/** PHP の `Field::NEEDS_TARGET` */
const NEEDS_TARGET = ["relation", "relations"];
/** PHP の `ContentSchema::RESERVED_FIELD_KEYS`（トップレベルだけ） */
const RESERVED_FIELD_KEYS = ["taxonomies"];
/**
 * `<projectRoot>/content/schemas` を base + overrides で読む。
 *
 * @throws SchemaContractError 壊れた定義・重複・未知の対象・不正な宣言
 */
export function loadProjectSchemas(projectRoot) {
    const out = {};
    if (!projectRoot)
        return out;
    const dir = join(projectRoot, "content", "schemas");
    // **「定義が無い」を「制約が無い」と読み替えない。**
    // 読めないまま進むと、何の裏づけも無い提案を作ってしまう
    if (!existsSync(dir)) {
        throw new SchemaContractError("コンテンツ定義のディレクトリがありません", dir);
    }
    for (const file of readdirSync(dir).sort()) {
        if (!file.endsWith(".json"))
            continue;
        const path = join(dir, file);
        const raw = readJson(path);
        const id = raw.id;
        if (typeof id !== "string" || id === "") {
            throw new SchemaContractError(`schema に "id" がありません`, path);
        }
        if (out[id]) {
            throw new SchemaContractError(`schema id [${id}] が重複しています`, path);
        }
        out[id] = normalize(raw, path);
    }
    const overrideDir = join(dir, "overrides");
    if (!existsSync(overrideDir))
        return out;
    for (const file of readdirSync(overrideDir).sort()) {
        if (!file.endsWith(".json"))
            continue;
        const path = join(overrideDir, file);
        const override = readJson(path);
        if (override.id !== undefined && typeof override.id !== "string") {
            throw new SchemaContractError("override の id は文字列です", path);
        }
        const id = typeof override.id === "string" && override.id !== ""
            ? override.id
            : file.replace(/\.json$/, "");
        const base = out[id];
        if (!base) {
            throw new SchemaContractError(`override が存在しない schema [${id}] を指しています`, path);
        }
        out[id] = mergeOverride(base, override, path);
    }
    return out;
}
/** 分類 key の一覧（plan の検証に使う allowlist の材料） */
export function declaredTaxonomyKeys(schemas) {
    const keys = new Set();
    for (const schema of Object.values(schemas)) {
        for (const taxonomy of schema?.taxonomies ?? [])
            keys.add(taxonomy.key);
    }
    return [...keys].sort();
}
function readJson(path) {
    let text;
    try {
        text = readFileSync(path, "utf8");
    }
    catch {
        throw new SchemaContractError("ファイルを読み取れません", path);
    }
    let value;
    try {
        value = JSON.parse(text);
    }
    catch {
        throw new SchemaContractError("JSON として読み取れません", path);
    }
    if (!value || typeof value !== "object" || Array.isArray(value)) {
        throw new SchemaContractError("JSON オブジェクトではありません", path);
    }
    return value;
}
function normalize(raw, path) {
    const rawFields = raw.fields;
    if (!Array.isArray(rawFields)) {
        throw new SchemaContractError(`schema の "fields" は配列で指定してください`, path);
    }
    const fields = [];
    const seen = new Set();
    for (const field of rawFields) {
        const validated = validatedField(field, path, true);
        if (seen.has(validated.key)) {
            throw new SchemaContractError(`field key [${validated.key}] が重複しています`, path);
        }
        seen.add(validated.key);
        fields.push(validated);
    }
    // 実際の contract は **`admin.list` / `admin.search`**（トップレベルではない）
    const admin = raw.admin;
    if (admin !== undefined) {
        if (!admin || typeof admin !== "object" || Array.isArray(admin)) {
            throw new SchemaContractError("admin はオブジェクトです", path);
        }
        for (const listKey of ["list", "search"]) {
            const list = admin[listKey];
            if (list === undefined)
                continue;
            if (!Array.isArray(list) || list.some((v) => typeof v !== "string")) {
                throw new SchemaContractError(`admin.${listKey} は文字列の並びです`, path);
            }
            for (const name of list) {
                // 存在しない field を管理画面が参照していたら、その画面は必ず壊れる
                if (!fields.some((f) => f.key === name)) {
                    throw new SchemaContractError(`admin.${listKey} が存在しない field [${name}] を参照しています`, path);
                }
            }
        }
    }
    const schema = { id: String(raw.id), fields };
    // CMS が作った型かどうかでモデルのクラス名の規則が変わる（R-13 Stage 2）
    if (typeof raw.managedBy === "string")
        schema.managedBy = raw.managedBy;
    if ("taxonomies" in raw) {
        schema.taxonomies = validatedDeclarations(raw.taxonomies, path);
    }
    return schema;
}
/**
 * field 1 つの検証。**PHP の `Field::fromArray()` と同じ条件で落とす**。
 *
 * ここが緩いと、CMS が起動しない定義を import 側だけが受け入れ、
 * その不完全な contract を根拠に「選択肢を足す」「分類項目を作る」を提案してしまう。
 *
 * @param topLevel トップレベルの field か（予約キーの判定はトップレベルだけ）
 */
function validatedField(raw, path, topLevel) {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
        throw new SchemaContractError("fields の要素はオブジェクトです", path);
    }
    const field = raw;
    const key = field.key;
    if (typeof key !== "string" || key === "") {
        throw new SchemaContractError("fields の key は空でない文字列です", path);
    }
    if (topLevel && RESERVED_FIELD_KEYS.includes(key)) {
        throw new SchemaContractError(`field key [${key}] は予約されています（分類の割り当て専用）`, path);
    }
    const type = field.type;
    if (typeof type !== "string" || type === "") {
        throw new SchemaContractError(`field [${key}] に type がありません`, path);
    }
    if (!FIELD_TYPES.includes(type)) {
        throw new SchemaContractError(`field [${key}] の type [${type}] は使えません`, path);
    }
    if (NEEDS_CHOICES.includes(type)) {
        const choices = field.choices;
        if (!Array.isArray(choices) || choices.length === 0) {
            throw new SchemaContractError(`field [${key}]（${type}）には空でない choices が必要です`, path);
        }
        if (choices.some((c) => typeof c !== "string")) {
            throw new SchemaContractError(`field [${key}] の choices は文字列の並びです`, path);
        }
    }
    if (NEEDS_TARGET.includes(type)) {
        const target = field.target;
        if (typeof target !== "string" || target === "") {
            throw new SchemaContractError(`field [${key}]（${type}）には参照先の target が必要です`, path);
        }
    }
    if (NEEDS_FIELDS.includes(type)) {
        const nested = field.fields;
        if (!Array.isArray(nested) || nested.length === 0) {
            throw new SchemaContractError(`field [${key}]（${type}）には入れ子の fields が必要です`, path);
        }
        const nestedKeys = new Set();
        for (const child of nested) {
            // 入れ子は `data` の中で完結するので、予約キーの制限は掛からない
            const validated = validatedField(child, path, false);
            if (nestedKeys.has(validated.key)) {
                throw new SchemaContractError(`field [${key}] の入れ子に key [${validated.key}] が重複しています`, path);
            }
            nestedKeys.add(validated.key);
        }
    }
    return field;
}
/**
 * taxonomy 宣言の検証。**PHP の `TaxonomyDeclaration::fromArray()` と同じ厳しさ**。
 *
 * `multiple` / `required` は必須の boolean で、`"true"` や `1` を暗黙変換しない。
 * ここを緩めると、CMS が拒否する定義を import 側だけが受け入れてしまう。
 */
function validatedDeclarations(raw, path) {
    if (!Array.isArray(raw)) {
        throw new SchemaContractError("taxonomies は配列で指定してください（未指定にする場合はキーごと省略してください。null は使えません）", path);
    }
    const list = [];
    const keys = new Set();
    for (const entry of raw) {
        if (!entry || typeof entry !== "object" || Array.isArray(entry)) {
            throw new SchemaContractError("taxonomies の要素はオブジェクトで指定してください", path);
        }
        const declaration = entry;
        for (const key of Object.keys(declaration)) {
            if (!TAXONOMY_DECLARATION_KEYS.includes(key)) {
                throw new SchemaContractError(`taxonomies に未知のキー [${key}] があります（使えるのは ${TAXONOMY_DECLARATION_KEYS.join(" / ")} です）`, path);
            }
        }
        const key = declaration.key;
        if (typeof key !== "string" ||
            !KEY_PATTERN.test(key) ||
            [...key].length > KEY_MAX_LENGTH) {
            throw new SchemaContractError(`taxonomy key [${String(key)}] の形式が不正です`, path);
        }
        if (keys.has(key)) {
            throw new SchemaContractError(`taxonomy key [${key}] が重複しています`, path);
        }
        for (const flag of ["multiple", "required"]) {
            if (typeof declaration[flag] !== "boolean") {
                throw new SchemaContractError(`taxonomies の ${flag} は true / false で指定してください（[${key}]）`, path);
            }
        }
        keys.add(key);
        list.push({
            key,
            multiple: declaration.multiple,
            required: declaration.required,
        });
    }
    return list;
}
function mergeOverride(base, override, path) {
    /*
     * **base の属性を落とさない**（R-13 9 件目）。
     *
     * ここは新しいオブジェクトを組み立て直すので、**書き忘れた属性は消える**。
     * `managedBy` が消えると「CMS が作った型」が「kit の module 型」に見え、
     * plan が module 前提の枝へ落ちる（実測: 型を作ったあとの再 plan で
     * 公開面の生成物が丸ごと入れ替わり、`/news` と `/works/{slug}` が 404 になった）。
     *
     * override が上書きしてよいのは **field の属性**だけで、
     * 「誰がこの型を管理しているか」は override の管轄ではない。
     */
    const merged = {
        id: base.id,
        ...(base.managedBy === undefined ? {} : { managedBy: base.managedBy }),
        fields: base.fields.map((f) => ({ ...f })),
        ...(base.taxonomies
            ? { taxonomies: base.taxonomies.map((t) => ({ ...t })) }
            : {}),
    };
    const fieldPatches = override.fields;
    if (fieldPatches !== undefined) {
        if (!fieldPatches ||
            typeof fieldPatches !== "object" ||
            Array.isArray(fieldPatches)) {
            throw new SchemaContractError("override の fields はオブジェクトで指定してください", path);
        }
        for (const [key, patch] of Object.entries(fieldPatches)) {
            if (!patch || typeof patch !== "object" || Array.isArray(patch)) {
                throw new SchemaContractError(`override の fields[${key}] はオブジェクトです`, path);
            }
            const index = merged.fields.findIndex((f) => f.key === key);
            if (index === -1) {
                throw new SchemaContractError(`override が存在しない field [${key}] を指しています（schema [${base.id}]）`, path);
            }
            // **重ねた結果をもう一度検証する。** override は type や choices も上書きできるので、
            // 「base は正しいが、重ねると壊れる」定義を通してしまわないようにする
            merged.fields[index] = validatedField({
                ...merged.fields[index],
                ...patch,
            }, path, true);
        }
    }
    if ("taxonomies" in override) {
        const patches = validatedDeclarations(override.taxonomies, path);
        const byKey = new Map((merged.taxonomies ?? []).map((t) => [t.key, t]));
        // 同じ key は **宣言ごと置換**する（部分マージしない — CMS 側と同じ規則）
        for (const patch of patches)
            byKey.set(patch.key, patch);
        merged.taxonomies = [...byKey.values()];
    }
    return merged;
}

import { readFileSync } from "node:fs";
import { join } from "node:path";
import { HTMLElement, parse } from "node-html-parser";

import { resolveRelative } from "../inspect/css.js";
import type { TemplatePlan } from "./plan-types.js";

/**
 * Blade 生成の共有ヘルパー（layout 抽出モード / standalone preserve モード共用）。
 * 「デザインに CMS を合わせる」— 変換は参照書き換えと CMS スロット注入のみに限る。
 */

export function parseSource(sourceDir: string, page: string): HTMLElement {
  return parse(readFileSync(join(sourceDir, page), "utf8"), { comment: true });
}

export function childrenBySig(
  container: HTMLElement,
  itemSig: string,
): HTMLElement[] {
  return container.childNodes.filter(
    (c): c is HTMLElement =>
      c instanceof HTMLElement &&
      `${c.tagName}.${(c.getAttribute("class") ?? "").trim()}` === itemSig,
  );
}

/** asset 参照と内部リンクを Laravel 向けへ書き換える（それ以外は触らない） */
export function rewriteRefs(
  el: HTMLElement,
  pageSource: string,
  plan: TemplatePlan,
): void {
  const prefix = plan.assets.target_prefix;
  const linkByHref = new Map(plan.link_map.map((l) => [l.href, l]));

  for (const node of [el, ...el.querySelectorAll("[src]")]) {
    const src = node.getAttribute?.("src");
    if (!src || /^(https?:)?\/\//.test(src) || src.startsWith("data:"))
      continue;
    if (src.includes("{{")) continue; // 既に Blade 化済み
    const resolved = resolveRelative(pageSource, src) ?? src;
    node.setAttribute("src", `{{ asset('${prefix}/${resolved}') }}`);
  }

  for (const node of el.querySelectorAll("a[href]")) {
    const href = node.getAttribute("href");
    if (!href || href.includes("{{")) continue;
    const mapped = linkByHref.get(href);
    if (!mapped) continue; // 外部 / mailto / tel / unmapped は保持

    /*
     * **名前付き route を持たない行き先もある**（R-13 Stage 2）。
     *
     * CMS が持つ投稿タイプの公開面は `Route::fallback` が受けるので、
     * 型ごとの名前付き route は作らない。route が無いからと元の `works.html` を
     * 残すと、**取り込んだページのリンクだけが静的ファイルを指したまま**になる。
     * 行き先（`url`）は plan が知っているので、それを素の href として書く。
     */
    if (mapped.route === null) {
      if (mapped.url === null || mapped.url.includes("{")) continue;

      node.setAttribute(
        "href",
        `${mapped.url}${mapped.fragment ? `#${mapped.fragment}` : ""}`,
      );

      continue;
    }

    if ((mapped.url ?? "").includes("{")) continue; // 動的 route（works.show 等）は integration 側で処理
    const routeExpr =
      mapped.route === "news.show"
        ? null // 動的 route は integration 側で処理済み
        : `{{ route('${mapped.route}') }}${mapped.fragment ? `#${mapped.fragment}` : ""}`;
    if (routeExpr) node.setAttribute("href", routeExpr);
  }

  for (const node of el.querySelectorAll("link[href]")) {
    const href = node.getAttribute("href");
    if (!href || /^(https?:)?\/\//.test(href) || href.includes("{{")) continue;
    const resolved = resolveRelative(pageSource, href) ?? href;
    node.setAttribute("href", `{{ asset('${prefix}/${resolved}') }}`);
  }
}

/** inline <style> の url() 参照を asset パスへ書き換える（実案件は CSS が全て inline のことがある） */
export function rewriteInlineCssUrls(
  root: HTMLElement,
  pageSource: string,
  plan: TemplatePlan,
): void {
  const prefix = plan.assets.target_prefix;
  for (const styleEl of root.querySelectorAll("style")) {
    const rewritten = styleEl.text.replace(
      /url\(\s*(['"]?)([^'")]+?)\1\s*\)/g,
      (all: string, quote: string, ref: string) => {
        if (ref.startsWith("data:") || /^(https?:)?\/\//.test(ref)) return all;
        const resolved = resolveRelative(pageSource, ref) ?? ref;
        return `url(${quote}/${prefix}/${resolved}${quote})`;
      },
    );
    styleEl.set_content(rewritten);
  }
}

/**
 * ブロック要素を差し込んでよい位置まで、外側へ登る（R-16 ②）。
 *
 * `<p>` は**段落の中身しか持てない**。その中へ `<div>` を書くと、
 * **ブラウザが `<p>` を先に閉じてしまう** — 結果、後ろに居た送信ボタンは
 * `<p>` の外へ出て、`<p>` に掛かっていた `text-align:center` を失う。
 *
 * 実測（chromium に `<p style="text-align:center"><div…></div><button>` を読ませる）:
 * ボタンの親は `FORM` になり、`text-align` は `start` に戻る。
 * node-html-parser は `<p>` を閉じないので、**生成物を見ているだけでは気づけない**。
 *
 * ▷ だから**入れ物ごと外へ出る**。包みも整列もそのまま残る。
 */
function blockInsertionAnchor(
  node: HTMLElement,
  stopAt: HTMLElement,
): HTMLElement {
  // 段落の中身しか持てない要素（ここに block を入れると入れ物が割れる）
  const PHRASING_ONLY = new Set(["P"]);
  let anchor = node;

  while (anchor !== stopAt) {
    const parent = anchor.parentNode;

    if (parent === null || parent === stopAt) break;
    if (!PHRASING_ONLY.has(parent.tagName ?? "")) break;

    anchor = parent;
  }

  return anchor;
}

/**
 * contact form を既存 Contact Pipeline へ接続する。
 * 見た目は保持し、@csrf / Turnstile / エラー表示 / old() / name 変更のみ注入（spec §25–26）。
 * method=get の静的モックフォームは POST 化する。
 */
/**
 * モックの「押せない」状態を外す（見た目の文言は触らない）。
 *
 * `disabled` と、それに付いてくる薄い見た目（`opacity` 等の inline style）を落とす。
 */
function unlockMockSubmit(form: HTMLElement): void {
  const mockSubmit = form.querySelector(
    'button[type="submit"][disabled], input[type="submit"][disabled]',
  );

  if (mockSubmit === null) return;

  mockSubmit.removeAttribute("disabled");
  mockSubmit.removeAttribute("style");
}

export function substituteContactForm(
  main: HTMLElement,
  formSelector: string,
  mapping: Record<string, string>,
): void {
  const form = main.querySelector(formSelector);
  if (!form) return;

  form.setAttribute("action", "{{ route('contact.store') }}");
  form.setAttribute("method", "post");
  // 静的モックの「送信不可」状態（onsubmit="return false" / disabled submit）は
  // Pipeline 接続で解除する（見た目のテキストは保持 — Preserve First）
  form.removeAttribute("onsubmit");
  unlockMockSubmit(form);
  form.insertAdjacentHTML("afterbegin", "\n    @csrf");

  for (const [htmlName, schemaKeyRaw] of Object.entries(mapping)) {
    const schemaKey = schemaKeyRaw.split(" ")[0] ?? schemaKeyRaw;
    const field = form.querySelector(`[name="${htmlName}"]`);
    if (!field) continue;
    if (htmlName !== schemaKey) field.setAttribute("name", schemaKey);
    if (field.tagName === "INPUT") {
      field.setAttribute("value", `{{ old('${schemaKey}') }}`);
    } else if (field.tagName === "TEXTAREA") {
      field.set_content(`{{ old('${schemaKey}') }}`);
    }
    // エラー表示も `<p>` なので、入れ物が段落なら**その外**へ出す
    blockInsertionAnchor(field, form).insertAdjacentHTML(
      "afterend",
      `\n@error('${schemaKey}')<p class="form-error" role="alert">{{ $message }}</p>@enderror`,
    );
  }

  const submit = form.querySelector(
    'button[type="submit"], input[type="submit"]',
  );
  if (submit) {
    blockInsertionAnchor(submit, form).insertAdjacentHTML(
      "beforebegin",
      `\n          <div class="cf-turnstile" data-sitekey="{{ config('agentcms.turnstile.site_key') }}" data-language="ja"></div>\n@error('turnstile')<p class="form-error" role="alert">{{ $message }}</p>@enderror\n`,
    );
  }

  form.insertAdjacentHTML(
    "beforebegin",
    `@if (session('contact_success'))\n<div class="contact-success" role="status"><p><strong>お問い合わせを受け付けました。</strong><br>確認メールをお送りしています。届かない場合も受付は完了しています。</p></div>\n@endif\n`,
  );
}

/** select（name=schema key）の各 option に @selected(old(...)) を付与（文字列パス） */
export function addSelectedDirectives(content: string): string {
  return content.replace(
    /<select([^>]*\sname="([a-z0-9_]+)"[^>]*)>([\s\S]*?)<\/select>/gi,
    (_all, attrs: string, name: string, body: string) => {
      const newBody = body.replace(
        /<option([^>]*\svalue="([^"]*)"[^>]*?)>/gi,
        (optAll: string, optAttrs: string, value: string) =>
          optAttrs.includes("@selected")
            ? optAll
            : `<option${optAttrs} @selected(old('${name}') === '${value}')>`,
      );
      return `<select${attrs}>${newBody}</select>`;
    },
  );
}

export function bladeArray(values: Record<string, string | null>): string {
  const entries = Object.entries(values)
    .filter(([, v]) => v !== null)
    .map(
      ([k, v]) =>
        `'${k}' => '${(v ?? "").replaceAll("\\", "\\\\").replaceAll("'", "\\'")}'`,
    );
  return `[${entries.join(", ")}]`;
}

export function escapeAttr(v: string): string {
  return v.replaceAll("&", "&amp;").replaceAll('"', "&quot;");
}

export function indent(text: string, spaces: number): string {
  const pad = " ".repeat(spaces);
  return text
    .split("\n")
    .map((l) => (l.trim() ? pad + l : l))
    .join("\n");
}

export function basename(path: string): string {
  return path.split("/").pop() ?? path;
}

/** PHP シングルクォート文字列リテラル用エスケープ */
export function phpString(v: string): string {
  return v.replaceAll("\\", "\\\\").replaceAll("'", "\\'");
}

/** 要素の document ルートからの構造パス（同一構造ページ間の対応付けに使う） */
export function pathOf(el: HTMLElement): number[] {
  const path: number[] = [];
  let cur: HTMLElement = el;
  for (;;) {
    const parent = cur.parentNode;
    if (!(parent instanceof HTMLElement)) break;
    const siblings = parent.childNodes.filter(
      (c): c is HTMLElement => c instanceof HTMLElement,
    );
    path.unshift(siblings.indexOf(cur));
    cur = parent;
  }
  return path;
}

export function atPath(root: HTMLElement, path: number[]): HTMLElement | null {
  let cur: HTMLElement = root;
  for (const idx of path) {
    const children = cur.childNodes.filter(
      (c): c is HTMLElement => c instanceof HTMLElement,
    );
    const next = children[idx];
    if (!next) return null;
    cur = next;
  }
  return cur;
}

/** 入力欄 1 件ぶんの雛形（R-12 Stage 2 / D-3） */
export interface FieldTemplate {
  /**
   * 外枠。`__CONTROL__` が制御要素の位置、`__LABEL__` がラベル文字列の位置。
   *
   * **型に依らず 1 枚**である（原本 2 種で実測 — Green Leaf は `div.field`、
   * 実案件は `p`。どちらも中身の型が違っても外枠は同じ形だった）。
   */
  unit: string;
  /**
   * 制御要素に載っていた見た目の属性（`class` / `style`）。
   *
   * **型を越えて一定**である（実測 — 実案件は input / email / textarea の 3 つとも
   * inline style が 1 文字も違わず、Green Leaf は 3 つとも持っていない）。
   * だから**原本に無い型でも、この属性を載せれば原本の見た目で描ける**。
   */
  controlAttrs: Record<string, string>;
  /** ラベルの置き方。`wrapping` = `<label>` が制御要素を包む / `sibling` = `for=` で結ぶ */
  labelStyle: "wrapping" | "sibling";
  /** 雛形に採らなかった項目の name（外枠の形が多数派と違ったもの） */
  outliers: string[];
}

/** 送信ボタン等を除いた、値を持つ制御要素 */
function valueControls(scope: HTMLElement): HTMLElement[] {
  return scope
    .querySelectorAll("input, textarea, select")
    .filter((el) => {
      const type = (el.getAttribute("type") ?? "text").toLowerCase();
      return !["submit", "button", "reset", "hidden", "image"].includes(type);
    });
}

/** `tag.class` の署名（外枠が同じ形かを見るのに使う） */
function unitSignature(el: HTMLElement): string {
  return `${el.tagName}.${(el.getAttribute("class") ?? "").trim()}`;
}

/**
 * その制御要素 1 つだけを含む、最大の祖先まで登る（`<form>` は超えない）。
 *
 * **原本 2 種で当てて、両方とも正しい単位に着地した**（Green Leaf = `div.field` /
 * 実案件 = `p`）。`blockInsertionAnchor()` とは目的が違う —
 * あちらは「block を差し込んでよい位置」、こちらは「繰り返しの単位」である。
 */
function fieldUnit(control: HTMLElement, form: HTMLElement): HTMLElement {
  let unit = control;

  while (unit.parentNode !== null && unit.parentNode !== form) {
    if (valueControls(unit.parentNode).length !== 1) break;
    unit = unit.parentNode;
  }

  return unit;
}

/**
 * 取り込んだフォームから、項目 1 件ぶんの雛形を取り出す（R-12 Stage 2 / D-3）。
 *
 * ## なぜ「外枠 1 枚 + 制御要素の属性」に割るのか
 *
 * 素朴に数えると**構造の違う雛形が型の数だけ要る**ように見える
 * （実測: Green Leaf は 5 項目で 4 種類、実案件は 3 項目で 2 種類）。
 * 公開フォームで使える型は **12 種**あるので、原本に無い型では意匠が崩れる —
 * **運用者が項目を 1 つ足した瞬間に「勝手にデザインが変わった」になる。**
 *
 * だが**違っていたのは制御要素そのものだけ**で、外枠も、制御要素に載る属性も、
 * 型を越えて一定だった。だから割れる。**型ごとに N 枚は要らない。**
 *
 * ## 多数派を採る
 *
 * 外枠の形が揃わないことは在る（Green Leaf の checkbox だけ
 * `div.field field-checkbox` だった）。**多数派を雛形にし、外れたものは
 * `outliers` で名指して返す** — 黙って多数派に寄せると、
 * 「1 つだけ違う見た目だったもの」が理由も分からず消える。
 */
export function extractFieldTemplate(
  form: HTMLElement | null,
): FieldTemplate | null {
  if (form === null) return null;

  const controls = valueControls(form);

  if (controls.length === 0) return null;

  const units = controls.map((c) => ({ control: c, unit: fieldUnit(c, form) }));

  // 署名ごとに数えて多数派を採る（同数なら先に出たほう）
  const counts = new Map<string, number>();
  for (const { unit } of units) {
    const sig = unitSignature(unit);
    counts.set(sig, (counts.get(sig) ?? 0) + 1);
  }

  let major = unitSignature(units[0]!.unit);
  for (const [sig, n] of counts) {
    if (n > (counts.get(major) ?? 0)) major = sig;
  }

  const chosen = units.find(({ unit }) => unitSignature(unit) === major)!;
  const outliers = units
    .filter(({ unit }) => unitSignature(unit) !== major)
    .map(({ control }) => control.getAttribute("name") ?? "(名前なし)");

  return {
    unit: templateOf(chosen.unit, chosen.control),
    controlAttrs: lookAttrs(chosen.control),
    labelStyle: labelStyleOf(chosen.unit, chosen.control),
    outliers,
  };
}

/**
 * 単位の HTML を雛形にする。
 *
 * **原本の文字列をそのまま使う**（組み立て直さない）。空白も属性の並びも
 * 原本のままにしたいので、置き換えるのは**制御要素とラベル文字列の 2 か所だけ**。
 */
function templateOf(unit: HTMLElement, control: HTMLElement): string {
  /*
   * **呼び出し元の DOM は 1 バイトも触らない。**
   *
   * `replaceWith()` で印を差してから戻す、という書き方もできるが、
   * 途中で例外が出ると**原本が壊れたまま残る**。文字列の置換なら、
   * 失敗しても入力は元のままである。
   */
  const html = unit.toString();
  const controlHtml = control.toString();
  const labelText = labelTextNode(unit, control);

  let out = html.replace(controlHtml, "__CONTROL__");

  if (labelText !== null && labelText.trim() !== "") {
    out = out.replace(labelText, "__LABEL__");
  }

  return out;
}

/** `<label>` が持っているラベル文字列（無ければ null） */
function labelTextNode(unit: HTMLElement, control: HTMLElement): string | null {
  const label = nearestLabel(unit, control);

  if (label === null) return null;

  for (const node of label.childNodes) {
    if (node instanceof HTMLElement) continue;
    const text = node.rawText;
    if (text.trim() !== "") return text;
  }

  return null;
}

/** 制御要素を包む `<label>`、無ければ単位の中の `<label>` */
function nearestLabel(unit: HTMLElement, control: HTMLElement): HTMLElement | null {
  let node: HTMLElement | null = control;

  while (node !== null && node !== unit.parentNode) {
    if (node.tagName === "LABEL") return node;
    node = node.parentNode;
  }

  return unit.querySelector("label");
}

function labelStyleOf(
  unit: HTMLElement,
  control: HTMLElement,
): "wrapping" | "sibling" {
  let node: HTMLElement | null = control.parentNode;

  while (node !== null && node !== unit.parentNode) {
    if (node.tagName === "LABEL") return "wrapping";
    node = node.parentNode;
  }

  return "sibling";
}

/**
 * 見た目を運ぶ属性だけ取る。
 *
 * `name` / `id` / `type` / `value` / `required` は**項目ごとに決まる**ので運ばない。
 * ここで運ぶのは、**その原本のフォーム全体で一定だった見た目**である。
 */
function lookAttrs(control: HTMLElement): Record<string, string> {
  const out: Record<string, string> = {};

  for (const key of ["class", "style"]) {
    const value = control.getAttribute(key);
    if (value !== undefined && value !== null && value.trim() !== "") {
      out[key] = value;
    }
  }

  return out;
}

/**
 * `@foreach` に載せる項目 1 件ぶんの Blade（R-12 Stage 2 / D-3 後半）。
 *
 * 雛形の `__LABEL__` / `__CONTROL__` を差し替えるだけで、**原本の空白も属性の並びも動かさない**。
 *
 * ## `for` / `id` は張り直す
 *
 * 兄弟の形（`<label for="a">` ＋ `<input id="a">`）は、原本の id が**1 つ目の項目のもの**なので、
 * そのまま繰り返すと **id が全部同じになる**（支援技術から見て壊れる）。
 * 項目の key から作り直す。
 */
function loopBody(template: FieldTemplate, partial: string): string {
  let unit = template.unit;

  // 原本に焼き付いた for="…" を、項目ごとの id へ張り直す
  unit = unit.replace(/for="[^"]*"/g, 'for="{{ $fieldId }}"');

  return unit
    .replace("__LABEL__", "{{ $field->label }}")
    .replace("__CONTROL__", `@include('${partial}', ['field' => $field, 'fieldId' => $fieldId])`);
}

/** 雛形が運んできた見た目の属性を、Blade の属性文字列にする */
function lookAttrString(attrs: Record<string, string>): string {
  return Object.entries(attrs)
    .map(([k, v]) => ` ${k}="${escapeAttr(v)}"`)
    .join("");
}

/**
 * 制御要素を **12 種すべて**描き分ける部品を作る（R-12 Stage 2 / D-3 後半）。
 *
 * ## なぜ kit の部品を使わないのか
 *
 * `forms/partials/field.blade.php` は同じ 12 種を描き分けるが、出すのは
 * **`<div class="form-field">` を含む外枠つき**である。取り込んだ外枠が `<p>` のとき、
 * 中へ入れると**ブラウザが `<p>` を先に閉じる**（`contact-form-wrapper.test.ts` の罠）。
 * こちらは**制御要素だけ**を出す — 外枠は原本のものを使うためである。
 *
 * ## 原本に無い型でも、原本の見た目で出す
 *
 * 見た目は `class` / `style` として制御要素に載っており、**型を越えて一定**だった（実測）。
 * だから 12 種すべてに同じ属性を載せれば、**原本が持っていなかった型でも浮かない**。
 */
export function buildControlPartial(template: FieldTemplate): string {
  const look = lookAttrString(template.controlAttrs);

  return `{{--
    入力欄の**制御要素だけ**（R-12 Stage 2 / D-3）。**生成物** — 手で直すと再 apply 時に conflict になります。

    外枠は取り込んだ原本のものを使うので、ここでは出さない。
    kit 既定（\`forms/partials/field.blade.php\`）は \`<div class="form-field">\` ごと出すため、
    原本の外枠が \`<p>\` のときに入れられない（段落が割れる）。

    見た目（class / style）は**原本の制御要素から写した 1 セット**を全種類に載せる。
    型を越えて一定だったので、原本に無い型でも浮かない。
--}}
@php
    $value = old($field->key, $values[$field->key] ?? $field->default);
    $textType = ['email' => 'email', 'url' => 'url', 'tel' => 'tel', 'number' => 'number',
                 'date' => 'date', 'datetime' => 'datetime-local'][$field->type] ?? 'text';
@endphp
@if (in_array($field->type, ['radio', 'checkbox'], true))
    @php $selected = is_array($value) ? array_map(strval(...), $value) : []; @endphp
    @foreach ($field->choices as $i => $choice)
        <label for="{{ $fieldId }}-{{ $i }}">
            @if ($field->type === 'radio')
                <input type="radio" id="{{ $fieldId }}-{{ $i }}" name="{{ $field->key }}" value="{{ $choice }}"
                    @checked((string) $value === (string) $choice)
                    @if($field->required) required @endif>
            @else
                <input type="checkbox" id="{{ $fieldId }}-{{ $i }}" name="{{ $field->key }}[]" value="{{ $choice }}"
                    @checked(in_array((string) $choice, $selected, true))>
            @endif
            <span>{{ $choice }}</span>
        </label>
    @endforeach
@elseif ($field->type === 'boolean')
    <input type="checkbox" id="{{ $fieldId }}" name="{{ $field->key }}" value="1"
        @checked(in_array($value, [true, 1, '1', 'on', 'true'], true))
        @if($field->required) required @endif>
@elseif ($field->type === 'textarea')
    <textarea id="{{ $fieldId }}" name="{{ $field->key }}" rows="6"${look}
        @if($field->required) required @endif
        @if($field->max !== null) maxlength="{{ $field->max }}" @endif
        @if($errors->has($field->key)) aria-invalid="true" @endif>{{ $value }}</textarea>
@elseif ($field->type === 'select')
    <select id="{{ $fieldId }}" name="{{ $field->key }}"${look}
        @if($field->required) required @endif
        @if($errors->has($field->key)) aria-invalid="true" @endif>
        <option value="">選択してください</option>
        @foreach ($field->choices as $choice)
            <option value="{{ $choice }}" @selected((string) $value === (string) $choice)>{{ $choice }}</option>
        @endforeach
    </select>
@else
    <input type="{{ $textType }}" id="{{ $fieldId }}" name="{{ $field->key }}" value="{{ $value }}"${look}
        @if($field->required) required @endif
        @if($field->max !== null) maxlength="{{ $field->max }}" @endif
        @if($errors->has($field->key)) aria-invalid="true" @endif>
@endif
`;
}

/**
 * 取り込んだフォームを **CMS のフォーム**へ繋ぎ替える（R-12 Stage 2 / D-3 後半）。
 *
 * `substituteContactForm()` の兄弟。あちらは**旧 Contact パイプライン**へ繋ぎ、
 * 項目は原本の 3 つのまま固定だった。こちらは **CMS の項目を `@foreach` で描く**ので、
 * 運用者が項目を足しても**原本の見た目のまま**出る。
 *
 * ## 送信先は決めない
 *
 * `action` は **`{{ $action }}`** にする。確認画面を挟むかはフォームの設定なので、
 * **`PublicFormController` が決めたものを受け取る**（ここで route を選ぶと、
 * 設定を変えたときに view の側が置いていかれる）。
 */
export function substituteCmsForm(
  main: HTMLElement,
  formSelector: string,
  template: FieldTemplate,
  partial: string,
): void {
  const form = main.querySelector(formSelector);

  if (form === null) return;

  form.setAttribute("action", "{{ $action }}");
  form.setAttribute("method", "post");
  form.removeAttribute("onsubmit");

  /*
   * 🔴 **モックの「押せない」状態を外す。**
   *
   * 静的テンプレートの送信ボタンには `disabled` が付いていることがある。
   * 残したまま出すと**フォームは動いているのに押せない** — 裏側（確認 → 送信 →
   * 保存 → 通知）は全部通るのに、**利用者はボタンを押せずに帰る。**
   * エラーは出ない（2026-08-28 に実案件で実測。ここだけ外し忘れていた）。
   *
   * ▷ **文言は変えない**（Preserve First）。`plan` が「準備中」を見つけたら
   *   note で人に伝える役で、書き換えるのはこちらの仕事ではない。
   *   **外すのは「押せない」という振る舞いの方だけ。**
   *
   * ▷ `substituteContactForm()` が同じことをしている。**同じ判断が 2 か所**だが、
   *   触っている木が別（旧 Pipeline 用と CMS フォーム用）で、片方を消すと
   *   もう片方が壊れる。**寄せるなら両方を 1 つの関数へ**（B-53 に数えてある）。
   */
  unlockMockSubmit(form);

  const controls = valueControls(form);
  const units = controls.map((c) => fieldUnit(c, form));

  // 1 つ目の単位を `@foreach` に置き換え、残りは消す（項目は CMS が決める）
  const first = units[0];

  if (first !== undefined) {
    first.insertAdjacentHTML(
      "beforebegin",
      `@foreach ($fields as $field)\n@php $fieldId = 'f-'.$field->key; @endphp\n${loopBody(template, partial)}\n@endforeach\n`,
    );
  }

  for (const unit of units) unit.remove();

  form.insertAdjacentHTML("afterbegin", "\n    @csrf");

  /*
   * **受付を終了したフォームでは、入力欄を出さない**（SPEC §C.1）。
   *
   * URL は生きているが送信はできない状態がある。原本にはこの状態が無いので、
   * **原本の見た目を 1 つも変えずに、フォームごと差し替える**形で足す
   * （原本の中へ「終了しました」を書き込むと、開いているときの見た目まで動く）。
   */
  form.insertAdjacentHTML(
    "beforebegin",
    '@if (isset($rateLimitMessage))\n<p class="form-error" role="alert">{{ $rateLimitMessage }}</p>\n@endif\n@if ($closed)\n<p class="form-closed" role="status">このフォームは受付を終了しました。</p>\n@else\n',
  );
  form.insertAdjacentHTML("afterend", "\n@endif\n");

  const submit = form.querySelector(
    'button[type="submit"], input[type="submit"]',
  );

  if (submit !== null) {
    /*
     * **人には見えない項目と Turnstile は、入れ物の外へ出す**（R-16 ②）。
     * どちらも block なので、`<p>` の中へ入れると段落が割れる。
     */
    blockInsertionAnchor(submit, form).insertAdjacentHTML(
      "beforebegin",
      `\n<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
    <label for="{{ $honeypot }}">この欄は入力しないでください</label>
    <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
</div>
@if ($turnstileSiteKey !== '')
    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-language="ja"></div>
    @error('cf-turnstile-response')<p class="form-error" role="alert">{{ $message }}</p>@enderror
@endif\n`,
    );
  }
}

/** 取り込んだセクション 1 つぶんの雛形（R-12 / NEW-2） */
export interface SectionTemplate {
  /** 原本の markup。編集できる文字が `__PROP_<key>__` に置き換わっている */
  unit: string;
  /** `content/components/*.json` の `propsSchema` に入れる項目 */
  props: {
    key: string;
    label: string;
    multiline: boolean;
    value: string;
    /**
     * `url` は画像の src とリンクの href。**検証は kit の `url` 型に任せる**
     * （`DocumentValidator::checkUrl()`: `/…` `#…` http(s) mailto tel だけ）。省略は文字
     */
    kind?: "url";
    /** 取り込んだ画像は、元URLを残したままメディアライブラリから差し替えられる。 */
    mediaKey?: string;
  }[];
}

/** 文字を編集させない入れ物（触ると導線や飾りが壊れる） */
const NOT_EDITABLE_ANCESTORS = new Set([
  "A", // 導線。文言を変えると行き先と食い違う
  "BUTTON",
  "SCRIPT",
  "STYLE",
  "SVG",
]);

/** その文字は編集させてよいか */
function editableText(node: HTMLElement, raw: string): boolean {
  const text = raw.trim();

  // 短すぎるもの（記号・区切り）は項目にしない
  if (text.length < 2) return false;

  // Blade の式・ディレクティブは原本の一部ではない
  if (text.startsWith("{{") || text.startsWith("@")) return false;

  for (let a: HTMLElement | null = node; a !== null; a = a.parentNode) {
    if (NOT_EDITABLE_ANCESTORS.has(a.tagName ?? "")) return false;
  }

  return true;
}

/** その文字の役割（項目の名前に使う） */
function roleOf(tag: string): { name: string; multiline: boolean } {
  if (/^H[1-6]$/.test(tag)) return { name: "見出し", multiline: false };
  if (tag === "P") return { name: "本文", multiline: true };
  if (tag === "LI") return { name: "箇条書き", multiline: false };

  return { name: "文字", multiline: false };
}

/**
 * 取り込んだセクションから、**編集できる文字だけ**を項目として取り出す（NEW-2）。
 *
 * ## なぜ「ブロックへ組み直す」ではないのか
 *
 * `PageDocument` の既定のブロック（heading / text / …）で組み直すと、
 * ブロックが**自前の markup と class** を出す。取り込んだ意匠は
 * **58,751 字の CSS が原本の class に載っている**ので、**組み直した時点で消える**。
 *
 * ▷ **オーナー決定は「デザインは勝手に変えちゃダメ」**（2026-08-27）。
 * お問い合わせで採った (ア-3b) と**同じ形**を、ページにも当てる —
 * **外枠は原本のまま、中の文字だけ差し替えられるようにする。**
 *
 * ## 編集させないもの
 *
 * **`<a>` の中は触らせない。** 文言を変えると**行き先と食い違う**
 * （「詳しく見る」を「お問い合わせ」に変えても、飛ぶ先は変わらない）。
 * `button` / `script` / `style` / `svg` も同じ理由で外す。
 *
 * @param section セクションの要素。原本の DOM は**1 バイトも変えない**
 */
/**
 * 前後の空白を詰める。**改行なしスペース（U+00A0）は残す**。
 *
 * 🔴 `String.trim()` は U+00A0 も落とす。原本の `&nbsp;` は
 * **意匠として置かれた隙間**なので、落とすと詰まって見える
 * （2026-08-28 に実案件の流れる文字で実測。前後 3 つずつ消えた）。
 */
function trimSpace(value: string): string {
  return value.replace(/^[ \t\n\r\f\v]+|[ \t\n\r\f\v]+$/g, "");
}

export function extractSectionTemplate(
  section: HTMLElement | null,
): SectionTemplate | null {
  if (section === null) return null;

  const html = section.toString();
  const props: SectionTemplate["props"] = [];
  const counts = new Map<string, number>();
  let out = html;

  for (const node of section.querySelectorAll("*")) {
    for (const child of node.childNodes) {
      if (child instanceof HTMLElement) continue;

      // **コメント（nodeType 8）は文字ではない。**項目にすると、原本の
      // `<!-- hero -->` のような書き込みが編集欄に出て、同じ綴りの本文と取り違える
      if (child.nodeType !== 3) continue;

      const raw = child.rawText;

      if (!editableText(node, raw)) continue;

      /*
       * 🔴 **持つのは、実体参照を解いた文字の方。**
       *
       * `rawText` は原本の綴りのままなので `&nbsp;` は 6 文字の記号列である。
       * それを props に入れると Blade の `{{ }}` が `&` を再び逃がし、
       * **画面に `&amp;nbsp;` と出る**（2026-08-28 に実案件の流れる文字で実測。
       * バイト数は 22 しか動かないので、合計値では気づけない）。
       *
       * 印を置く先は原本の綴り（`raw`）、持つ値は解いた文字（`child.text`）。
       */
      const text = trimSpace(child.text);
      const role = roleOf(node.tagName ?? "");
      const seq = (counts.get(role.name) ?? 0) + 1;
      counts.set(role.name, seq);

      const key = `${role.name === "見出し" ? "heading" : role.name === "本文" ? "body" : "text"}_${seq}`;
      const marker = `__PROP_${key}__`;

      /*
       * **同じ文字が 2 か所に在ることがある。** 先に出たほうだけ差し替える
       * （`replace` は 1 回だけ置く）。取り違えると、片方が原本のまま残る。
       */
      /*
       * **置き換えるのは前後の空白を除いた部分だけ。**
       *
       * `raw` ごと置くと、原本にあった前後の空白まで消える。
       * インラインの文字だと**隣の語とくっつく**（`前 後` が `前後` になる）。
       */
      const source = raw.trim();
      const before = out;
      out = out.replace(source, marker);

      if (out === before) continue; // 置けなかった（既に差し替え済み等）

      /*
       * **名前は「役割 ＋ もとの文言」にする。**
       *
       * 「文字 4」だけでは、運用者が**どれのことか分からない**（実測: 概念の節は
       * 14 項目あり、`CONCEPT` / `みどり造園` / `こだわり` / `01` が全部「文字 N」になった）。
       *
       * ▷ **class 名は使わない**（`section-title-accent` 等）。
       * **顧客面の UI に技術語彙を 1 つも出さない**（B-25 の最上位の原則）。
       *
       * ▷ **もとの文言なら陳腐化しても害が無い** — 直したあとも
       * 「もとは CONCEPT だった場所」として読める。
       */
      // 名前の方は普通に詰める（見えない文字を名前の頭に置かない）
      const named = text.trim();
      const hint = named.length > 12 ? `${named.slice(0, 12)}…` : named;

      props.push({
        key,
        label: `${role.name}（${hint}）`,
        multiline: role.multiline,
        value: text,
      });
    }
  }

  /*
   * ---- 画像の src / alt とリンクの href / 文言（R03 続き (2)）----
   *
   * 属性の値も項目にする。**構造・class は 1 バイトも動かさない** — 動くのは属性の値だけ。
   *
   * ▷ 画像の src は生成の段で `{{ asset('imported/…') }}` になっている。初期値は
   *   `/imported/…`（同じ場所を指す root 相対）にして、運用者が別の URL を入れられるようにする。
   *   `{{ }}` を含む値・相対パス（`images/a.png`）は `url` 型の検証に通らないので項目にしない。
   * ▷ リンクの文言は、`<a>` の中が**文字だけ**のときに項目にする。href と一緒に直せるので、
   *   「文言を変えたのに行き先が違う」は起きない（両方が同じ節の項目として並ぶ）。
   *   `{{ route(…) }}` など Blade の href はそのまま（CMS の導線）。
   */
  const attrCounts = new Map<string, number>();
  const nextSeq = (name: string): number => {
    const seq = (attrCounts.get(name) ?? 0) + 1;
    attrCounts.set(name, seq);

    return seq;
  };
  const replaceAttr = (attr: string, raw: string, key: string): boolean => {
    const source = `${attr}="${raw}"`;
    const before = out;
    out = out.replace(source, `${attr}="__PROP_${key}__"`);

    return out !== before;
  };
  const hint = (value: string): string =>
    value.length > 24 ? `…${value.slice(-22)}` : value;

  // 値の無い属性（`<img alt>`）は文字列で来ないことがある。文字列以外は「無い」と読む
  const attr = (el: HTMLElement, name: string): string | undefined => {
    const value: unknown = el.rawAttributes[name];

    return typeof value === "string" ? value : undefined;
  };

  for (const img of section.querySelectorAll("img")) {
    const rawSrc = attr(img, "src");
    const rawAlt = attr(img, "alt");
    const seq = nextSeq("image");
    const asset = rawSrc === undefined ? null : /^\{\{\s*asset\('([^']+)'\)\s*\}\}$/.exec(rawSrc);
    const srcValue =
      asset !== null ? `/${asset[1]}` : rawSrc !== undefined && isPlainUrl(rawSrc) ? rawSrc : null;

    if (rawSrc !== undefined && srcValue !== null && replaceAttr("src", rawSrc, `image_${seq}_src`)) {
      props.push({
        key: `image_${seq}_src`,
        mediaKey: `image_${seq}_media`,
        label: `画像 ${seq} の場所（${hint(srcValue)}）`,
        multiline: false,
        value: srcValue,
        kind: "url",
      });
    }

    if (rawAlt !== undefined && !rawAlt.includes("{{") && replaceAttr("alt", rawAlt, `image_${seq}_alt`)) {
      props.push({
        key: `image_${seq}_alt`,
        label: `画像 ${seq} の説明（${rawAlt === "" ? "空" : hint(decodeEntities(rawAlt))}）`,
        multiline: false,
        value: decodeEntities(rawAlt),
      });
    }
  }

  for (const a of section.querySelectorAll("a")) {
    const rawHref = attr(a, "href");
    const seq = nextSeq("link");
    const hrefOk =
      rawHref !== undefined && isPlainUrl(rawHref) && replaceAttr("href", rawHref, `link_${seq}_href`);

    if (hrefOk) {
      props.push({
        key: `link_${seq}_href`,
        label: `リンク ${seq} の行き先（${hint(rawHref)}）`,
        multiline: false,
        value: rawHref,
        kind: "url",
      });
    }

    // 中が文字だけの `<a>` は文言も項目にする（入れ子の要素が在れば触らない）
    const onlyText =
      a.childNodes.length === 1 && a.childNodes[0] !== undefined && a.childNodes[0].nodeType === 3;

    if (!onlyText) continue;

    const rawText = a.childNodes[0]!.rawText;
    const text = trimSpace(a.childNodes[0]!.text);

    if (text.length < 1 || text.startsWith("{{") || text.startsWith("@")) continue;

    const key = `link_${seq}_text`;
    const before = out;
    out = out.replace(rawText.trim(), `__PROP_${key}__`);

    if (out === before) continue;

    props.push({
      key,
      label: `リンク ${seq} の文言（${hint(text)}）`,
      multiline: false,
      value: text,
    });
  }

  return { unit: out, props };
}

/**
 * `url` 型の検証（`DocumentValidator::checkUrl()`）に通る**素の** URL か。
 * Blade の式・相対パス・javascript: は項目にしない（通らない値を初期値にすると、取り込みが作ったページを
 * 取り込みを動かした本人が保存できない）。
 */
function isPlainUrl(value: string): boolean {
  if (value.includes("{{") || value.includes("{!!") || value.trim() === "") return false;
  if (value.startsWith("/") || value.startsWith("#")) return true;

  return /^(?:https?:|mailto:|tel:)/i.test(value);
}

/** 属性の値に残る実体参照を解く（`&amp;` だけを見る。属性に出る実体参照はほぼこれだけ） */
function decodeEntities(value: string): string {
  return value
    .replaceAll("&amp;", "&")
    .replaceAll("&quot;", '"')
    .replaceAll("&#39;", "'")
    .replaceAll("&lt;", "<")
    .replaceAll("&gt;", ">");
}

/** 取り込んだセクション 1 つを、CMS の component として書き出したもの（NEW-2） */
export interface ImportedSectionComponent {
  /** `content/components/<type>.json` の中身 */
  definition: string;
  /** `resources/views/components/document/<view>.blade.php` の中身 */
  view: string;
  /** 登録する type（`imported_<id>`） */
  type: string;
  /**
   * view の名前（`components.document.imported-<id>`）。
   *
   * **呼ぶ側で組み直させない。**`type` から綴りを導く規則を 2 か所に置くと、
   * 片方だけ直した日に、定義が指す先と実ファイルの置き場所がずれる（B-53 の族）。
   */
  viewName: string;
  /** document へ最初に置く props（原本の文言） */
  props: Record<string, string>;
}

/**
 * `id` を機械名にする（`content/components` の type は allowlist の鍵になる）。
 *
 * 🔴 **綴りを組むのはここだけ**（D-4）。2026-08-28 に**同じ綴りを 3 か所が
 * 別々に組んでいた**（ここ／`imported-collection.ts`／`imported-blocks.ts`）。
 * しかも**答えが違った** — 6 通りで試すと 5 通りで食い違う:
 *
 * ```
 * "GALLERY"     imported_gallery     vs  imported_GALLERY
 * "works-list"  imported_works_list  vs  imported_works-list
 * ""            imported_section     vs  imported_
 * ```
 *
 * **定義が指す先と実ファイルの置き場所がずれると、一覧には並ぶのに描けない。**
 */
export function componentType(sectionId: string): string {
  const safe = sectionId.toLowerCase().replaceAll(/[^a-z0-9_]/g, "_");

  return `imported_${safe === "" ? "section" : safe}`;
}

/**
 * その type を描く view の名前。
 *
 * **`type` から綴りを導く規則を 2 か所に置かない** — 片方だけ直した日に、
 * 定義が指す先と実ファイルの置き場所がずれる。
 */
export function componentViewName(type: string): string {
  return `components.document.${type.replaceAll("_", "-")}`;
}

/**
 * 取り込んだセクションを、**CMS の component 1 つ**として書き出す（NEW-2 後半）。
 *
 * ## 何が手に入るか
 *
 * - **意匠は原本の markup そのもの**（`renderer.view` に自前の Blade を指す）
 * - **運用者が触るのは `propsSchema` の名前つき項目**だけ
 * - 視覚エディタの属性パネルは **`propsSchema` を回して入力欄を出す**ので、
 *   **新しい型でもそのまま編集できる**（実測で確認）
 *
 * ## 置ける場所を絞る
 *
 * `allowedParents` は **`page` だけ**。取り込んだセクションは**ページ直下に並ぶもの**で、
 * `container` の中へ入れられると意匠が崩れる。
 * `allowedChildren` は **空**（中身は原本が決めているので、ブロックを足させない）。
 *
 * ## 見出しの階層を持ち込まない
 *
 * `heading` のような `level` は持たせない。**原本の `<h2>` はそのまま `<h2>` で描かれる**ので、
 * **CMS 側で階層を変えられるようにすると、原本と食い違う。**
 */
/**
 * 項目の上限。
 *
 * 🔴 **原本に入っていたものより狭い上限を設けない。**
 * 役割だけで決めると、`<p>` でない長い文字（流れる文字の帯など）が
 * **取り込んだ直後に CMS 自身から拒まれる** — 取り込みが作ったページを、
 * 取り込みを動かした本人が保存できない状態になる（2026-08-28 に実案件で実測。
 * 245 字の marquee が上限 200 で弾かれた）。
 *
 * 広げるときは **100 字単位で切り上げる**。原本ぴったりにすると、
 * 運用者が 1 字足しただけで保存できなくなる。
 */
function fieldMax(p: SectionTemplate["props"][number]): number {
  const byRole = p.multiline ? 2000 : 200;

  return p.value.length <= byRole
    ? byRole
    : Math.ceil(p.value.length / 100) * 100;
}

export function buildImportedSectionComponent(
  sectionId: string,
  label: string,
  template: SectionTemplate,
): ImportedSectionComponent {
  const type = componentType(sectionId);
  const view = componentViewName(type);

  const propsSchema: Record<string, unknown> = {};
  const props: Record<string, string> = {};

  for (const p of template.props) {
    /*
     * **複数行かどうかは `max` が決める。**
     *
     * `multiline` という鍵は無い — 属性パネルは `spec.max > 200` で判断している
     * （`InspectorPanel.vue:179`・実測）。**書いても効かない鍵を送らない。**
     * 本文は 2000（複数行）、見出しは 200（1 行）。**200 ちょうどは 1 行**である。
     */
    propsSchema[p.key] =
      p.kind === "url"
        ? { type: "url", label: p.label }
        : { type: "string", label: p.label, max: fieldMax(p) };
    props[p.key] = p.value;
    if (p.mediaKey !== undefined) {
      propsSchema[p.mediaKey] = { type: "media", label: "差し替える画像を選択（未選択なら元の画像）" };
    }
  }

  const definition =
    JSON.stringify(
      {
        type,
        label,
        category: "imported",
        since: 1,
        propsSchema,
        styleSchema: ["visibility"],
        bindable: [],
        allowedChildren: [],
        allowedParents: ["page"],
        renderer: { kind: "blade", view },
        capabilities: { responsive: ["visibility"], container: false },
      },
      null,
      2,
    ) + "\n";

  /*
   * 原本の markup の `__PROP_<key>__` を Blade の出力へ置き換える。
   *
   * **`{{ }}` を使う**（`{!! !!}` ではない）。ここに入るのは**運用者が書いた文字**で、
   * **HTML として解釈させない** — B-25 が「CSS や HTML を利用者に意識させない」と
   * 決めている以上、**書けてしまう口を開けない**。
   */
  let body = template.unit;

  for (const p of template.props) {
    /*
     * **印は 1 つしか無い**（`key` は文字ごとに採番するため）。
     * だから `replace` でも結果は同じで、**変異を打っても赤くならない**。
     *
     * ▷ **試験が足りないのではない** — 印が 1 対 1 であること自体は
     * 「印が 1 つも残らない」「項目と初期値の数が合う」で固定してある。
     * `replaceAll` にしてあるのは、**その前提が崩れたときに黙って取りこぼさない**ため。
     */
    body = body.replaceAll(
      `__PROP_${p.key}__`,
      p.mediaKey === undefined
        ? `{{ $props['${p.key}'] ?? '' }}`
        : `{{ \\Modules\\Media\\Models\\Media::query()->where('visibility', 'public')->find($props['${p.mediaKey}'] ?? null)?->url() ?? ($props['${p.key}'] ?? '') }}`,
    );
  }

  const viewSource = `{{--
    取り込んだセクション「${label}」（NEW-2）。**生成物** — 手で直すと再 apply 時に conflict になります。

    **意匠は原本の markup そのもの。** 触れるのは \`propsSchema\` の項目だけで、
    外枠・class・入れ子は 1 バイトも動かない（CSS が原本の class に載っているため）。

    画像の場所・説明とリンクの行き先・文言も項目（\`url\` 型は kit の検証に通る値だけ）。入れ子のあるリンクの中身は触らない。
--}}
${body}
`;

  return { definition, view: viewSource, type, viewName: view, props };
}

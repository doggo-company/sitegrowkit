/**
 * データ接続の判定（Phase 9.6 / P0-C5・`PHASE9_6_SPEC.md` §4.9）。
 *
 * ## ここに型整合の表は無い
 *
 * どの Field をどの prop へ流せるかは、サーバーの `BindingTypes` が解いて
 * `spec.accepts` に入れてある。ここがするのは「候補にこの型が入っているか」を
 * 見ることだけである。**表を 2 つ持たない** — 片方だけ直したときに
 * 「UI では選べるのに保存できない」（あるいはその逆）が生まれる。
 *
 * ## Inspector から出してある理由
 *
 * 画面を開かなくても決まることだからである。判定を SFC の中に置くと、
 * 「エラーと呼ぶかどうか」を確かめるのに毎回ブラウザが要る。
 */
import type {
    BindingField,
    BindingSources,
    ComponentSpec,
} from '@/types/document';

export interface BindingRecord {
    source?: string;
    type?: string;
    field?: string;
    prop?: string;
}

/** その prop へ流せる Field 型（宣言が無ければ空） */
function acceptsFor(spec: ComponentSpec, prop: string | undefined): string[] {
    return prop === undefined ? [] : (spec.accepts[prop] ?? []);
}

/** その prop が HTML を受け取る宣言か（= richtext だけが流せる） */
function htmlProp(spec: ComponentSpec, prop: string): boolean {
    return spec.propsSchema[prop]?.format === 'html';
}

/** その binding の参照元が出せる項目（受け入れで絞る前の全部） */
function allFields(
    binding: BindingRecord,
    sources: BindingSources,
): BindingField[] {
    if (binding.source === 'page') {
        return sources.page;
    }

    return sources.entry.find((e) => e.type === binding.type)?.fields ?? [];
}

/** 流し先に流せる項目だけ */
export function fieldChoices(
    binding: BindingRecord | null,
    spec: ComponentSpec,
    sources: BindingSources,
): BindingField[] {
    if (binding === null) {
        return [];
    }

    const accepts = acceptsFor(spec, binding.prop);

    return allFields(binding, sources).filter((field) =>
        accepts.includes(field.type),
    );
}

function propLabel(spec: ComponentSpec, prop: string): string {
    return spec.propsSchema[prop]?.label ?? prop;
}

/**
 * この接続の**壊れているところ**。壊れていなければ `null`。
 *
 * サーバーの検証と同じ順で見る。そして **正当なものをエラーと呼ばない** —
 * `settings` の接続は型が引けないだけで、validator も resolver も通る。
 * 正当なものをエラー表示すると「エラー = 直すべきもの」の意味が壊れる。
 */
export function bindingProblem(
    binding: BindingRecord | null,
    spec: ComponentSpec,
    sources: BindingSources,
): string | null {
    if (binding === null) {
        return null;
    }

    if (binding.source === 'collection') {
        // 一覧はまるごと流し込むので、流し先の項目が無い
        return binding.prop === undefined
            ? null
            : '一覧の接続に流し先の項目は指定できません';
    }

    const prop = binding.prop;

    if (prop === undefined || prop === '') {
        return '流し先の項目が指定されていません';
    }

    if (!spec.bindable.includes(prop)) {
        return `[${prop}] はコンテンツを流せる項目ではありません`;
    }

    /*
     * **型が引けない参照元は、HTML を受ける項目にだけ流せない**（§4.9 の非対称）。
     * ほかの項目では、型が読めないことを理由に断らない。
     */
    if (binding.source === 'settings') {
        return htmlProp(spec, prop)
            ? `[${propLabel(spec, prop)}] には記事のリッチテキストだけが流せます`
            : null;
    }

    const field = allFields(binding, sources).find(
        (f) => f.key === binding.field,
    );

    if (field === undefined) {
        return `参照先に [${binding.field ?? ''}] は見つかりません`;
    }

    return acceptsFor(spec, prop).includes(field.type)
        ? null
        : `[${field.label}] は [${propLabel(spec, prop)}] へ流せません`;
}

/** 参照元の候補。**流せる項目を 1 つも持たない参照元は出さない** */
export interface SourceChoice {
    /** `page` か、記事の content type */
    value: string;
    label: string;
}

export function sourceChoices(
    prop: string | undefined,
    spec: ComponentSpec,
    sources: BindingSources,
): SourceChoice[] {
    const choices: SourceChoice[] = [];

    if (fieldChoices({ source: 'page', prop }, spec, sources).length > 0) {
        choices.push({ value: 'page', label: 'このページ' });
    }

    for (const entry of sources.entry) {
        const usable = fieldChoices(
            { source: 'entry', type: entry.type, prop },
            spec,
            sources,
        );

        if (usable.length > 0) {
            choices.push({ value: entry.type, label: entry.label });
        }
    }

    return choices;
}

/**
 * 送れる形に**仕上げる**。仕上がらなければ `null`。
 *
 * ## なぜ必要か
 *
 * 参照元や流し先を変えると、いま指している項目がその組み合わせで
 * 使えなくなることがある。項目を欠いたまま送ると**サーバーが断る** —
 * 画面から出した接続が、画面のせいで保存できない状態になる。
 *
 * だから**送る前にここで整える**。使える項目が 1 つも無い組み合わせは
 * そもそも選ばせない（`sourceChoices`）ので、`null` は最後の砦である。
 */
export function completeBinding(
    binding: BindingRecord,
    spec: ComponentSpec,
    sources: BindingSources,
): BindingRecord | null {
    const usable = fieldChoices(binding, spec, sources);

    if (usable.length === 0) {
        return null;
    }

    // いま指している項目が使えるなら、そのまま
    if (usable.some((field) => field.key === binding.field)) {
        return binding;
    }

    return { ...binding, field: usable[0].key };
}

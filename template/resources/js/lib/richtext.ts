/**
 * richtext の書式ボタン（Phase 9.15 / STAGE 28D・SPEC §D）。
 *
 * ## HTML を手で書かせない、が目的である
 *
 * いままで `richtext` は `textarea` と行数しか違わず、編集する人は
 * **HTML を手で書くことを求められていた**。ここはその要求を消すためにある。
 *
 * ## WYSIWYG にしない（SPEC §D.3）
 *
 * textarea のままにする。理由は **戻せる方向から始める**ためである。
 * WYSIWYG が吐く HTML に依存した記事が積み上がると、後から editor を変えられない。
 * ここは新規の依存が 0 で、外しても textarea が残る。
 *
 * ## 出す tag は sanitize の allowlist からしか採らない
 *
 * 出せば消えるものをボタンにすると、**編集中に見えたものが公開で消える**。
 * それは編集する人にとって**画面が嘘をついた**ことになる。
 * ずれていないことは `RichtextToolbarTest` が検査する。
 */
export interface RichtextCommand {
    /** ボタンの名前（画面に出る） */
    label: string;
    /**
     * 選んだ文字を挟む形。`inline` は選択が無ければ何もしない。
     *
     * `media` だけは**挟むのではなく挿す**ので、`applyRichtextCommand` ではなく
     * `insertRichtextImage()` を使う（画像の URL と説明が要るため）。
     */
    kind: 'inline' | 'block' | 'list' | 'media';
    /** 使う tag */
    tag: string;
}

export const RICHTEXT_COMMANDS: readonly RichtextCommand[] = [
    { label: '見出し', kind: 'block', tag: 'h2' },
    { label: '小見出し', kind: 'block', tag: 'h3' },
    { label: '段落', kind: 'block', tag: 'p' },
    { label: '太字', kind: 'inline', tag: 'strong' },
    { label: '斜体', kind: 'inline', tag: 'em' },
    { label: '打ち消し', kind: 'inline', tag: 's' },
    { label: '引用', kind: 'block', tag: 'blockquote' },
    { label: '箇条書き', kind: 'list', tag: 'ul' },
    { label: '番号付き', kind: 'list', tag: 'ol' },
    /*
     * 画像（WP 一周 7 周目 2026-09-03）。
     *
     * ここに載せるのは、`RichtextToolbarTest` の検査へ入れるためでもある。
     * あの試験は `RICHTEXT_COMMANDS` の `tag` を読んで
     * 「公開でも残る tag か」を見る。載せないと**画像だけ検査から外れる**。
     */
    { label: '画像', kind: 'media', tag: 'img' },
] as const;

export interface Selection {
    start: number;
    end: number;
}

export interface ApplyResult {
    value: string;
    /** 適用後に選ばれている範囲（続けて打てるように） */
    selection: Selection;
}

/**
 * 選んだ範囲へ書式を当てる。
 *
 * **何も選んでいなければ、空の tag を置く。** カーソルは中へ入れる —
 * そうしないと「押したのに何も起きない」ように見える。
 */
export function applyRichtextCommand(
    value: string,
    selection: Selection,
    command: RichtextCommand,
): ApplyResult {
    const before = value.slice(0, selection.start);
    const chosen = value.slice(selection.start, selection.end);
    const after = value.slice(selection.end);

    const inserted = wrap(chosen, command);
    const innerStart =
        before.length + inserted.indexOf(chosen === '' ? '><' : chosen) + 1;

    return {
        value: before + inserted + after,
        selection:
            chosen === ''
                ? { start: innerStart, end: innerStart }
                : {
                      start: before.length,
                      end: before.length + inserted.length,
                  },
    };
}

function wrap(chosen: string, command: RichtextCommand): string {
    if (command.kind === 'list') {
        const items = chosen === '' ? [''] : chosen.split('\n');

        return [
            `<${command.tag}>`,
            ...items.map((item) => `  <li>${item}</li>`),
            `</${command.tag}>`,
        ].join('\n');
    }

    return `<${command.tag}>${chosen}</${command.tag}>`;
}

/** 本文へ挿す画像。`MediaItem` からそのまま渡せる形にしてある */
export interface RichtextImage {
    url: string | null;
    alt: string | null;
    width: number | null;
    height: number | null;
}

/**
 * 属性の中で閉じられないようにする。
 *
 * 名前と説明は**人が付ける**ので、`"` や `<` がそのまま入りうる。
 */
function escapeAttribute(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * 同じサイトの URL なら、パスだけにする（WP 一周 7 周目 2026-09-03 実測）。
 *
 * 🔴 **本文にドメインを焼き込まない。**
 *
 * メディアが返す URL は絶対（`http://localhost:8180/storage/…`）である。
 * そのまま書くと、**公開ドメインへ移した瞬間に記事の画像が全部旧ドメインを指す**。
 * 記事は何十本も積み上がるので、気づいたときには一括置換の仕事になる。
 *
 * よそのサイトの画像は**そのまま**にする（パスへ落とすと 404 になる）。
 * `window` が無い場所（試験・SSR）では触らない。
 *
 * export しているのは、見たまま編集（`RichtextEditor.vue`）が画像を挿すときも
 * **同じ判断で**パスへ落とすため（2026-09-09）。判断を 2 か所に書かない。
 */
export function sameSitePath(url: string, siteOrigin: string): string {
    if (siteOrigin === '') {
        return url;
    }

    try {
        const parsed = new URL(url, siteOrigin);

        return parsed.origin === new URL(siteOrigin).origin
            ? parsed.pathname + parsed.search + parsed.hash
            : url;
    } catch {
        // 読めない形は、書かれたとおりに扱う（推測で直さない）
        return url;
    }
}

/**
 * 本文へ画像を挿す（WP 一周 7 周目 2026-09-03 / 効くもの ①）。
 *
 * ## なぜ「挟む」ではなく「挿す」か
 *
 * ほかのボタンは選んだ文字を tag で挟む。画像は挟む相手がいない —
 * 必要なのは URL と説明で、それはメディアから来る。
 * だから `applyRichtextCommand` とは別の入口にしてある。
 *
 * ## 選んだ文字は消さない
 *
 * 選択があっても**置き換えない**。消したつもりが無いのに消えるのが、
 * 編集していていちばん困る。うしろへ挿す。
 *
 * ## 出す属性は allowlist の中だけ
 *
 * `Purifier::ALLOWED` は `img[src|alt|width|height]`。
 * ここが増えると**編集画面では見えて公開では消える**ので、増やさない。
 */
export function insertRichtextImage(
    value: string,
    selection: Selection,
    image: RichtextImage,
    /**
     * このサイトの origin（`window.location.origin`）。
     *
     * **環境をここで見に行かない。** 見に行くと、試験や SSR で
     * `window` の有無に振る舞いが変わる — つまり**試験と本番で答えが違う**。
     * 呼ぶ側が渡す。
     */
    siteOrigin = '',
): ApplyResult {
    // 一般ファイルは直リンクを持たない（private disk）。挿せるものが無い
    if (image.url === null || image.url === '') {
        return { value, selection };
    }

    const attributes = [
        `src="${escapeAttribute(sameSitePath(image.url, siteOrigin))}"`,
    ];

    /*
     * 🔴 **説明が無くても `alt` は出す。**
     *
     * 属性ごと落とすと、読み上げが**ファイル名を読む**。
     * 空の `alt` は「飾りなので読み飛ばしてよい」という意味になる。
     */
    attributes.push(`alt="${escapeAttribute(image.alt ?? '')}"`);

    // 寸法は分かっているときだけ。**嘘の値を書かない**
    if (image.width !== null && image.height !== null) {
        attributes.push(`width="${image.width}"`, `height="${image.height}"`);
    }

    const tag = `<img ${attributes.join(' ')}>`;
    const before = value.slice(0, selection.end);
    const after = value.slice(selection.end);

    // 直前が改行でも文頭でもなければ、行を分ける（前の段落へ食い込ませない）
    const separator = before === '' || before.endsWith('\n') ? '' : '\n';
    const inserted = separator + tag;
    const cursor = before.length + inserted.length;

    return {
        value: before + inserted + after,
        selection: { start: cursor, end: cursor },
    };
}

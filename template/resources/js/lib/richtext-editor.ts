/**
 * 見たまま編集（WYSIWYG）の判断（リリース完成 2026-09-09・R02）。
 *
 * ## ここに置くもの
 *
 * 画面を開かずに決まることだけ — **リンク先として通してよい URL か**、
 * **見たまま編集で扱えない HTML が入っているか**、**ボタンが出す tag の一覧**。
 * TipTap の起動や DOM の操作は `RichtextEditor.vue` が持つ。
 * ここを分けるのは vitest（node）で試すためである（`vitest.config.ts` の住み分け）。
 *
 * ## 出す tag は sanitize の allowlist からしか採らない
 *
 * `richtext.ts` の書式ボタンと同じ約束。見たまま編集が `<div>` や `<span>` を吐けば、
 * 編集画面では見えて公開では消える。ずれていないことは `RichtextToolbarTest`
 * （PHP）が **このファイルの `tag:` も読んで**検査する。
 *
 * ## textarea を消さない（`richtext.ts` §「WYSIWYG にしない」への答え）
 *
 * あの判断は「戻せる方向から始める」だった。ここでは **HTML ソース切替を残す**ことで
 * 戻す道を保つ — 見たまま編集で扱えない HTML は、黙って捨てずに HTML のまま編集させる。
 */

/** 見たまま編集のボタン。`tag` は公開でも残る tag だけ */
export interface VisualRichtextCommand {
    /** 画面に出す名前（読み上げにも使う） */
    label: string;
    /**
     * 押したときに TipTap へ頼む操作。
     * `RichtextEditor.vue` がこの名前で分岐する（文字列で持つのは試験で数えるため）。
     */
    action:
        | 'paragraph'
        | 'heading2'
        | 'heading3'
        | 'heading4'
        | 'bold'
        | 'italic'
        | 'underline'
        | 'strike'
        | 'blockquote'
        | 'bulletList'
        | 'orderedList'
        | 'link'
        | 'image'
        | 'undo'
        | 'redo';
    /** この操作が出す tag。出さない操作（undo / redo）は null */
    tag: string | null;
    /** キーボードの近道（表示用。実際の束縛は TipTap が持つ） */
    shortcut?: string;
}

export const VISUAL_RICHTEXT_COMMANDS: readonly VisualRichtextCommand[] = [
    { label: '段落', action: 'paragraph', tag: 'p' },
    { label: '見出し', action: 'heading2', tag: 'h2' },
    { label: '小見出し', action: 'heading3', tag: 'h3' },
    { label: '小々見出し', action: 'heading4', tag: 'h4' },
    { label: '太字', action: 'bold', tag: 'strong', shortcut: 'Ctrl+B' },
    { label: '斜体', action: 'italic', tag: 'em', shortcut: 'Ctrl+I' },
    { label: '下線', action: 'underline', tag: 'u', shortcut: 'Ctrl+U' },
    { label: '打ち消し', action: 'strike', tag: 's' },
    { label: '引用', action: 'blockquote', tag: 'blockquote' },
    { label: '箇条書き', action: 'bulletList', tag: 'ul' },
    { label: '番号付き', action: 'orderedList', tag: 'ol' },
    { label: 'リンク', action: 'link', tag: 'a', shortcut: 'Ctrl+K' },
    { label: '画像', action: 'image', tag: 'img' },
    { label: '元に戻す', action: 'undo', tag: null, shortcut: 'Ctrl+Z' },
    { label: 'やり直す', action: 'redo', tag: null, shortcut: 'Ctrl+Shift+Z' },
] as const;

/** 見出しとして扱う段階。`Purifier::ALLOWED` の h2 / h3 / h4 と揃える（h1 は題名の役） */
export const HEADING_LEVELS = [2, 3, 4] as const;

/**
 * 見たまま編集が**失わずに**持てる tag。
 *
 * `Purifier::ALLOWED` の tag と、それに読み替えられる古い書き方（`b` → strong など）。
 * ここに無い tag が本文に在れば、見たまま編集へ入れた時点で**形か中身が変わる**。
 */
const VISUAL_EDITOR_TAGS: ReadonlySet<string> = new Set([
    'p',
    'br',
    'strong',
    'b',
    'em',
    'i',
    'u',
    's',
    'strike',
    'del',
    'blockquote',
    'ul',
    'ol',
    'li',
    'h2',
    'h3',
    'h4',
    'a',
    'img',
]);

/**
 * 見たまま編集で扱えない tag を数える。
 *
 * 🔴 **黙って捨てない、のための入口。**
 * TipTap は schema に無い tag を**警告なしに落とす**（`<table>` は文字だけ残り、
 * `<iframe>` は消える）。だから入れる前にここで数え、在れば HTML のまま開いて知らせる。
 *
 * ここで数える集合は **サーバーの `Purifier::ALLOWED` と同じ**である。つまり見たまま編集で
 * 扱えない要素は、HTML のまま保存しても**サーバーが書き込み時に取り除く**（実ブラウザで実測）。
 * HTML のまま開く意味は「残せる」ではなく「取り除かれる前に、何が在るかを見せる」。
 * 帯の文言もそう書く — 「HTML なら残る」と言うと嘘になる。
 *
 * 正規表現で tag 名だけを拾う。DOM を使わないのは node（vitest）でも同じ答えを
 * 出すためで、ここで要るのは「何が在るか」だけである（構造の正しさは見ない）。
 * コメントは先に落とす — `<!-- <table> -->` を tag として数えない。
 */
export function findUnsupportedTags(html: string): string[] {
    const withoutComments = html.replace(/<!--[\s\S]*?-->/g, '');
    const found = new Set<string>();

    for (const match of withoutComments.matchAll(
        /<\/?([a-zA-Z][a-zA-Z0-9-]*)/g,
    )) {
        const tag = match[1].toLowerCase();

        if (!VISUAL_EDITOR_TAGS.has(tag)) {
            found.add(tag);
        }
    }

    return [...found];
}

export type RichtextMode = 'visual' | 'html';

/**
 * 開いたときの編集の形。
 *
 * 扱えない tag が入っている本文は **HTML のまま開く**。見たままで開くと、
 * 開いただけで（保存していないのに）形が変わって見え、編集する人は
 * 「壊れた」と受け取る。空や普通の本文は見たままで開く。
 */
export function initialRichtextMode(html: string): RichtextMode {
    return findUnsupportedTags(html).length === 0 ? 'visual' : 'html';
}

/**
 * 危険な形かどうかだけを見る（**通す側を限らない**）。
 *
 * ## なぜ「通す scheme の allowlist」で判定しないのか（2026-09-09 の指摘で直した）
 *
 * 直前の実装は「http / https / mailto と `/` `#` `?` 始まり、あるいはドメインの形」だけを通し、
 * それ以外を落としていた。**`Purifier` は相対 URL をそのまま残す**ので、
 * `../about` `./about` `about/team` `contact` のような**既存の本文に実在するリンクが、
 * 見たまま編集で開いた瞬間に落ちる**（TipTap の `isAllowedUri` が false を返すと mark が消える）。
 *
 * だから **URL の形は判定しない**。断るのは危ないものだけにする:
 *
 * - http / https / mailto **以外の scheme**（`javascript:` `data:` `vbscript:` `ftp:` `tel:` …）
 * - **protocol-relative**（`//evil.example`）と、その backslash 変種（`\\evil` `/\evil` `\/evil`）—
 *   相対に見えて別サイトへ飛ぶ
 *
 * 判定は**空白と制御文字を落としてから**行う（`java\tscript:` `java\u0000script:` を素通しさせない）。
 */
export function isSafeLinkHref(input: string | undefined): boolean {
    if (input === undefined) {
        return false;
    }

    const cleaned = stripInvisible(input);

    if (cleaned === '') {
        return false;
    }

    // backslash は browser が `/` として読む。protocol-relative の変種を先に潰す
    const slashed = cleaned.replace(/\\/g, '/');

    if (slashed.startsWith('//')) {
        return false;
    }

    const scheme = slashed.match(/^([a-zA-Z][a-zA-Z0-9+.-]*):/);

    if (scheme === null) {
        // scheme が無い ＝ 相対 URL。`Purifier` が残すので、こちらも残す
        return true;
    }

    const name = scheme[1].toLowerCase();

    return name === 'http' || name === 'https' || name === 'mailto';
}

/** 判定の前に落とす見えない文字（空白・制御文字） */
function stripInvisible(value: string): string {
    return value.replace(/[\s\u0000-\u001f\u007f]/g, '');
}

/**
 * 入力欄に打たれた文字を、リンク先の形に直す。通せなければ null。
 *
 * ## 断るのは危ないものだけ
 *
 * 判断は `isSafeLinkHref` に閉じる。**形で落とさない** — 相対 URL
 * （`/about` `./about` `../about` `about/team` `contact` `#top` `?page=2`）はそのまま通す。
 *
 * ## 「example.com」と打った人には https を補う
 *
 * WordPress 利用者は URL 欄に `https://` 無しで打つ。ただし `index.php` `contact.html` も
 * **同じ形**なので、点の有無では見分けられない。**迷ったら補わない** —
 * 相対 URL として残すほうが、既存の本文を壊さない。
 * 補うのは `www.` 始まりか、末尾のラベルが下の短い一覧に在るときだけにする。
 */
export function normalizeLinkHref(input: string): string | null {
    if (!isSafeLinkHref(input)) {
        return null;
    }

    const trimmed = input.trim();
    const cleaned = stripInvisible(input);

    // scheme があるものは、見えない文字を落とした形で返す（`java\tscript:` 対策の名残）
    if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(cleaned)) {
        return cleaned;
    }

    // 「www.example.com/page」のような、scheme を落として打った形
    if (looksLikeDomain(trimmed)) {
        return `https://${trimmed}`;
    }

    return trimmed;
}

/**
 * ドメインとして打たれた形か。
 *
 * **`index.php` と `example.com` は同じ形**なので、点の有無では決まらない。
 * ここは「補ってよいと言い切れるもの」だけを true にする — `www.` 始まりか、
 * 末尾のラベルがよく使う TLD のとき。外れたものは相対 URL として残る（壊れない側へ倒す）。
 */
function looksLikeDomain(value: string): boolean {
    const host = value.split(/[/?#]/)[0];

    if (!/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/.test(host)) {
        return false;
    }

    if (host.toLowerCase().startsWith('www.')) {
        return true;
    }

    const tld = host.slice(host.lastIndexOf('.') + 1).toLowerCase();

    return COMMON_TLDS.has(tld);
}

/** 補ってよいと言い切れる TLD。**足すのは実際に打たれたものだけ**（迷うものは入れない） */
const COMMON_TLDS: ReadonlySet<string> = new Set([
    'com',
    'net',
    'org',
    'jp',
    'co',
    'io',
    'dev',
    'app',
    'info',
    'biz',
    'me',
    'shop',
    'site',
    'work',
    'tokyo',
]);

/**
 * TipTap の `isAllowedUri` に渡す形（**読み込み・貼り付け・保存のすべてを通る**）。
 *
 * ここが false を返すと、**既に保存されている本文のリンクが開いた時点で消える**。
 * だから入力欄の正規化ではなく、**危険な形かどうかだけ**を見る。
 */
export function isAllowedLinkHref(href: string | undefined): boolean {
    return isSafeLinkHref(href);
}

/**
 * 保存する値。
 *
 * TipTap は空の本文を `<p></p>` として返す。それを保存すると、
 * サーバーの「必須」検査が**中身のある文字列**として通してしまう。
 * 空なら空文字にして、必須の検査が効く形で渡す。
 */
export function htmlForModel(html: string, isEmpty: boolean): string {
    return isEmpty ? '' : html;
}

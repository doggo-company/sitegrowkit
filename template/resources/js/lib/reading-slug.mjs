/**
 * 日本語の題・サイト名から、読みの分かる URL・フォルダ名の候補を作る（UX 一周 17 周目 G1）。
 *
 * 以前は 2 か所が別々の自前の表で読んでいて、食い違っていた（松本珈琲店: setup は matsumoto-kohi、
 * ページは matsumoto-kohi-mise）うえ、送り仮名のある語・地名・名字を読めなかった
 * （焼き上がり時間 → yaki-ki-ue-ga-ri-toki-ma、吉祥寺 → kisshou-tera、山田 → yama-ta）。
 *
 * いまは setup（CLI の node・kuromoji）が使う。管理画面・MCP・API は**同じ決まりの PHP 版**
 * （`core/src/Pages/Reading/TitleReading.php`・IPADIC の Viterbi）を使う（UX 一周 19 周目）。
 * 両方が同じ例で同じ候補になることを `tests/fixtures/reading-examples.json` で確かめる（変えるときは両方）。
 *
 * - 定番のページ名は英語の URL（会社概要 → company、お店について → about）。**題名がその語だけのとき**に限る
 *   （20 周目 R1: 「吉祥寺高橋トリミングサロン」が trimming になり、地名と名字が消えた）
 * - カタカナ語は英語の表記（JMdict から、元の英語と音が合うものだけ。ネイルサロン → nail-salon）。
 *   表に無いものは音のローマ字（20 周目 R2: トリミングは英語、ネイルサロンは neirusaron と揺れていた）
 * - 漢字を含む語は、解析器の読み（カタカナ）をローマ字に（店舗案内 → tenpo-annai）
 * - かなだけの語は、続いた分を 1 語としてそのまま読む（サイクルかわの → saikuru-kawano）
 * - 漢字の語のすぐ後ろの助詞は前後と切る（花のいろは → hana-no-iroha）
 * - 読みの無い字（解析器が読めなかった語）は**落とす**。変な綴りは作らず、読めた部分だけにする
 *
 * - 漢字の無い題は解析器を使わない（かな・英数字の続きで切る）
 *
 * 🔴 このファイルは CLI からも読み込む（`packages/cli/src/wizard/reading.ts`）。依存を足さないこと。
 */

const BASE = {
    あ: 'a',
    い: 'i',
    う: 'u',
    え: 'e',
    お: 'o',
    か: 'ka',
    き: 'ki',
    く: 'ku',
    け: 'ke',
    こ: 'ko',
    が: 'ga',
    ぎ: 'gi',
    ぐ: 'gu',
    げ: 'ge',
    ご: 'go',
    さ: 'sa',
    し: 'shi',
    す: 'su',
    せ: 'se',
    そ: 'so',
    ざ: 'za',
    じ: 'ji',
    ず: 'zu',
    ぜ: 'ze',
    ぞ: 'zo',
    た: 'ta',
    ち: 'chi',
    つ: 'tsu',
    て: 'te',
    と: 'to',
    だ: 'da',
    ぢ: 'ji',
    づ: 'zu',
    で: 'de',
    ど: 'do',
    な: 'na',
    に: 'ni',
    ぬ: 'nu',
    ね: 'ne',
    の: 'no',
    は: 'ha',
    ひ: 'hi',
    ふ: 'fu',
    へ: 'he',
    ほ: 'ho',
    ば: 'ba',
    び: 'bi',
    ぶ: 'bu',
    べ: 'be',
    ぼ: 'bo',
    ぱ: 'pa',
    ぴ: 'pi',
    ぷ: 'pu',
    ぺ: 'pe',
    ぽ: 'po',
    ま: 'ma',
    み: 'mi',
    む: 'mu',
    め: 'me',
    も: 'mo',
    や: 'ya',
    ゆ: 'yu',
    よ: 'yo',
    ら: 'ra',
    り: 'ri',
    る: 'ru',
    れ: 're',
    ろ: 'ro',
    わ: 'wa',
    を: 'o',
    ん: 'n',
    ゔ: 'vu',
    ぁ: 'a',
    ぃ: 'i',
    ぅ: 'u',
    ぇ: 'e',
    ぉ: 'o',
};

const PAIRS = {
    きゃ: 'kya',
    きゅ: 'kyu',
    きょ: 'kyo',
    ぎゃ: 'gya',
    ぎゅ: 'gyu',
    ぎょ: 'gyo',
    しゃ: 'sha',
    しゅ: 'shu',
    しょ: 'sho',
    じゃ: 'ja',
    じゅ: 'ju',
    じょ: 'jo',
    ちゃ: 'cha',
    ちゅ: 'chu',
    ちょ: 'cho',
    にゃ: 'nya',
    にゅ: 'nyu',
    にょ: 'nyo',
    ひゃ: 'hya',
    ひゅ: 'hyu',
    ひょ: 'hyo',
    びゃ: 'bya',
    びゅ: 'byu',
    びょ: 'byo',
    ぴゃ: 'pya',
    ぴゅ: 'pyu',
    ぴょ: 'pyo',
    みゃ: 'mya',
    みゅ: 'myu',
    みょ: 'myo',
    りゃ: 'rya',
    りゅ: 'ryu',
    りょ: 'ryo',
    てぃ: 'ti',
    でぃ: 'di',
    ふぁ: 'fa',
    ふぃ: 'fi',
    ふぇ: 'fe',
    ふぉ: 'fo',
    うぃ: 'wi',
    うぇ: 'we',
    うぉ: 'wo',
    ゔぁ: 'va',
    しぇ: 'she',
    じぇ: 'je',
    ちぇ: 'che',
};

/**
 * 定番のページ名に付くありふれた付け足し（`SlugFromTitle::COMMON_SUFFIXES` と同じ表・UX 一周 21 周目 Y1）。
 * 題名が「定番名＋これ」だけなら定番名の英語（料金のご案内 → price・アクセス方法 → access）。
 * それ以外（店名・地名・人名が付く）は全部読む（吉祥寺高橋トリミングサロン）。
 */
export const COMMON_SUFFIXES = [
    'のご案内',
    'のご紹介',
    'のご説明',
    'ご案内',
    'ご紹介',
    'の案内',
    'の紹介',
    'の方法',
    'の一覧',
    'の情報',
    'について',
    '案内',
    '紹介',
    '方法',
    '一覧',
    '情報',
];

/** 定番のページ名 → URL（`SlugFromTitle::COMMON` と同じ表。長い名前を先に当てる） */
export const COMMON = [
    ['特定商取引法に基づく表記', 'tokushoho'],
    ['プライバシーポリシー', 'privacy-policy'],
    ['個人情報保護方針', 'privacy-policy'],
    ['はじめての方へ', 'first-visit'],
    ['初めての方へ', 'first-visit'],
    ['よくあるご質問', 'faq'],
    ['よくある質問', 'faq'],
    ['お客様の声', 'voice'],
    ['お問い合わせ', 'contact'],
    ['お問合せ', 'contact'],
    ['問い合わせ', 'contact'],
    ['私たちについて', 'about'],
    ['当店について', 'about'],
    ['お店について', 'about'],
    ['お店の紹介', 'about'],
    ['お店の案内', 'about'],
    ['店舗紹介', 'about'],
    ['会社概要', 'company'],
    ['会社案内', 'company'],
    ['企業情報', 'company'],
    ['事業内容', 'service'],
    ['サービス', 'service'],
    ['料金表', 'price'],
    ['料金', 'price'],
    ['価格', 'price'],
    ['メニュー', 'menu'],
    ['アクセス', 'access'],
    ['店舗情報', 'access'],
    ['スタッフ', 'staff'],
    ['採用情報', 'recruit'],
    ['求人', 'recruit'],
    ['施工事例', 'works'],
    ['制作実績', 'works'],
    ['実績', 'works'],
    ['ギャラリー', 'gallery'],
    ['作品', 'gallery'],
    ['お知らせ', 'news'],
    ['ブログ', 'blog'],
    ['ご予約について', 'reservation'],
    ['予約について', 'reservation'],
    ['ご予約', 'reservation'],
    ['予約', 'reservation'],
    ['代表挨拶', 'message'],
    ['ご挨拶', 'message'],
    ['ごあいさつ', 'message'],
    ['沿革', 'history'],
    ['商品', 'products'],
    ['トリミング', 'trimming'],
];

const HIRA = /^[\u3041-\u3096ー]+$/u;
const KATA = /^[\u30a1-\u30f6ー]+$/u;
const ASCII_WORD = /^[A-Za-z0-9]+$/u;

function toHiragana(text) {
    return text.replace(/[ァ-ヶ]/gu, (ch) =>
        String.fromCharCode(ch.charCodeAt(0) - 0x60),
    );
}

/** かなをローマ字に。カタカナの音読みでは長音の母音を残す。漢字の読みは従来の表記。 */
export function romanize(kana, longVowels = false) {
    const text = toHiragana(kana.normalize('NFKC'));
    let out = '';
    let geminate = false;

    for (let i = 0; i < text.length; i += 1) {
        const ch = text[i];
        const pair = PAIRS[text.slice(i, i + 2)];
        const romaji = pair ?? BASE[ch];

        if (ch === 'っ') {
            geminate = true;
            continue;
        }

        if (ch === 'ー') {
            if (longVowels && /[aeiou]$/.test(out)) {
                out += out.at(-1);
            }

            continue;
        }

        if (romaji !== undefined) {
            out += geminate
                ? (romaji.startsWith('ch') ? 't' : romaji[0]) + romaji
                : romaji;
            geminate = false;

            if (pair) {
                i += 1;
            }

            continue;
        }

        geminate = false;
    }

    return out;
}

function slugify(words) {
    let slug = words
        .join('-')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    // PHP の TitleReading・ページ作成と同じ上限。語の途中や末尾のハイフンで切らない。
    if (slug.length > 180) {
        const boundary = slug.lastIndexOf('-', 180);
        slug = slug.slice(0, boundary < 0 ? 180 : boundary);
    }

    return slug.replace(/-+$/g, '');
}

/**
 * 題から候補を作る。何も読めなければ空文字（呼ぶ側が「page」「my-site」などに倒す）。
 *
 * @param {string} title
 * @param {(text: string) => Array<{surface_form: string, reading?: string, pos: string}>} tokenize
 * @returns {string}
 */
/** 漢字を含むか（含むときだけ解析器が要る） */
export function needsDictionary(title) {
    // 漢字があるか、ひらがなとカタカナが混ざるとき（パンのご案内: の｜ご を切るのに品詞が要る・21 周目 Y2）
    return (
        /\p{Script=Han}/u.test(title) ||
        (/\p{Script=Hiragana}/u.test(title) &&
            /\p{Script=Katakana}/u.test(title))
    );
}

/** 漢字の無い題を、かな・カタカナ・英数字の続きで切る（解析器の代わり） */
function splitWithoutDictionary(text) {
    const tokens = [];
    const kindOf = (ch) =>
        /[\u3041-\u3096]/u.test(ch)
            ? 'hira'
            : /[\u30a1-\u30f6ー]/u.test(ch)
              ? 'kata'
              : /[A-Za-z0-9]/u.test(ch)
                ? 'ascii'
                : null;
    let current = '';
    let kind = null;

    for (const ch of text) {
        const next = kindOf(ch);

        if (next !== kind || next === null) {
            if (current !== '') {
                tokens.push({ surface_form: current, pos: '名詞' });
            }

            current = '';
        }

        if (next !== null) {
            current += ch;
        } else {
            tokens.push({ surface_form: ch, pos: '記号' });
        }

        kind = next;
    }

    if (current !== '') {
        tokens.push({ surface_form: current, pos: '名詞' });
    }

    return tokens;
}

/**
 * カタカナの続きを、英語の表記の語に切る（長い語から先に当てる）。表に無い部分は音のローマ字。
 *
 * @param {string} kata
 * @param {Map<string, string> | null} english カタカナ語 → 英語（ハイフンでつないだ形）
 */
function katakanaWords(kata, english) {
    if (!english || english.size === 0) {
        return [romanize(kata, true)];
    }

    const chars = [...kata];
    const words = [];
    let pending = '';

    for (let i = 0; i < chars.length;) {
        let hit = null;

        for (
            let length = Math.min(KATAKANA_MAX, chars.length - i);
            length >= 2;
            length -= 1
        ) {
            const value = english.get(chars.slice(i, i + length).join(''));

            if (value !== undefined) {
                hit = [length, value];
                break;
            }
        }

        if (hit === null) {
            pending += chars[i];
            i += 1;
            continue;
        }

        if (pending !== '') {
            words.push(romanize(pending, true));
            pending = '';
        }

        // 英語が空の語は、英語でない外来語（パン・アルバイト）。その語のまま音で読む
        words.push(
            hit[1] === ''
                ? romanize(chars.slice(i, i + hit[0]).join(''), true)
                : hit[1],
        );
        i += hit[0];
    }

    if (pending !== '') {
        words.push(romanize(pending, true));
    }

    return words;
}

/** カタカナ語の表の、語の長さの上限（作るときと同じ） */
const KATAKANA_MAX = 20;

/**
 * 題名を、例外の表の語とそれ以外に切る（長い語から先に当てる）。
 *
 * @param {string} text
 * @param {Map<string, string> | null} exceptions
 * @returns {Array<[string, string | null]>}
 */
function splitExceptions(text, exceptions) {
    if (!exceptions || exceptions.size === 0) {
        return [[text, null]];
    }

    const words = [...exceptions.keys()].sort(
        (a, b) => [...b].length - [...a].length || (a < b ? -1 : a > b ? 1 : 0),
    );
    const chars = [...text];
    const out = [];
    let pending = '';

    for (let i = 0; i < chars.length;) {
        const word = words.find(
            (w) => chars.slice(i, i + [...w].length).join('') === w,
        );

        if (word === undefined) {
            pending += chars[i];
            i += 1;
            continue;
        }

        if (pending !== '') {
            out.push([pending, null]);
            pending = '';
        }

        out.push([word, exceptions.get(word)]);
        i += [...word].length;
    }

    if (pending !== '') {
        out.push([pending, null]);
    }

    return out;
}

/**
 * @param {string} title
 * @param {(text: string) => Array<{surface_form: string, reading?: string, pos: string}>} tokenize
 * @param {Map<string, string> | null} [english] カタカナ語 → 英語（template の core/resources/reading/katakana-english.tsv.gz）
 * @param {Map<string, string> | null} [exceptions] 読みがずれる語 → URL の語（core/resources/reading/reading-exceptions.json）
 */
export function readingSlug(
    title,
    tokenize,
    english = null,
    exceptions = null,
) {
    const trimmed = title.normalize('NFKC').trim();

    if (trimmed === '') {
        return '';
    }

    if (/^[\x20-\x7e]+$/u.test(trimmed)) {
        return slugify([trimmed]);
    }

    const compact = trimmed.replace(/[\s　]+/gu, '');

    for (const [name, slug] of COMMON) {
        if (
            compact === name ||
            (compact.startsWith(name) &&
                COMMON_SUFFIXES.includes(compact.slice(name.length)))
        ) {
            return slug;
        }
    }

    const words = [];
    let run = null; // かなだけの語の続き { script, text }
    let previous = null; // 'kanji' | 'hira' | 'kata' | 'particle' | null
    const flush = () => {
        if (run !== null) {
            words.push(
                ...(run.script === 'kata'
                    ? katakanaWords(run.text, english)
                    : [romanize(run.text)]),
            );
            run = null;
        }
    };

    // 読みがずれる語（七五三 → nana-go-san）は例外の表で先に切り出し、残りだけ解析する（UX 一周 23 周目 #4）
    const tokens = [];

    for (const [segment, fixed] of splitExceptions(trimmed, exceptions)) {
        if (fixed !== null) {
            tokens.push({ surface_form: segment, pos: '名詞', slug: fixed });
            continue;
        }

        tokens.push(
            ...(needsDictionary(segment)
                ? tokenize(segment)
                : splitWithoutDictionary(segment)),
        );
    }

    for (const token of tokens) {
        const surface = token.surface_form;

        if (token.slug !== undefined) {
            flush();
            words.push(token.slug);
            previous = 'kanji';
            continue;
        }

        if (token.pos === '記号' || surface.trim() === '') {
            flush();
            previous = null;
            continue;
        }

        if (ASCII_WORD.test(surface)) {
            flush();
            words.push(surface.toLowerCase());
            previous = null;
            continue;
        }

        const script = HIRA.test(surface)
            ? 'hira'
            : KATA.test(surface)
              ? 'kata'
              : null;

        if (script !== null) {
            // 漢字の語のすぐ後ろの助詞は 1 語で立てる（花の｜いろは）
            if (token.pos === '助詞' && previous === 'kanji') {
                flush();
                words.push(romanize(surface));
                previous = 'particle';
                continue;
            }

            // 接頭詞（お・ご）は前の語にくっつけない（の＋ご → nogo にしない・21 周目 Y2）
            if (
                run !== null &&
                run.script === script &&
                token.pos !== '接頭詞'
            ) {
                run.text += surface;
            } else {
                flush();
                run = { script, text: surface };
            }

            previous = script;
            continue;
        }

        // 漢字を含む語は読み（カタカナ）で。読めなかった語は落とす
        flush();
        const reading = token.reading;

        if (
            typeof reading === 'string' &&
            reading !== '*' &&
            KATA.test(reading)
        ) {
            words.push(romanize(reading));
        }

        previous = 'kanji';
    }

    flush();

    return slugify(words.filter((word) => word !== ''));
}

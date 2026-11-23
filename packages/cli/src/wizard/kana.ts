/**
 * かな → ローマ字（フォルダ名の候補づくり専用・UX 一周 3 周目 M1）。
 *
 * 2 周目は「ベーカリーこむぎ堂」と打つとフォルダ名が `my-site` になり、
 * 1 周目の `my-site` が残っていたため「同じ名前のフォルダがすでにあります」で止まった。
 * 読める部分（かな）だけをローマ字にして、**その店の名前に見える候補**を出す。
 *
 * 漢字は読めない（読みを推測しない）。読めない文字は区切りとして扱う。
 * 正確な表記を約束するものではなく、**打ち直さずに済む候補**を出すためだけに使う。
 */

const BASE: Record<string, string> = {
  あ: "a",
  い: "i",
  う: "u",
  え: "e",
  お: "o",
  か: "ka",
  き: "ki",
  く: "ku",
  け: "ke",
  こ: "ko",
  が: "ga",
  ぎ: "gi",
  ぐ: "gu",
  げ: "ge",
  ご: "go",
  さ: "sa",
  し: "shi",
  す: "su",
  せ: "se",
  そ: "so",
  ざ: "za",
  じ: "ji",
  ず: "zu",
  ぜ: "ze",
  ぞ: "zo",
  た: "ta",
  ち: "chi",
  つ: "tsu",
  て: "te",
  と: "to",
  だ: "da",
  ぢ: "ji",
  づ: "zu",
  で: "de",
  ど: "do",
  な: "na",
  に: "ni",
  ぬ: "nu",
  ね: "ne",
  の: "no",
  は: "ha",
  ひ: "hi",
  ふ: "fu",
  へ: "he",
  ほ: "ho",
  ば: "ba",
  び: "bi",
  ぶ: "bu",
  べ: "be",
  ぼ: "bo",
  ぱ: "pa",
  ぴ: "pi",
  ぷ: "pu",
  ぺ: "pe",
  ぽ: "po",
  ま: "ma",
  み: "mi",
  む: "mu",
  め: "me",
  も: "mo",
  や: "ya",
  ゆ: "yu",
  よ: "yo",
  ら: "ra",
  り: "ri",
  る: "ru",
  れ: "re",
  ろ: "ro",
  わ: "wa",
  を: "o",
  ん: "n",
  ゔ: "vu",
  ぁ: "a",
  ぃ: "i",
  ぅ: "u",
  ぇ: "e",
  ぉ: "o",
};

/** 拗音（きゃ等）と、カタカナの外来音（ティ・ファ等） */
const PAIRS: Record<string, string> = {
  きゃ: "kya",
  きゅ: "kyu",
  きょ: "kyo",
  ぎゃ: "gya",
  ぎゅ: "gyu",
  ぎょ: "gyo",
  しゃ: "sha",
  しゅ: "shu",
  しょ: "sho",
  じゃ: "ja",
  じゅ: "ju",
  じょ: "jo",
  ちゃ: "cha",
  ちゅ: "chu",
  ちょ: "cho",
  にゃ: "nya",
  にゅ: "nyu",
  にょ: "nyo",
  ひゃ: "hya",
  ひゅ: "hyu",
  ひょ: "hyo",
  びゃ: "bya",
  びゅ: "byu",
  びょ: "byo",
  ぴゃ: "pya",
  ぴゅ: "pyu",
  ぴょ: "pyo",
  みゃ: "mya",
  みゅ: "myu",
  みょ: "myo",
  りゃ: "rya",
  りゅ: "ryu",
  りょ: "ryo",
  てぃ: "ti",
  でぃ: "di",
  ふぁ: "fa",
  ふぃ: "fi",
  ふぇ: "fe",
  ふぉ: "fo",
  うぃ: "wi",
  うぇ: "we",
  うぉ: "wo",
  ゔぁ: "va",
  しぇ: "she",
  じぇ: "je",
  ちぇ: "che",
};

/** カタカナをひらがなへ（同じ表で引くため） */
function toHiragana(text: string): string {
  return text.replace(/[ァ-ヶ]/g, (ch) =>
    String.fromCharCode(ch.charCodeAt(0) - 0x60),
  );
}

/**
 * かなをローマ字にする。かな以外の文字（漢字・記号）は空白に置き換える。
 * 英数字はそのまま残す。
 */
export function romanizeKana(input: string): string {
  const original = input.normalize("NFKC");
  const text = toHiragana(original);
  let out = "";
  let geminate = false;
  let lastScript: "hira" | "kata" | null = null;

  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i]!;
    // カタカナとひらがなの切れ目は語の切れ目として扱う（ベーカリー｜こむぎ）
    const script = scriptOf(original[i]!);
    if (script !== null && lastScript !== null && script !== lastScript)
      out += " ";
    if (script !== null) lastScript = script;
    const pair = PAIRS[text.slice(i, i + 2)];
    const romaji = pair ?? BASE[ch];

    if (ch === "っ") {
      geminate = true;
      continue;
    }
    // 長音は母音を重ねない（ベーカリー → bekari）
    if (ch === "ー") continue;

    if (romaji !== undefined) {
      // 促音はヘボン式で子音を重ねる（ch の前だけ t: きっちん → kitchin）
      out += geminate
        ? (romaji.startsWith("ch") ? "t" : romaji[0]!) + romaji
        : romaji;
      geminate = false;
      if (pair) i += 1;
      continue;
    }

    geminate = false;
    lastScript = null;
    out += /[A-Za-z0-9]/.test(ch) ? ch : " ";
  }

  return out;
}

function scriptOf(ch: string): "hira" | "kata" | null {
  if (/[\u3041-\u3096]/.test(ch)) return "hira";
  if (/[\u30a1-\u30f6]/.test(ch)) return "kata";
  return null;
}

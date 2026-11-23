<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages\Reading;

use AgentCms\Core\Pages\SlugFromTitle;

/**
 * 日本語の題から、読みの分かる URL の候補を作る（UX 一周 19 周目）。
 *
 * **`resources/js/lib/reading-slug.mjs`（setup が kuromoji で使う）と同じ決まり**を PHP で持つ。
 * 管理画面のページの作成・MCP の page_create（空の slug）・API はここを通る。
 * 両方が同じ例で同じ候補になることを `tests/fixtures/reading-examples.json` で確かめる（変えるときは両方）。
 *
 * - 定番のページ名は英語の URL（会社概要 → company）。題名がその語だけのときに限る（20 周目 R1）
 * - カタカナ語は英語の表記（JMdict 由来の表・元の英語と音が合うものだけ）。表に無いものは音のローマ字（20 周目 R2）
 * - 漢字を含む語は、形態素解析（IpadicTokenizer）の読みをローマ字に
 * - かなだけの語は続いた分を 1 語に。漢字の語のすぐ後ろの助詞は前後と切る
 * - 読めない語は落とす（変な綴りを作らない）
 */
final class TitleReading
{
    private const BASE = [
        'あ' => 'a',
        'い' => 'i',
        'う' => 'u',
        'え' => 'e',
        'お' => 'o',
        'か' => 'ka',
        'き' => 'ki',
        'く' => 'ku',
        'け' => 'ke',
        'こ' => 'ko',
        'が' => 'ga',
        'ぎ' => 'gi',
        'ぐ' => 'gu',
        'げ' => 'ge',
        'ご' => 'go',
        'さ' => 'sa',
        'し' => 'shi',
        'す' => 'su',
        'せ' => 'se',
        'そ' => 'so',
        'ざ' => 'za',
        'じ' => 'ji',
        'ず' => 'zu',
        'ぜ' => 'ze',
        'ぞ' => 'zo',
        'た' => 'ta',
        'ち' => 'chi',
        'つ' => 'tsu',
        'て' => 'te',
        'と' => 'to',
        'だ' => 'da',
        'ぢ' => 'ji',
        'づ' => 'zu',
        'で' => 'de',
        'ど' => 'do',
        'な' => 'na',
        'に' => 'ni',
        'ぬ' => 'nu',
        'ね' => 'ne',
        'の' => 'no',
        'は' => 'ha',
        'ひ' => 'hi',
        'ふ' => 'fu',
        'へ' => 'he',
        'ほ' => 'ho',
        'ば' => 'ba',
        'び' => 'bi',
        'ぶ' => 'bu',
        'べ' => 'be',
        'ぼ' => 'bo',
        'ぱ' => 'pa',
        'ぴ' => 'pi',
        'ぷ' => 'pu',
        'ぺ' => 'pe',
        'ぽ' => 'po',
        'ま' => 'ma',
        'み' => 'mi',
        'む' => 'mu',
        'め' => 'me',
        'も' => 'mo',
        'や' => 'ya',
        'ゆ' => 'yu',
        'よ' => 'yo',
        'ら' => 'ra',
        'り' => 'ri',
        'る' => 'ru',
        'れ' => 're',
        'ろ' => 'ro',
        'わ' => 'wa',
        'を' => 'o',
        'ん' => 'n',
        'ゔ' => 'vu',
        'ぁ' => 'a',
        'ぃ' => 'i',
        'ぅ' => 'u',
        'ぇ' => 'e',
        'ぉ' => 'o',
    ];

    private const PAIRS = [
        'きゃ' => 'kya',
        'きゅ' => 'kyu',
        'きょ' => 'kyo',
        'ぎゃ' => 'gya',
        'ぎゅ' => 'gyu',
        'ぎょ' => 'gyo',
        'しゃ' => 'sha',
        'しゅ' => 'shu',
        'しょ' => 'sho',
        'じゃ' => 'ja',
        'じゅ' => 'ju',
        'じょ' => 'jo',
        'ちゃ' => 'cha',
        'ちゅ' => 'chu',
        'ちょ' => 'cho',
        'にゃ' => 'nya',
        'にゅ' => 'nyu',
        'にょ' => 'nyo',
        'ひゃ' => 'hya',
        'ひゅ' => 'hyu',
        'ひょ' => 'hyo',
        'びゃ' => 'bya',
        'びゅ' => 'byu',
        'びょ' => 'byo',
        'ぴゃ' => 'pya',
        'ぴゅ' => 'pyu',
        'ぴょ' => 'pyo',
        'みゃ' => 'mya',
        'みゅ' => 'myu',
        'みょ' => 'myo',
        'りゃ' => 'rya',
        'りゅ' => 'ryu',
        'りょ' => 'ryo',
        'てぃ' => 'ti',
        'でぃ' => 'di',
        'ふぁ' => 'fa',
        'ふぃ' => 'fi',
        'ふぇ' => 'fe',
        'ふぉ' => 'fo',
        'うぃ' => 'wi',
        'うぇ' => 'we',
        'うぉ' => 'wo',
        'ゔぁ' => 'va',
        'しぇ' => 'she',
        'じぇ' => 'je',
        'ちぇ' => 'che',
    ];

    /** @param  (callable(string): list<array{surface_form: string, reading: ?string, pos: string}>)|null  $tokenize */
    public static function slug(string $title, ?callable $tokenize = null): string
    {
        $trimmed = self::trim(self::nfkc($title));

        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^[\x20-\x7e]+$/', $trimmed) === 1) {
            return self::slugify([$trimmed]);
        }

        $compact = (string) preg_replace('/[\s\x{3000}]+/u', '', $trimmed);

        foreach (SlugFromTitle::COMMON as $name => $slug) {
            if ($compact === $name
                || (str_starts_with($compact, $name) && in_array(substr($compact, strlen($name)), SlugFromTitle::COMMON_SUFFIXES, true))) {
                return $slug;
            }
        }

        // 漢字があるか、ひらがなとカタカナが混ざるとき（の｜ご を切るのに品詞が要る・UX 一周 21 周目 Y2）
        // 読みがずれる語（七五三 → nana-go-san）は例外の表で先に切り出し、残りだけ解析する（UX 一周 23 周目 #4）
        $tokens = [];

        foreach (self::splitExceptions($trimmed) as [$segment, $fixed]) {
            if ($fixed !== null) {
                $tokens[] = ['surface_form' => $segment, 'reading' => null, 'pos' => '名詞', 'slug' => $fixed];

                continue;
            }

            // 漢字があるか、ひらがなとカタカナが混ざるとき（の｜ご を切るのに品詞が要る・UX 一周 21 周目 Y2）
            $part = preg_match('/\p{Han}/u', $segment) === 1
                || (preg_match('/\p{Hiragana}/u', $segment) === 1 && preg_match('/\p{Katakana}/u', $segment) === 1)
                ? ($tokenize ?? static fn (string $text): array => IpadicTokenizer::shared()->tokenize($text))($segment)
                : self::splitWithoutDictionary($segment);
            array_push($tokens, ...$part);
        }

        $words = [];
        /** @var array{script: string, text: string}|null $run */
        $run = null;
        $previous = null;
        $flush = static function () use (&$run, &$words): void {
            if ($run !== null) {
                array_push($words, ...($run['script'] === 'kata' ? self::katakanaWords($run['text']) : [self::romanize($run['text'])]));
                $run = null;
            }
        };

        foreach ($tokens as $token) {
            $surface = $token['surface_form'];

            if (isset($token['slug'])) {
                $flush();
                $words[] = $token['slug'];
                $previous = 'kanji';

                continue;
            }

            if ($token['pos'] === '記号' || self::trim($surface) === '') {
                $flush();
                $previous = null;

                continue;
            }

            if (preg_match('/^[A-Za-z0-9]+$/', $surface) === 1) {
                $flush();
                $words[] = strtolower($surface);
                $previous = null;

                continue;
            }

            $script = self::isHiragana($surface) ? 'hira' : (self::isKatakana($surface) ? 'kata' : null);

            if ($script !== null) {
                if ($token['pos'] === '助詞' && $previous === 'kanji') {
                    $flush();
                    $words[] = self::romanize($surface);
                    $previous = 'particle';

                    continue;
                }

                // 接頭詞（お・ご）は前の語にくっつけない（の＋ご → nogo にしない）
                if ($run !== null && $run['script'] === $script && $token['pos'] !== '接頭詞') {
                    $run['text'] .= $surface;
                } else {
                    $flush();
                    $run = ['script' => $script, 'text' => $surface];
                }

                $previous = $script;

                continue;
            }

            $flush();
            $reading = $token['reading'] ?? null;

            if (is_string($reading) && $reading !== '*' && self::isKatakana($reading)) {
                $words[] = self::romanize($reading);
            }

            $previous = 'kanji';
        }

        $flush();

        return self::slugify(array_values(array_filter($words, static fn (string $word): bool => $word !== '')));
    }

    /** カタカナ語の表の、語の長さの上限（作るときと同じ） */
    private const KATAKANA_MAX = 20;

    /** @var array<string, string>|null */
    private static ?array $exceptions = null;

    /**
     * 読みがずれる語 → URL の語（`core/resources/reading/reading-exceptions.json`・setup も同じ表を読む）。
     *
     * @return array<string, string>
     */
    public static function exceptions(): array
    {
        if (self::$exceptions !== null) {
            return self::$exceptions;
        }

        $raw = @file_get_contents(dirname(__DIR__, 3).'/resources/reading/reading-exceptions.json');
        $decoded = $raw === false ? null : json_decode($raw, true);
        $map = [];

        foreach (is_array($decoded) ? $decoded : [] as $word => $slug) {
            if (is_string($word) && is_string($slug) && ! str_starts_with($word, '_')) {
                $map[$word] = $slug;
            }
        }

        // 長い語から先に当てる（七五三詣で を足したとき 七五三 より先に）
        uksort($map, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));

        return self::$exceptions = $map;
    }

    /**
     * 題名を、例外の表の語とそれ以外に切る。
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private static function splitExceptions(string $text): array
    {
        $table = self::exceptions();
        $chars = mb_str_split($text);
        $count = count($chars);
        $out = [];
        $pending = '';

        for ($i = 0; $i < $count;) {
            $hit = null;

            foreach ($table as $word => $slug) {
                $length = mb_strlen($word);

                if (implode('', array_slice($chars, $i, $length)) === $word) {
                    $hit = [$word, $slug, $length];

                    break;
                }
            }

            if ($hit === null) {
                $pending .= $chars[$i];
                $i++;

                continue;
            }

            if ($pending !== '') {
                $out[] = [$pending, null];
                $pending = '';
            }

            $out[] = [$hit[0], $hit[1]];
            $i += $hit[2];
        }

        if ($pending !== '') {
            $out[] = [$pending, null];
        }

        return $out;
    }

    /** @var array<string, string>|null */
    private static ?array $english = null;

    /**
     * カタカナ語 → 英語の表（JMdict 由来・`core/resources/reading/katakana-english.tsv.gz`・
     * 作り方は `scripts/build-katakana-english.py`）。setup も同じ表を読む。
     *
     * @return array<string, string>
     */
    private static function english(): array
    {
        if (self::$english !== null) {
            return self::$english;
        }

        $map = [];
        $raw = @file_get_contents(dirname(__DIR__, 3).'/resources/reading/katakana-english.tsv.gz');
        $text = $raw === false ? false : @gzdecode($raw);

        foreach ($text === false ? [] : explode("\n", $text) as $line) {
            $tab = strpos($line, "\t");

            if ($tab !== false && $tab > 0) {
                $map[substr($line, 0, $tab)] = substr($line, $tab + 1);
            }
        }

        return self::$english = $map;
    }

    /**
     * カタカナの続きを、英語の表記の語に切る（長い語から先に当てる）。表に無い部分は音のローマ字。
     *
     * @return list<string>
     */
    private static function katakanaWords(string $kata): array
    {
        $english = self::english();
        $chars = mb_str_split($kata);
        $count = count($chars);
        $words = [];
        $pending = '';

        for ($i = 0; $i < $count;) {
            $hit = null;

            for ($length = min(self::KATAKANA_MAX, $count - $i); $length >= 2; $length--) {
                $value = $english[implode('', array_slice($chars, $i, $length))] ?? null;

                if ($value !== null) {
                    $hit = [$length, $value];

                    break;
                }
            }

            if ($hit === null) {
                $pending .= $chars[$i];
                $i++;

                continue;
            }

            if ($pending !== '') {
                $words[] = self::romanize($pending, true);
                $pending = '';
            }

            // 英語が空の語は、英語でない外来語（パン・アルバイト）。その語のまま音で読む（UX 一周 21 周目 Y2）
            $words[] = $hit[1] === '' ? self::romanize(implode('', array_slice($chars, $i, $hit[0])), true) : $hit[1];
            $i += $hit[0];
        }

        if ($pending !== '') {
            $words[] = self::romanize($pending, true);
        }

        return $words;
    }

    /** かな → ローマ字。カタカナの音読みでは長音の母音を残す。漢字の読みは従来の表記。 */
    public static function romanize(string $kana, bool $longVowels = false): string
    {
        $chars = mb_str_split(self::toHiragana(self::nfkc($kana)));
        $out = '';
        $geminate = false;
        $count = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $ch = $chars[$i];
            $pair = $i + 1 < $count ? (self::PAIRS[$ch.$chars[$i + 1]] ?? null) : null;
            $romaji = $pair ?? (self::BASE[$ch] ?? null);

            if ($ch === 'っ') {
                $geminate = true;

                continue;
            }

            if ($ch === 'ー') {
                if ($longVowels && preg_match('/[aeiou]$/', $out) === 1) {
                    $out .= substr($out, -1);
                }

                continue;
            }

            if ($romaji !== null) {
                $out .= $geminate ? (str_starts_with($romaji, 'ch') ? 't' : $romaji[0]).$romaji : $romaji;
                $geminate = false;

                if ($pair !== null) {
                    $i++;
                }

                continue;
            }

            $geminate = false;
        }

        return $out;
    }

    /** @return list<array{surface_form: string, reading: ?string, pos: string}> */
    private static function splitWithoutDictionary(string $text): array
    {
        $tokens = [];
        $current = '';
        $kind = null;

        foreach (mb_str_split($text) as $ch) {
            $next = self::isHiragana($ch) ? 'hira' : (self::isKatakana($ch) ? 'kata' : (preg_match('/^[A-Za-z0-9]$/', $ch) === 1 ? 'ascii' : null));

            // 長音（ー）はひらがなにもカタカナにも付く。前の続きにする（トイプードル が トイプ｜ードル に切れていた）
            if ($ch === 'ー' && ($kind === 'hira' || $kind === 'kata')) {
                $next = $kind;
            }

            if ($next !== $kind || $next === null) {
                if ($current !== '') {
                    $tokens[] = ['surface_form' => $current, 'reading' => null, 'pos' => '名詞'];
                }

                $current = '';
            }

            if ($next !== null) {
                $current .= $ch;
            } else {
                $tokens[] = ['surface_form' => $ch, 'reading' => null, 'pos' => '記号'];
            }

            $kind = $next;
        }

        if ($current !== '') {
            $tokens[] = ['surface_form' => $current, 'reading' => null, 'pos' => '名詞'];
        }

        return $tokens;
    }

    /** @param  list<string>  $words */
    private static function slugify(array $words): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower(implode('-', $words)));

        $slug = trim($slug, '-');

        // 作成時の上限と揃え、途中の語や末尾のハイフンで切らない（UX 一周 24 周目 #2）。
        if (strlen($slug) > 180) {
            $boundary = strrpos(substr($slug, 0, 181), '-');
            $slug = substr($slug, 0, $boundary === false ? 180 : $boundary);
        }

        return rtrim($slug, '-');
    }

    private static function isHiragana(string $text): bool
    {
        return preg_match('/^[\x{3041}-\x{3096}ー]+$/u', $text) === 1;
    }

    private static function isKatakana(string $text): bool
    {
        return preg_match('/^[\x{30A1}-\x{30F6}ー]+$/u', $text) === 1;
    }

    private static function toHiragana(string $text): string
    {
        return (string) preg_replace_callback('/[\x{30A1}-\x{30F6}]/u', static fn (array $m): string => (string) mb_chr(mb_ord($m[0]) - 0x60), $text);
    }

    private static function nfkc(string $text): string
    {
        $normal = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_KC) : $text;

        return is_string($normal) ? $normal : $text;
    }

    private static function trim(string $text): string
    {
        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $text);
    }
}

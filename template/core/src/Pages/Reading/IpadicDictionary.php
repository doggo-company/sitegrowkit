<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages\Reading;

use PDO;
use RuntimeException;

/**
 * IPADIC（mecab-ipadic 2.7.0）を SQLite にまとめた辞書（UX 一周 19 周目）。
 *
 * 題の読みはサーバーが出す（以前はブラウザが 17.8MB の辞書を読んでいた）。辞書は
 * `core/resources/reading/ipadic.sqlite.gz`（約 11.8MB・作り方は `scripts/build-reading-dictionary.mjs`）を、
 * 初めて使うときに storage へ展開して読む（約 31MB）。公開サイトには出さない。
 * 許諾は同じ場所の `ipadic.NOTICE`。
 */
final class IpadicDictionary
{
    private static ?self $shared = null;

    private PDO $db;

    private string $matrix;

    private int $rightSize;

    private int $maxLength;

    /** @var list<array{lo: int, hi: int, categories: list<string>}> */
    private array $ranges = [];

    /** @var array<string, array{invoke: int, group: int, length: int}> */
    private array $categories = [];

    /** @var array<string, list<array{lid: int, rid: int, cost: int, pos: string}>> */
    private array $unknown = [];

    /** @var array<string, list<string>> */
    private array $charCache = [];

    public static function shared(): self
    {
        return self::$shared ??= new self(self::extracted());
    }

    /** 同梱の gzip を storage へ展開した SQLite の場所（展開は 1 回だけ） */
    public static function extracted(): string
    {
        $gz = dirname(__DIR__, 3).'/resources/reading/ipadic.sqlite.gz';

        if (! is_file($gz)) {
            throw new RuntimeException('読みの辞書が見つかりません: '.$gz);
        }

        $dir = function_exists('storage_path') ? storage_path('framework/reading') : sys_get_temp_dir().'/sitegrowkit-reading';
        $path = $dir.'/ipadic-'.filesize($gz).'-'.filemtime($gz).'.sqlite';

        if (is_file($path)) {
            return $path;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('読みの辞書を展開できません: '.$dir);
        }

        $tmp = $path.'.'.getmypid().'.tmp';
        $in = gzopen($gz, 'rb');
        $out = fopen($tmp, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('読みの辞書を展開できません: '.$gz);
        }

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }

        gzclose($in);
        fclose($out);
        rename($tmp, $path);

        return $path;
    }

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        /** @var array{rsize: int|string, costs: string} $matrix */
        $matrix = $this->statement('SELECT rsize, costs FROM matrix')->fetch(PDO::FETCH_ASSOC);
        $this->rightSize = (int) $matrix['rsize'];
        $this->matrix = (string) $matrix['costs'];
        $this->maxLength = (int) $this->statement("SELECT value FROM meta WHERE key = 'max_length'")->fetchColumn();

        foreach ($this->statement('SELECT lo, hi, categories FROM char_ranges ORDER BY lo') as $row) {
            $this->ranges[] = ['lo' => (int) $row['lo'], 'hi' => (int) $row['hi'], 'categories' => explode(' ', (string) $row['categories'])];
        }

        foreach ($this->statement('SELECT name, invoke, grp, len FROM categories') as $row) {
            $this->categories[(string) $row['name']] = ['invoke' => (int) $row['invoke'], 'group' => (int) $row['grp'], 'length' => (int) $row['len']];
        }

        foreach ($this->statement('SELECT category, lid, rid, cost, pos FROM unknown') as $row) {
            $this->unknown[(string) $row['category']][] = ['lid' => (int) $row['lid'], 'rid' => (int) $row['rid'], 'cost' => (int) $row['cost'], 'pos' => (string) $row['pos']];
        }
    }

    private function statement(string $sql): \PDOStatement
    {
        $statement = $this->db->query($sql);

        if ($statement === false) {
            throw new RuntimeException('読みの辞書を読めません: '.$sql);
        }

        return $statement;
    }

    public function maxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * 語の候補（表に在るものだけ）。
     *
     * @param  list<string>  $surfaces
     * @return list<array{surface: string, lid: int, rid: int, cost: int, pos: string, reading: ?string}>
     */
    public function lookup(array $surfaces): array
    {
        if ($surfaces === []) {
            return [];
        }

        $statement = $this->db->prepare('SELECT surface, lid, rid, cost, pos, reading FROM words WHERE surface IN ('.implode(',', array_fill(0, count($surfaces), '?')).')');
        $statement->execute($surfaces);
        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'surface' => (string) $row['surface'],
                'lid' => (int) $row['lid'],
                'rid' => (int) $row['rid'],
                'cost' => (int) $row['cost'],
                'pos' => (string) $row['pos'],
                'reading' => $row['reading'] === null ? null : (string) $row['reading'],
            ];
        }

        return $rows;
    }

    /** 前の語の右文脈 → 次の語の左文脈 の連接コスト */
    public function connection(int $previousRight, int $nextLeft): int
    {
        /** @var array{1: int} $value */
        $value = unpack('s', $this->matrix, ($previousRight * $this->rightSize + $nextLeft) * 2);

        return $value[1];
    }

    /**
     * その文字の種類（先頭が主な種類。char.def に無ければ DEFAULT）
     *
     * @return list<string>
     */
    public function categoriesOf(string $char): array
    {
        if (isset($this->charCache[$char])) {
            return $this->charCache[$char];
        }

        $code = mb_ord($char, 'UTF-8');
        $found = ['DEFAULT'];

        foreach ($this->ranges as $range) {
            if ($code >= $range['lo'] && $code <= $range['hi']) {
                $found = $range['categories'];
            }
        }

        return $this->charCache[$char] = $found;
    }

    /** @return array{invoke: int, group: int, length: int} */
    public function category(string $name): array
    {
        return $this->categories[$name] ?? ['invoke' => 0, 'group' => 1, 'length' => 0];
    }

    /** @return list<array{lid: int, rid: int, cost: int, pos: string}> */
    public function unknownEntries(string $category): array
    {
        return $this->unknown[$category] ?? $this->unknown['DEFAULT'] ?? [];
    }
}

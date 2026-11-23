<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages\Reading;

/**
 * 形態素解析（UX 一周 19 周目）。IPADIC の語の候補を並べ、コスト（語のコスト＋連接コスト）が最小になる
 * 切り方を選ぶ（Viterbi。MeCab・kuromoji と同じ考え方・同じ辞書）。表に無い語は char.def / unk.def の
 * 未知語の決まりで候補にする（読みは無し）。
 *
 * setup（Node）は kuromoji で読む。同じ例で同じ候補になることを、両方の試験が
 * `tests/fixtures/reading-examples.json` で確かめる。
 */
final class IpadicTokenizer
{
    public function __construct(private readonly IpadicDictionary $dictionary) {}

    public static function shared(): self
    {
        return new self(IpadicDictionary::shared());
    }

    /** @return list<array{surface_form: string, reading: ?string, pos: string}> */
    public function tokenize(string $text): array
    {
        $chars = mb_str_split($text);
        $count = count($chars);

        if ($count === 0) {
            return [];
        }

        // ends[i] = i で終わる節点。節点: [start, surface, rid, pos, reading, total, previous]
        $ends = array_fill(0, $count + 1, []);
        $ends[0][] = ['start' => 0, 'surface' => '', 'rid' => 0, 'pos' => 'BOS', 'reading' => null, 'total' => 0, 'previous' => null];
        $maxLength = $this->dictionary->maxLength();

        for ($i = 0; $i < $count; $i++) {
            if ($ends[$i] === []) {
                continue;
            }

            $surfaces = [];

            for ($length = 1; $length <= min($maxLength, $count - $i); $length++) {
                $surfaces[] = implode('', array_slice($chars, $i, $length));
            }

            $candidates = [];

            foreach ($this->dictionary->lookup($surfaces) as $row) {
                $candidates[] = $row + ['length' => mb_strlen($row['surface'])];
            }

            $categories = $this->dictionary->categoriesOf($chars[$i]);
            $name = $categories[0];
            $category = $this->dictionary->category($name);

            if ($candidates === [] || $category['invoke'] === 1) {
                $lengths = [];

                if ($category['group'] === 1) {
                    $length = 1;

                    while ($i + $length < $count && in_array($name, $this->dictionary->categoriesOf($chars[$i + $length]), true)) {
                        $length++;
                    }

                    $lengths[$length] = true;
                }

                for ($length = 1; $length <= $category['length'] && $i + $length <= $count; $length++) {
                    if ($length > 1 && ! in_array($name, $this->dictionary->categoriesOf($chars[$i + $length - 1]), true)) {
                        break;
                    }

                    $lengths[$length] = true;
                }

                foreach (array_keys($lengths) as $length) {
                    $surface = implode('', array_slice($chars, $i, $length));

                    foreach ($this->dictionary->unknownEntries($name) as $entry) {
                        $candidates[] = $entry + ['surface' => $surface, 'reading' => null, 'length' => $length];
                    }
                }
            }

            foreach ($candidates as $candidate) {
                $best = null;
                $bestTotal = PHP_INT_MAX;

                foreach ($ends[$i] as $index => $previous) {
                    $total = $previous['total'] + $this->dictionary->connection($previous['rid'], $candidate['lid']) + $candidate['cost'];

                    if ($total < $bestTotal) {
                        $bestTotal = $total;
                        $best = $index;
                    }
                }

                $ends[$i + $candidate['length']][] = [
                    'start' => $i,
                    'surface' => $candidate['surface'],
                    'rid' => $candidate['rid'],
                    'pos' => $candidate['pos'],
                    'reading' => $candidate['reading'],
                    'total' => $bestTotal,
                    'previous' => [$i, $best],
                ];
            }
        }

        // 文末（左文脈 0）
        $best = null;
        $bestTotal = PHP_INT_MAX;

        foreach ($ends[$count] as $index => $node) {
            $total = $node['total'] + $this->dictionary->connection($node['rid'], 0);

            if ($total < $bestTotal) {
                $bestTotal = $total;
                $best = $index;
            }
        }

        $tokens = [];
        $at = [$count, $best];

        while ($at[1] !== null) {
            $node = $ends[$at[0]][$at[1]];

            if ($node['previous'] === null) {
                break;
            }

            array_unshift($tokens, ['surface_form' => $node['surface'], 'reading' => $node['reading'], 'pos' => $node['pos']]);
            $at = $node['previous'];
        }

        return $tokens;
    }
}

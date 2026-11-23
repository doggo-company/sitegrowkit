<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

/**
 * ファイルに何が入っているか（Phase 9.14 / STAGE 27B・SPEC §A.3）。
 *
 * **DB を 1 行も読まない。** ここで答えるのは「このファイルの中身」だけで、
 * 「こちらへ入るかどうか」は plan（27C）の仕事である。
 *
 * ## 取り込めないものも必ず数える
 *
 * 移行は 1 回きりの作業で、**気づかなかったものは永久に気づかれない**。
 * 「500 件のうち 480 件を入れました」ではなく、
 * 「入れなかった 20 件はページです」まで出せる材料をここで作る。
 */
final class WxrSummary
{
    /** 見つけた shortcode の名前をこれ以上は覚えない（報告に十分な数） */
    private const MAX_SHORTCODE_NAMES = 30;

    /** @var array<string, int> post_type => 件数 */
    public array $postTypes = [];

    /** @var array<string, int> status => 件数 */
    public array $statuses = [];

    /** @var array<string, int> 分類（domain）=> 項目の延べ数 */
    public array $termDomains = [];

    /** @var array<string, array<string, string>> domain => [nicename => name] */
    public array $terms = [];

    /**
     * `<channel>` 直下の定義の数（WP 一周 2026-09-03）。
     *
     * **`terms` とは別物である。** `terms` は**記事が指している種類**で、
     * ここは**名前の定義がある数**。実物の書き出しには定義が 1 件も無いものがあり、
     * 「項目 4 種」とだけ出すと、定義が在ると読まれてしまう。
     *
     * @var array<string, int> domain => 定義の数
     */
    public array $termDefinitions = [];

    public int $items = 0;

    public int $comments = 0;

    public int $attachments = 0;

    /** @var array<string, int> shortcode 名 => 件数 */
    public array $shortcodes = [];

    /**
     * 本文が大きい item の `wp:post_id`。
     *
     * @var list<string>
     */
    public array $largeBodies = [];

    /**
     * 1 件を数える。
     *
     * **重複の検査はここでは行わない。** `WxrReader::items()` が読む時点で
     * 見ている。集計側に置くと、plan や apply が reader を直接回したときに
     * 検査を素通りできてしまう（迂回できる道を作らない）。
     */
    /** `<channel>` 直下の定義を 1 件数える（item とは別の入口から来る） */
    public function addDefinition(string $taxonomy): void
    {
        $this->termDefinitions[$taxonomy] = ($this->termDefinitions[$taxonomy] ?? 0) + 1;
    }

    public function add(WxrItem $item): void
    {
        $this->items++;
        $this->postTypes[$item->postType] = ($this->postTypes[$item->postType] ?? 0) + 1;
        $this->statuses[$item->status] = ($this->statuses[$item->status] ?? 0) + 1;
        $this->comments += count($item->comments);

        if ($item->postType === 'attachment') {
            $this->attachments++;
        }

        foreach ($item->terms as $term) {
            $domain = $term['domain'];
            $this->termDomains[$domain] = ($this->termDomains[$domain] ?? 0) + 1;

            if ($term['nicename'] !== '') {
                $this->terms[$domain][$term['nicename']] = $term['name'];
            }
        }

        $this->collectShortcodes($item->content);

        if (strlen($item->content) > WxrReader::LARGE_BODY_BYTES) {
            $this->largeBodies[] = $item->postId;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        ksort($this->postTypes);
        ksort($this->statuses);
        ksort($this->termDomains);
        ksort($this->termDefinitions);
        arsort($this->shortcodes);

        return [
            'items' => $this->items,
            'postTypes' => $this->postTypes,
            'statuses' => $this->statuses,
            'comments' => $this->comments,
            'attachments' => $this->attachments,
            'termDomains' => $this->termDomains,
            'terms' => array_map(static fn (array $t): int => count($t), $this->terms),
            'termDefinitions' => $this->termDefinitions,
            'shortcodes' => $this->shortcodes,
            'largeBodies' => count($this->largeBodies),
        ];
    }

    /**
     * 本文に残る shortcode を数える。
     *
     * **展開も削除もしない**（`WP_PARITY_BASELINE.md` §4）。
     * 代わりに「移行したら動かなくなるもの」を、移行する**前に**言えるようにする。
     */
    private function collectShortcodes(string $content): void
    {
        if ($content === '' || ! str_contains($content, '[')) {
            return;
        }

        if (preg_match_all('/\[([a-z][a-z0-9_-]*)[\s\]\/]/i', $content, $matches) === false) {
            return;
        }

        foreach ($matches[1] as $name) {
            $name = strtolower($name);

            if (! isset($this->shortcodes[$name]) && count($this->shortcodes) >= self::MAX_SHORTCODE_NAMES) {
                continue;
            }

            $this->shortcodes[$name] = ($this->shortcodes[$name] ?? 0) + 1;
        }
    }
}

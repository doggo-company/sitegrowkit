<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use JsonException;

/**
 * AI 向け索引（`.agentcms/registry/taxonomies.json`）へ書き戻す
 * （WP 一周 2026-09-06 / 移行が verify で止まっていた所）。
 *
 * ## なぜ要るのか
 *
 * `ConsumerRegistry` は索引と `content/schemas/*.json` の宣言が食い違うと
 * `agentcms verify` を落とす。**正しい判定である** — 索引が古いまま残ると、
 * AI が「どの型がどの分類を使うか」を間違って読む。
 *
 * 問題は、**索引を更新する道具が 1 つも無かった**ことである。
 * `agentcms:wxr-plan --create-taxonomies` は `content/taxonomies/<key>.json` と
 * `content/schemas/<型>.json` の宣言（＝索引の元になる正）を書くのに、
 * そこから導かれる索引を置き去りにしていた。結果、
 * `docs/MIGRATE_FROM_WORDPRESS.md` の手順どおりに進めた人は、
 * 最後の `agentcms verify` で必ず止まった。
 *
 * `agentcms:taxonomy-sync` はこれを直せない。あれは
 * **`content/taxonomies/*.json` を DB へ**同期するもので、`content/schemas/` を
 * 読まないため、索引が持つ `consumers`（どの型が使うか）を知らない。
 *
 * ## 契約
 *
 * - **正は `content/taxonomies/*.json` と `content/schemas/*.json`。**
 *   ここはそこから導いた値を書き写すだけで、独自の判断を持たない
 * - **渡された key だけを触る。** 索引に既にある他の項目は、順番も中身も変えない
 *   （この道具が起こしていない乖離を、黙って直さない）
 *   `ConsumerRegistry` が引き続き検出し、verify が人に知らせる
 * - **項目の未知のキーは残す。** 上書きするのは導出できる 4 つだけ
 * - **読めない・壊れている索引は書き換えない。** 直せなかったことを返す
 * - `ConsumerRegistry` は read-only のまま（あちらの docblock の約束を破らない）
 */
final class ConsumerRegistryWriter
{
    /** 索引の項目のうち、正から導出できるもの。**ここだけ上書きする** */
    private const DERIVED_KEYS = ['key', 'managed_by', 'hierarchical', 'definition', 'consumers'];

    public function __construct(private readonly string $path) {}

    /**
     * 渡した分類の項目を、正に合わせて作る / 直す。
     *
     * @param  array<string, TaxonomyDefinition>  $definitions  対象の key => 定義
     * @param  array<string, list<string>>  $consumers  key => その分類を宣言している Content Type ID
     * @return bool 索引を書き換えたら true（元から一致していれば false）
     */
    public function upsert(array $definitions, array $consumers): bool
    {
        $document = $this->read();

        if ($document === null) {
            return false;
        }

        /** @var list<mixed> $entries */
        $entries = $document['taxonomies'];
        $changed = false;

        foreach ($definitions as $key => $definition) {
            $want = $this->entryFor($key, $definition, $consumers[$key] ?? []);
            $at = $this->indexOf($entries, $key);

            if ($at === null) {
                $entries[] = $want;
                $changed = true;

                continue;
            }

            /** @var array<string, mixed> $current */
            $current = $entries[$at];

            // 未知のキー（利用者が足したメモ等）は残し、導出できるものだけ重ねる
            $merged = $current;

            foreach (self::DERIVED_KEYS as $field) {
                $merged[$field] = $want[$field];
            }

            if ($merged !== $current) {
                $entries[$at] = $merged;
                $changed = true;
            }
        }

        if (! $changed) {
            return false;
        }

        $document['taxonomies'] = array_values($entries);

        return $this->write($document);
    }

    /**
     * 1 件分の項目。**`template/.agentcms/registry/taxonomies.json` と同じ形**にする。
     *
     * @param  list<string>  $consumers
     * @return array<string, mixed>
     */
    private function entryFor(string $key, TaxonomyDefinition $definition, array $consumers): array
    {
        sort($consumers, SORT_STRING);

        return [
            'key' => $key,
            'managed_by' => $definition->managedBy->value,
            'hierarchical' => $definition->hierarchical,
            'definition' => 'content/taxonomies/'.$definition->sourceFile,
            'consumers' => $consumers,
        ];
    }

    /**
     * 既存項目の位置。**key が一致する最初のもの**（重複は `ConsumerRegistry` が言う）。
     *
     * @param  array<int, mixed>  $entries
     */
    private function indexOf(array $entries, string $key): ?int
    {
        foreach ($entries as $index => $entry) {
            if (is_array($entry) && ($entry['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 索引を読む。**形が違えば null**（壊れたものを上書きして原因を消さない）。
     *
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['taxonomies']) || ! is_array($data['taxonomies'])
            || ! array_is_list($data['taxonomies'])) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * 書き出す。**インデントは 2 で揃える** — 索引は kit が 2 で配っており
     * （`packages/cli/src/distribution.ts` も 2 で書き直す）、ここだけ 4 にすると
     * 利用者の diff が全行入れ替わる。
     *
     * @param  array<string, mixed>  $document
     */
    private function write(array $document): bool
    {
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            return false;
        }

        // JSON_PRETTY_PRINT は 4 空白。**行頭の空白だけ**を半分にする
        // （値の中の空白は行頭に来ないので、これで壊れない）
        $json = (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $m): string => str_repeat(' ', (int) (strlen($m[0]) / 2)),
            $json,
        );

        return file_put_contents($this->path, $json.PHP_EOL) !== false;
    }
}

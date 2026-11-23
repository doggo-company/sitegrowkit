<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Wxr\WxrError;

/**
 * 取り込みの指定を、**書き方に依らない 1 つの形**へ揃える
 * （Phase 9.14 / STAGE 27C・SPEC §C.2・§C.3・§D.1）。
 *
 * ## なぜ正規化するのか
 *
 * `apply` は `plan` の hash を照合する（§D.1）。ここが書き順で変わると、
 * **同じことを頼んでいるのに apply が拒否される**。人は拒否が続くと
 * 確認そのものを飛ばすようになるので、**照合を信用できなくする**。
 *
 * ## 矛盾は黙って解決しない
 *
 * `--type=post:blog --type=post:news` は、どちらが本当か決められない。
 * 「後に書いた方が勝つ」にすると書き間違いが黙って通り、
 * **十年分が意図しない型へ入る**。決められないときは止める。
 */
final class ImportOptions
{
    /**
     * @param  array<string, string>  $types  WXR の post_type => こちらの型 id
     * @param  array<string, string>  $taxonomies  WXR の分類 => こちらの分類 key
     */
    private function __construct(
        public readonly array $types,
        public readonly array $taxonomies,
        public readonly bool $media,
        /**
         * 分類に定義が 1 件も無いとき、記事側の割り当てから平らな項目を作るか
         * （`FlatTermDefinitionSource`・WP 一周 2026-09-04）。
         *
         * **既定は作る側。** 「書き出し直してください」は、サイトが既に閉じている
         * 案件・前の制作会社が鍵を持っている案件では実行できない。
         * 従来どおり止めたいときだけ `--strict-term-definitions` を付ける。
         */
        public readonly bool $flattenMissingTermDefinitions = true,
    ) {}

    /**
     * @param  list<string>  $types  `post:blog` の形
     * @param  list<string>  $taxonomies  `category:blog_category` の形
     *
     * @throws WxrError
     */
    public static function fromCli(
        array $types,
        array $taxonomies,
        bool $media,
        bool $flattenMissingTermDefinitions = true,
    ): self {
        return new self(
            types: self::pairs($types, '--type'),
            taxonomies: self::pairs($taxonomies, '--taxonomy'),
            media: $media,
            flattenMissingTermDefinitions: $flattenMissingTermDefinitions,
        );
    }

    /**
     * hash に載せる形。**キーは並べ替えて返す**（書き順を持ち込まない）。
     *
     * @return array{flattenMissingTermDefinitions: bool, media: bool, taxonomies: array<string, string>, types: array<string, string>}
     */
    public function toCanonical(): array
    {
        $types = $this->types;
        $taxonomies = $this->taxonomies;

        ksort($types);
        ksort($taxonomies);

        // トップのキーも並べた順で書く（json_encode は挿入順で出す）
        return [
            'flattenMissingTermDefinitions' => $this->flattenMissingTermDefinitions,
            'media' => $this->media,
            'taxonomies' => $taxonomies,
            'types' => $types,
        ];
    }

    /**
     * `左:右` を集める。同じ左に違う右が来たら止める。
     *
     * @param  list<string>  $raw
     * @return array<string, string>
     *
     * @throws WxrError
     */
    private static function pairs(array $raw, string $flag): array
    {
        $pairs = [];

        foreach ($raw as $entry) {
            $entry = trim($entry);

            // `:` は 1 つだけ。`a:b:c` は読み取れない
            $parts = explode(':', $entry);

            if (count($parts) !== 2) {
                throw new WxrError(
                    "{$flag} の書き方が違います: [{$entry}]",
                    "{$flag}=<WXR 側の名前>:<こちら側の名前> の形で書いてください。",
                );
            }

            [$from, $to] = array_map(trim(...), $parts);

            if ($from === '' || $to === '') {
                throw new WxrError(
                    "{$flag} に空の名前があります: [{$entry}]",
                    "{$flag}=<WXR 側の名前>:<こちら側の名前> の形で、両方を書いてください。",
                );
            }

            // 同じ指定を重ねるのは、1 回書いたのと同じ意味
            if (array_key_exists($from, $pairs) && $pairs[$from] !== $to) {
                throw new WxrError(
                    "{$flag} で [{$from}] の行き先が 2 つ指定されています（[{$pairs[$from]}] と [{$to}]）。",
                    'どちらへ入れるか決められません。'
                        .'後に書いた方を採ると、書き間違いが黙って通ります。'
                        .'1 つに絞って、もう一度実行してください。',
                );
            }

            $pairs[$from] = $to;
        }

        return $pairs;
    }
}

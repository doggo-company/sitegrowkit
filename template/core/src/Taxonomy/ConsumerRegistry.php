<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use JsonException;

/**
 * AI 向け index（`.agentcms/registry/taxonomies.json`）の `consumers` を
 * **ContentSchema から導出した実際の利用者と突き合わせる**
 * （Phase 9.3B / STAGE 3C CORRECTION・F-3C-2）。
 *
 * ## なぜ必要か
 *
 * consumer の**正**は `content/schemas/*.json` の `taxonomies[]` にある。
 * registry の `consumers` は AI へ見せるための**手書きの索引**であり、
 * 放置すると schema と静かに乖離する。両方を個別に assert しても
 * 「片方だけ直した」状態は検出できない。
 *
 * ## 契約
 *
 * - **read-only。** DB へ触らず、ファイルも書き換えない
 * - **自動修正しない。** 直すのは人（または `agentcms sync-template-context`）
 * - 実行中プロジェクト自身の `.agentcms` を見る（kit の canonical ではない）
 * - **taxonomy key / Content Type ID を hardcode しない**
 * - 表示は利用者向けの日本語。**絶対パス・SQL・接続情報を出さない**
 */
final class ConsumerRegistry
{
    /** 画面へ出す相対表記（絶対パスを見せない） */
    public const LABEL = '.agentcms/registry/taxonomies.json';

    public function __construct(private readonly string $path) {}

    /**
     * 不一致を日本語の問題点として返す。**空なら一致**。
     *
     * @param  list<string>  $definitionKeys  `content/taxonomies/*.json` にある key
     * @param  array<string, list<string>>  $expected  taxonomy key => 参照している Content Type ID
     * @return list<string>
     */
    public function problems(array $definitionKeys, array $expected): array
    {
        $entries = $this->read();

        if (is_string($entries)) {
            return [$entries];
        }

        $problems = [];
        $seen = [];

        foreach ($entries as $index => $entry) {
            $position = $index + 1;

            if (! is_array($entry) || ! is_string($entry['key'] ?? null) || $entry['key'] === '') {
                $problems[] = "{$position} 件目の項目に key がありません。";

                continue;
            }

            $key = $entry['key'];

            if (isset($seen[$key])) {
                $problems[] = "[{$key}] が {$position} 件目で重複しています。";

                continue;
            }

            $seen[$key] = true;

            // 定義ファイルの無い key は index に載せない（定義が唯一の情報源）
            if (! in_array($key, $definitionKeys, true)) {
                $problems[] = "[{$key}] は content/taxonomies/ に定義がありません。";

                continue;
            }

            $consumers = $this->consumersOf($entry);

            if (is_string($consumers)) {
                $problems[] = "[{$key}] {$consumers}";

                continue;
            }

            $want = $expected[$key] ?? [];
            sort($want, SORT_STRING);

            if ($consumers !== $want) {
                $problems[] = sprintf(
                    '[%s] コンテンツタイプの宣言: %s / この索引の記録: %s',
                    $key,
                    $want === [] ? '（なし）' : implode(' / ', $want),
                    $consumers === [] ? '（なし）' : implode(' / ', $consumers),
                );
            }
        }

        foreach ($definitionKeys as $key) {
            if (! isset($seen[$key])) {
                $problems[] = "[{$key}] の項目がありません（定義はあるのに索引に載っていません）。";
            }
        }

        return $problems;
    }

    /**
     * 索引を読む。**問題があれば文字列（日本語メッセージ）を返す**。
     *
     * @return list<mixed>|string
     */
    private function read(): array|string
    {
        if (! is_file($this->path)) {
            return self::LABEL.' が見つかりません。';
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false) {
            return self::LABEL.' を読み取れません（読み取り権限を確認してください）。';
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // 例外メッセージにはオフセット等しか出ないが、内容は載せない
            return self::LABEL.' の JSON を読み取れません。';
        }

        if (! is_array($data) || ! isset($data['taxonomies']) || ! is_array($data['taxonomies'])
            || ! array_is_list($data['taxonomies'])) {
            return self::LABEL.' の形式が違います（taxonomies の配列が必要です）。';
        }

        return $data['taxonomies'];
    }

    /**
     * `consumers` を正規化する。**問題があれば文字列を返す**。
     *
     * @param  array<array-key, mixed>  $entry
     * @return list<string>|string
     */
    private function consumersOf(array $entry): array|string
    {
        $raw = $entry['consumers'] ?? null;

        if (! is_array($raw) || ! array_is_list($raw)) {
            return 'consumers はコンテンツタイプ ID の配列で指定してください。';
        }

        $consumers = [];

        foreach ($raw as $value) {
            if (! is_string($value) || $value === '') {
                return 'consumers にはコンテンツタイプ ID（文字列）だけを書いてください。';
            }

            if (in_array($value, $consumers, true)) {
                return "consumers に [{$value}] が重複しています。";
            }

            $consumers[] = $value;
        }

        sort($consumers, SORT_STRING);

        return $consumers;
    }
}

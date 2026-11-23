<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

/**
 * Field Group の上限（Phase 9.5-C / ADR-0017・Owner 決定）。
 *
 * **1 か所で持つ。** 定義の保存時（`FieldGroupDraft`）と読み込み時（`FieldShape`）の
 * 両方が同じ値を読むので、管理画面と crafted request で契約が食い違わない。
 *
 * ## なぜ明示するのか
 *
 * 9.5-B までは上限が無く、実際に効いていたのは `json_decode($raw, true, 32)` の
 * 副作用（深さ 15 以上で「Maximum stack depth exceeded」）だけだった。
 * 仕様として決めた値でもなく、理由文から原因も読めない。
 * **decoder の偶発的な最大深さを仕様上限として使わない。**
 *
 * ## 値の根拠
 *
 * 同梱 schema の実測は最大で **深さ 2 / 総数 28**（`product`）。
 * 先例として `DocumentValidator` が `MAX_DEPTH = 12` / `MAX_NODES = 500` を持つ。
 * 深さ 3 は「group の中の repeater の中の項目」まで許し、実運用に余裕がある。
 */
final class FieldGroupLimits
{
    /** トップレベルの field を深さ 1 と数える */
    public const MAX_DEPTH = 3;

    /** 1 束あたりの field 総数（入れ子を含む） */
    public const MAX_FIELDS = 200;

    /** `repeater` の `max` が未指定のときに入れる既定値 */
    public const REPEATER_DEFAULT_MAX = 50;

    /** `repeater` の `max` に指定できる最大値 */
    public const REPEATER_MAX = 500;

    /**
     * JSON を読むときの深さ。
     *
     * **上限そのものではない。** `MAX_DEPTH` を我々の側で判定できるよう、
     * decoder が先に音を上げない程度に取ってあるだけである。
     */
    public const JSON_DEPTH = 512;

    /**
     * 宣言の総数（入れ子を含む）。
     *
     * @param  list<array<string, mixed>>  $declarations
     */
    public static function countFields(array $declarations): int
    {
        $count = 0;

        foreach ($declarations as $declaration) {
            $count++;

            $children = $declaration['fields'] ?? null;

            if (is_array($children)) {
                /** @var list<array<string, mixed>> $children */
                $count += self::countFields($children);
            }
        }

        return $count;
    }

    /**
     * `repeater` の行数上限を確定させる（Phase 9.5-C / ADR-0017）。
     *
     * 未指定は既定へ**正規化**する。上限が無い repeater は `data` JSON を
     * 際限なく太らせるので、「書き忘れたら無制限」にはしない（SPEC §11）。
     * 範囲外は拒否する（黙って丸めない）。
     *
     * 入れ子も同じ規則で辿る。
     *
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException 範囲外のとき
     */
    public static function withRepeaterBounds(array $declaration, string $path): array
    {
        if (($declaration['type'] ?? null) === 'repeater') {
            $max = $declaration['max'] ?? null;

            if ($max === null || $max === '') {
                $declaration['max'] = self::REPEATER_DEFAULT_MAX;
            } elseif ((int) $max < 1 || (int) $max > self::REPEATER_MAX) {
                throw new \InvalidArgumentException(
                    "{$path}.max は 1〜".self::REPEATER_MAX.' の範囲で指定してください（行数の上限です）',
                );
            } else {
                $declaration['max'] = (int) $max;
            }
        }

        $children = $declaration['fields'] ?? null;

        if (is_array($children)) {
            $bounded = [];

            foreach (array_values($children) as $index => $child) {
                $bounded[] = is_array($child)
                    ? self::withRepeaterBounds($child, "{$path}.fields[{$index}]")
                    : $child;
            }

            $declaration['fields'] = $bounded;
        }

        return $declaration;
    }

    public static function fieldCountMessage(int $count): string
    {
        return "項目が多すぎます（入れ子を含めて {$count} 個。上限は ".self::MAX_FIELDS.' 個です）';
    }

    public static function depthMessage(string $path): string
    {
        return "{$path}: 入れ子が深すぎます（許容できる階層は ".self::MAX_DEPTH.' までです）';
    }
}

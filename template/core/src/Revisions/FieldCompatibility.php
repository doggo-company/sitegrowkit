<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

/**
 * その版の値を、**いまの定義へそのまま戻してよいか**（Phase 9.8 / SPEC §F.2.2）。
 *
 * ## 型が同じでは足りない
 *
 * 一番危ういのは `relation` である。参照先の型が `works` → `news` に変わっていると、
 * 版が持っている `42` は**別の型の 42 として解釈される**。型も名前も同じままなので、
 * 型だけを見ていると通ってしまい、まったく無関係な投稿を指す参照が生まれる。
 * ID は**型と組でしか意味を持たない**。
 *
 * 同じ理由で、保存先が変わった（列 → JSON）、選択肢から値が消えた、
 * 検証が厳しくなった、入れ子の形が変わった、も戻してはいけない。
 *
 * ## 判定は小さく明示的に
 *
 * 「属性が 1 つでも違えば非互換」にはしない。表示名や並び順の違いで
 * 「復元できません」と言われても、利用者には何のことか分からない。
 * **復元の可否に関わるものだけ**を、理由の言える形で並べる。
 */
final class FieldCompatibility
{
    /**
     * 互換でない理由。互換なら null。
     *
     * @param  array<string, mixed>  $historical  版の時点の定義
     * @param  array<string, mixed>  $current  いまの定義
     */
    public static function reason(array $historical, array $current): ?string
    {
        if (($historical['type'] ?? null) !== ($current['type'] ?? null)) {
            return "項目の種類が変わっています（{$historical['type']} → {$current['type']}）";
        }

        /*
         * **参照先の型**。ここが変わっていたら、同じ数字が別の型を指すことになる。
         * 「その ID は今の参照先にも在る」は理由にならない — 別物である。
         */
        if (($historical['target'] ?? null) !== ($current['target'] ?? null)) {
            return '参照先の投稿タイプが変わっています（'
                .($historical['target'] ?? '未設定').' → '.($current['target'] ?? '未設定').'）';
        }

        // 保存先が変わると、値の入れ場所そのものが違う
        if (($historical['storage'] ?? null) !== ($current['storage'] ?? null)) {
            return '値の保存先が変わっています';
        }

        if (self::choicesNarrowed($historical, $current)) {
            return '選択肢から値が削られています（その版の値は選べません）';
        }

        if (self::validationTightened($historical, $current)) {
            return '入力の条件が厳しくなっています';
        }

        if (self::childrenChanged($historical, $current)) {
            return '入れ子の項目構成が変わっています';
        }

        return null;
    }

    /**
     * 選択肢が**狭まった**か。増えたぶんには困らない。
     *
     * @param  array<string, mixed>  $historical
     * @param  array<string, mixed>  $current
     */
    private static function choicesNarrowed(array $historical, array $current): bool
    {
        $before = is_array($historical['choices'] ?? null) ? $historical['choices'] : [];
        $after = is_array($current['choices'] ?? null) ? $current['choices'] : [];

        if ($before === []) {
            return false;
        }

        return array_diff($before, $after) !== [];
    }

    /**
     * 検証が**厳しくなった**か。緩む分には、その版の値は通る。
     *
     * @param  array<string, mixed>  $historical
     * @param  array<string, mixed>  $current
     */
    private static function validationTightened(array $historical, array $current): bool
    {
        if (($historical['required'] ?? false) === false && ($current['required'] ?? false) === true) {
            return true;
        }

        if (($historical['unique'] ?? false) === false && ($current['unique'] ?? false) === true) {
            return true;
        }

        if (self::limitTightened($historical['max'] ?? null, $current['max'] ?? null)) {
            return true;
        }

        $before = is_array($historical['validation'] ?? null) ? $historical['validation'] : [];
        $after = is_array($current['validation'] ?? null) ? $current['validation'] : [];

        foreach ($after as $rule => $value) {
            if (! array_key_exists($rule, $before)) {
                // 無かった規則が増えた
                return true;
            }

            if (str_contains((string) $rule, 'max') && self::limitTightened($before[$rule], $value)) {
                return true;
            }

            if (str_contains((string) $rule, 'min') && self::limitTightened($value, $before[$rule])) {
                return true;
            }
        }

        return false;
    }

    /** 上限が下がったか（`null` は「上限なし」） */
    private static function limitTightened(mixed $before, mixed $after): bool
    {
        if ($after === null) {
            return false;
        }

        if ($before === null) {
            return true;
        }

        return is_numeric($before) && is_numeric($after) && (float) $after < (float) $before;
    }

    /**
     * 入れ子の構成が変わったか。**子も同じ規則で見る**。
     *
     * @param  array<string, mixed>  $historical
     * @param  array<string, mixed>  $current
     */
    private static function childrenChanged(array $historical, array $current): bool
    {
        $before = is_array($historical['children'] ?? null) ? $historical['children'] : [];
        $after = is_array($current['children'] ?? null) ? $current['children'] : [];

        if ($before === [] && $after === []) {
            return false;
        }

        $afterByIdentity = [];

        foreach ($after as $child) {
            if (is_array($child)) {
                $afterByIdentity[(string) ($child['identity'] ?? '')] = $child;
            }
        }

        foreach ($before as $child) {
            if (! is_array($child)) {
                return true;
            }

            $identity = (string) ($child['identity'] ?? '');
            $now = $afterByIdentity[$identity] ?? null;

            // 子が消えた、あるいは子自体が非互換になった
            if ($now === null || self::reason($child, $now) !== null) {
                return true;
            }
        }

        return false;
    }
}

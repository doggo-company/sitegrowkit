<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * その版の時点の**内容**（Phase 9.8 / STAGE 21D・SPEC §F.2）。
 *
 * ## 何を「内容」と呼ぶか
 *
 * 列の値、`data` JSON、分類の割り当て、公開状態。**保管状態（ゴミ箱かどうか）は入らない** —
 * それは内容ではなく置き場所の話で、`OperationAudit` が追う。1 つの履歴に
 * 「中身が変わった」と「捨てた・戻した」を混ぜない。
 *
 * ## entry の時刻を 2 つ入れる理由
 *
 * 版の行にも `created_at` は在るが、それは**その行が生まれた時刻**である。
 * backfill で作った版なら backfill を流した時刻になる。履歴画面が出したいのは
 * 「その版の時点で投稿がどう見えていたか」なので、entry 側の時刻を別に持つ。
 *
 * **復元でこれを entry へ書き戻さない。** 復元は内容を戻す操作であって、
 * 更新日時を過去へ巻き戻す操作ではない。戻すと一覧の並びや sitemap の
 * `lastmod` が壊れる。**表示と説明のためだけに持つ。**
 *
 * ## 入れないもの
 *
 * secret / token / password、media の中身（ID だけ）、監査の本文、
 * 利用者のメール・IP。版は**内容の履歴**であって行動ログではない。
 */
final class RevisionSnapshot
{
    /**
     * snapshot の**形**の版。形を変えたら上げる。
     *
     * 上げたときに古い版をどう読むかは、読む側が分岐できるようにするためであって、
     * 「読めなくてよい」という意味ではない。
     */
    public const VERSION = 1;

    /**
     * いまの entry から snapshot を作る。
     *
     * **entry と分類を書き終えたあとに呼ぶこと**（SPEC §F.3.1 の ③）。
     * 先に作ると「入っていない値の版」ができる。
     *
     * @return array<string, mixed>
     */
    public static function of(ContentSchema $schema, string $contentType, Model $model): array
    {
        $columns = [];

        foreach ($schema->columnFields() as $field) {
            $columns[$field->key] = self::normalizeValue($model->getAttribute($field->key));
        }

        ksort($columns);

        $data = $model->getAttribute('data');

        return [
            'snapshot_version' => self::VERSION,
            // 型をまたいだ取り違えを防ぐ
            'content_type' => $contentType,
            'columns' => $columns,
            'data' => is_array($data) ? self::sortRecursively($data) : null,
            'taxonomies' => self::taxonomies($contentType, (int) $model->getKey()),
            'entry_created_at' => self::timestamp($model->getAttribute('created_at')),
            'entry_updated_at' => self::timestamp($model->getAttribute('updated_at')),
        ];
    }

    /**
     * 2 つの版の内容が同じか。
     *
     * **表示のための時刻は比べない。** 保存を押しただけで `updated_at` は動くので、
     * それを見ると「中身が同じなのに版が増える」ことになる（OG7 が避けたかった形）。
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function sameContent(array $a, array $b): bool
    {
        /*
         * **同じ形へそろえてから、保存される形で比べる。**
         *
         * 2 つのずれを同時に吸収する必要がある。
         *
         * 1. PHP の `===` は型まで見るので、DB を往復した値（`1000` が `1000.0` に、
         *    `false` が `0` になる、など driver 次第の違い）が「内容が変わった」に化ける
         * 2. MySQL の `json` 型は保存時に**オブジェクトのキー順を並べ替える**ので、
         *    読み戻すと書いたときと並びが違う（SQLite は文字列のまま持つので気づけない）
         *
         * だから **キー順をそろえてから JSON にして**比べる。そうしないと、
         * 保存を押すたびに中身の同じ版が増えていく。
         */
        return self::encode(self::sortRecursively(self::comparable($a)))
            === self::encode(self::sortRecursively(self::comparable($b)));
    }

    /** @param  array<string, mixed>  $snapshot */
    private static function encode(array $snapshot): string
    {
        return (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function comparable(array $snapshot): array
    {
        unset($snapshot['entry_created_at'], $snapshot['entry_updated_at']);

        return $snapshot;
    }

    /**
     * 分類の割り当て（**分類キー → term ID の list**）。
     *
     * 表示用の名前は入れない。名前は後から変わるが、**戻したいのは割り当てそのもの**である。
     *
     * **term の ID は ULID（文字列）である。** 数値へ寄せない — 寄せた瞬間に
     * 別の term を指す値になる。
     *
     * @return array<string, list<string>>
     */
    public static function taxonomies(string $contentType, int $contentId): array
    {
        $rows = DB::table('taxonomy_term_assignments as a')
            ->join('taxonomy_terms as t', 't.id', '=', 'a.term_id')
            ->join('taxonomies as x', 'x.id', '=', 't.taxonomy_id')
            ->where('a.content_type', $contentType)
            ->where('a.content_id', $contentId)
            ->orderBy('x.key')
            ->orderBy('a.sort_order')
            ->orderBy('a.id')
            ->get(['x.key as taxonomy_key', 'a.term_id']);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->taxonomy_key][] = (string) $row->term_id;
        }

        ksort($grouped);

        return $grouped;
    }

    /** JSON へ入れる前に、比較できる形へそろえる */
    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_array($value) ? self::sortRecursively($value) : $value;
    }

    /**
     * 連想配列はキー順に、list は順序を保つ。
     *
     * キー順を固定しないと、**同じ内容なのに違う JSON** になって「変わった」と判定される。
     * 一方 list の順序は内容そのものなので、並べ替えない。
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }
}

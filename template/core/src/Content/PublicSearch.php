<?php

declare(strict_types=1);

namespace AgentCms\Core\Content;

use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Builder;

/**
 * 公開側の検索（P1 ④）。
 *
 * ## 口を 1 か所に閉じる
 *
 * いまは `LIKE %…%` である。**速さが問題になった日に FULLTEXT へ移す**
 * （`docs/BACKLOG.md` B-10）。そのとき**移すのは中身だけ**で済むように、
 * 「どう探すか」をここへ閉じる。呼ぶ側は「探す」としか言わない。
 *
 * ## なぜ FULLTEXT で始めないか
 *
 * MySQL の既定の parser は空白区切りなので、**日本語ではほぼ何も返さない**
 * （`ngram` parser が要り、migration と既存サイトへの当て直しも要る）。
 * さらに **SQLite では動かない**ので、試験と本番で別の道を持つことになる。
 *
 * ## `%` と `_` は記号のまま（**いまは塞げない**）
 *
 * 利用者が `%` を書くと、それは LIKE の記号として働く。塞ぐには
 * `LIKE ? ESCAPE '…'` と書く必要があるが、**それは raw SQL** であり、
 * 列名を差し込む形になる（この codebase は `literal-string` でない raw SQL を許さない）。
 *
 * さらに driver 差もある — **SQLite は backslash を escape 文字として扱わず**、
 * **MySQL では `ESCAPE '\\'` が構文誤り**になった（両方とも実測）。
 *
 * ▷ **害は「`%` だけで検索すると全件が出る」に留まる**（頁繰りは効く）。
 *   **守れないものを、守っているように書かない。** 塞ぐのは FULLTEXT へ移すとき
 *   （`docs/BACKLOG.md` B-10 / B-13）。
 *
 * ## 何で探すかは schema が決める
 *
 * `search`（無ければ後方互換で `admin.search`）で宣言された列だけを見る。
 * **両方在れば直下が勝つ**（`ContentSchemaSearchTest`）。
 * **面ごとに別の宣言を持たない** — 「何で探せるか」は管理画面の都合ではない。
 *
 * ## 宣言が空なら、**絞らない**（B-14）
 *
 * 宣言を失った型では、検索語を入れても**一覧がそのまま出る**。
 * これは**画面から壊れて見えない** — 「探せない」と「全部が該当した」が
 * 同じ見た目になる。ここでは**絞らない**を選んでいる（誤って 0 件と言うより、
 * 見えている一覧を返すほうが実害が小さい）が、**見せ方は決めていない**。
 * 検索窓を出さないか、宣言が無いと言うかは `docs/BACKLOG.md` B-14 で決める。
 */
final class PublicSearch
{
    /**
     * 検索語を当てる。**語が空なら何もしない**（絞り込まない）。
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function apply(Builder $query, ContentSchema $schema, string $term): void
    {
        $term = trim($term);

        if ($term === '' || $schema->searchable === []) {
            return;
        }

        $pattern = '%'.$term.'%';

        $query->where(static function (Builder $q) use ($schema, $pattern): void {
            foreach ($schema->searchable as $column) {
                /*
                 * **framework の `orWhereLike` を使う。**
                 *
                 * 自前で `LIKE ? ESCAPE '…'` を組むと 2 つ踏む:
                 * **SQLite は backslash を escape 文字として扱わない**（守りが
                 * 黙って無効になる）し、**MySQL では `ESCAPE '\\'` が構文誤り**になった。
                 * さらに列名を SQL へ差し込む形になり、**literal でない SQL** を作る。
                 *
                 * driver ごとの違いは framework が既に知っている。**知っている側に任せる。**
                 */
                $q->orWhereLike($column, $pattern);
            }
        });
    }
}

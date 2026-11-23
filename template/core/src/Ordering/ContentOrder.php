<?php

declare(strict_types=1);

namespace AgentCms\Core\Ordering;

use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Builder;

/**
 * 「どの順で見えるか」を **1 か所に閉じる**（P1 ②）。
 *
 * ## 面ごとに順を書かない
 *
 * 公開一覧・管理一覧・並べ替えの画面が別々の順で並べると、
 * **利用者が並べたものと、実際に出るものが食い違う**。
 * 手で並べ替える機能は、その食い違いが**そのまま事故**になる
 * （管理画面で 3 番目に置いた記事が、公開では 5 番目に出る）。
 *
 * ## 第一キーだけを足す
 *
 * `sort_order` を**先頭に差し込むだけ**で、第二キー以降は呼ぶ側のまま。
 * 既存の行は全部 0 なので、**並べ替えを 1 度もしていないサイトでは
 * いままでと同じ並び**になる。
 *
 * ## 列が無いときは、黙って今までの順
 *
 * 更新を当てる前（code は新しいが migration がまだ）でも**一覧は落とさない**。
 * ゴミ箱（`TrashReadinessResolver`）は「分からない」を通すと
 * **捨てた投稿が公開へ出る**ので厳しく止めるが、こちらは
 * **並びが今までのままになるだけで、出てはいけないものは 1 件も出ない**。
 * 危険の重さが違うので、扱いも変える。
 */
final class ContentOrder
{
    /**
     * 一覧の並びに、手で決めた順を第一キーとして差し込む。
     *
     * **呼ぶ側の `orderBy` より先に呼ぶこと。** あとから呼ぶと第二キーになり、
     * 手で並べた順が効かなくなる。
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function apply(Builder $query, string $table): void
    {
        if (! app(SortOrderReadiness::class)->supports($table)) {
            return;
        }

        $query->orderBy($query->getModel()->qualifyColumn(SortOrderColumn::NAME));
    }

    /**
     * 実体化するときの並び（= **いま見えている順**）。
     *
     * 並べ替えは「見えている列を組み替える」操作なので、
     * **組み替える前の列が何であるかが決まっていなければならない**。
     *
     * 第二キーに公開日を使うのは、**公開側の見え方が成果物だから**である。
     * 公開日を持たない型（Builder で作った最小の型など）では id の新しい順に落とす。
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function visible(Builder $query, ContentSchema $schema): void
    {
        $model = $query->getModel();

        $query->orderBy($model->qualifyColumn(SortOrderColumn::NAME));

        if ($schema->hasField('published_at')) {
            $query->orderByDesc($model->qualifyColumn('published_at'));
        }

        // **同値で止めない。** id まで入れて、並びが一意に決まるようにする
        $query->orderByDesc($model->qualifyColumn($model->getKeyName()));
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Ordering\SortOrderColumn;
use AgentCms\Core\Ordering\SortOrderReadiness;
use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 投稿の手動並べ替え（P1 ②）。
 *
 * ## 全件を送らせない（`term.reorder` と違えた 1 点）
 *
 * 分類項目の並べ替えは**兄弟全件**を送らせる。兄弟が数個だからで、
 * あの契約は「画面に出ていない archived の兄弟を上書きしない」ために在る。
 *
 * **投稿は 1000 件になり得る。** 同じ契約を課すと、頁繰りのある一覧では
 * **頁の中だけ**を送ることになり、まさにその「画面に出ていない行の上書き」が起きる。
 * だから送るのは **「どれを（`id`）、どれの直後へ（`afterId`）」** の 2 つだけにした。
 * `afterId` が `null` なら先頭へ。**頁をまたいでも成立する。**
 *
 * ## 最初の 1 回だけ、全行に番号が付く
 *
 * 既存の行は全部 `sort_order = 0` である。**0 同士では順序が決まらない**ので、
 * 1 件動かすときに**いま見えている順**（`ContentOrder::visible()`）をそのまま
 * 0 から連番にする。これをしないと、**動かした 1 件以外の順序が未定義**になる。
 *
 * この実体化は**この操作の中で自然に起きる** — 毎回「いまの順を読み、組み替え、
 * 変わった行だけ書く」からである。初回はすべての行が変わるので全行が書かれ、
 * 2 回目からは**動かした範囲だけ**が書かれる。
 *
 * ## 書くのは主キーで
 *
 * `sort_order` の範囲で UPDATE すると、その index に **gap lock** が乗り、
 * 無関係な行の書き込みを待たせ得る（分類の割り当てで実際に踏んだ）。
 * **変わった行の id を並べて、主キーで書く。**
 *
 * ## 型をまたがない
 *
 * 番号はその型の中だけで閉じる。別の型の行は 1 つも読まないし、書かない。
 */
final class ReorderContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly SortOrderReadiness $readiness,
    ) {}

    public function operationId(): string
    {
        return 'content.reorder';
    }

    /** 掲載順を変えるだけで、**何も失われない**（また動かせる） */
    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  mixed  $afterId  **外部入力なので `?int` と決めつけない。**
     *                          `null` / 未指定は「先頭へ」。それ以外で整数として読めなければ断る
     */
    public function execute(string $type, int $id, mixed $afterId, OperationContext $context, mixed $direction = null): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        if (! $this->resolver->knows($type)) {
            return $this->refuse($context, Result::notFound("コンテンツタイプ [{$type}] は存在しません"), $type);
        }

        $schema = $this->resolver->schema($type);

        if (! EditorialCollections::isEditorial($schema)) {
            // メディアライブラリや設定は「掲載順」を持たない
            return $this->refuse($context, Result::notFound("[{$type}] は並べ替えできる投稿タイプではありません"), $type);
        }

        if (! $this->readiness->supports($schema->table)) {
            /*
             * **「まだできない」と「できない」を混ぜない。**
             * 列が無いのは更新の当て忘れであって、型の性質ではない。
             */
            return $this->refuse($context, Result::conflict(
                "[{$type}] はまだ並べ替えに対応していません（`php artisan migrate` を実行してください）",
            ), $type);
        }

        /*
         * **曖昧な入力を黙って解釈しない**（F-39）。
         *
         * `"abc"` や `true` を `(int)` で潰すと、**`true` は 1 になって
         * 「id 1 の直後へ」に化ける** — 頼んでいない場所へ動く。
         * `"3.9"` の切り詰めも同じ理由で断る（3 と 4 のどちらのつもりかは分からない）。
         *
         * `null` と未指定は別扱いにしない（どちらも「前に何も無い場所」= 先頭へ）。
         */
        // 管理画面の上下移動は全体の現在位置で判断する。ページ内の二行目を全体先頭へ飛ばさない。
        if ($direction !== null && (! is_string($direction) || ! in_array($direction, ['up', 'down'], true) || $afterId !== null)) {
            return $this->refuse($context, Result::validationError([
                'direction' => ['上下移動は up/down のどちらかだけを指定してください。'],
            ]), $type);
        }

        $after = null;

        if ($afterId !== null) {
            $after = self::entryId($afterId);

            if ($after === null) {
                return $this->refuse($context, Result::validationError(
                    ['afterId' => ['afterId は投稿の id（整数）で指定してください']],
                ), $type);
            }
        }

        if ($after === $id) {
            return $this->refuse($context, Result::validationError(
                ['afterId' => ['自分自身の直後へは動かせません']],
            ), $type);
        }

        /** @var class-string<Model> $class */
        $class = $this->resolver->modelClass($type);

        $result = DB::transaction(fn (): Result => $this->apply($class, $schema, $id, $after, $type, $direction), 3);

        return $this->outcome->record(
            $this, $context, $result,
            resourceType: $type,
            resourceId: (string) $id,
            metadata: ['afterId' => $after, 'direction' => $direction],
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function apply(string $class, ContentSchema $schema, int $id, ?int $afterId, string $type, ?string $direction = null): Result
    {
        $query = $class::query();
        ContentOrder::visible($query, $schema);

        /** @var list<int> $ordered いま見えている順の id */
        $ordered = array_values(array_map(intval(...), $query->lockForUpdate()->pluck((new $class)->getKeyName())->all()));

        $from = array_search($id, $ordered, true);

        if ($from === false) {
            return Result::notFound("[{$type}] に id [{$id}] の投稿がありません");
        }

        if ($afterId !== null && ! in_array($afterId, $ordered, true)) {
            return Result::notFound("[{$type}] に id [{$afterId}] の投稿がありません");
        }

        if ($direction !== null) {
            $target = $direction === 'up' ? $from - 1 : $from + 1;
            if ($target < 0 || $target >= count($ordered)) {
                return Result::success(['order' => $ordered], ['type' => $type, 'moved' => $id, 'rewritten' => 0]);
            }
            // afterId の既存経路へ変換し、更新と監査は一つに保つ。
            $afterId = $direction === 'up' ? ($ordered[$from - 2] ?? null) : $ordered[$from + 1];
        }

        // 抜いてから差し込む。**抜いたあとの位置**を見るので、前後どちらへ動かしても同じ式になる
        array_splice($ordered, $from, 1);

        $to = $afterId === null
            ? 0
            : (int) array_search($afterId, $ordered, true) + 1;

        array_splice($ordered, $to, 0, [$id]);

        $changed = $this->write($class, $ordered);

        return Result::success(
            ['order' => $ordered],
            ['type' => $type, 'moved' => $id, 'afterId' => $afterId, 'rewritten' => $changed],
        );
    }

    /**
     * 新しい番号を書く。**変わった行だけ・主キーで。**
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $ordered
     * @return int 書き直した行数
     */
    private function write(string $class, array $ordered): int
    {
        $model = new $class;
        $key = $model->getKeyName();

        /** @var array<int, int> $current id => いまの番号 */
        $current = $class::query()->pluck(SortOrderColumn::NAME, $key)
            ->map(intval(...))->all();

        /** @var array<int, list<int>> $byOrder 新しい番号 => その番号にする id */
        $byOrder = [];

        foreach ($ordered as $position => $entryId) {
            if (($current[$entryId] ?? null) !== $position) {
                $byOrder[$position][] = $entryId;
            }
        }

        $changed = 0;

        foreach ($byOrder as $position => $ids) {
            /*
             * **主キーで書く。** `sort_order` の範囲で更新すると、その index に
             * gap lock が乗り、無関係な行の書き込みを待たせ得る。
             */
            $changed += $class::query()->whereIn($key, $ids)
                ->update([SortOrderColumn::NAME => $position]);
        }

        return $changed;
    }

    /** 整数として**そのまま**読めるか。読めなければ null（丸めない・切り詰めない） */
    private static function entryId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function refuse(OperationContext $context, Result $result, string $type): Result
    {
        return $this->outcome->record($this, $context, $result, resourceType: $type);
    }
}

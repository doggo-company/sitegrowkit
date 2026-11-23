<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashReadinessResolver;
use AgentCms\Core\Trash\TrashScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `relation` / `relations` の候補（Phase 9.5-C / ADR-0017 §6）。
 *
 * ## 面を増やさない
 *
 * **管理画面の内部 route だけ**。公開 API にも MCP にも足さない。
 * 実体は既存の `content.list`（Read / `content.read`）で、
 * 新しい operation も新しい capability も作っていない。
 *
 * ## 全件を初期 HTML へ入れない
 *
 * 候補は投稿数だけ増える。編集画面を開いた時点で全部埋め込むと、
 * 投稿が増えるほど画面が重くなる。**検索付きで少しずつ**取りに来る。
 *
 * ## 対象は共通境界から解決する
 *
 * 投稿タイプ名・module 名・table 名を書き写さない。実際に CRUD できる
 * collection かどうかは `ContentTypeResolver` が決める（画面・API・MCP と同じ母集団）。
 */
final class RelationOptionController
{
    /** 1 度に返す最大件数。要求が大きくてもここで頭を打つ */
    public const MAX_PER_PAGE = 20;

    /** 検索語の長さ上限（無制限に長い LIKE を投げさせない） */
    private const SEARCH_MAX = 100;

    public function __invoke(
        Request $request,
        string $type,
        ListContent $list,
        ContentTypeResolver $types,
        OperationPolicy $policy,
    ): JsonResponse {
        // 参照先に選べない型は **存在しない**ものとして扱う（型の一覧を漏らさない）
        if (! $types->knows($type) || $types->schema($type)->kind !== 'collection') {
            abort(404);
        }

        $context = $this->context($request);

        if (! $policy->allows('content.list', OperationClassification::Read, $context)) {
            abort(403, $policy->denialReason('content.list', $context));
        }

        /*
         * `ids` が **付いているか**で経路を決める（中身が空かどうかではない）。
         *
         * 不正な ID だけを送られたときに一覧へ落ちると、「絞ったつもりが全件」に
         * なる。要求した経路のまま 0 件を返すほうが、起きたことと表示が一致する。
         */
        $wantsIds = $request->query('ids') !== null;
        $ids = $wantsIds ? $this->requestedIds($request) : [];
        $titleKey = $types->schema($type)->titleKey();

        /*
         * `ids` 指定は **今選ばれているものの名前を引く**ための経路。
         *
         * 候補一覧は先頭 20 件しか返さないので、それより後ろにある既存の選択は
         * 名前が分からず「ID 42」としか出せない。選んだ本人が何を選んだのか
         * 読めないのは不親切なので、選択中の ID だけを名前解決できるようにする。
         * 件数は候補と同じ上限で頭を打つ。
         */
        $items = match (true) {
            $wantsIds && $ids === [] => [],
            $wantsIds => $this->byIds($types, $type, $ids),
            default => $this->bySearch($list, $type, $context, $request),
        };

        return response()->json([
            'options' => array_map(
                static fn (array $item): array => [
                    'id' => (int) $item['id'],
                    // 表示名は schema が決める。投稿タイプごとの分岐を書かない
                    'title' => (string) ($titleKey === null ? $item['id'] : ($item[$titleKey] ?? $item['id'])),
                    // 既に選ばれている相手がゴミ箱にあるか（候補には現れない）
                    'trashed' => (bool) ($item['trashed'] ?? false),
                ],
                $items,
            ),
            'limit' => self::MAX_PER_PAGE,
        ]);
    }

    /**
     * `?ids=1,2,3`。数値以外は捨てず、**そもそも数値だけを受け取る**形にする。
     *
     * @return list<int>
     */
    private function requestedIds(Request $request): array
    {
        $raw = (string) $request->query('ids', '');

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (array_slice(explode(',', $raw), 0, self::MAX_PER_PAGE) as $value) {
            $value = trim($value);

            if (ctype_digit($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function byIds(ContentTypeResolver $types, string $type, array $ids): array
    {
        /*
         * **ゴミ箱の中も引く**（Phase 9.8 / STAGE 21B・SPEC §E）。
         *
         * 候補（`bySearch`）はゴミ箱を除く — 新しくゴミ箱の中身を選ばせない。
         * だが**既に選ばれているもの**は話が別で、相手がゴミ箱へ入ったからといって
         * 名前が引けなくなると、画面には「ID 42」としか出ない。何を選んだのか
         * 読めないまま保存させることになる。名前は引けるようにしておき、
         * 「ゴミ箱にあります」と添えるのは UI の仕事にする。
         *
         * 関連そのものは消えていない（trash は参照を壊さない — OG4）。
         */
        $query = $types->newModel($type)->newQuery();

        // ゴミ箱に対応している型でだけ既定除外を外す。対応していない型には
        // そもそも trashed の行が存在し得ないので、外す必要も無い
        if (app(TrashReadinessResolver::class)->supports($type)) {
            $query->withoutGlobalScope(TrashScope::NAME);
        }

        $items = [];

        foreach ($query->whereIn('id', $ids)->limit(self::MAX_PER_PAGE)->get() as $model) {
            $row = $model->toArray();
            // 機械が読める印。表示の仕方は画面が決める
            $row['trashed'] = $model->getAttribute(TrashColumns::DELETED_AT) !== null;
            $items[] = $row;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bySearch(ListContent $list, string $type, OperationContext $context, Request $request): array
    {
        $result = $list->execute(
            $type,
            $context,
            mb_substr(trim((string) $request->query('q', '')), 0, self::SEARCH_MAX),
            1,
            self::MAX_PER_PAGE,
        );

        if ($result->status === OperationStatus::Forbidden) {
            abort(403, $result->message ?? '');
        }

        if (! $result->isSuccess()) {
            abort(500, $result->message ?? '');
        }

        return array_values($result->data['items'] ?? []);
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name);
    }
}

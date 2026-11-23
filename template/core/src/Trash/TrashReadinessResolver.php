<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Throwable;

/**
 * 投稿タイプごとのゴミ箱対応状況を **1 か所で**解決する（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2）。
 *
 * ## 面ごとに判定を書かない
 *
 * 一覧・詳細・公開・API・MCP・検索・件数・sitemap・関連候補、すべてが同じ答えを要る。
 * 9.7 の `ContentTypeLifecyclePolicy` と同じ置き方にして、判定器を 2 つ作らない。
 *
 * ## boot では読まない
 *
 * route を登録している最中に DB を読むと、DB が落ちているだけで**全 URL が消える**。
 * 判定は要求を受けてから行う（9.7 §0.2-(1) と同じ線）。この class は
 * **要求ごとに 1 つ**（scoped）で、型ごとに 1 回だけ `hasColumn` を投げる。
 *
 * ## 例外を握りつぶさない
 *
 * 確かめられなかったことを「対応していない」に倒すと、
 * **ゴミ箱にある投稿を公開してしまう**。分からないときは分からないと答える。
 */
final class TrashReadinessResolver
{
    /** @var array<string, TrashReadiness> 要求のあいだだけ持つ。型ごとに 1 回 */
    private array $resolved = [];

    /** 検査を何回投げたか（「1 要求 1 回」を測るためだけに持つ） */
    private int $lookups = 0;

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly SchemaBuilder $schema,
    ) {}

    public function readiness(string $type): TrashReadiness
    {
        if (isset($this->resolved[$type])) {
            return $this->resolved[$type];
        }

        return $this->resolved[$type] = $this->detect($type);
    }

    /** ゴミ箱として扱える型か（例外を投げずに尋ねたいとき） */
    public function supports(string $type): bool
    {
        return $this->readiness($type) === TrashReadiness::Ready;
    }

    /**
     * ゴミ箱そのものを扱う経路の入口で呼ぶ。
     *
     * **read の入口では呼ばない** — `Unmigrated` の型は通常どおり読めるからである。
     *
     * @throws TrashNotSupported 列が無いと確認できた
     * @throws TrashReadinessUnavailable 確認できなかった
     */
    public function assertUsable(string $type): void
    {
        match ($this->readiness($type)) {
            TrashReadiness::Ready => null,
            TrashReadiness::Unmigrated => throw new TrashNotSupported($type),
            TrashReadiness::Indeterminate => throw new TrashReadinessUnavailable($type),
        };
    }

    /** 検査の実行回数（テストと診断のためだけに公開する） */
    public function lookupCount(): int
    {
        return $this->lookups;
    }

    private function detect(string $type): TrashReadiness
    {
        /*
         * **判定の全体を守る。**
         *
         * 落ちるのは列の検査だけではない。その手前で effective schema を組み立てる
         * ときに Field Group の overlay が DB を読み、そこで落ちることがある。
         * 素通りさせると 503 でも service-unavailable でもない 500 になり、
         * 「今は読めない」と伝える経路を丸ごと迂回してしまう。
         */
        try {
            /*
             * 分類は **ファイル宣言だけ**で決める（`declared()` は DB を読まない）。
             * overlay は項目を足すだけで **型を増やさない**ので、型の集合は同じである。
             * こうすると、投稿タイプでない型（media / contact / site）では
             * DB を 1 度も見ずに「対象外」と答えられる（SPEC §A）。
             * 判定そのものは 9.7 の `EditorialCollections` に任せ、型名を並べない。
             */
            $schema = $this->schemas->declared()[$type] ?? null;

            if ($schema === null || ! EditorialCollections::isEditorial($schema)) {
                return TrashReadiness::Unmigrated;
            }

            $this->lookups++;

            return $this->schema->hasColumn($schema->table, TrashColumns::DELETED_AT)
                ? TrashReadiness::Ready
                : TrashReadiness::Unmigrated;
        } catch (Throwable) {
            // 原因は外へ出さない。出すのは「確認できなかった」という事実だけ
            return TrashReadiness::Indeterminate;
        }
    }
}

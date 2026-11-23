<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Schema\TaxonomyDeclaration;
use InvalidArgumentException;

/**
 * 取り込み先の型の、**いまの状態**（Phase 9.14 / STAGE 27C・SPEC §C.2）。
 *
 * `plan` を見た時点と `apply` の時点でここが変わっていたら、
 * 起きることは利用者が見たものと違う。だから hash に載せる。
 *
 * 状態は `ContentTypeLifecyclePolicy` が答える。**ここで型名を並べない**
 * （面ごとに判定を書くと、1 つ書き忘れた面だけが archived な型を書けてしまう）。
 */
final class TargetTypeState
{
    /** @param list<TaxonomyDeclaration> $taxonomies */
    public function __construct(
        public readonly string $id,
        /** 定義そのものの指紋。`SchemaManifest::fingerprint()` を使う */
        public readonly string $schemaFingerprint,
        /** 投稿タイプか（media / site 設定などの facility ではないか） */
        public readonly bool $isEditorial,
        /** 新しい entry を作ってよいか。**archived な型を増やさない** */
        public readonly bool $canCreate,
        /** 公開面に出るか。非公開の型へ十年分を入れると、誰も気づかないまま埋まる */
        public readonly bool $isPublic,
        /**
         * 公開 URL の入口。
         *
         * ここが変わると、取り込んだ記事の URL が丸ごと変わる。
         * 同じ plan のつもりで別の場所へ十年分が現れることになる。
         */
        public readonly ?string $publicSlug,
        /** 一覧ページを持つか（`has_archive`） */
        public readonly bool $hasArchive,
        /** 単体ページへ到達できるか（`publicly_queryable`） */
        public readonly bool $publiclyQueryable,
        /**
         * コメントを受けられる型か。
         *
         * **関係しないときは `null`。** WXR にコメントが 1 件も無いなら、
         * 型のコメント設定を変えても取り込みの結果は変わらない。
         * `false` を入れてしまうと、**無関係な設定変更で再 plan を求める**ことになる。
         */
        public readonly ?bool $commentsEnabled,
        public readonly array $taxonomies,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $schemaFingerprint) !== 1) {
            // 壊れた値をそのまま畳むと、壊れていることに気づけない
            throw new InvalidArgumentException(
                "schemaFingerprint は 64 桁の小文字 16 進です（型 [{$id}]）。",
            );
        }

        $keys = array_map(static fn (TaxonomyDeclaration $d): string => $d->key, $taxonomies);

        if ($keys !== array_unique($keys)) {
            /*
             * 配列のキーで畳むと**後から来た方が黙って勝つ**。
             * 違う宣言を渡したのに同じ値になるのは、この検査の意味を失わせる。
             */
            throw new InvalidArgumentException(
                "型 [{$id}] に同じ分類の宣言が 2 つ以上あります。",
            );
        }
    }

    /**
     * **key だけでは足りない。** `multiple` が false になれば 2 つ目の項目は入らず、
     * `required` が true になれば項目の無い記事が入らない。
     * どちらも「何件入るか」を変えるので、宣言ごと載せる。
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        $taxonomies = [];

        foreach ($this->taxonomies as $declaration) {
            $taxonomies[$declaration->key] = [
                'multiple' => $declaration->multiple,
                'required' => $declaration->required,
            ];
        }

        ksort($taxonomies);

        return [
            'canCreate' => $this->canCreate,
            'commentsEnabled' => $this->commentsEnabled,
            'hasArchive' => $this->hasArchive,
            'isEditorial' => $this->isEditorial,
            'isPublic' => $this->isPublic,
            'publicSlug' => $this->publicSlug,
            'publiclyQueryable' => $this->publiclyQueryable,
            'schemaFingerprint' => $this->schemaFingerprint,
            'taxonomies' => $taxonomies,
        ];
    }
}

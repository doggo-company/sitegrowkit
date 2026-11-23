<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 「この投稿タイプはいま何をしてよいか」だけを答える（Phase 9.7 / SPEC §J.4）。
 *
 * **状態の判定はここ 1 か所である。**
 * サイドバー・型一覧・entry の CRUD・API・MCP・PageDocument の binding・sitemap は
 * 別々のコードから型を引いている。それぞれが `status` を見に行く実装にすると、
 * 1 つ書き忘れた面だけが archived な型を書けてしまう。
 *
 * 禁止する実装:
 * - 面ごとに middleware を hardcode する
 * - controller ごとに `if ($status === 'archived')` を書く
 * - `SchemaRegistry::declared()` の中で DB を読む（boot が DB を読むことになる）
 */
final class ContentTypeLifecyclePolicy
{
    public function __construct(
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly SchemaRegistry $schemas,
    ) {}

    /**
     * 定義が在るか（SPEC §J.4 の orphan）。
     *
     * いまは **ファイルの schema が在るか**だけを見る。`declared()` を使うので
     * DB を読まず、Field Group overlay も引かない。
     *
     * **DB 定義の型が作れるようになったら（Stage 20C / ADR-0019）、ここは
     * 「登録行が定義そのものを持っているか」も見る必要がある。**
     * その 1 行を足し忘れると、DB 定義の型がすべて orphan 扱いになる。
     */
    public function isDefined(string $contentTypeId): bool
    {
        return array_key_exists($contentTypeId, $this->schemas->declared());
    }

    /**
     * 投稿タイプか（① system facility ではないか）。
     *
     * 判定は `EditorialCollections` が持つ。ここで型名を並べない。
     */
    public function isEditorial(string $contentTypeId): bool
    {
        $schema = $this->schemas->declared()[$contentTypeId] ?? null;

        return $schema !== null && EditorialCollections::isEditorial($schema);
    }

    /** 中身を読んでよいか。archived でも **read は通す**（OG-F = (a)） */
    public function canRead(string $contentTypeId): bool
    {
        return $this->isDefined($contentTypeId)
            && $this->settings($contentTypeId)->lifecycle->isReady();
    }

    /** 既存の entry を更新してよいか */
    public function canWrite(string $contentTypeId): bool
    {
        if (! $this->isDefined($contentTypeId) || ! $this->resolver->readiness()->allowsWrites()) {
            return false;
        }

        $settings = $this->settings($contentTypeId);

        return $settings->lifecycle->isReady()
            && $settings->status === ContentTypeStatus::Active;
    }

    /** 新しい entry を作ってよいか。**archived な型を増やさない** */
    public function canCreate(string $contentTypeId): bool
    {
        return $this->canWrite($contentTypeId);
    }

    /** 管理サイドバーに出すか。archived は日常運用の導線から外す */
    public function showInMenu(string $contentTypeId): bool
    {
        if (! $this->isDefined($contentTypeId)) {
            return false;
        }

        $settings = $this->settings($contentTypeId);

        if (! $settings->lifecycle->isReady() || $settings->status !== ContentTypeStatus::Active) {
            return false;
        }

        $override = $settings->displayOverrides['show_in_menu'] ?? null;

        return is_bool($override) ? $override : true;
    }

    /** 公開routeを持つコード管理型でも、停止・準備中の型を検索へ混ぜない。 */
    public function isActive(string $contentTypeId): bool
    {
        if (! $this->isDefined($contentTypeId)) {
            return false;
        }
        $settings = $this->settings($contentTypeId);

        return $settings->lifecycle->isReady() && $settings->status === ContentTypeStatus::Active;
    }

    /**
     * 公開面に出すか。
     *
     * **archived は 404**（OG-D = (a)）。`public` が true でも
     * 公開スラッグが無ければ URL が無いので false（設定が嘘にならないようにする）。
     */
    public function isPublic(string $contentTypeId): bool
    {
        if (! $this->isActive($contentTypeId)) {
            return false;
        }
        $settings = $this->settings($contentTypeId);

        return $settings->flag('public') && $settings->publicSlug !== null;
    }

    /**
     * コメントを使ってよい型か。**既定は OFF**（SPEC §L）。
     *
     * **① system facility は恒久的に false で、設定を読みに行かない。**
     * メディアライブラリやサイト設定にコメントは付かない。行が在っても見ない。
     *
     * これは「型がコメント機能を使える状態か」だけを答える。
     * 受け付ける仕組みそのものは P0-9 の仕事である。
     */
    public function commentsEnabled(string $contentTypeId): bool
    {
        if (! $this->isEditorial($contentTypeId)) {
            return false;
        }

        if (! $this->isDefined($contentTypeId)) {
            return false;
        }

        $settings = $this->settings($contentTypeId);

        if (! $settings->lifecycle->isReady() || $settings->status !== ContentTypeStatus::Active) {
            return false;
        }

        return $settings->commentsEnabled;
    }

    /**
     * 読み model 用のまとめ。**画面ごとに真偽値を組み立て直さない**。
     *
     * @return array<string, bool>
     */
    public function capabilities(string $contentTypeId): array
    {
        return [
            'canRead' => $this->canRead($contentTypeId),
            'canWrite' => $this->canWrite($contentTypeId),
            'canCreate' => $this->canCreate($contentTypeId),
            'showInMenu' => $this->showInMenu($contentTypeId),
            'isPublic' => $this->isPublic($contentTypeId),
            'commentsEnabled' => $this->commentsEnabled($contentTypeId),
        ];
    }

    public function settings(string $contentTypeId): ContentTypeSettings
    {
        return $this->resolver->for($contentTypeId);
    }

    public function readiness(): ContentTypeReadiness
    {
        return $this->resolver->readiness();
    }

    /** 保存されている行を直接読みたい面（orphan の診断）のための入口 */
    public function settingsResolver(): ContentTypeSettingsResolver
    {
        return $this->resolver;
    }
}

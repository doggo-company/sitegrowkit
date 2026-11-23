<?php

declare(strict_types=1);

namespace AgentCms\Core\Admin;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * CMS が作った Content Type の管理画面 CRUD（Phase 9.4 / STAGE #17A）。
 *
 * **Content Type ごとに Controller を増やさない。** 扱う type は
 * **route 名**（`admin.<type>.<action>`）から受け取り、あとは既存の
 * `SchemaResourceController` の実装をそのまま使う。
 * 一覧・フォーム・検証・確認・監査は news / works と同じ経路を通る。
 *
 * type は **URL のパスパラメータではない**。route 名は登録側が決めるので、
 * 利用者の入力が type になる経路が無く、未登録 type を外から差し込めない。
 * 念のためここでも文法検査と登録簿の照合を行う。
 */
final class ManagedContentController extends SchemaResourceController
{
    /** `admin.<type>.<action>` から type を取り出す */
    private const NAME_PATTERN = '/\Aadmin\.([a-z][a-z0-9_]{1,39})\.(index|create|store|edit|preview|autosave|update|destroy|trash|restore|duplicate|revisions|revision\-restore)\z/';

    private readonly string $type;

    public function __construct(Router $router, private readonly ContentTypeRegistry $types)
    {
        $name = $router->current()?->getName() ?? '';

        if (preg_match(self::NAME_PATTERN, $name, $matches) !== 1 || ! $this->types->has($matches[1])) {
            // route 登録時に必ず規約どおりの名前が付く。ここに来るのは配線の誤りだけ
            throw new RuntimeException('管理対象の Content Type を解決できませんでした');
        }

        $this->type = $matches[1];
    }

    protected function schemaId(): string
    {
        return $this->type;
    }

    /** @return class-string<Model&SchemaModel> */
    protected function modelClass(): string
    {
        /** @var class-string<Model&SchemaModel> $class */
        $class = $this->types->modelFor($this->type);

        return $class;
    }
}

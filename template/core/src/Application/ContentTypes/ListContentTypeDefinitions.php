<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\CmsManagedContentTypes;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeReadiness;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashReadiness;
use AgentCms\Core\Trash\TrashReadinessResolver;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Throwable;

/**
 * Content Type の定義一覧（Phase 9.4 / STAGE #17A）。
 *
 * `content.types.list`（AI 向けの最小情報）とは別に、
 * **管理画面が必要とする運用情報**（保存先テーブル・管理主体・件数・状態）を返す。
 *
 * 一覧の母集団は `ContentTypeResolver::availableTypes()` — つまり
 * schema と Eloquent モデルの**両方**が揃っている型だけである。
 * 画面と API と MCP が別々の「型の一覧」を持たないようにするため、
 * ここで独自の走査規則を作らない。
 */
final class ListContentTypeDefinitions implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly SchemaRegistry $schemas,
        private readonly OperationPolicy $policy,
        private readonly Registrar $router,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly TrashReadinessResolver $trash,
    ) {}

    public function operationId(): string
    {
        return 'content.types.definitions';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $types = [];

        foreach ($this->resolver->availableTypes() as $id) {
            $schema = $this->resolver->schema($id);

            if ($schema->kind !== 'collection') {
                continue;
            }

            $types[] = $this->describe($id, $schema);
        }

        // 定義はあるのにモデルを読み込めない CMS 管理型は**隠さない**。
        // 黙って消えると「作ったのに一覧に出ない」になり、原因が追えない
        foreach (CmsManagedContentTypes::schemas($this->schemas) as $id => $schema) {
            if ($this->resolver->knows($id)) {
                continue;
            }

            $types[] = [
                'id' => $id,
                'label' => $this->lifecycle->settings($id)->labelOr($schema->label),
                'table' => $schema->table,
                'managedBy' => $schema->managedBy,
                'editable' => false,
                'fieldCount' => count($schema->fields),
                'taxonomyCount' => count($schema->taxonomies),
                'entryCount' => null,
                'adminUrl' => null,
                'status' => 'unavailable',
                'settings' => $this->lifecycle->settings($id)->toArray(),
                'capabilities' => $this->lifecycle->capabilities($id),
                'trash' => self::trashState($this->trash->readiness($id)),
            ];
        }

        /*
         * 定義が消えたのに設定行だけ残った型（orphan — SPEC §J.7）。
         *
         * **黙って捨てない。** 行を自動削除すると「なぜ設定が消えたのか」を
         * 誰も説明できなくなる。一覧に出して診断させる。
         */
        $known = array_column($types, 'id');

        foreach ($this->lifecycle->settingsResolver()->stored() as $id => $settings) {
            if (in_array($id, $known, true)) {
                continue;
            }

            $types[] = [
                'id' => $id,
                'label' => $settings->labelOr($id),
                'table' => null,
                'managedBy' => null,
                'editable' => false,
                'fieldCount' => null,
                'taxonomyCount' => null,
                'entryCount' => null,
                'adminUrl' => null,
                'status' => 'orphan_settings',
                'settings' => $settings->toArray(),
                'capabilities' => $this->lifecycle->capabilities($id),
                // 定義そのものが無いので、ゴミ箱の対応も測れない
                'trash' => self::trashState(TrashReadiness::Unmigrated),
            ];
        }

        /*
         * 並びは **menu_order → ID の辞書順**（SPEC §J.2）。
         * `menu_order = 0` は「未設定」なので既定順の後ろへ回す。
         */
        usort($types, function (array $a, array $b): int {
            $orderA = $this->menuRank($a);
            $orderB = $this->menuRank($b);

            return $orderA === $orderB
                ? strcmp((string) $a['id'], (string) $b['id'])
                : $orderA <=> $orderB;
        });

        return Result::success([
            'types' => $types,
            // 設定を読めなかったことを黙って隠さない（SPEC §J.4）
            'readiness' => match ($this->lifecycle->readiness()) {
                ContentTypeReadiness::Ready => 'ready',
                ContentTypeReadiness::Unmigrated => 'unmigrated',
                ContentTypeReadiness::Indeterminate => 'indeterminate',
            },
            'readinessMessage' => $this->lifecycle->readiness()->message(),
        ]);
    }

    /** @return array<string, mixed> */
    private function describe(string $id, ContentSchema $schema): array
    {
        $tableExists = $this->tableExists($schema->table);
        $adminUrl = $this->adminUrl($id);
        $trash = $this->trash->readiness($id);

        return [
            'id' => $id,
            'label' => $this->lifecycle->settings($id)->labelOr($schema->label),
            'table' => $schema->table,
            'managedBy' => $schema->managedBy,
            // 編集できるのは **cms 管理の投稿タイプだけ**（SPEC §B.3）。
            // 判定は EditorialCollections が持ち、画面は真偽値を受け取るだけ
            'editable' => EditorialCollections::isManageable($schema),
            'fieldCount' => count($schema->fields),
            'taxonomyCount' => count($schema->taxonomies),
            /*
             * ゴミ箱の状態が **確かめられない型のモデルは読まない**（SPEC §C.4.1.2）。
             *
             * ここは診断面である。原因を調べに来た人の前で、原因そのものに
             * 巻き込まれて落ちるわけにいかない。件数は「参考値」なので諦める。
             */
            'entryCount' => $tableExists && $trash !== TrashReadiness::Indeterminate
                ? $this->entryCount($id)
                : null,
            'adminUrl' => $adminUrl,
            'status' => match (true) {
                ! $tableExists => 'missing_table',
                $adminUrl === null => 'no_admin_route',
                default => 'ready',
            },
            // 運用状態 / 登録の進行状態は `status`（利用可能かの診断）とは別軸である（SPEC §F.1）
            'settings' => $this->lifecycle->settings($id)->toArray(),
            'capabilities' => $this->lifecycle->capabilities($id),
            'trash' => self::trashState($trash),
        ];
    }

    /**
     * ゴミ箱の対応状況（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2）。
     *
     * 機械が読む値と、人が読む説明を分けて出す。**例外文・SQL・接続情報は載せない**。
     *
     * @return array{state: string, message: string|null}
     */
    private static function trashState(TrashReadiness $readiness): array
    {
        return [
            'state' => $readiness->value,
            'message' => $readiness->message(),
        ];
    }

    /**
     * 並べ替えの順位。**`menu_order = 0`（未設定）は既定順の後ろ**（SPEC §J.2）。
     *
     * @param  array<string, mixed>  $type
     */
    private function menuRank(array $type): int
    {
        $order = $type['settings']['menuOrder'] ?? 0;

        return is_int($order) && $order > 0 ? $order : PHP_INT_MAX;
    }

    private function adminUrl(string $id): ?string
    {
        return $this->router->has("admin.{$id}.index")
            ? route("admin.{$id}.index", absolute: false)
            : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return SchemaFacade::hasTable($table);
        } catch (QueryException) {
            return false;
        }
    }

    /** 件数は「参考値」。取得できない場合は null にして画面を落とさない */
    private function entryCount(string $id): ?int
    {
        try {
            return $this->resolver->newModel($id)->newQuery()->count();
        } catch (Throwable) {
            return null;
        }
    }
}

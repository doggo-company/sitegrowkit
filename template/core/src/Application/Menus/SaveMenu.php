<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Menus;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use AgentCms\Core\Menus\MenuPresenter;
use AgentCms\Core\Menus\MenuTreeValidator;
use AgentCms\Core\Menus\MenuTreeWriter;
use Illuminate\Support\Facades\DB;

/**
 * メニューを木ごと差し替える（Phase 9.9 / STAGE 22B・SPEC §E.2・OG5）。
 *
 * ## 順序を固定する
 *
 *   1. `OperationPolicy`
 *   2. メニューの存在確認
 *   3. **版**（`lock_version`）— 送っていなければ拒否する。移行期間は無い（新しい表である）
 *   4. 形の検査（`MenuTreeValidator`）— DB を引かずに分かることを先に潰す
 *   5. 参照先の実在（`MenuTreeWriter::verifyReferences`）
 *   6. **transaction の中で版を CAS** → 木を差し替え
 *   7. `OperationAudit`
 *
 * 4 と 5 を分けているのは、**形が壊れた入力で DB を引かない**ためである。
 *
 * ## 名前の変更も同じ経路
 *
 * 「メニューの編集」は名前と木の両方を指す。別々の operation にすると
 * 版が 2 回進み、片方だけ通った状態が作れる。
 *
 * `key` と `managed_by` は**この経路では変えられない**（`$fillable` から外してある）。
 * テーマが `<x-navigation menu="header" />` と書いている以上、key は識別子であって
 * 編集対象ではない。
 */
final class SaveMenu implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly MenuTreeValidator $validator,
        private readonly MenuTreeWriter $writer,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'menu.save';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $attributes  `name` / `description`
     * @param  list<mixed>  $items  木（`parent_id` は含まない。入れ子が親子を表す）
     */
    public function execute(
        string $key,
        array $attributes,
        array $items,
        OperationContext $context,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $menu = GetMenu::find($key);

        if ($menu === null) {
            return Result::notFound("メニュー [{$key}] は存在しません");
        }

        $version = $this->version($lockVersion);

        if ($version instanceof Result) {
            return $version;
        }

        $errors = [
            ...$this->attributes($attributes),
            ...$this->validator->validate($items),
        ];

        if ($errors !== []) {
            return Result::validationError($errors);
        }

        // 形が通ってから DB を引く
        $errors = $this->writer->verifyReferences($items);

        foreach ($this->writer->foreignIds($menu, $items) as $foreign) {
            $errors['items'][] = "ほかのメニューの項目は指定できません（ID: {$foreign}）";
        }

        if ($errors !== []) {
            return Result::validationError($errors);
        }

        $result = DB::transaction(function () use ($menu, $attributes, $items, $version): Result {
            /*
             * **CAS。** 読んだ版と同じときだけ 1 つ進める。
             * 進められなければ、誰かが先に保存している。
             */
            $applied = Menu::query()
                ->whereKey($menu->getKey())
                ->where('lock_version', $version)
                ->update([
                    'name' => $attributes['name'] ?? $menu->name,
                    // 送られてきた空（null）は「消した」。送られてこなかったときだけ今のまま（6 周目: 空にして保存しても残った）
                    'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $menu->description,
                    'lock_version' => $version + 1,
                    'updated_at' => now(),
                ]);

            if ($applied === 0) {
                return Result::conflict(
                    'ほかの操作と重なったため保存できませんでした。画面を読み込み直してください。',
                );
            }

            $this->writer->write($menu, $items);

            $menu->refresh();

            $rows = MenuItem::query()
                ->where('menu_id', $menu->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return Result::success(
                ['menu' => MenuPresenter::menu($menu), 'items' => MenuPresenter::tree($rows)],
                ['key' => $menu->key, 'total' => $rows->count(), 'lock_version' => (int) $menu->lock_version],
            );
        });

        // 監査に項目の本文や URL は入れない（誰が何をしたかで足りる）
        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'menu',
            resourceId: $menu->key,
        );
    }

    /** @return int|Result 版として使える値、または拒否 */
    private function version(LockVersionInput|int|null $lockVersion): int|Result
    {
        $input = $lockVersion instanceof LockVersionInput
            ? $lockVersion
            : ($lockVersion === null ? LockVersionInput::unspecified() : LockVersionInput::of($lockVersion));

        if ($input->rejected) {
            return Result::validationError(['lock_version' => ['版の指定が正しくありません']]);
        }

        if ($input->version === null) {
            /*
             * **移行期間を設けない。** この表は 9.9 で新設したので、版を知らない
             * 呼び出し元は存在しない。ここを緩めると、あとで締める理由が無くなる。
             */
            return Result::validationError(['lock_version' => ['版の指定が必要です']]);
        }

        return $input->version;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, list<string>>
     */
    private function attributes(array $attributes): array
    {
        $errors = [];
        $name = $attributes['name'] ?? null;

        if ($name !== null && (! is_string($name) || trim($name) === '')) {
            $errors['name'][] = '名前が空です';
        }

        if (is_string($name) && mb_strlen($name) > 120) {
            $errors['name'][] = '名前が長すぎます（120 文字まで）';
        }

        $description = $attributes['description'] ?? null;

        if ($description !== null && ! is_string($description)) {
            $errors['description'][] = '説明の形が正しくありません';
        }

        foreach (['key', 'managed_by', 'lock_version'] as $immutable) {
            if (array_key_exists($immutable, $attributes)) {
                $errors[$immutable][] = 'この経路では変更できません';
            }
        }

        return $errors;
    }
}

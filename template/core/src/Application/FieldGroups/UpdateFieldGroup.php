<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaMutation;
use AgentCms\Core\Schema\SchemaMutationClassifier;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\QueryException;

/**
 * Field Group の更新（Phase 9.5-B / ADR-0016）。
 *
 * ## 危険度分類を入口へつなぐ（SPEC F-2 の解消）
 *
 * `SchemaMutationClassifier` は Phase 9.2B から存在したが、production では
 * **1 度も呼ばれていなかった**。ここが最初の呼び出し元になる。
 *
 * | 分類 | 扱い |
 * | --- | --- |
 * | `safe` | そのまま適用（項目の追加・表示名・説明・選択肢の追加） |
 * | `migration_required` | **確認ゲート**（改名・型変更・必須化。値は残るが見え方が変わる） |
 * | `destructive` | **確認ゲート**（項目の削除・選択肢の削除） |
 *
 * 値そのものは消さない（owner 決定 G3-(b)）。項目を外しても `data` JSON の中に残り、
 * **外したまま記事を保存し直しても残る**（B-32 で実測）。だから「消える」ではなく
 * 「見えなくなる」と伝える。
 *
 * ▷ **同じキーで**戻せば再び見えるのも実測どおりだが、**確認文には書かない** —
 *   キーを利用者に書かせない面（R-8A ③）では、同じ表示名で作り直しても別の項目になる。
 *   条件つきの約束は、条件を知らない人には嘘になる。
 *
 * ## 項目の同一性
 *
 * 追跡は **immutable な field id**（ULID）で行う（owner 決定 G10-(b)）。
 * key を変えても同じ項目として扱われるので、PageDocument の binding が切れない。
 */
final class UpdateFieldGroup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupWriter $writer,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeResolver $types,
        private readonly SchemaMutationClassifier $classifier,
    ) {}

    public function operationId(): string
    {
        return 'field_group.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(string $id, array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $group = $this->repository->isInstalled() ? $this->repository->find($id) : null;

        if ($group === null) {
            return $this->outcome->record($this, $context, Result::notFound('カスタムフィールドが見つかりません'),
                resourceType: 'field_group', resourceId: $id);
        }

        if (! $group->isValid()) {
            // 壊れた定義を土台に差分を取ると、実際より軽い判定になる
            return $this->outcome->record($this, $context, Result::conflict(
                'この束の定義が読めないため編集できません。定義エラーを解消してください。',
            ), resourceType: 'field_group', resourceId: $id);
        }

        $draft = new FieldGroupDraft(
            $input,
            $this->schemas,
            $this->repository,
            $this->types,
            ignoreGroupId: $id,
            currentFieldIds: $this->fieldIds($group),
        );

        if ($draft->failed()) {
            return $this->outcome->record($this, $context, Result::validationError(
                $draft->errors(), '入力内容を確認してください',
            ), resourceType: 'field_group', resourceId: $id);
        }

        $verdict = $this->classifier->compare($this->currentSchema($group), $draft->asSchema());
        $level = $verdict['level'];

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classificationFor($level),
            $context,
            /*
             * 指紋は **受け取った入力そのもの**へ束縛する。
             *
             * 正規化後の宣言には、新しい項目へ採番したばかりの ULID が混ざる。
             * それを指紋に含めると 1 回目と 2 回目で必ず値が変わり、
             * 承認しても「別の対象」と判定されて永久に保存できない。
             */
            ['id' => $id, 'input' => $input],
            "カスタムフィールド「{$group->label}」の定義を変更します",
            $this->impact($verdict['changes']),
        );

        if ($confirmation !== null) {
            return $this->outcome->record(
                $this,
                $context,
                // enum ではなく **値**で返す。画面も API も同じ文字列を読む
                Result::confirmationRequired($confirmation, [
                    'plan' => ['level' => $level->value, 'changes' => $verdict['changes']],
                ]),
                resourceType: 'field_group',
                resourceId: $id,
                metadata: ['key' => $group->key, 'level' => $level->value],
                classification: $this->classificationFor($level),
            );
        }

        try {
            $this->writer->update($id, $draft->key, $draft->label, $draft->fields, $draft->locations, $context);
        } catch (QueryException $e) {
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                '同時に別の変更があったため保存できませんでした。もう一度お試しください。',
            ), resourceType: 'field_group', resourceId: $id);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['fieldGroup' => ['id' => $id, 'key' => $draft->key, 'label' => $draft->label]]),
            resourceType: 'field_group',
            resourceId: $id,
            // 変更の**種別と件数**だけ。改名前後の値や選択肢は載せない
            metadata: [
                'key' => $draft->key,
                'level' => $level->value,
                'changeCount' => count($verdict['changes']),
                'fieldCount' => count($draft->fields),
                'locationCount' => count($draft->locations),
            ],
            classification: $this->classificationFor($level),
        );
    }

    /**
     * 危険度に応じた分類。
     *
     * `Destructive` は `ConfirmationGate` が確認を要求する分類である。
     * 改名・型変更（`migration_required`）も、見え方が変わるので同じ扱いにする。
     */
    private function classificationFor(SchemaMutation $level): OperationClassification
    {
        return $level === SchemaMutation::Safe
            ? OperationClassification::Write
            : OperationClassification::Destructive;
    }

    /**
     * @param  list<array{field: string, change: string, level: string, reason: string}>  $changes
     * @return list<string>
     */
    private function impact(array $changes): array
    {
        $impact = [];

        foreach ($changes as $change) {
            if ($change['level'] === SchemaMutation::Safe->value) {
                continue;
            }

            $impact[] = "{$change['field']}: {$change['reason']}";
        }

        if ($impact !== []) {
            /*
             * 「消える」と誤解させない。値は残る（owner 決定 G3-(b)）。
             *
             * **条件つきの約束は書かない**（B-32）。以前は「束へ戻せば再び表示されます」と
             * 書いていたが、戻して見えるのは**同じキーで戻したとき**だけである。
             * フォームの画面ではキーを利用者に書かせないので（R-8A ③）、
             * 同じ表示名で作り直しても**別の項目**になる。
             * **どの面でも必ず成り立つことだけ**を言う。
             */
            $impact[] = '入力済みの値が消えることはありません。';
        }

        return $impact;
    }

    /**
     * 編集で持ち込める field id。
     *
     * **入れ子の子まで数える**（Phase 9.5-C）。トップレベルだけ集めると、
     * 子の ID が「知らない ID」と判定されて改名のたびに採番し直され、
     * binding が切れてしまう。
     *
     * @return array<string, string> field id => key
     */
    private function fieldIds(FieldGroupRecord $group): array
    {
        return $this->collectIds($group->fields);
    }

    /**
     * @param  list<Field>  $fields
     * @return array<string, string>
     */
    private function collectIds(array $fields): array
    {
        $ids = [];

        foreach ($fields as $field) {
            if ($field->id !== null) {
                $ids[$field->id] = $field->key;
            } else {
                /*
                 * ID を持たずに作られた項目（同梱のお問い合わせフォームなど）は、
                 * 比べる側（`Field::identity()`）がキーで同じ項目と見る。ここでもキーで渡す
                 * （UX 一周 2026-09-28: 選択肢を 1 つ変えただけで、全項目が「消えて足される」と出た）。
                 */
                $ids[$field->key] = $field->key;
            }

            $ids = [...$ids, ...$this->collectIds($field->fields)];
        }

        return $ids;
    }

    private function currentSchema(FieldGroupRecord $group): ContentSchema
    {
        return new ContentSchema(
            id: 'field_group.'.$group->key,
            label: $group->label,
            kind: 'collection',
            fields: array_map(static fn (Field $f): Field => $f, $group->fields),
        );
    }
}

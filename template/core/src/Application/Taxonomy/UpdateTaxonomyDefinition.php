<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;
use AgentCms\Core\Taxonomy\TaxonomyTerm;

/**
 * 管理画面から分類の定義を編集する（Phase 9.7 / STAGE 20D・SPEC §H.2）。
 *
 * 変えられるのは **表示名・説明・階層の有無**だけである。
 * `key` は変えられない（割り当ても宣言も索引もそこを指している）。
 *
 * **階層あり → なし は、親を持つ項目があれば拒否する。** 通すと親子関係が
 * どこにも残らないまま消える。既存の `taxonomy-sync` が定義ファイル側で
 * 同じ判断をしているので、判断の形をそろえてある。
 */
final class UpdateTaxonomyDefinition implements Operation
{
    /** @var list<string> */
    public const ACCEPTED_KEYS = ['key', 'lock_version', 'label', 'description', 'hierarchical'];

    private const LABEL_MAX = 60;

    private const DESCRIPTION_MAX = 200;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, OperationContext $context): Result
    {
        $unknown = array_values(array_diff(array_keys($input), self::ACCEPTED_KEYS));

        if ($unknown !== []) {
            return $this->outcome->record($this, $context, Result::validationError([
                'key' => ['受け付けられない項目が含まれています（'.implode('、', $unknown).'）'],
            ]), resourceType: 'taxonomy');
        }

        $key = is_string($input['key'] ?? null) ? $input['key'] : '';
        $guard = TaxonomyDefinitionGuard::for($this, $this->policy, $this->outcome, $key, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $taxonomy = $guard->taxonomy;
        $errors = [];

        $label = TaxonomyText::line($input['label'] ?? null, self::LABEL_MAX);
        $rawDescription = $input['description'] ?? null;
        $description = TaxonomyText::line($rawDescription, self::DESCRIPTION_MAX);
        $hierarchical = ($input['hierarchical'] ?? $taxonomy->hierarchical) === true;

        if ($label === null) {
            $errors['label'][] = '表示名は 1〜'.self::LABEL_MAX.' 文字で入力してください（改行・制御文字は使えません）';
        }

        if ($rawDescription !== null && $rawDescription !== '' && $description === null) {
            $errors['description'][] = '説明は '.self::DESCRIPTION_MAX.' 文字までで入力してください（改行・制御文字は使えません）';
        }

        if ($errors !== []) {
            return $this->outcome->record($this, $context, Result::validationError($errors),
                resourceType: 'taxonomy', resourceId: $key);
        }

        // 階層あり → なし。親を持つ項目があるなら通さない
        if ($taxonomy->hierarchical && ! $hierarchical) {
            $parented = TaxonomyTerm::query()
                ->where('taxonomy_id', $taxonomy->id)
                ->whereNotNull('parent_id')
                ->count();

            if ($parented > 0) {
                return $this->outcome->record(
                    $this,
                    $context,
                    Result::conflict(
                        "親を持つ項目が {$parented} 件あるため、階層なしへは変更できません。".
                        '先に親子関係を解消してください。',
                    ),
                    resourceType: 'taxonomy',
                    resourceId: $key,
                    metadata: ['parentedTerms' => $parented],
                );
            }
        }

        $expected = is_numeric($input['lock_version'] ?? null) ? (int) $input['lock_version'] : -1;

        $applied = $this->writer->update($taxonomy->id, [
            'label' => $label,
            'description' => $description,
            'hierarchical' => $hierarchical,
        ], $expected);

        if (! $applied) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作でこの分類が更新されました。読み込み直してからやり直してください'),
                resourceType: 'taxonomy',
                resourceId: $key,
                metadata: ['expectedVersion' => $expected, 'actualVersion' => $this->writer->lockVersionOf($taxonomy->id)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['taxonomy' => ['key' => $key, 'lockVersion' => $expected + 1]]),
            resourceType: 'taxonomy',
            resourceId: $key,
            // **変更のあった項目名まで**（値は入れない）
            metadata: ['changed' => ['label', 'description', 'hierarchical']],
        );
    }
}

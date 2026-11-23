<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;
use AgentCms\Core\Taxonomy\TaxonomyKey;

/**
 * 管理画面から分類を作る（Phase 9.7 / STAGE 20D・SPEC §H.1）。
 *
 * **key は作ったあと変えられない。** 投稿タイプの内部 ID と同じ理由で、
 * key は割り当て・宣言・索引が指す先そのものである。
 * 文法の判定は `TaxonomyKey` が単一情報源で、ここに別の正規表現を書かない。
 */
final class CreateTaxonomyDefinition implements Operation
{
    /** @var list<string> */
    public const ACCEPTED_KEYS = ['key', 'label', 'description', 'hierarchical'];

    /** 通し番号の接頭辞（`-` はキーに使えないので `_`） */
    private const NUMBERED_PREFIX = 'taxonomy_';

    private const LABEL_MAX = 60;

    private const DESCRIPTION_MAX = 200;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly TaxonomyDefinitionRegistry $definitions,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.create';
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
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $unknown = array_values(array_diff(array_keys($input), self::ACCEPTED_KEYS));

        if ($unknown !== []) {
            return $this->reject($context, ['key' => ['受け付けられない項目が含まれています（'.implode('、', $unknown).'）']]);
        }

        $errors = [];
        $key = is_string($input['key'] ?? null) ? $input['key'] : '';
        $label = TaxonomyText::line($input['label'] ?? null, self::LABEL_MAX);
        $description = TaxonomyText::line($input['description'] ?? null, self::DESCRIPTION_MAX);
        $hierarchical = ($input['hierarchical'] ?? false) === true;

        /*
         * **名前だけで作れるようにする**（WP 一周 6 周目 2026-09-03）。
         *
         * これまでは「分類を追加」の最初の欄が英字必須のキーで、
         * 表示名に「診療科目」と入れても**そこで止まっていた**。
         * 製品は他の場所では機械の名前を書かせない方針で揃えている
         * （投稿の URL は日付から・ページの URL は空でよい・
         * 分類の項目の綴りも自動）。ここだけが残っていた。
         *
         * 🔴 **キーは URL に出る**（`/blog/{キー}/{項目}`）ので、
         * 英字の名前からは名前どおりに作る。日本語だけの名前からは作れないので
         * `taxonomy_1` へ倒す。**作ったあと変えられないのはこれまでどおり**で、
         * 画面が作る前に直せることと URL に出ることを言う。
         *
         * 自分で書いたキーには触らない。**形が違うときも黙って直さない**
         * （直すと「書いたものと違うキーで作られた」になる）。
         */
        if ($key === '' && $label !== null) {
            $key = $this->deriveKey($label);
        }

        if (! TaxonomyKey::isValid($key)) {
            $errors['key'][] = '分類キーの形式が不正です（'.TaxonomyKey::requirement().'）';
        }

        if ($label === null) {
            $errors['label'][] = '表示名は 1〜'.self::LABEL_MAX.' 文字で入力してください（改行・制御文字は使えません）';
        }

        if (($input['description'] ?? null) !== null && $description === null && $input['description'] !== '') {
            $errors['description'][] = '説明は '.self::DESCRIPTION_MAX.' 文字までで入力してください（改行・制御文字は使えません）';
        }

        // **code 管理の定義とも重ならない。** ファイル側に同じ key があると
        // taxonomy-sync が衝突として止まり、どちらも直せなくなる
        if ($errors === [] && $this->definitions->has($key)) {
            $errors['key'][] = "分類キー [{$key}] は定義ファイルが使っています";
        }

        if ($errors === [] && Taxonomy::query()->where('key', $key)->exists()) {
            $errors['key'][] = "分類キー [{$key}] は既に使われています";
        }

        if ($errors !== []) {
            return $this->reject($context, $errors);
        }

        $id = $this->writer->create($key, (string) $label, $description, $hierarchical);

        if ($id === null) {
            return $this->outcome->record($this, $context, Result::conflict('同じ分類キーが同時に作られました。読み込み直してからやり直してください'),
                resourceType: 'taxonomy', resourceId: $key);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['taxonomy' => ['key' => $key, 'id' => $id, 'lockVersion' => 1]]),
            resourceType: 'taxonomy',
            resourceId: $key,
            metadata: ['hierarchical' => $hierarchical],
        );
    }

    /**
     * 表示名からキーを決める（6 周目 2026-09-03）。
     *
     * 名前から作れて、まだ空いていればそれを使う（`Press Release` → `press_release`）。
     * 作れないとき・埋まっているときは、空いている**通し番号**へ倒す。
     *
     * 定義ファイル側（`content/taxonomies/*.json`）とも重ならないように見る。
     * ファイルと同じキーを作ると `taxonomy-sync` が衝突で止まり、どちらも直せなくなる。
     */
    private function deriveKey(string $label): string
    {
        $fromLabel = TaxonomyKey::suggestFrom($label);

        if ($fromLabel !== '' && ! $this->keyTaken($fromLabel)) {
            return $fromLabel;
        }

        for ($n = 1; $n <= 1000; $n++) {
            $candidate = self::NUMBERED_PREFIX.$n;

            if (! $this->keyTaken($candidate)) {
                return $candidate;
            }
        }

        // ここまで来たら形の検証で弾かれる（推測で作らない）
        return '';
    }

    private function keyTaken(string $key): bool
    {
        return $this->definitions->has($key)
            || Taxonomy::query()->where('key', $key)->exists();
    }

    /** @param  array<string, list<string>>  $errors */
    private function reject(OperationContext $context, array $errors): Result
    {
        return $this->outcome->record($this, $context, Result::validationError($errors), resourceType: 'taxonomy');
    }
}

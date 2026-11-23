<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Document\AccessibilityCheck;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\JsonPatch;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use InvalidArgumentException;

/**
 * PageDocument の編集（Phase 9.1D・owner 決定 G22）。
 *
 * 受け取るのは **semantic command**（`component.update` 等）で、
 * 内部では JSON Patch へ落として適用する。AI に JSON Pointer を書かせない。
 *
 * - 変更は常に **draft version** に対して行う（公開中の版は変わらない）
 * - `expectedVersion` が合わなければ `conflict`（silent overwrite を防ぐ）
 * - `component.delete` は **DESTRUCTIVE**。ConfirmationGate を通る
 */
final class PatchPageDocument implements Operation
{
    private const COMMANDS = [
        'component.insert', 'component.update', 'component.move',
        'component.duplicate', 'component.delete',
        /*
         * **切り離し**（Phase 9.6 / P0-C7・SPEC §4.5）。
         *
         * 再利用 component への参照を外し、その時点の部分木をこの node の中へ写す。
         * **document を変える操作なので、独立した口を作らずここへ足す** —
         * 別の口を持つと、validator・確認ゲート・監査・楽観ロックの 4 つを
         * 二重化するか、どれかを穴にするかの二択になる（C6a の「取り消しも同じ口から
         * 入る」と同じ原則）。
         */
        'component.detach',
    ];

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly PageRepository $pages,
        private readonly ComponentRegistry $components,
        private readonly DocumentValidator $validator,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly AccessibilityCheck $accessibility,
    ) {}

    public function operationId(): string
    {
        return 'page.document.patch';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** 実際に実行する command の分類（delete だけ破壊的） */
    public function classificationFor(string $command): OperationClassification
    {
        return $command === 'component.delete'
            ? OperationClassification::Destructive
            : OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function execute(int $pageId, array $command, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $operation = (string) ($command['operation'] ?? '');

        if (! in_array($operation, self::COMMANDS, true)) {
            return $this->outcome->record($this, $context, Result::validationError(['operation' => ["操作 [{$operation}] は使えません"]]), resourceType: 'page', resourceId: (string) $pageId);
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $this->outcome->record($this, $context, $page, resourceType: 'page', resourceId: (string) $pageId);
        }

        if (! $page->isDocumentManaged()) {
            return $this->record($context, Result::conflict('このページはコードで管理されているため、ここからは編集できません'), $page->id, $operation);
        }

        $draft = $this->pages->draft($page);

        /*
         * **編集中の版が居ないなら、書かない。** 黙って作り直すと、
         * 失われた内容の代わりに**別物の上へ書き足させる**ことになる（§4.11）。
         */
        if ($draft instanceof Result) {
            return $this->record($context, $draft, $page->id, $operation);
        }

        $conflict = $this->pages->checkRevision($draft, self::intOrNull($command['expectedVersion'] ?? null));

        if ($conflict !== null) {
            return $this->record($context, $conflict, $page->id, $operation);
        }

        $document = $draft->document;
        $target = (string) ($command['target'] ?? '');

        if ($operation === 'component.delete') {
            $stop = $this->requireConfirmation($page, $document, $target, $command, $context);

            if ($stop !== null) {
                return $this->record($context, $stop, $page->id, $operation);
            }
        }

        if ($operation === 'component.detach') {
            $stop = $this->requireDetachConfirmation($page, $document, $target, $context);

            if ($stop !== null) {
                return $this->record($context, $stop, $page->id, $operation);
            }
        }

        try {
            $patch = $this->toPatch($operation, $document, $command);
        } catch (InvalidArgumentException $e) {
            return $this->record($context, Result::validationError(['target' => [$e->getMessage()]]), $page->id, $operation);
        }

        if ($patch === []) {
            return $this->record($context, Result::validationError(['operation' => ['変更内容がありません']]), $page->id, $operation);
        }

        try {
            $updated = JsonPatch::apply($document, $patch);
        } catch (InvalidArgumentException $e) {
            return $this->record($context, Result::validationError(['operation' => [$e->getMessage()]]), $page->id, $operation);
        }

        $errors = $this->validator->validate($updated);

        if ($errors !== []) {
            return $this->record($context, Result::validationError($errors, 'ページの内容を確認してください'), $page->id, $operation);
        }

        $version = $this->pages->createVersion(
            $page,
            $updated,
            $context->interface->value,
            $context->actorId,
            /*
             * **編集画面の変更はすべて自動保存である**（SPEC §4.11 / Owner 決定 2026-08-19）。
             *
             * 明示保存の操作が無いので、ここを通る版に「利用者が意図して残した」ものは
             * 混ざらない。復元（`page.document.restore`）と公開は別の口から来るので、
             * 既定の `false` のままになる。
             */
            autosave: true,
        );

        // 監査には **変更されたキー名と件数の要約**だけを残す（本文・HTML は入れない）
        return $this->record(
            $context,
            Result::success(
                ['document' => $updated],
                [
                    'pageId' => $page->id,
                    'version' => $version->revision,
                    // 直した瞬間に消える／出るのが分かるよう、変更のたびに返す
                    'accessibility' => $this->accessibility->inspect($updated),
                    /*
                     * **取り消しは、送り返せる形で返す。**
                     *
                     * 履歴を持つのはクライアント（Owner Gate V6-a）だが、
                     * 「何が逆か」を組み立てるのは document を持っているこちらである。
                     * 組み立てさせると、消える node の id を渡すことになる。
                     */
                    'undo' => $this->undoCommand($operation, $document, $updated, $command),
                ],
            ),
            $page->id,
            $operation,
            [
                'command' => $operation,
                'target' => $target,
                'patchOps' => count($patch),
                'version' => $version->revision,
            ],
        );
    }

    /**
     * この operation の記録口（Correction 2-5）。
     *
     * `component.delete` だけ危険度が変わるので、`classificationFor()` を
     * 明示的に渡す。呼び出し側で毎回書くと取りこぼすため 1 か所へまとめる。
     *
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        OperationContext $context,
        Result $result,
        int $pageId,
        string $command,
        array $metadata = [],
    ): Result {
        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'page',
            resourceId: (string) $pageId,
            metadata: $metadata,
            classification: $this->classificationFor($command),
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $command
     */
    private function requireConfirmation(
        Page $page,
        array $document,
        string $target,
        array $command,
        OperationContext $context,
    ): ?Result {
        $node = DocumentTree::find($document, $target);

        if ($node === null) {
            return Result::notFound("ブロック [{$target}] は見つかりません");
        }

        $children = DocumentTree::countNodes($node) - 1;
        $type = (string) ($node['type'] ?? '');
        $label = $this->components->has($type) ? $this->components->get($type)->label : $type;

        $confirmation = $this->confirmations->require(
            'component.delete',
            OperationClassification::Destructive,
            $context,
            ['pageId' => $page->id, 'target' => $target],
            summary: "「{$page->label()}」から{$label}を削除します",
            impact: array_values(array_filter([
                $children > 0 ? "中の {$children} 個のブロックも一緒に削除されます" : null,
                /*
                 * **取り消せるようになったので、文を合わせる**（SPEC §4.10(3)）。
                 *
                 * 「元に戻せません」は嘘になった。ただし取り消せるのは
                 * **編集を続けている間だけ**で、閉じたり読み直したりすれば履歴は消える
                 * （履歴はクライアントが持っている — Owner Gate V6-a）。
                 * そこまで言わないと、「あとで戻せる」と読んで閉じてしまう。
                 */
                '取り消せるのは編集を続けている間だけです（閉じると戻せません）',
            ])),
        );

        return $confirmation === null ? null : Result::confirmationRequired($confirmation);
    }

    /**
     * semantic command → JSON Patch。
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $command
     * @return list<array<string, mixed>>
     */
    private function toPatch(string $operation, array $document, array $command): array
    {
        $target = (string) ($command['target'] ?? '');

        return match ($operation) {
            'component.update' => $this->updatePatch($document, $target, $command),
            'component.delete' => [['op' => 'remove', 'path' => $this->pointer($document, $target)]],
            'component.insert' => $this->insertPatch($document, $command),
            'component.move' => $this->movePatch($document, $target, $command),
            'component.duplicate' => $this->duplicatePatch($document, $target),
            'component.detach' => $this->detachPatch($document, $target),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $command
     * @return list<array<string, mixed>>
     */
    private function updatePatch(array $document, string $target, array $command): array
    {
        $pointer = $this->pointer($document, $target);
        $changes = is_array($command['changes'] ?? null) ? $command['changes'] : [];
        $patch = [];

        foreach (['props', 'style', 'responsive', 'binding', 'key'] as $section) {
            if (! array_key_exists($section, $changes)) {
                continue;
            }

            $value = $changes[$section];

            if ($section === 'props' || $section === 'style' || $section === 'responsive') {
                $current = DocumentTree::at($document, "{$pointer}/{$section}") ?? [];
                $value = self::merge((array) $current, is_array($value) ? $value : []);
            }

            /*
             * **既にあるものは `replace`、無いものだけ `add`**（Owner Gate V7）。
             *
             * 適用の結果はどちらでも同じ（RFC 6902 の `add` は既存キーを置き換える）が、
             * **この patch を `invert()` に通したときに元へ戻ることを保つ**ためである。
             * `add` の逆は `remove` なので、`add` を出しているかぎり逆変換は
             * そのセクションを丸ごと消す。
             *
             * **いまの取り消しはこの性質に依存していない** — 逆の semantic command を
             * 組み立てて送るので、`invert()` を通らない。それでも直すのは、
             * **patch は保存され、別の経路から逆変換され得る**からである。
             * RFC の意味どおりにしておく。
             *
             * 在るかどうかを見てから選ぶので、`replace` が対象を見つけられずに
             * 失敗することはない（無いときは `add` のままである）。
             */
            $exists = DocumentTree::at($document, "{$pointer}/{$section}") !== null;

            /*
             * **`binding: null` は「外す」である**（Phase 9.6 / P0-C5・SPEC §4.9）。
             *
             * `props` の `merge()` が既に「null は外す」を採っているので、作法を揃える。
             * `null` を値として書き込むと、読む側が「binding が無い」を
             * **キーが無い形と null の形の 2 通り**で相手にすることになる。
             *
             * そして外す表現があって初めて、**初めて binding を付けた操作の逆**が
             * 組み立てられる（`undoUpdate()` — §4.10 の制限がここで解ける）。
             *
             * 元から無いものを外す patch は出さない。`remove` は対象が無いと失敗する。
             */
            if ($section === 'binding' && $value === null) {
                if ($exists) {
                    $patch[] = ['op' => 'remove', 'path' => "{$pointer}/{$section}"];
                }

                continue;
            }

            $patch[] = [
                'op' => $exists ? 'replace' : 'add',
                'path' => "{$pointer}/{$section}",
                'value' => $value,
            ];
        }

        return $patch;
    }

    /**
     * この command の**取り消し**にあたる command。組み立てられなければ `null`。
     *
     * ## 取り消しも同じ口から入る
     *
     * 逆 patch を生で受け取る口は作らない。作れば validator も確認ゲートも監査も
     * 通らない 2 本目の書き込み経路になる。取り消しは
     * **「さっきと逆のことを、同じ口から頼む」**形にする（SPEC §4.10）。
     *
     * ## 組み立てるのはサーバーである
     *
     * 何を消したか・どこに在ったかを知っているのは document を持っている側である。
     * クライアントに組み立てさせると、**消える node の id を渡して送り返させる**
     * ことになり、「ID はサーバーが採番する」と交換になる。
     *
     * ## 戻るのは形と中身で、id ではない
     *
     * 消した node を戻すと作り直しになるので、新しい id を持つ（SPEC §4.10 の DoD）。
     *
     * @param  array<string, mixed>  $before  適用前の document
     * @param  array<string, mixed>  $after  適用後の document
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>|null
     */
    private function undoCommand(string $operation, array $before, array $after, array $command): ?array
    {
        $target = (string) ($command['target'] ?? '');

        return match ($operation) {
            'component.update' => $this->undoUpdate($before, $target, $command),
            'component.delete' => $this->undoDelete($before, $target),
            'component.insert', 'component.duplicate' => $this->undoAdded($before, $after),
            'component.move' => $this->undoMove($before, $target),
            default => null,
        };
    }

    /**
     * 更新の取り消し = **送ったキーだけを元の値へ戻す**。
     *
     * 送っていないキーには触らない。セクションごと元の値で上書きすると、
     * その後に別経路で入った変更まで巻き戻す。
     *
     * `binding` / `key` は重ねずに差し替える欄なので、元が無かった場合に
     * 「無かった状態」を送れるかどうかで扱いが分かれる。
     *
     * - **`binding` は送れる** — `binding: null` が「外す」なので（§4.9 / P0-C5）
     * - **`key` は送れない**ので、そこは取り消しを出さない（`null`）—
     *   嘘の取り消しを出すより、押せない方がよい
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>|null
     */
    private function undoUpdate(array $before, string $target, array $command): ?array
    {
        $pointer = DocumentTree::pointer($before, $target);

        if ($pointer === null) {
            return null;
        }

        $changes = is_array($command['changes'] ?? null) ? $command['changes'] : [];
        $undo = [];

        foreach ($changes as $section => $value) {
            if (! in_array($section, ['props', 'style', 'responsive', 'binding', 'key'], true)) {
                continue;
            }

            $current = DocumentTree::at($before, "{$pointer}/{$section}");

            if ($section === 'binding') {
                /*
                 * **元が無ければ「外す」を送る。**
                 *
                 * かつてはここで `null`（取り消しを出さない）を返していた。
                 * 外す表現が無く、「無かった状態」を送る方法が無かったためである
                 * （§4.10 — 嘘の取り消しを出すより押せない方がよい）。
                 * `binding: null` が外すようになったので、その制限は要らない。
                 */
                $undo[$section] = $current;

                continue;
            }

            if ($section === 'key') {
                // key には外す表現が無いので、元が無ければ取り消しを出さない
                if ($current === null) {
                    return null;
                }

                $undo[$section] = $current;

                continue;
            }

            $undo[$section] = self::inverseMerge(
                is_array($current) ? $current : [],
                is_array($value) ? $value : [],
            );
        }

        return $undo === []
            ? null
            : ['operation' => 'component.update', 'target' => $target, 'changes' => $undo];
    }

    /**
     * 重ねた変更を元へ戻すための「送るもの」。**`merge()` の裏返しである。**
     *
     * - 元に在ったキー → その値へ戻す
     * - 元に無かったキー → `null`（= 外す。`merge()` の約束）
     * - 入れ子も同じ規則で辿る（画面幅ごとの上書きが 2 段になっているため）
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function inverseMerge(array $current, array $incoming): array
    {
        $undo = [];

        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $current)) {
                // 足したものは外す
                $undo[$key] = null;

                continue;
            }

            if (is_array($value) && is_array($current[$key]) && $value !== []) {
                $undo[$key] = self::inverseMerge($current[$key], $value);

                continue;
            }

            $undo[$key] = $current[$key];
        }

        return $undo;
    }

    /**
     * 削除の取り消し = **同じ場所へ入れ直す**。
     *
     * id は付けずに返す（`insertPatch()` が採番する）。中身と並びは戻り、
     * **id だけが新しくなる**。
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>|null
     */
    private function undoDelete(array $before, string $target): ?array
    {
        $place = $this->placeOf($before, $target);
        $node = DocumentTree::find($before, $target);

        if ($place === null || $node === null) {
            return null;
        }

        return [
            'operation' => 'component.insert',
            'parent' => $place['parent'],
            'after' => $place['after'],
            'component' => self::withoutIdentifiers($node),
        ];
    }

    /**
     * 追加・複製の取り消し = **増えた node を消す**。
     *
     * どれが増えたかは、適用前後の id の差でしか分からない。
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>|null
     */
    private function undoAdded(array $before, array $after): ?array
    {
        $was = DocumentTree::identifiers($before)['ids'];
        $added = array_keys(array_diff_key(DocumentTree::identifiers($after)['ids'], $was));

        /*
         * **増えたうちの最初が、その一番上である。**
         *
         * `DocumentTree::walk()` は親を見てから子へ降りるので、`identifiers()` は
         * 文書順に並ぶ。入れ子ごと足された場合、その根が先に現れる。
         * 一番上を 1 つ消せば子も一緒に消えるので、探し回る必要が無い。
         *
         * この前提が崩れたら（走査の順を変えたら）、複製の取り消しが
         * **子だけ消して親を残す**。入れ子を複製する試験がそこを見ている。
         */
        $top = $added[0] ?? null;

        return $top === null ? null : ['operation' => 'component.delete', 'target' => $top];
    }

    /**
     * 移動の取り消し = **元の親の、元の位置へ戻す**。
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>|null
     */
    private function undoMove(array $before, string $target): ?array
    {
        $place = $this->placeOf($before, $target);

        if ($place === null) {
            return null;
        }

        return [
            'operation' => 'component.move',
            'target' => $target,
            'parent' => $place['parent'],
            'after' => $place['after'],
        ];
    }

    /**
     * その node が「どの親の、誰の後ろ」に在るか。
     *
     * @param  array<string, mixed>  $document
     * @return array{parent: string, after: string|null}|null
     */
    private function placeOf(array $document, string $target): ?array
    {
        $pointer = DocumentTree::pointer($document, $target);

        if ($pointer === null) {
            return null;
        }

        $parentPointer = $this->parentOf($pointer);
        $parentNode = DocumentTree::at($document, $parentPointer);

        if (! is_array($parentNode)) {
            return null;
        }

        $children = is_array($parentNode['children'] ?? null) ? $parentNode['children'] : [];
        $after = null;

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['id'] ?? null) === $target) {
                break;
            }

            $after = (string) ($child['id'] ?? '');
        }

        return [
            'parent' => (string) ($parentNode['id'] ?? 'page'),
            'after' => $after === '' ? null : $after,
        ];
    }

    /**
     * 入れ直すために id を落とす。**採番はサーバーの仕事である。**
     *
     * `key` も落とす — 同じ綴りが残っていると `suggestKey()` が別名を振るので、
     * 落として振り直させた方が結果が読みやすい。
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function withoutIdentifiers(array $node): array
    {
        unset($node['id']);

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        foreach ($children as $i => $child) {
            if (is_array($child)) {
                $children[$i] = self::withoutIdentifiers($child);
            }
        }

        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * 送られたぶんだけを現在値へ重ねる。
     *
     * - **`null` は「外す」**。空の配列を送っても現在値と混ざるだけなので、
     *   外す表現が他に無い（これが無いと、一度付けた見た目を二度と外せない）
     * - **入れ子も同じ意味で扱う。** 画面幅ごとの上書き（`responsive[breakpoint][key]`）は
     *   2 段になっているので、浅く差し替えると同じ画面幅の他の指定まで消える
     * - **空になった入れ物は残さない。** 最後の 1 つを外したら、その画面幅ごと片付ける
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function merge(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === null) {
                unset($current[$key]);

                continue;
            }

            if (! is_array($value)) {
                $current[$key] = $value;

                continue;
            }

            $nested = self::merge(
                is_array($current[$key] ?? null) ? $current[$key] : [],
                $value,
            );

            if ($nested === []) {
                unset($current[$key]);
            } else {
                $current[$key] = $nested;
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $command
     * @return list<array<string, mixed>>
     */
    private function insertPatch(array $document, array $command): array
    {
        $parent = (string) ($command['parent'] ?? '');
        $parentPointer = $this->pointer($document, $parent);
        $parentNode = DocumentTree::at($document, $parentPointer) ?? [];
        $children = is_array($parentNode['children'] ?? null) ? $parentNode['children'] : [];

        $node = is_array($command['component'] ?? null) ? $command['component'] : [];

        if (! is_string($node['type'] ?? null)) {
            throw new InvalidArgumentException('追加するブロックの種類が指定されていません');
        }

        // **ID はサーバーが採番する**。クライアントに identity を作らせない
        $identifiers = DocumentTree::identifiers($document);
        $keys = $identifiers['keys'];
        $node = $this->prepareNode($node, $identifiers['ids'], $keys);

        $index = count($children);
        $after = $command['after'] ?? null;

        if (is_string($after) && $after !== '') {
            foreach ($children as $i => $child) {
                if (is_array($child) && (($child['id'] ?? null) === $after || ($child['key'] ?? null) === $after)) {
                    $index = $i + 1;
                    break;
                }
            }
        }

        return [['op' => 'add', 'path' => "{$parentPointer}/children/{$index}", 'value' => $node]];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $takenIds
     * @param  array<string, true>  $takenKeys
     * @return array<string, mixed>
     */
    private function prepareNode(array $node, array $takenIds, array &$takenKeys): array
    {
        $node['id'] = NodeId::generate();
        $node['props'] = is_array($node['props'] ?? null) ? $node['props'] : [];

        // **children は必ず配列で置く。**
        // 無いまま保存すると、その節点の中へ次の component を入れる patch が
        // 「path が存在しません」で落ちる（= 追加した箱に何も入れられない）
        $node['children'] = is_array($node['children'] ?? null) ? $node['children'] : [];

        $key = $node['key'] ?? null;
        $suggested = NodeId::suggestKey(
            (string) $node['type'],
            $takenKeys,
            is_string($key) && $key !== '' ? $key : null,
        );

        if ($suggested !== null) {
            $node['key'] = $suggested;
            $takenKeys[$suggested] = true;
        } else {
            unset($node['key']);
        }

        foreach ($node['children'] as $i => $child) {
            if (is_array($child)) {
                $node['children'][$i] = $this->prepareNode($child, $takenIds, $takenKeys);
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $command
     * @return list<array<string, mixed>>
     */
    private function movePatch(array $document, string $target, array $command): array
    {
        $from = $this->pointer($document, $target);
        $parent = (string) ($command['parent'] ?? '');

        if ($parent === '') {
            // 親を指定しない場合は同じ親の中で after の直後へ動かす
            $parent = $this->parentOf($from);
        } else {
            $parent = $this->pointer($document, $parent);
        }

        $parentNode = DocumentTree::at($document, $parent) ?? [];
        $children = is_array($parentNode['children'] ?? null) ? $parentNode['children'] : [];
        $index = count($children);
        $after = $command['after'] ?? null;

        if (is_string($after) && $after !== '') {
            foreach ($children as $i => $child) {
                if (is_array($child) && (($child['id'] ?? null) === $after || ($child['key'] ?? null) === $after)) {
                    $index = $i + 1;
                    break;
                }
            }
        } elseif ($after === null) {
            $index = 0;
        }

        return [['op' => 'move', 'from' => $from, 'path' => "{$parent}/children/{$index}"]];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function duplicatePatch(array $document, string $target): array
    {
        $pointer = $this->pointer($document, $target);
        $node = DocumentTree::at($document, $pointer);

        if ($node === null) {
            throw new InvalidArgumentException("ブロック [{$target}] は見つかりません");
        }

        $identifiers = DocumentTree::identifiers($document);
        $keys = $identifiers['keys'];
        $copy = DocumentTree::reidentify($node, $identifiers['ids'], $keys);

        $segments = DocumentTree::segments($pointer);
        $index = (int) array_pop($segments) + 1;

        return [['op' => 'add', 'path' => $this->parentOf($pointer)."/children/{$index}", 'value' => $copy]];
    }

    /**
     * 切り離し（Phase 9.6 / P0-C7・SPEC §4.5）。
     *
     * `instanceOf` を外し、**その時点の**部分木をこの node の中へ写す。
     *
     * ## 戻せない
     *
     * 写した後は、実体を直してもこのページは変わらない。**元の参照へは戻せない** —
     * 参照を復すだけなら形は戻るが、その間に実体が変わっていれば
     * 「切り離す前と同じ」にはならないからである。だから片方向にして、確認を求める。
     *
     * ## 写すのは公開版ではなく下書き
     *
     * 切り離すのは編集中のページである。**目の前に見えているもの**（preview は
     * draft を描く）が写る方が、押した人の期待に合う。
     *
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function detachPatch(array $document, string $target): array
    {
        $pointer = $this->pointer($document, $target);
        $node = DocumentTree::at($document, $pointer);

        if ($node === null) {
            throw new InvalidArgumentException("ブロック [{$target}] は見つかりません");
        }

        $instanceOf = $node['instanceOf'] ?? null;

        if (! is_string($instanceOf) || $instanceOf === '') {
            throw new InvalidArgumentException('このブロックは再利用 component を参照していません');
        }

        $component = ReusableComponent::query()->find($instanceOf);

        if (! $component instanceof ReusableComponent) {
            throw new InvalidArgumentException('参照している再利用 component が見つかりません');
        }

        /*
         * **id は取り直す。** 実体の部分木の id をそのまま置くと、同じ実体を
         * 2 か所で切り離したときに **document 内で id が重複する**。
         */
        $identifiers = DocumentTree::identifiers($document);
        $keys = $identifiers['keys'];
        $subtree = DocumentTree::reidentify($component->draft_document, $identifiers['ids'], $keys);

        /*
         * **入れ子にしない。** 参照する node は実体と同じ type なので
         * （`DocumentValidator` がそれを求める）、**中身をそのまま引き継ぐ**。
         * 入れ子にすると、section が section の中へ入るような形になり、
         * 置き場所の規則に反する木ができる。
         */
        $detached = $node;
        unset($detached['instanceOf']);
        $detached['props'] = is_array($subtree['props'] ?? null) ? $subtree['props'] : [];
        $detached['children'] = is_array($subtree['children'] ?? null) ? $subtree['children'] : [];

        return [['op' => 'replace', 'path' => $pointer, 'value' => $detached]];
    }

    /**
     * 切り離しの確認。**戻せないことを言う。**
     *
     * 分類は `Write` である（消えるものは無い）。それでも訊くのは、
     * **元へ戻せない**からである（`RestorePageVersion` と同じ `requireAlways`）。
     *
     * @param  array<string, mixed>  $document
     */
    private function requireDetachConfirmation(
        Page $page,
        array $document,
        string $target,
        OperationContext $context,
    ): ?Result {
        $node = DocumentTree::find($document, $target);
        $instanceOf = is_array($node) ? ($node['instanceOf'] ?? null) : null;

        if (! is_string($instanceOf)) {
            // 参照していないものは、ここでは断らない（`detachPatch` が理由を返す）
            return null;
        }

        $component = ReusableComponent::query()->find($instanceOf);
        $label = $component instanceof ReusableComponent ? $component->label : $instanceOf;

        $confirmation = $this->confirmations->requireAlways(
            'component.detach',
            $context,
            ['pageId' => $page->id, 'target' => $target],
            summary: "「{$page->label()}」の「{$label}」を切り離します",
            impact: [
                'この操作は取り消せません',
                '切り離した後は、再利用 component を直してもこのページは変わりません',
            ],
        );

        return $confirmation === null ? null : Result::confirmationRequired($confirmation);
    }

    /** @param array<string, mixed> $document */
    private function pointer(array $document, string $target): string
    {
        $pointer = DocumentTree::pointer($document, $target);

        if ($pointer === null) {
            throw new InvalidArgumentException("ブロック [{$target}] は見つかりません");
        }

        return $pointer;
    }

    /** `/root/children/2` → `/root` */
    private function parentOf(string $pointer): string
    {
        $segments = DocumentTree::segments($pointer);
        array_pop($segments); // index
        array_pop($segments); // "children"

        return $segments === [] ? '/root' : '/'.implode('/', $segments);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

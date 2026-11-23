<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Sync;

use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinition;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionException;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * code-managed taxonomy の非破壊同期（Phase 9.3B / STAGE 1・SPEC §J.4 SY-1〜SY-18）。
 *
 * **実行順序は固定**である:
 *   1. 全 definition を読み込む（1 件でも不正なら例外 → 書き込みに進まない）
 *   2. 全 definition と DB 状態を preflight する
 *   3. conflict をすべて収集する
 *   4. conflict が 1 件でもあれば **書き込みを開始しない**
 *   5. conflict が無ければ transaction 内で created / updated を適用する
 *
 * 「先に一部の taxonomy を更新してから別 taxonomy の CONFLICT に気づく」実装を禁じるため、
 * **plan と apply を同一 transaction に入れ、plan がすべて終わるまで 1 行も書かない**。
 *
 * **やらないこと（非破壊 contract）**
 * - term の作成・削除・rename（SY-8）
 * - assignment / PageDocument の変更（SY-9）
 * - definition から消えた taxonomy の削除（SY-7）
 * - `key` の rename（SY-6。別 key は別 taxonomy として扱い、旧行を残す）
 * - `managed_by` の変更（SY-11）
 */
final class TaxonomySynchronizer
{
    public function __construct(
        private readonly TaxonomyDefinitionRegistry $definitions,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * 同期を実行する。
     *
     * @throws TaxonomyDefinitionException 定義が不正（DB は触らない）
     */
    public function sync(): TaxonomySyncReport
    {
        // 1. 定義の読み込み。不正なら **transaction に入る前に**失敗させる
        $definitions = $this->definitions->all();

        /** @var TaxonomySyncReport $report */
        $report = $this->db->connection()->transaction(function () use ($definitions): TaxonomySyncReport {
            // 2 + 3. 全件 preflight（読み取りのみ）
            $entries = $this->plan($definitions);

            $conflicts = array_values(array_filter(
                $entries,
                static fn (TaxonomySyncEntry $e): bool => $e->action === TaxonomySyncEntry::CONFLICT,
            ));

            // 4. conflict があれば 1 行も書かない
            if ($conflicts !== []) {
                return $this->report($entries, applied: false);
            }

            // 5. ここではじめて書き込む
            foreach ($entries as $entry) {
                if ($entry->action === TaxonomySyncEntry::CREATE) {
                    $this->create($entry->definition);
                } elseif ($entry->action === TaxonomySyncEntry::UPDATE) {
                    $this->update($entry);
                }
            }

            return $this->report($entries, applied: true);
        });

        return $report;
    }

    /**
     * 書き込みを伴わない preflight。`agentcms verify` からも使う。
     *
     * @param  array<string, TaxonomyDefinition>  $definitions
     * @return list<TaxonomySyncEntry>
     */
    public function plan(array $definitions): array
    {
        $entries = [];

        foreach ($definitions as $definition) {
            $entries[] = $this->planOne($definition);
        }

        return $entries;
    }

    private function planOne(TaxonomyDefinition $definition): TaxonomySyncEntry
    {
        $existing = Taxonomy::query()->where('key', $definition->key)->first();

        if ($existing === null) {
            return new TaxonomySyncEntry(TaxonomySyncEntry::CREATE, $definition);
        }

        // SY-10: cms 管理の同一 key は code definition で上書きしない
        if ($existing->managed_by !== TaxonomyManagedBy::Code) {
            return new TaxonomySyncEntry(
                TaxonomySyncEntry::CONFLICT,
                $definition,
                /*
                 * 🔴 **抜け方まで言う**（WP 一周 2026-09-03・移行の下ごしらえで実際に詰まった）。
                 *
                 * 何が起きたかだけを言われても、キーは後から変えられず、
                 * 一度でも使った分類は消せない。**進む道が見えない。**
                 * 確認では DB を直接触って抜けたが、買った人はそれをできない。
                 */
                reason: "管理画面が管理している分類（managed_by={$existing->managed_by->value}）と"
                    ."key [{$definition->key}] が重なっています。定義ファイル側では変更できません。"
                    ."\n    抜け方は 2 つです:"
                    ."\n    (1) 定義ファイルの key を**別のキー**にする（例: {$definition->key}_code）"
                    ."\n    (2) 管理画面の「分類管理」で [{$definition->key}] を削除してから、もう一度"
                    ."\n        （削除できるのは、まだ項目も投稿も付いていない分類だけです）",
            );
        }

        $changes = [];

        // SY-14: label は常に同期してよい
        if ($existing->label !== $definition->label) {
            $changes['label'] = $definition->label;
        }

        // SY-12 / SY-14: hierarchical は既存 term と矛盾しない場合のみ
        if ($existing->hierarchical !== $definition->hierarchical) {
            if ($existing->hierarchical && ! $definition->hierarchical) {
                $parented = TaxonomyTerm::query()
                    ->where('taxonomy_id', $existing->id)
                    ->whereNotNull('parent_id')
                    ->count();

                if ($parented > 0) {
                    return new TaxonomySyncEntry(
                        TaxonomySyncEntry::CONFLICT,
                        $definition,
                        reason: "分類 [{$definition->key}] を階層なしへ変更しようとしましたが、"
                            ."親を持つ項目が {$parented} 件あります。"
                            .'先に親子関係を解消するか、hierarchical を true のままにしてください。',
                    );
                }
            }

            $changes['hierarchical'] = $definition->hierarchical;
        }

        // SY-6 / SY-11: key と managed_by は変更対象に含めない

        return $changes === []
            ? new TaxonomySyncEntry(TaxonomySyncEntry::UNCHANGED, $definition)
            : new TaxonomySyncEntry(TaxonomySyncEntry::UPDATE, $definition, $changes);
    }

    private function create(TaxonomyDefinition $definition): void
    {
        // id / key / managed_by は fillable から外してあるため内部経路で明示的に入れる
        (new Taxonomy)->forceFill([
            'id' => TaxonomyId::generate(),
            'key' => $definition->key,
            'label' => $definition->label,
            'hierarchical' => $definition->hierarchical,
            'managed_by' => $definition->managedBy->value,
        ])->save();
    }

    private function update(TaxonomySyncEntry $entry): void
    {
        $taxonomy = Taxonomy::query()->where('key', $entry->key())->firstOrFail();

        // fillable は label / hierarchical だけ。key・managed_by・id は通らない
        $taxonomy->fill($entry->changes)->save();
    }

    /** @param  list<TaxonomySyncEntry>  $entries */
    private function report(array $entries, bool $applied): TaxonomySyncReport
    {
        $pick = static fn (string $action): array => array_values(array_map(
            static fn (TaxonomySyncEntry $e): string => $e->key(),
            array_filter($entries, static fn (TaxonomySyncEntry $e): bool => $e->action === $action),
        ));

        $conflicts = array_values(array_map(
            static fn (TaxonomySyncEntry $e): array => [
                'key' => $e->key(),
                'reason' => $e->reason ?? '',
            ],
            array_filter(
                $entries,
                static fn (TaxonomySyncEntry $e): bool => $e->action === TaxonomySyncEntry::CONFLICT,
            ),
        ));

        return new TaxonomySyncReport(
            created: $applied ? $pick(TaxonomySyncEntry::CREATE) : [],
            updated: $applied ? $pick(TaxonomySyncEntry::UPDATE) : [],
            unchanged: $applied ? $pick(TaxonomySyncEntry::UNCHANGED) : [],
            conflicts: $conflicts,
            applied: $applied,
        );
    }
}

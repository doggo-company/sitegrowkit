<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * 代表的な結末が **ちょうど 1 行**残ることの実証（STAGE #18A / Correction 2-5）。
 *
 * `OperationAuditCoverageTest` は「記録を通らない return が無い」ことを
 * 構造で保証するが、通った先で **本当に 1 行入るか**は分からない
 * （二重記録・分類の取り違え・resource の欠落は構造では見えない）。
 * ここは実際に走らせて数える。
 *
 * 5 つの結末をそれぞれ別の operation で 1 回ずつ通す。
 * 同じ operation を使い回すと、記録漏れが「別の行」に隠れる。
 */
final class AuditOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    /** forbidden — 権限の無い相手の試行が 1 行だけ残る */
    public function test_forbiddenがちょうど1行記録される(): void
    {
        $viewer = User::factory()->role(Role::Viewer)->create();

        $result = app(UpdateSettings::class)->execute(
            ['site_name' => 'のっとり'],
            OperationContext::admin((int) $viewer->getKey(), 'viewer'),
        );

        $this->assertSame(OperationStatus::Forbidden, $result->status);
        $this->assertOutcome('settings.update', 'forbidden', resourceType: 'settings', resourceId: 'site');

        // 拒否されたのだから値は変わっていない
        $this->assertDatabaseMissing('settings', ['value' => 'のっとり']);
    }

    /** validation_error — 入力を直せば通る失敗も 1 行だけ残る */
    public function test_validation_errorがちょうど1行記録される(): void
    {
        $result = app(CreateContent::class)->execute(
            'news',
            ['title' => '', 'slug' => ''],
            $this->context(),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertOutcome('content.create', 'validation_error');

        // 入力値そのものは監査へ残さない
        $this->assertSame(0, News::query()->count());
    }

    /** not_found — 実在しない対象への試行も 1 行だけ残る */
    public function test_not_foundがちょうど1行記録される(): void
    {
        $result = app(TrashPage::class)->execute(999_999, $this->context());

        $this->assertSame(OperationStatus::NotFound, $result->status);
        $this->assertOutcome('page.trash', 'not_found', resourceType: 'page', resourceId: '999999');
    }

    /** conflict — 参照があって止めた削除が 1 行だけ残る */
    public function test_conflictがちょうど1行記録される(): void
    {
        $term = $this->usedTerm();

        $result = app(DeleteTerm::class)->execute($term->id, $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertOutcome('term.delete', 'conflict', resourceType: 'taxonomy_term', resourceId: $term->id);
        $this->assertDatabaseHas('taxonomy_terms', ['id' => $term->id]);
    }

    /** confirmation_required → success は **別の結末**なので合計 2 行 */
    public function test_確認待ちと成功がそれぞれ1行ずつ記録される(): void
    {
        $id = $this->news();
        $context = $this->context();

        $first = app(DeleteContent::class)->execute('news', $id, $context);
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);

        $second = app(DeleteContent::class)->execute(
            'news',
            $id,
            $context->withConfirmationToken((string) $first->confirmation?->token),
        );
        $this->assertTrue($second->isSuccess());

        $rows = DB::table('audit_logs')->where('operation', 'content.delete')->orderBy('id')->get();

        $this->assertCount(2, $rows, '確認待ちと成功で 2 行にならない');
        $this->assertSame(['confirmation_required', 'success'], $rows->pluck('result')->all());
        $this->assertSame(0, News::query()->count());
    }

    /**
     * 同じ結末を policy と service の両方から書かない（Correction 7 の回帰）。
     *
     * 経路を 3 つ通しても、1 試行 = 1 行のままであること。
     */
    public function test_どの経路から呼んでも1試行1行のまま(): void
    {
        $id = $this->news();

        foreach ([
            OperationContext::admin((int) $this->owner->getKey(), 'admin'),
            OperationContext::api((int) $this->owner->getKey(), 'api'),
            OperationContext::cli('cli'),
        ] as $index => $context) {
            DB::table('audit_logs')->delete();

            $result = app(UpdateContent::class)->execute(
                'news',
                $id,
                ['title' => "更新 {$index}"],
                $context,
                partial: true,
            );

            $this->assertTrue($result->isSuccess(), $context->interface->value.' で失敗した');
            $this->assertSame(
                1,
                DB::table('audit_logs')->where('operation', 'content.update')->count(),
                $context->interface->value.' で二重記録されている',
            );
        }
    }

    /** 1 つの結末に対して 1 行、しかも中身が正しいことを確かめる */
    private function assertOutcome(
        string $operation,
        string $result,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $rows = DB::table('audit_logs')->where('operation', $operation)->get();

        $this->assertCount(1, $rows, "{$operation} が 1 行になっていない");
        $this->assertSame($result, $rows[0]->result, "{$operation} の結末が違う");
        $this->assertNotNull($rows[0]->request_id, "{$operation} に request_id が無い");

        if ($resourceType !== null) {
            $this->assertSame($resourceType, $rows[0]->resource_type, "{$operation} の resource_type が違う");
        }

        if ($resourceId !== null) {
            $this->assertSame($resourceId, $rows[0]->resource_id, "{$operation} の resource_id が違う");
        }
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), 'tester');
    }

    /** 監査を数えたいので、下ごしらえのぶんは消してから始める */
    private function news(): int
    {
        $news = News::query()->create([
            'title' => '記事', 'slug' => 'kiji-'.uniqid(), 'body' => '<p>本文</p>',
        ]);

        DB::table('audit_logs')->delete();

        return (int) $news->getKey();
    }

    /** 参照されていて削除できない項目 */
    private function usedTerm(): TaxonomyTerm
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(),
            'key' => 'audit_test_'.substr(uniqid(), -6),
            'label' => '監査テスト',
            'managed_by' => TaxonomyManagedBy::Code->value,
            'hierarchical' => false,
        ])->save();

        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomy->id,
            'name' => '使用中',
            'slug' => 'in-use',
            'sort_order' => 0,
        ])->save();

        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        DB::table('audit_logs')->delete();

        return $term;
    }
}

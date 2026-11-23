<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use App\Support\OperationCatalog;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * Phase 9.1B / STEP 14: AI（MCP）から実行できる範囲の境界。
 *
 * ここが緩むと「AI が管理画面より強い権限を持つ」状態になる。
 * 公開範囲・禁止事項・stdin 経由の入力・監査をテストで固定する。
 */
final class McpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    public function test_allowlist_matches_the_agreed_operation_set(): void
    {
        $expected = [
            'content.types.list', 'content.schema.get',
            'content.list', 'content.get', 'content.create', 'content.duplicate', 'content.update',
            // `content.trash` が正式名で、`content.delete` は互換 wrapper（9.8 / OG2 = (c)）
            'content.delete', 'content.trash',
            // 掲載順（P1 ②）。**内容は 1 文字も変わらず、また動かせる**ので出す
            // （`term.reorder` を出しているのと同じ理由。一括置換のように戻せない操作は出さない）
            'content.reorder',
            'media.list', 'media.get', 'media.update', 'media.replace',
            'settings.get', 'settings.update',
            // 旧お問い合わせの受信（`contact.list` / `contact.get`）は **意図的に無い**（B-43）。
            // 下の 9.12 の項と同じ理由 — 本文と連絡先そのもので、生 IP まで乗っていた
            'page.list', 'page.create', 'page.duplicate', 'page.get', 'page.document.get',
            // 版の一覧は読むだけ（中身は返さない）。**復元は出さない**（§4.12・P0-C6c）
            'page.document.versions',
            'page.document.patch', 'page.publish', 'page.unpublish', 'page.trash',
            // 分類（Phase 9.3B / §P.3）。**taxonomy 自体の CRUD は含まない**
            'taxonomy.list', 'taxonomy.get',
            'term.list', 'term.create', 'term.update', 'term.reorder', 'term.delete',
            // メニュー（Phase 9.9 / P0-4）。**参照だけ**。保存と削除は出さない
            'menu.list', 'menu.get',
            // リダイレクト（Phase 9.10 / P0-5）。simulate は何も変えないので出す
            'redirect.list', 'redirect.simulate',
            // backup（Phase 9.11 / P0-6）。**参照だけ**。復元は渡さない
            'backup.list', 'backup.verify',
            // フォーム（Phase 9.12 / P0-8）。**定義の参照だけ**。
            // 受信（`form.submission.*`）は個人情報そのもので、AI へは渡さない
            'form.list', 'form.get',
        ];

        sort($expected);
        $actual = OperationCatalog::ids();
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_destructive_media_and_contact_mutations_are_not_exposed_to_agents(): void
    {
        // 画像の削除・問い合わせの状態変更は管理画面のみ（owner 指示のスコープ）
        $this->assertFalse(OperationCatalog::allows('media.delete'));
        $this->assertFalse(OperationCatalog::allows('contact.updateStatus'));
    }

    public function test_destructive_and_external_effect_operations_are_the_expected_ones(): void
    {
        $byClass = static function (OperationClassification $class): array {
            return array_values(array_filter(
                OperationCatalog::ids(),
                static fn (string $id): bool => OperationCatalog::classificationOf($id) === $class,
            ));
        };

        /*
         * 確認を要する操作。増えたら確認フローの見直しが要る。
         *
         * `content.delete` / `content.trash` は 9.8 以降 **取り消せる**（ゴミ箱へ移す）が、
         * 分類は `Destructive` のままにする。利用者にとっては「消す」導線であり、
         * 確認なしで押させたくない。**取り消せるようになったから確認を外す**のは、
         * この製品の安全側の既定と逆である。
         */
        $this->assertSame(
            ['content.delete', 'content.trash', 'page.trash', 'term.delete'],
            $byClass(OperationClassification::Destructive),
        );
        // 外部（公開サイト）へ影響する操作
        $this->assertSame(['page.publish', 'page.unpublish'], $byClass(OperationClassification::ExternalEffect));
    }

    public function test_taxonomy_itself_is_never_exposed(): void
    {
        // 分類そのものの追加・変更・削除は Phase 9.4 まで公開しない（§P.2）
        foreach ([
            'taxonomy.create', 'taxonomy.update', 'taxonomy.delete', 'taxonomy.sync',
            'term.assign', 'term.unassign', 'term.archive', 'term.unarchive',
        ] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }
    }

    /**
     * **自動保存は AI へ出さない**（Phase 9.15 / STAGE 28B）。
     *
     * 自動保存は「編集 session の途中経過」であり、**AI には編集 session が無い**。
     * AI が内容を変えたいなら `content.update` が既に在る。
     *
     * 出さないと決めたことは、**名前で残さないと守れない**。
     * catalog に増えたかどうかを数で見ていると、別の operation と入れ替わっても気づかない。
     */
    public function test_autosave_is_never_exposed(): void
    {
        $this->assertFalse(
            OperationCatalog::allows('content.autosave'),
            'content.autosave が AI へ公開されている',
        );
    }

    public function test_page_hard_delete_is_never_exposed(): void
    {
        foreach (['page.purge', 'page.delete', 'page.forceDelete', 'page.destroy'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden));
        }
    }

    public function test_arbitrary_artisan_commands_cannot_be_reached_through_the_bridge(): void
    {
        foreach (['migrate:fresh', 'db:wipe', 'tinker', 'env', 'key:generate', 'agentcms:site-setting'] as $command) {
            $this->assertFalse(
                OperationCatalog::allows($command),
                "{$command} が AI から実行可能になっています",
            );
        }
    }

    public function test_bridge_rejects_an_operation_outside_the_allowlist(): void
    {
        $exit = Artisan::call('agentcms:operation', ['operation' => 'db.wipe']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('forbidden', $output);
    }

    public function test_bridge_reads_arguments_from_stdin_not_from_argv(): void
    {
        // signature には operation ID しか無い（payload を argv へ載せない設計）
        $definition = Artisan::all()['agentcms:operation']->getDefinition();

        $this->assertSame(['operation'], array_keys($definition->getArguments()));
        $this->assertSame([], array_keys($definition->getOptions()));
    }

    public function test_agent_operations_are_recorded_with_the_mcp_interface(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(
            OperationInterface::Mcp,
            'test-agent',
        );

        $result = $catalog->invoke('content.update', [
            'type' => 'news',
            'id' => (int) News::query()->firstOrFail()->getKey(),
            'data' => ['title' => 'MCP から更新'],
        ], $context);

        $this->assertTrue($result->isSuccess());
        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'mcp',
            'operation' => 'content.update',
            'actor_type' => 'agent',
        ]);
    }

    public function test_media_replace_does_not_accept_a_server_file_path(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(
            OperationInterface::Mcp,
        );

        // path を渡しても読み取られず、base64 が無いので検証エラーになる
        $result = $catalog->invoke('media.replace', [
            'id' => 1,
            'path' => '/etc/passwd',
            'filename' => 'passwd',
        ], $context);

        $this->assertSame('validation_error', $result->status->value);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use AgentCms\Core\Application\ActorType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use App\Console\Commands\ImportedUsageCommand;
use App\Console\Commands\ImportPageCommand;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 取り込んだ部品・枠が、いまどのページに使われているかを答える（読み取り専用）。
 *
 * apply の prune が**使われている部品を消して公開面を壊さない**ための問い合わせ口。
 * ここで固定するのは 4 つ:
 *   1. 使われている部品は、使っているページの slug で返す。使われていなければ空
 *   2. 枠（layout）は結び付きの行で判断する
 *   3. ゴミ箱のページも「使っている」に数える（復元できる）
 *   4. 読み取り専用 — 1 行も変えない
 */
final class ImportedUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/agentcms-usage-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir);
        File::copyDirectory(base_path('content/components'), $this->dir);

        foreach (['imported_home_hero', 'imported_home_band'] as $type) {
            File::put($this->dir.'/'.$type.'.json', (string) json_encode([
                'type' => $type, 'label' => $type, 'category' => 'imported', 'since' => 1,
                'propsSchema' => new \stdClass, 'styleSchema' => ['visibility'], 'bindable' => [],
                'allowedChildren' => [], 'allowedParents' => ['page'],
                'renderer' => ['kind' => 'blade', 'view' => 'components.document.text'],
                'capabilities' => ['responsive' => ['visibility'], 'container' => false],
            ], JSON_UNESCAPED_UNICODE));
        }

        $this->app->instance(ComponentRegistry::class, new ComponentRegistry($this->dir));
        $dir = $this->dir;
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($dir));
    }

    public function test_使われている部品と枠は_使っているページの_slug_で返す(): void
    {
        $this->importHome();

        $out = $this->usage([
            'components' => ['imported_home_hero', 'imported_home_band', 'imported_other_x'],
            'layouts' => ['layouts.preserved.home', 'layouts.preserved.other'],
        ]);

        $this->assertSame('success', $out['status']);
        $this->assertSame(['home'], $out['components']['imported_home_hero']);
        $this->assertSame([], $out['components']['imported_home_band'], '置いていない部品を使っていることにしている');
        $this->assertSame([], $out['components']['imported_other_x']);
        $this->assertSame(['home'], $out['layouts']['layouts.preserved.home']);
        $this->assertSame([], $out['layouts']['layouts.preserved.other']);
    }

    public function test_ゴミ箱のページも使っているに数える(): void
    {
        $id = $this->importHome();

        // ゴミ箱へ（確認 token の往復は主題ではないので、状態を直接置く）
        Page::query()->whereKey($id)->update([
            'status' => PageStatus::Trashed->value,
            'trashed_at' => now(),
        ]);
        $this->assertNotNull(Page::query()->findOrFail($id)->trashed_at);

        $out = $this->usage(['components' => ['imported_home_hero'], 'layouts' => ['layouts.preserved.home']]);

        // 復元できるものを「未使用」と言うと、復元した瞬間に部品の無いページになる
        $this->assertSame(['home'], $out['components']['imported_home_hero']);
        $this->assertSame(['home'], $out['layouts']['layouts.preserved.home']);
    }

    public function test_過去の版にだけ在る部品も使っているに数える(): void
    {
        $id = $this->importHome();

        // hero を外した新しい版を作る（現在の下書き・公開版には hero が無い）
        $node = (string) (PageVersion::query()->findOrFail(Page::query()->findOrFail($id)->draft_version_id)
            ->document['root']['children'][0]['id']);
        $command = ['operation' => 'component.delete', 'target' => $node];
        $removed = app(PatchPageDocument::class)->execute($id, $command, OperationContext::cli('editor'));

        if ($removed->status === OperationStatus::ConfirmationRequired) {
            $removed = app(PatchPageDocument::class)->execute($id, $command, new OperationContext(
                actorType: ActorType::System,
                interface: OperationInterface::Cli,
                actorLabel: 'editor',
                confirmationToken: $removed->confirmation?->token,
            ));
        }

        $this->assertSame(OperationStatus::Success, $removed->status, json_encode($removed->errors, JSON_UNESCAPED_UNICODE) ?: '');

        $out = $this->usage(['components' => ['imported_home_hero'], 'layouts' => []]);

        // 🔴 Codex 続き (2): 版の履歴から復元できる部品を「未使用」と言うと、復元した瞬間に描けないページになる
        $this->assertSame(['home'], $out['components']['imported_home_hero']);
    }

    public function test_何も渡さなければ空を返し_受け付けないキーは断る(): void
    {
        $out = $this->usage(['components' => [], 'layouts' => []]);
        $this->assertSame([], $out['components']);
        $this->assertSame([], $out['layouts']);

        $refused = $this->usage(['pages' => []], expectFailure: true);
        $this->assertStringContainsString('pages', $refused['message']);

        $bad = $this->usage(['components' => 'imported_home_hero'], expectFailure: true);
        $this->assertStringContainsString('文字列の並び', $bad['message']);
    }

    public function test_読み取り専用(): void
    {
        $this->importHome();
        $before = Page::query()->first()?->toArray();

        $this->usage(['components' => ['imported_home_hero'], 'layouts' => ['layouts.preserved.home']]);

        $this->assertSame($before, Page::query()->first()?->toArray());
    }

    // ── 道具 ──────────────────────────────────────────────

    private function importHome(): int
    {
        $out = $this->runCommand(app(ImportPageCommand::class), [
            'page' => [
                'title' => 'ホーム', 'slug' => 'home', 'publish' => true,
                'layout' => 'layouts.preserved.home',
                'blocks' => [['type' => 'imported_home_hero', 'props' => []]],
            ],
        ]);

        $this->assertSame('created', $out['action'] ?? null, json_encode($out, JSON_UNESCAPED_UNICODE) ?: '');

        return (int) $out['id'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function usage(array $payload, bool $expectFailure = false): array
    {
        return $this->runCommand(app(ImportedUsageCommand::class), $payload, $expectFailure);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runCommand(Command $command, array $payload, bool $expectFailure = false): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command->setLaravel(app());
        $exit = $command->run($input, $output);
        $raw = trim($output->fetch());
        fclose($stream);

        $this->assertSame($expectFailure ? 1 : 0, $exit, "終了コードが違う: {$raw}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "JSON を返していない: {$raw}");

        return $decoded;
    }
}

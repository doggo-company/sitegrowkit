<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\ReadinessResult;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * サーバーに置く `.env` のひな形でも、サイトは日本時間で動く（直し 5 回目）。
 *
 * 手元の `.env.example` には `APP_TIMEZONE=Asia/Tokyo` があったが、サーバー用の
 * `.agentcms/deploy/env.*.example` には無く、`config/app.php` の既定 `UTC` で動いていた。
 * そのままだと、編集画面で「12月1日 10:00」に予約したページが、**日本時間の 19:00** まで出ない
 * （画面も UTC で読むので、管理画面の上では食い違いが見えない）。
 *
 * ひな形の文字を探すのでなく、**ひな形のファイルから `config('app.timezone')` を作り直し**、
 * 予約公開を公開面で見る。
 */
final class Round5ServerTimezoneTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private string $timezoneBefore = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezoneBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->timezoneBefore);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function serverEnvExamples(): array
    {
        return [
            'staging' => ['.agentcms/deploy/env.staging.example'],
            'production' => ['.agentcms/deploy/env.production.example'],
        ];
    }

    #[DataProvider('serverEnvExamples')]
    public function test_a_page_scheduled_for_10_00_appears_at_10_00_japan_time_on_the_server(string $example): void
    {
        $this->useTimezoneOf($example);

        // 日本時間 12月1日 8:00 に、10:00 の予約を入れる（編集画面の datetime-local と同じ形）
        $this->travelTo(Carbon::parse('2026-11-30T23:00:00Z'));
        $page = $this->makePublishablePage('open-day');
        $result = app(PublishPage::class)->execute($page->id, $this->context(), '2026-12-01T10:00');
        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        // 管理画面に出る予約の日時
        $presented = app(GetPage::class)->execute($page->id, $this->context())->data['page'];
        $this->assertSame('2026-12-01 10:00', $presented['publishedAtLocal']);

        // 日本時間 9:59:59 ではまだ出ず、10:00:00 で出る
        $this->travelTo(Carbon::parse('2026-12-01T00:59:59Z'));
        $this->get('/open-day')->assertNotFound();

        $this->travelTo(Carbon::parse('2026-12-01T01:00:00Z'));
        $this->get('/open-day')->assertOk()->assertSee('予約した見出し');
    }

    /** 直し 4 回目の「公開前チェックは日本時間で出す」が、日本時間のサーバーで二重にずれない */
    #[DataProvider('serverEnvExamples')]
    public function test_the_readiness_time_is_not_shifted_twice_on_a_japan_time_server(string $example): void
    {
        $this->useTimezoneOf($example);
        Carbon::setTestNow(Carbon::parse('2026-11-23T10:28:00Z')); // 日本時間 19:28

        app(ReadinessResult::class)->record(0);
        SettingsStore::forget();

        $last = app(ReadinessResult::class)->last();
        $this->assertNotNull($last);
        $this->assertSame('11月23日 19:28', $last['label']);
        $this->assertStringEndsWith('+09:00', $last['checkedAt']);
    }

    /**
     * ひな形の `.env` を置いたサーバーで、`config('app.timezone')` が何になるか。
     * `config/app.php` をそのまま読み直す（既定値をここに書き写さない）。
     */
    private function useTimezoneOf(string $example): void
    {
        $values = Dotenv::parse((string) file_get_contents(base_path($example)));
        $before = [$_ENV['APP_TIMEZONE'] ?? null, $_SERVER['APP_TIMEZONE'] ?? null, getenv('APP_TIMEZONE')];

        unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
        putenv('APP_TIMEZONE');
        if (array_key_exists('APP_TIMEZONE', $values)) {
            $_ENV['APP_TIMEZONE'] = $_SERVER['APP_TIMEZONE'] = (string) $values['APP_TIMEZONE'];
        }

        try {
            $timezone = (string) (require base_path('config/app.php'))['timezone'];
        } finally {
            unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
            if ($before[0] !== null) {
                $_ENV['APP_TIMEZONE'] = $before[0];
            }
            if ($before[1] !== null) {
                $_SERVER['APP_TIMEZONE'] = $before[1];
            }
            if ($before[2] !== false) {
                putenv('APP_TIMEZONE='.$before[2]);
            }
        }

        // ここで時間帯の名前を見て止めない — 試験はずれた結果（公開面）で落とす
        // boot 時と同じ 2 か所を揃える
        config()->set('app.timezone', $timezone);
        date_default_timezone_set($timezone);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'fixture');
    }

    /** 公開検証（空ページ不可）を通る中身を持った下書きページ */
    private function makePublishablePage(string $slug): Page
    {
        $result = app(CreatePage::class)->execute(['title' => $slug, 'slug' => $slug], $this->context());
        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        $root = (string) $version->document['root']['id'];

        $section = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $root,
            'component' => ['type' => 'section', 'children' => []],
        ], $this->context());
        $this->assertSame(OperationStatus::Success, $section->status, json_encode($section->errors));

        $children = $section->data['document']['root']['children'];
        $heading = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) end($children)['id'],
            'component' => ['type' => 'heading', 'props' => ['text' => '予約した見出し', 'level' => 2]],
        ], $this->context());
        $this->assertSame(OperationStatus::Success, $heading->status, json_encode($heading->errors));

        return $page;
    }
}

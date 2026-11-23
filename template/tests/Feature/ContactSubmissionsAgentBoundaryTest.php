<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Contact\Models\ContactSubmission;
use Tests\TestCase;

/**
 * B-43: **受信は AI / 外部 API へ出さない。運用者は今までどおり読める。**
 *
 * Phase 9.12 は `OperationCatalog` の中で「受信（`form.submission.*`）は出さない。
 * 本文と連絡先そのもの」と決めていた。ところが 9.12 より前に作られた
 * 旧側の `contact.list` / `contact.get` が残っており、**同じ種類のデータについて
 * 新旧で決定が逆向き**になっていた（`toArray()` 素通しで生 IP まで出ていた）。
 *
 * ここで固定するのは 4 つ:
 *   1. AI（MCP / artisan bridge）から受信が取れない — **`ip` も本文も**
 *   2. 同一オリジン API（`/api/v1`）にも受信の口が無い
 *   3. **管理画面からは今までどおり読める**（口を塞いだのであって、原本は動かしていない）
 *   4. **その管理画面の props にも生 IP は乗らない**（R-12 Stage 2 で足した — B-43 の残り）
 *
 * 9.12 の面（`form.submission.*`）も一緒に見る。片方だけ戻ると、
 * また「新旧で逆向き」に戻るため。
 */
final class ContactSubmissionsAgentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.77';

    private const MESSAGE = '機密の相談内容';

    private const EMAIL = 'himitsu@example.com';

    /** 受信を読む口の operation ID（旧 = contact.*、新 = form.submission.*） */
    private const SUBMISSION_READ_OPERATIONS = [
        'contact.list',
        'contact.get',
        'form.submission.list',
        'form.submission.get',
        'form.submission.export',
    ];

    private function submission(): ContactSubmission
    {
        return ContactSubmission::query()->create([
            'name' => '山田太郎',
            'email' => self::EMAIL,
            'company' => '秘密株式会社',
            'subject' => '相談',
            'message' => self::MESSAGE,
            'status' => 'received',
            'ip' => self::IP,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_受信を読む操作はエージェントの許可一覧に_1_つも無い(): void
    {
        foreach (self::SUBMISSION_READ_OPERATIONS as $id) {
            $this->assertFalse(
                OperationCatalog::allows($id),
                "受信を読む操作 [{$id}] が AI の allowlist に載っている（B-43 / 9.12）",
            );
        }
    }

    public function test_エージェント経路で呼んでも生_ip_も本文も返らない(): void
    {
        $submission = $this->submission();
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'b43');

        foreach ([
            'contact.list' => [],
            'contact.get' => ['id' => (int) $submission->id],
            'form.submission.list' => [],
            'form.submission.get' => ['id' => (int) $submission->id],
            'form.submission.export' => [],
        ] as $id => $input) {
            $result = $catalog->invoke($id, $input, $context);

            $this->assertSame(
                OperationStatus::Forbidden,
                $result->status,
                "[{$id}] が AI から実行できてしまう",
            );

            $encoded = (string) json_encode(
                ['data' => $result->data, 'meta' => $result->meta, 'message' => $result->message],
                JSON_UNESCAPED_UNICODE,
            );

            $this->assertStringNotContainsString(self::IP, $encoded, "[{$id}] の応答に生 IP が入っている");
            $this->assertStringNotContainsString(self::MESSAGE, $encoded, "[{$id}] の応答に本文が入っている");
            $this->assertStringNotContainsString(self::EMAIL, $encoded, "[{$id}] の応答に連絡先が入っている");
        }
    }

    public function test_同一オリジンの操作面に受信の口が無い(): void
    {
        $paths = array_values(array_filter(
            array_map(static fn ($route): string => $route->uri(), Route::getRoutes()->getRoutes()),
            static fn (string $uri): bool => str_starts_with($uri, 'api/v1/')
                && (str_contains($uri, 'contact') || str_contains($uri, 'submission')),
        ));

        $this->assertSame([], $paths, '/api/v1 に受信を読む route が在る（B-43）');
    }

    public function test_同一オリジンの操作面を叩いても生_ip_も本文も返らない(): void
    {
        $submission = $this->submission();
        $admin = $this->admin();

        foreach (['/api/v1/contacts', '/api/v1/contacts/'.$submission->id] as $path) {
            $response = $this->actingAs($admin)->getJson($path);

            $this->assertSame(404, $response->status(), "{$path} がまだ応答する");
            $this->assertStringNotContainsString(self::IP, $response->getContent() ?: '');
            $this->assertStringNotContainsString(self::MESSAGE, $response->getContent() ?: '');
        }
    }

    public function test_管理画面からは今までどおり読める(): void
    {
        $submission = $this->submission();
        $admin = $this->admin();

        $index = $this->actingAs($admin)->get('/admin/contact');
        $index->assertOk();
        $rows = $index->viewData('page')['props']['items']['data'] ?? null;
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(self::MESSAGE, $rows[0]['message']);
        $this->assertSame(self::EMAIL, $rows[0]['email']);

        $show = $this->actingAs($admin)->get('/admin/contact/'.$submission->id);
        $show->assertOk();
        $item = $show->viewData('page')['props']['item'] ?? null;
        $this->assertIsArray($item);
        $this->assertSame(self::MESSAGE, $item['message']);
        $this->assertSame(self::EMAIL, $item['email']);
        $this->assertSame('未対応', $item['status_label']);
    }

    /**
     * **管理画面の props にも生 IP は乗らない**（R-12 Stage 2 / B-43 の残り）。
     *
     * B-43 は AI と外部 API の口を塞いだが、`toArray()` 素通しの props は残っていた。
     * **画面は表示していないので、見ても気づけない** — `data-page` 属性として HTML に出る。
     * だから props の鍵だけでなく、**応答の生文字列**でも見る。
     *
     * 原本は動かさない。Stage 2 の移行が hash を作るのに `$model->ip` が要る。
     */
    public function test_管理画面の_props_にも生_ip_は乗らない(): void
    {
        $submission = $this->submission();
        $admin = $this->admin();

        $index = $this->actingAs($admin)->get('/admin/contact');
        $index->assertOk();
        $rows = $index->viewData('page')['props']['items']['data'] ?? null;
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('ip', $rows[0], '一覧の props に生 IP が乗っている');
        $this->assertStringNotContainsString(
            self::IP,
            $index->getContent() ?: '',
            '一覧の HTML（data-page）に生 IP が出ている',
        );

        $show = $this->actingAs($admin)->get('/admin/contact/'.$submission->id);
        $show->assertOk();
        $item = $show->viewData('page')['props']['item'] ?? null;
        $this->assertIsArray($item);
        $this->assertArrayNotHasKey('ip', $item, '詳細の props に生 IP が乗っている');
        $this->assertStringNotContainsString(
            self::IP,
            $show->getContent() ?: '',
            '詳細の HTML（data-page）に生 IP が出ている',
        );

        // 列そのものは残す（裁定 1 — 旧テーブルは Stage 3 まで原本として在る）
        $this->assertSame(self::IP, $submission->refresh()->ip, '原本の ip 列まで消している');
    }

    public function test_管理画面の状態変更は今までどおり動く(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->admin())
            ->patch('/admin/contact/'.$submission->id.'/status', ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('completed', $submission->refresh()->status->value);
    }
}

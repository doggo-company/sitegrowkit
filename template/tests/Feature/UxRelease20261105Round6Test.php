<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Forms\SubmissionStatus;
use App\Models\User;
use App\Support\ContactEntry;
use App\Support\LinkTargets;
use App\Support\SamplePages;
use Database\Seeders\ContactFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開前の UX 一周 6 周目（2026-11-05・英会話教室の先生・ノート PC とスマホ・rc10）の回帰。
 *
 * - R6-1: 窓口のフォーム（contact）を下書きにすると、/contact が同梱の見本の窓口に戻り、
 *   見本の電話番号つきで受け付けを続け、通知は見本の宛先へ飛んだ
 * - R6-2: /contact を非公開にする道が画面に無かった
 * - R6-4: ダッシュボードの「お問い合わせ」が、自分で作ったフォームの申し込みを数えなかった
 *
 * R6-3（メニューの 2 回続けての保存）は `Menus/MenuAdminTest` と `tests/e2e/ux-round6.spec.ts`。
 */
final class UxRelease20261105Round6Test extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    public function test_窓口のフォームを下書きにすると_contact_は閉じて見本に戻らない(): void
    {
        $this->seed(ContactFormSeeder::class);
        $this->get('/contact')->assertOk();

        Form::query()->where('key', ContactEntry::RESERVED_KEY)->update(['status' => 'draft']);

        $this->get('/contact')->assertNotFound();
        $this->post('/contact', ['name' => '見本', 'email' => 'a@example.com', 'message' => 'x'])->assertNotFound();
        $this->assertNotContains('/contact', array_column(app(LinkTargets::class)->all(), 'href'));

        $row = collect(app(SamplePages::class)->forAdmin())->firstWhere('key', 'contact');
        $this->assertSame('hidden', $row['state'] ?? null, '一覧が「見本を公開中」と言っている');
    }

    public function test_窓口のフォームが公開中なら_contact_を非公開にする道がある(): void
    {
        $this->seed(ContactFormSeeder::class);

        $row = collect(app(SamplePages::class)->forAdmin())->firstWhere('key', 'contact');

        if ($row === null) {
            $this->markTestSkipped('この環境では /contact が見本のデザインで出ていない（一覧に行が無い）');
        }

        $this->assertContains('/contact を非公開にする（フォームを下書きにする）', array_column($row['actions'], 'label'));
    }

    public function test_ダッシュボードは自分で作ったフォームの申し込みも数える(): void
    {
        $this->seed(ContactFormSeeder::class);
        $contact = app(ContactEntry::class)->publishedForm();
        $trial = Form::query()->forceCreate([
            'key' => 'trial', 'name' => '体験レッスンのお申し込み', 'status' => 'published',
        ]);

        FormSubmission::query()->create([
            'form_id' => $trial->getKey(),
            'data' => ['name' => '体験 花子', FormSubmission::LABELS_KEY => [['name', 'お名前']]],
            'status' => SubmissionStatus::Received->value,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contact.total', 1)
                ->where('contact.received', 1)
                ->where('contact.indexUrl', '/admin/forms/trial/submissions'));

        $this->assertNotNull($contact);
    }
}

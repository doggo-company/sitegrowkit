<?php

declare(strict_types=1);

namespace Modules\Contact\Tests;

use AgentCms\Core\Turnstile\FakeVerifier;
use AgentCms\Core\Turnstile\TurnstileVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Contact\Mail\AdminNotification;
use Modules\Contact\Mail\AutoReply;
use Modules\Contact\Models\ContactStatus;
use Modules\Contact\Models\ContactSubmission;
use RuntimeException;
use Tests\TestCase;

/**
 * 仕様 CONTACT FORM の検証:
 * Validation → Turnstile → DB 保存 → 管理者通知 → 自動返信 → 通知結果記録。
 * 最重要: メール送信に失敗しても問い合わせデータを失わないこと。
 */
final class ContactPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_INPUT = [
        'name' => '山田太郎',
        'email' => 'taro@example.com',
        'company' => 'テスト株式会社',
        'subject' => 'estimate',
        'message' => 'お見積もりをお願いします。',
        'cf-turnstile-response' => 'dummy-token',
    ];

    public function test_happy_path_saves_submission_and_sends_both_mails(): void
    {
        Mail::fake();
        $this->app->instance(TurnstileVerifier::class, new FakeVerifier(pass: true));

        $response = $this->post('/contact', self::VALID_INPUT);

        $response->assertRedirect('/contact')->assertSessionHas('contact_success');

        $submission = ContactSubmission::sole();
        $this->assertSame('山田太郎', $submission->name);
        $this->assertSame(ContactStatus::Received, $submission->status);
        $this->assertNotNull($submission->admin_notified_at);
        $this->assertNotNull($submission->autoreply_sent_at);
        $this->assertNull($submission->admin_notification_error);
        $this->assertSame('notification_sent', $submission->notificationState());

        Mail::assertSent(AdminNotification::class, 1);
        Mail::assertSent(AutoReply::class, fn (AutoReply $mail) => $mail->hasTo('taro@example.com'));
    }

    public function test_turnstile_failure_rejects_without_saving(): void
    {
        Mail::fake();
        $this->app->instance(TurnstileVerifier::class, new FakeVerifier(pass: false));

        $response = $this->from('/contact')->post('/contact', self::VALID_INPUT);

        $response->assertRedirect('/contact')->assertSessionHasErrors('turnstile');
        $this->assertSame(0, ContactSubmission::count());
        Mail::assertNothingSent();
    }

    public function test_missing_turnstile_token_rejects_without_saving(): void
    {
        Mail::fake();
        $this->app->instance(TurnstileVerifier::class, new FakeVerifier(pass: true));

        $input = self::VALID_INPUT;
        unset($input['cf-turnstile-response']);
        $this->post('/contact', $input)->assertSessionHasErrors('turnstile');

        $this->assertSame(0, ContactSubmission::count());
    }

    public function test_validation_errors_reject_without_saving(): void
    {
        $this->app->instance(TurnstileVerifier::class, new FakeVerifier(pass: true));

        $this->post('/contact', ['email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        $this->assertSame(0, ContactSubmission::count());
    }

    public function test_mail_failure_does_not_lose_submission(): void
    {
        // 最重要ルールの検証: メール送信が例外を投げてもデータは保存され、失敗が記録される
        $this->app->instance(TurnstileVerifier::class, new FakeVerifier(pass: true));
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        $response = $this->post('/contact', self::VALID_INPUT);

        $response->assertRedirect('/contact')->assertSessionHas('contact_success');

        $submission = ContactSubmission::sole();
        $this->assertSame('山田太郎', $submission->name);
        $this->assertNull($submission->admin_notified_at);
        $this->assertSame('SMTP down', $submission->admin_notification_error);
        $this->assertSame('SMTP down', $submission->autoreply_error);
        $this->assertSame('notification_failed', $submission->notificationState());
    }

    public function test_contact_form_page_renders(): void
    {
        // Phase 5: import されたフォーム（ご用件 select + Turnstile widget）が表示される
        $this->get('/contact')->assertOk()
            ->assertSee('ご用件')
            ->assertSee('cf-turnstile', false);
    }
}

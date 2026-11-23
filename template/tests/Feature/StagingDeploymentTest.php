<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class StagingDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_returns_ok_with_db_and_storage(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'db' => true, 'storage' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_healthz_does_not_leak_environment_details(): void
    {
        $body = (string) $this->getJson('/healthz')->getContent();
        foreach (['DB_', 'APP_KEY', 'password', 'mysql', 'path'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
    }

    public function test_robots_allows_indexing_outside_staging(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_staging_blocks_indexing_via_robots_and_header(): void
    {
        $this->app['env'] = 'staging';

        $response = $this->get('/robots.txt');
        $response->assertOk()
            ->assertSee('Disallow: /')
            ->assertDontSee('Allow: /')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_staging_mail_redirects_all_recipients_to_safe_address(): void
    {
        $this->app['env'] = 'staging';
        config([
            'mail.default' => 'array',
            'agentcms.mail.staging_redirect_to' => 'safe@example.test',
        ]);

        (new AppServiceProvider($this->app))->boot();

        Mail::to('customer@example.com')->send(new class extends Mailable
        {
            public function build(): self
            {
                return $this->subject('staging test')->html('<p>test</p>');
            }
        });

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $to = collect($messages->first()->getEnvelope()->getRecipients())
            ->map(fn ($address) => $address->getAddress());
        $this->assertSame(['safe@example.test'], $to->all());
    }

    public function test_staging_without_redirect_address_falls_back_to_log_mailer(): void
    {
        $this->app['env'] = 'staging';
        config([
            'mail.default' => 'smtp',
            'agentcms.mail.staging_redirect_to' => '',
            'agentcms.mail.admin_to' => 'admin@example.test', // 未設定扱いの既定値
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_production_mail_is_not_redirected(): void
    {
        config([
            'mail.default' => 'array',
            'agentcms.mail.staging_redirect_to' => 'safe@example.test',
        ]);

        (new AppServiceProvider($this->app))->boot();

        Mail::to('customer@example.com')->send(new class extends Mailable
        {
            public function build(): self
            {
                return $this->subject('non-staging test')->html('<p>test</p>');
            }
        });

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $to = collect($messages->first()->getEnvelope()->getRecipients())
            ->map(fn ($address) => $address->getAddress());
        $this->assertSame(['customer@example.com'], $to->all());
    }
}

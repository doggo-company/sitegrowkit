<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Contact\Application\GetContact;
use Modules\Contact\Application\ListContacts;
use Modules\Contact\Application\UpdateContactStatus;
use Modules\Contact\Models\ContactSubmission;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Application\GetMedia;
use Modules\Media\Application\ListMedia;
use Modules\Media\Application\UpdateMedia;
use Modules\Media\Application\UploadMedia;
use Modules\Media\Models\Media;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * Phase 9.1B / STEP 9: Media / Contact の Application Service。
 *
 * 管理画面と同じ検証・削除安全性・確認・監査を、AI（MCP）経路からも通ること。
 */
final class MediaContactOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'test-agent');
    }

    private function upload(string $name = 'テスト画像'): Media
    {
        $result = app(UploadMedia::class)->execute(
            UploadedFile::fake()->image('photo.png', 400, 300),
            $this->agent(),
            $name,
            '代替テキスト',
        );

        $this->assertSame(OperationStatus::Success, $result->status);

        return Media::query()->findOrFail($result->data['media']['id']);
    }

    public function test_upload_stores_the_file_and_audits_the_mutation(): void
    {
        $media = $this->upload();

        $this->assertSame(400, $media->width);
        $this->assertStringStartsWith('media/', $media->path);
        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'mcp',
            'operation' => 'media.upload',
            'resource_type' => 'media',
            'result' => 'success',
        ]);
    }

    public function test_list_marks_media_that_is_in_use(): void
    {
        $media = $this->upload();
        $result = app(ListMedia::class)->execute($this->agent());

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertFalse($result->data['items'][0]['used']);

        Work::query()->create([
            'title' => '実績',
            'slug' => 'work-1',
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'main_image_id' => $media->id,
        ]);

        $result = app(ListMedia::class)->execute($this->agent());
        $this->assertTrue($result->data['items'][0]['used']);
    }

    public function test_get_returns_usages_so_delete_safety_is_visible(): void
    {
        $media = $this->upload();
        $result = app(GetMedia::class)->execute((int) $media->id, $this->agent());

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertArrayHasKey('usages', $result->data['media']);
    }

    public function test_update_rejects_an_empty_name(): void
    {
        $media = $this->upload();
        $result = app(UpdateMedia::class)->execute((int) $media->id, ['name' => ''], $this->agent());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('name', $result->errors);
    }

    public function test_media_in_use_cannot_be_deleted_by_an_agent(): void
    {
        $media = $this->upload();
        Work::query()->create([
            'title' => '実績',
            'slug' => 'work-1',
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'main_image_id' => $media->id,
        ]);

        $result = app(DeleteMedia::class)->execute((int) $media->id, $this->agent());

        // 使用中は確認以前に拒否される（DELETE SAFETY / ADR-0006）
        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_unused_media_delete_requires_confirmation_then_removes_files(): void
    {
        $media = $this->upload();
        $path = $media->path;

        $first = app(DeleteMedia::class)->execute((int) $media->id, $this->agent());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        Storage::disk('public')->assertExists($path);

        $second = app(DeleteMedia::class)->execute(
            (int) $media->id,
            $this->agent()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertSame(OperationStatus::Success, $second->status);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'media.delete',
            'classification' => 'destructive',
            'result' => 'success',
        ]);
    }

    public function test_contacts_can_be_listed_and_filtered_by_status(): void
    {
        ContactSubmission::query()->create([
            'name' => '山田太郎', 'email' => 'a@example.com', 'subject' => 'お問い合わせ', 'message' => '本文', 'status' => 'received',
        ]);
        ContactSubmission::query()->create([
            'name' => '鈴木花子', 'email' => 'b@example.com', 'subject' => 'お問い合わせ', 'message' => '本文', 'status' => 'completed',
        ]);

        $all = app(ListContacts::class)->execute($this->agent());
        $this->assertSame(2, $all->meta['total']);

        $done = app(ListContacts::class)->execute($this->agent(), status: 'completed');
        $this->assertSame(1, $done->meta['total']);
    }

    public function test_contact_status_update_is_validated_and_audited_without_message_content(): void
    {
        $submission = ContactSubmission::query()->create([
            'name' => '山田太郎', 'email' => 'a@example.com', 'subject' => 'お問い合わせ', 'message' => '機密の相談内容', 'status' => 'received',
        ]);

        $invalid = app(UpdateContactStatus::class)->execute((int) $submission->id, 'not_a_status', $this->agent());
        $this->assertSame(OperationStatus::ValidationError, $invalid->status);

        $ok = app(UpdateContactStatus::class)->execute((int) $submission->id, 'completed', $this->agent());
        $this->assertSame(OperationStatus::Success, $ok->status);
        $this->assertSame('completed', $submission->refresh()->status->value);

        $metadata = (string) \DB::table('audit_logs')
            ->where('operation', 'contact.updateStatus')->value('metadata');
        $this->assertStringNotContainsString('機密の相談内容', $metadata);
    }

    public function test_contact_get_returns_not_found_for_a_missing_id(): void
    {
        $result = app(GetContact::class)->execute(999, $this->agent());

        $this->assertSame(OperationStatus::NotFound, $result->status);
    }
}

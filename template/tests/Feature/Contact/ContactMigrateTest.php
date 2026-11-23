<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use AgentCms\Core\Forms\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\Models\ContactSubmission;
use Tests\TestCase;

/**
 * 旧お問い合わせの受信をフォームの受信へ移す（R-12 Stage 2 / B）。
 *
 * ここで固定するのは 5 つ:
 *   1. **既定は数えるだけ**（`--force` を付けるまで 1 件も増えない）
 *   2. **冪等**（2 回流しても二重にならない）
 *   3. **生 IP は持ち込まない**（裁定 1 — hash して移す）
 *   4. **原本は消さない**（裁定 1 — Stage 3 まで残す）
 *   5. **行き先の項目が無い列も捨てない**（問い合わせ主が書いたものだから）
 */
final class ContactMigrateTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.77';

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishForm(['name' => 'お名前', 'email' => 'メール', 'message' => 'ご相談内容']);
    }

    // ── 1. 既定は数えるだけ ──────────────────────────────

    public function test_forceを付けなければ1件も増えない(): void
    {
        $this->legacy();

        $this->artisan('agentcms:contact-migrate')->assertSuccessful();

        $this->assertSame(0, FormSubmission::query()->count(), '数えるだけのはずが移している');
        $this->assertSame(0, DB::table('contact_submission_migrations')->count());
    }

    /** **強いほうが安全側**（`--dry-run` は `--force` に勝つ） */
    public function test_dryrunはforceに勝つ(): void
    {
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force --dry-run')->assertSuccessful();

        $this->assertSame(0, FormSubmission::query()->count(), '--dry-run を付けたのに移している');
    }

    // ── 移す ────────────────────────────────────────────

    public function test_値と見出しを揃えて移す(): void
    {
        $this->legacy(['name' => '山田太郎', 'email' => 'a@example.test', 'message' => '本文です']);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame('山田太郎', $submission->values()['name']);
        $this->assertSame('a@example.test', $submission->values()['email']);
        $this->assertSame('本文です', $submission->values()['message']);
        // 見出しは**フォームの項目のもの**（旧 schema の「お問い合わせ内容」ではない）
        $this->assertSame('ご相談内容', $submission->labelFor('message'));
    }

    /** 旧と新の状態 enum は 4 値とも同じ語彙である（`SubmissionStatus` の docblock） */
    public function test_対応状態をそのまま写す(): void
    {
        $this->legacy(['status' => 'completed']);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertSame('completed', FormSubmission::query()->firstOrFail()->status);
    }

    /** **受信日時を写す。** 移行した日にすると「いつ届いたか」が壊れる */
    public function test_受信日時を写す(): void
    {
        $this->legacy(['created_at' => '2026-05-01 09:30:00']);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertSame(
            '2026-05-01 09:30:00',
            (string) FormSubmission::query()->firstOrFail()->created_at?->format('Y-m-d H:i:s'),
        );
    }

    // ── 2. 冪等 ─────────────────────────────────────────

    public function test_2回流しても二重にならない(): void
    {
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();
        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertSame(1, FormSubmission::query()->count(), '2 回目で二重に増えている');
        $this->assertSame(1, DB::table('contact_submission_migrations')->count());
    }

    /** **あとから届いた分だけ**移る */
    public function test_2回目は新しい分だけ移す(): void
    {
        $this->legacy(['email' => 'one@example.test']);
        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->legacy(['email' => 'two@example.test']);
        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertSame(2, FormSubmission::query()->count());
    }

    // ── 3. 生 IP は持ち込まない ──────────────────────────

    public function test_生ipは持ち込まずhashにする(): void
    {
        $this->legacy(['ip' => self::IP]);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame(
            hash('sha256', (string) config('app.key').'|'.self::IP),
            $submission->ip_hash,
            '他の面と同じ式で hash していない',
        );

        // 生 IP がどこにも残っていない（値にも見出しにも）
        $this->assertStringNotContainsString(
            self::IP,
            (string) json_encode($submission->data, JSON_UNESCAPED_UNICODE),
            '移した受信に生 IP が入っている',
        );
    }

    public function test_ipが無ければhashもnull(): void
    {
        $this->legacy(['ip' => null]);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertNull(FormSubmission::query()->firstOrFail()->ip_hash);
    }

    // ── 4. 原本は消さない ────────────────────────────────

    public function test_旧テーブルは1件も減らない(): void
    {
        $this->legacy();
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertSame(2, ContactSubmission::query()->count(), '原本を消している（裁定 1 に反する）');
    }

    // ── 5. 行き先の項目が無い列も捨てない ────────────────

    public function test_行き先の項目が無い列も捨てない(): void
    {
        $this->legacy(['company' => '秘密株式会社', 'subject' => 'estimate']);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame('秘密株式会社', $submission->values()['company'] ?? null, '会社名を捨てている');
        $this->assertSame('estimate', $submission->values()['subject'] ?? null, '件名を捨てている');
        // 見出しは**旧 schema のもの**を使う（ここで文言を作らない）
        $this->assertSame('会社名', $submission->labelFor('company'));
        $this->assertSame('件名', $submission->labelFor('subject'));
    }

    /** **空の任意項目を「答えた」ことにしない** */
    public function test_空の任意項目は入れない(): void
    {
        $this->legacy(['company' => null]);

        $this->artisan('agentcms:contact-migrate --force')->assertSuccessful();

        $this->assertArrayNotHasKey('company', FormSubmission::query()->firstOrFail()->values());
    }

    // ── 対応の指定 ───────────────────────────────────────

    public function test_mapで行き先を指定できる(): void
    {
        $this->publishForm(['name' => 'お名前', 'email' => 'メール', 'body' => 'ご相談内容']);
        $this->legacy(['message' => '本文です']);

        $this->artisan('agentcms:contact-migrate --force --map=message:body')->assertSuccessful();

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame('本文です', $submission->values()['body'] ?? null);
        $this->assertArrayNotHasKey('message', $submission->values());
        $this->assertSame('ご相談内容', $submission->labelFor('body'));
    }

    /** **壊れた指定では動かない**（黙って既定へ落ちない） */
    public function test_mapの右側が項目に無ければ止まる(): void
    {
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force --map=message:nope')->assertFailed();

        $this->assertSame(0, FormSubmission::query()->count(), '止まったのに移している');
    }

    public function test_mapの左側が旧列でなければ止まる(): void
    {
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force --map=nope:name')->assertFailed();

        $this->assertSame(0, FormSubmission::query()->count());
    }

    // ── 前提が無いときは止まる ───────────────────────────

    public function test_フォームが無ければ止まる(): void
    {
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force --form=missing')->assertFailed();

        $this->assertSame(0, FormSubmission::query()->count());
    }

    /** **項目が無いフォームへ移すと、全部が「定義に無い key」になる** — 先に止める */
    public function test_項目が無いフォームへは移さない(): void
    {
        DB::table('forms')->where('key', 'contact')->update(['field_group_id' => null]);
        $this->legacy();

        $this->artisan('agentcms:contact-migrate --force')->assertFailed();

        $this->assertSame(0, FormSubmission::query()->count());
    }

    // ── 道具 ────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    private function legacy(array $attributes = []): ContactSubmission
    {
        $row = ContactSubmission::query()->create([
            'name' => '山田太郎',
            'email' => 'himitsu@example.test',
            'company' => null,
            'subject' => 'consultation',
            'message' => '相談したいことがあります',
            'status' => 'received',
            'ip' => self::IP,
            ...$attributes,
        ]);

        if (isset($attributes['created_at'])) {
            $row->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        return $row->refresh();
    }

    /** @param array<string, string> $fields key => 表示名 */
    private function publishForm(array $fields): void
    {
        $groupId = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.strtolower(substr($groupId, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode(
                array_map(
                    static fn (string $key, string $label): array => [
                        'id' => (string) Str::ulid(),
                        'key' => $key,
                        'type' => $key === 'email' ? 'email' : 'text',
                        'label' => $label,
                        'required' => $key !== 'company',
                    ],
                    array_keys($fields),
                    array_values($fields),
                ),
                JSON_UNESCAPED_UNICODE,
            ),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * **束を「フォーム」へ割り当てる。** これが無いと `FormFields` は
         * 項目を 1 つも返さない（投稿タイプ用の束をフォームへ流用させないための門）。
         */
        DB::table('field_group_locations')->insert([
            'field_group_id' => $groupId,
            'kind' => 'form',
            'value' => 'contact',
        ]);

        DB::table('forms')->updateOrInsert(
            ['key' => 'contact'],
            [
                'name' => 'お問い合わせ', 'field_group_id' => $groupId,
                'status' => 'published', 'confirm_step' => true, 'submit_label' => '送信する',
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }
}

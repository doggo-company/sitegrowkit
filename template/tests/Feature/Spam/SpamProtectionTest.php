<?php

declare(strict_types=1);

namespace Tests\Feature\Spam;

use AgentCms\Core\Spam\SpamBlocks;
use AgentCms\Core\Spam\SpamReason;
use AgentCms\Core\Spam\SpamSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 書き込み口で共用する spam 対策（Phase 9.13 / STAGE 26B・SPEC §F.1）。
 *
 * ここで固定するのは、**1 つの実装を 2 つの口が使えること**と、
 * **9.12 で数えた分が移行で消えないこと**である。
 */
final class SpamProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_表と索引ができている(): void
    {
        $this->assertTrue(Schema::hasTable(SpamBlocks::TABLE));
        // 旧表は畳んである（書き込み口ごとに表を増やさない）
        $this->assertFalse(Schema::hasTable('form_spam_blocks'));

        $names = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes(SpamBlocks::TABLE),
        );

        $this->assertContains('uq_spam_block_subject_day', $names);
    }

    public function test_種類が違えば別々に数える(): void
    {
        $blocks = app(SpamBlocks::class);

        $blocks->record(new SpamSubject(kind: 'form', id: 7), SpamReason::Honeypot);
        $blocks->record(new SpamSubject(kind: 'comment', id: 'post'), SpamReason::Honeypot);
        $blocks->record(new SpamSubject(kind: 'comment', id: 'post'), SpamReason::Honeypot);

        $this->assertSame(1, (int) DB::table(SpamBlocks::TABLE)
            ->where('kind', 'form')->where('subject_id', '7')->value('hits'));
        $this->assertSame(2, (int) DB::table(SpamBlocks::TABLE)
            ->where('kind', 'comment')->where('subject_id', 'post')->value('hits'));
    }

    public function test_理由ごとに行を分ける(): void
    {
        $blocks = app(SpamBlocks::class);
        $subject = new SpamSubject(kind: 'comment', id: 'post');

        $blocks->record($subject, SpamReason::Honeypot);
        $blocks->record($subject, SpamReason::RateLimit);

        // 「何が効いているか」を後から測れるようにする
        $this->assertSame(2, DB::table(SpamBlocks::TABLE)->where('subject_id', 'post')->count());
    }

    public function test_対象が消えたら記録も片付く(): void
    {
        $blocks = app(SpamBlocks::class);
        $subject = new SpamSubject(kind: 'form', id: 42);

        $blocks->record($subject, SpamReason::Honeypot);
        $blocks->forget($subject);

        // FK が張れない代わりに、消す側が自分で片付ける
        $this->assertSame(0, DB::table(SpamBlocks::TABLE)->where('subject_id', '42')->count());
    }

    public function test_9_12で数えた分は移行で消えない(): void
    {
        // 旧表の状態へ戻してから、もう一度 up する
        $this->migration()->down();

        $formId = DB::table('forms')->insertGetId([
            'key' => 'legacy', 'name' => '移行前', 'status' => 'draft',
            'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('form_spam_blocks')->insert([
            'form_id' => $formId, 'reason' => 'honeypot',
            'blocked_on' => '2026-08-20', 'hits' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();

        $row = DB::table(SpamBlocks::TABLE)
            ->where('kind', 'form')
            ->where('subject_id', (string) $formId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(12, (int) $row->hits);
        $this->assertSame('honeypot', $row->reason);
        $this->assertFalse(Schema::hasTable('form_spam_blocks'));
    }

    public function test_移行を戻すと旧表へ書き戻る(): void
    {
        $formId = DB::table('forms')->insertGetId([
            'key' => 'back', 'name' => '戻す', 'status' => 'draft',
            'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(SpamBlocks::class)->record(new SpamSubject(kind: 'form', id: $formId), SpamReason::Bot);

        $this->migration()->down();

        $this->assertTrue(Schema::hasTable('form_spam_blocks'));
        $this->assertSame(1, (int) DB::table('form_spam_blocks')->where('form_id', $formId)->value('hits'));

        $this->migration()->up();
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('core/database/migrations/2026_08_23_000010_create_spam_blocks_table.php');

        return $migration;
    }
}

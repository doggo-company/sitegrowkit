<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\FieldGroups\UpdateFieldGroup;
use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 項目を外したとき、入力済みの値はどうなるのか（B-32）。
 *
 * ## なぜ試験にするのか
 *
 * 確認ダイアログの 2 行が**同じ画面で食い違っていた** —
 * 「フィールドを削除すると、そのデータは失われます」と
 * 「入力済みの値は削除されません。束へ戻せば再び表示されます。」。
 * **一番読まれる場所で、事実が 2 通りに書かれていた**（F-26 型）。
 *
 * 文言を直すには**まず何が本当かを測る**必要があり、測った以上、
 * **その事実をここで留める**。文言だけ直しても、挙動が変われば同じ食い違いに戻る。
 */
final class FieldRemovalFactsTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private ?User $boss = null;

    private string $groupId = '';

    public function test_項目を外しても入力済みの値は残る(): void
    {
        $entryId = $this->makeEntryWithExtraNote();

        $this->replaceFieldWith('other_note', 'ほかの備考');

        // **DB からは消えない**
        $this->assertSame('あとで外す値', $this->stored($entryId)['extra_note'] ?? null);

        // ただし**読めなくなる**（schema に無いので返らない）
        $this->assertNull($this->read($entryId, 'extra_note'));
    }

    public function test_外したまま記事を保存し直しても値は残る(): void
    {
        /*
         * **ここが「消えません」の実体である。** 外した後に編集して保存するのは
         * 一番ありそうな操作で、そこで消えるなら「消えません」は嘘になる。
         */
        $entryId = $this->makeEntryWithExtraNote();
        $this->replaceFieldWith('other_note', 'ほかの備考');

        $result = app(UpdateContent::class)->execute('post', $entryId, [
            'title' => '題を直した', 'slug' => 'kiji', 'status' => 'draft',
            'body' => '<p>本文</p>', 'other_note' => 'あたらしい値',
        ], $this->ctx());

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame('あとで外す値', $this->stored($entryId)['extra_note'] ?? null);
    }

    public function test_同じキーで戻すとまた読める(): void
    {
        $entryId = $this->makeEntryWithExtraNote();
        $this->replaceFieldWith('other_note', 'ほかの備考');
        $this->replaceFieldWith('extra_note', '備考');

        $this->assertSame('あとで外す値', $this->read($entryId, 'extra_note'));
    }

    public function test_確認文は測った事実と食い違わない(): void
    {
        $this->makeEntryWithExtraNote();

        $result = app(UpdateFieldGroup::class)->execute($this->groupId, [
            'key' => 'extra_fields',
            'label' => '追加の項目',
            'locations' => [['kind' => 'content_type', 'value' => 'post']],
            'fields' => [['key' => 'other_note', 'type' => 'text', 'label' => 'ほかの備考']],
        ], $this->ctx());

        $impact = $result->confirmation?->impact ?? [];

        // **「失われます」とは言わない**（実測では残る）
        foreach ($impact as $line) {
            $this->assertStringNotContainsString('失われます', $line);
        }

        $this->assertContains('入力済みの値が消えることはありません。', $impact);
        $this->assertContains(
            'extra_note: この項目は画面に出なくなります。入力済みの値は消えません',
            $impact,
        );

        /*
         * **条件つきの約束は書かない。** 「戻せば再び表示されます」は
         * **同じキーで戻したとき**だけ本当で、キーを書かせない面では嘘になる。
         */
        foreach ($impact as $line) {
            $this->assertStringNotContainsString('戻せば', $line);
        }
    }

    // ── helpers ──────────────────────────────

    private function makeEntryWithExtraNote(): int
    {
        $this->saveGroup(null, [['key' => 'extra_note', 'type' => 'text', 'label' => '備考']]);
        $this->groupId = (string) DB::table('field_groups')->value('id');
        app()->forgetScopedInstances();

        $created = app(CreateContent::class)->execute('post', [
            'title' => '記事', 'slug' => 'kiji', 'status' => 'draft',
            'body' => '<p>本文</p>', 'extra_note' => 'あとで外す値',
        ], $this->ctx());

        $this->assertTrue($created->isSuccess());

        return (int) ($created->data['item']['id'] ?? 0);
    }

    private function replaceFieldWith(string $key, string $label): void
    {
        $this->saveGroup($this->groupId, [['key' => $key, 'type' => 'text', 'label' => $label]]);
        app()->forgetScopedInstances();
    }

    /** @param list<array<string, mixed>> $fields */
    private function saveGroup(?string $id, array $fields): void
    {
        $payload = [
            'key' => 'extra_fields',
            'label' => '追加の項目',
            'locations' => [['kind' => 'content_type', 'value' => 'post']],
            'fields' => $fields,
        ];

        if ($id === null) {
            $this->actingAs($this->boss())->post('/admin/field-groups', $payload)->assertSessionHasNoErrors();

            return;
        }

        $this->actingAs($this->boss())->patch("/admin/field-groups/{$id}", $payload);

        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $token = is_array($flash) && is_array($flash['confirmation'] ?? null)
            ? ($flash['confirmation']['token'] ?? null)
            : null;

        if (is_string($token)) {
            $this->actingAs($this->boss())
                ->patch("/admin/field-groups/{$id}", $payload + ['confirmation_token' => $token]);
        }
    }

    /** DB に入っている `data` JSON（**画面を通さずに見る**） @return array<string, mixed> */
    private function stored(int $entryId): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) DB::table('post')->where('id', $entryId)->value('data'), true) ?: [];

        return $data;
    }

    private function read(int $entryId, string $key): mixed
    {
        app()->forgetScopedInstances();

        $item = app(GetContent::class)->execute('post', $entryId, $this->ctx())->data['item'] ?? [];

        return $item[$key] ?? null;
    }

    private function ctx(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'admin');
    }

    private function boss(): User
    {
        // カスタムフィールドの画面は作りの画面（制作者だけ・2026-09-28）
        return $this->boss ??= User::factory()->create(['role' => Role::Developer->value]);
    }
}

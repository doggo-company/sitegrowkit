<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Spam\HoneypotCheck;
use AgentCms\Core\Spam\SpamBlocks;
use AgentCms\Core\Turnstile\TurnstileVerifier;
use App\Mail\FormAdminNotification;
use App\Mail\FormAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 公開フォームの送信（Phase 9.12 / STAGE 25C・SPEC §C・§F・§G）。
 *
 * ここで固定するのは、**メールが落ちても受信が残ること**と、
 * **弾き方が理由ごとに違うこと**、そして**生の IP を持たないこと**である。
 */
final class PublicFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('form-submit');

        /*
         * **鍵は明示的に空にする。** 開発機の `.env` に Turnstile の鍵が入っていると、
         * テストの結果が machine ごとに変わる。既定は「鍵が無い project」とし、
         * 鍵があるときの挙動は専用のテストで確かめる。
         */
        config(['agentcms.turnstile.secret_key' => '', 'agentcms.turnstile.site_key' => '']);
    }

    // ── 見え方 ─────────────────────────────────────────────

    public function test_下書きのフォームは存在しない(): void
    {
        $this->form('secret', 'draft');

        $this->get('/forms/secret')->assertNotFound();
        $this->post('/forms/secret', $this->input())->assertNotFound();
    }

    public function test_受付中のフォームは入力画面が出る(): void
    {
        $this->form('contact-us');

        $this->get('/forms/contact-us')
            ->assertOk()
            ->assertSee('お名前')
            ->assertSee('メールアドレス')
            // 人には見えない項目は置いてある（SPEC §F.2）
            ->assertSee(HoneypotCheck::FIELD);
    }

    public function test_受付を終了したフォームは画面は出るが送れない(): void
    {
        $this->form('contact-us', 'closed');

        // 配ってしまった URL を無言の 404 にしない
        $this->get('/forms/contact-us')->assertOk()->assertSee('受付を終了しました');

        $this->post('/forms/contact-us', $this->input())
            ->assertRedirect('/forms/contact-us')
            ->assertSessionHasErrors('form');

        $this->assertSame(0, FormSubmission::query()->count());
    }

    /**
     * 二度押しは 1 件（UX 一周 8 周目 R8-3: スマホで「送信」を二度タップすると 2 件受け付け、
     * 店にもお客さんにも同じメールが 2 通ずつ届いた）。内容が違えば別の送信として受ける。
     */
    public function test_同じ内容を続けて送っても1件として受け付ける(): void
    {
        $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input())->assertRedirect('/forms/contact-us/complete');
        $this->post('/forms/contact-us', $this->input())->assertRedirect('/forms/contact-us/complete');

        $this->assertSame(1, FormSubmission::query()->count());
        Mail::assertSent(FormAdminNotification::class, 1);

        $this->post('/forms/contact-us', ['name' => '山田花子', 'email' => 'hanako@example.test'])->assertRedirect('/forms/contact-us/complete');
        $this->assertSame(2, FormSubmission::query()->count());
    }

    public function test_存在しないフォームは404(): void
    {
        $this->get('/forms/nope')->assertNotFound();
    }

    // ── 送信 ────────────────────────────────────────────────

    public function test_送信すると保存されて完了画面へ行く(): void
    {
        $form = $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input())
            ->assertRedirect('/forms/contact-us/complete');

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame($form->getKey(), $submission->form_id);
        $this->assertSame('山田太郎', $submission->values()['name']);
        // そのとき画面に出ていた表示名も一緒に持つ（OG4）
        $this->assertSame('お名前', $submission->labelFor('name'));
    }

    public function test_完了画面は送信した人にだけ出る(): void
    {
        $this->form('contact-us');

        // 直接叩いただけの人へ「送信しました」と言わない
        $this->get('/forms/contact-us/complete')->assertRedirect('/forms/contact-us');

        $this->post('/forms/contact-us', $this->input());
        $this->get('/forms/contact-us/complete')->assertOk()->assertSee('送信しました');
    }

    public function test_確認画面を挟むと入力値がそのまま出る(): void
    {
        $this->form('contact-us');

        $this->post('/forms/contact-us/confirm', $this->input())
            ->assertOk()
            ->assertSee('山田太郎')
            ->assertSee('taro@example.test')
            ->assertSee('まだ送信は完了していません');

        // 確認しただけでは保存しない
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_確認を通っても保存の直前にもう一度検証する(): void
    {
        $this->form('contact-us');

        // 確認画面の hidden は書き換えられる（SPEC §C.2）
        $this->post('/forms/contact-us', ['name' => '山田太郎', 'email' => 'not-an-email'])
            ->assertRedirect('/forms/contact-us')
            ->assertSessionHasErrors('email');

        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_必須が空なら入力画面へ戻る(): void
    {
        $this->form('contact-us');

        $this->post('/forms/contact-us', ['email' => 'taro@example.test'])
            ->assertRedirect('/forms/contact-us')
            ->assertSessionHasErrors('name');
    }

    // ── メールが落ちても失わない（受入条件 3・OG12） ───────────

    public function test_メールが落ちても受信は残る(): void
    {
        $this->form('contact-us');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->post('/forms/contact-us', $this->input())
            ->assertRedirect('/forms/contact-us/complete');

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame('山田太郎', $submission->values()['name']);
        // 失敗したことは記録する。握りつぶさない
        $this->assertNull($submission->admin_notified_at);
        $this->assertNotNull($submission->admin_notification_error);
    }

    public function test_管理者通知と自動返信が送られる(): void
    {
        $this->form('contact-us', autoreplyField: 'email');

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(FormAdminNotification::class);
        Mail::assertSent(
            FormAutoReply::class,
            static fn (FormAutoReply $mail): bool => $mail->hasTo('taro@example.test'),
        );

        $this->assertNotNull(FormSubmission::query()->firstOrFail()->autoreply_sent_at);
    }

    /**
     * 通知先を複数書いたら、**全員へ届く**（R-11 ⑤）。
     *
     * 以前は 1 カラム 1 アドレスで、`Mail::to("a@…, b@…")` は
     * **1 つの宛先として扱われて必ず失敗した**（書けるのに届かない）。
     */
    public function test_通知先を複数書いたら全員へ届く(): void
    {
        $this->form('contact-us', notifyTo: 'ops@example.test,sales@example.test');

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(
            FormAdminNotification::class,
            static fn (FormAdminNotification $mail): bool => $mail->hasTo('ops@example.test')
                && $mail->hasTo('sales@example.test'),
        );

        $this->assertNotNull(FormSubmission::query()->firstOrFail()->admin_notified_at);
    }

    /**
     * 署名は**本文とは別に持つ**（R-11 ③）。
     * 本文の末尾に書かせると、文面を直すたびに署名も巻き込んで消える。
     */
    public function test_署名を設定すると自動返信の末尾に出る(): void
    {
        $form = $this->form('contact-us', autoreplyField: 'email');
        $form->forceFill(['autoreply_signature' => "みどり工房\nkyoto@example.test"])->save();

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(FormAutoReply::class, function (FormAutoReply $mail): bool {
            // **末尾に出ること**を見る（本文の中に混ざっていても署名ではない）
            $body = rtrim(strip_tags((string) $mail->render()));

            return str_ends_with($body, 'kyoto@example.test')
                && str_contains($body, 'みどり工房');
        });
    }

    /** 空のままなら、これまでどおりサイト名 1 行（導入だけで文面を変えない） */
    public function test_署名が空なら従来どおりサイト名が出る(): void
    {
        app(SettingsStore::class)->put(['site_name' => 'テスト工房']);
        $this->form('contact-us', autoreplyField: 'email');

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(FormAutoReply::class, function (FormAutoReply $mail): bool {
            /*
             * **「署名が出ない」だけでは足りない。**
             * サイト名は挨拶文にも出るので、`str_contains` では
             * 末尾の 1 行が消えたことに気づけない（実測: 変異が素通りした）。
             */
            $body = rtrim(strip_tags((string) $mail->render()));

            return str_ends_with($body, 'テスト工房');
        });
    }

    /**
     * 差出人は**表示名だけ**を変える（R-11 ④）。
     *
     * From アドレスをフォームごとに変えると SPF / DKIM が合わず、
     * 迷惑メール判定になりやすい。返信先は Reply-To で別に指定できる。
     */
    public function test_差出人の表示名と返信先をフォームごとに変えられる(): void
    {
        $form = $this->form('contact-us', autoreplyField: 'email');
        $form->forceFill([
            'mail_from_name' => 'みどり工房 サポート',
            'reply_to' => 'support@example.test',
        ])->save();

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(FormAutoReply::class, function (FormAutoReply $mail): bool {
            $envelope = $mail->envelope();

            return $envelope->from?->name === 'みどり工房 サポート'
                // **アドレスは変えない**（サイト全体の設定のまま）
                && $envelope->from?->address === config('mail.from.address')
                && ($envelope->replyTo[0]->address ?? null) === 'support@example.test';
        });
    }

    /** 未設定なら差出人にも返信先にも手を入れない（サイト全体の設定のまま） */
    public function test_未設定なら差出人も返信先も触らない(): void
    {
        $this->form('contact-us', autoreplyField: 'email');

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(FormAutoReply::class, function (FormAutoReply $mail): bool {
            $envelope = $mail->envelope();

            return $envelope->from === null && $envelope->replyTo === [];
        });
    }

    public function test_自動返信の宛先が未設定なら送らない(): void
    {
        $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input());

        Mail::assertSent(FormAdminNotification::class);
        Mail::assertNotSent(FormAutoReply::class);
    }

    public function test_自動返信の宛先がemail型でなければ送らない(): void
    {
        // 「名前から推測しない」の帰結（OG6）
        $this->form('contact-us', autoreplyField: 'name');

        $this->post('/forms/contact-us', $this->input());

        Mail::assertNotSent(FormAutoReply::class);
    }

    // ── bot と濫用（§F） ────────────────────────────────────

    public function test_honeypotは保存せずに成功したように見せる(): void
    {
        $form = $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input() + [HoneypotCheck::FIELD => 'http://spam.test'])
            // bot に「弾かれた」と教えない（OG10）
            ->assertRedirect('/forms/contact-us/complete');

        $this->assertSame(0, FormSubmission::query()->count());
        Mail::assertNothingSent();

        // ただし何件弾いたかは残す（§F.4）
        $this->assertSame(1, (int) DB::table(SpamBlocks::TABLE)
            ->where('kind', 'form')->where('subject_id', (string) $form->getKey())
            ->where('reason', 'honeypot')->value('hits'));
    }

    public function test_弾いた記録に本文は入らない(): void
    {
        $form = $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input() + [HoneypotCheck::FIELD => 'x']);

        $row = DB::table(SpamBlocks::TABLE)->where('subject_id', (string) $form->getKey())->first();

        $this->assertNotNull($row);
        // スパムの本文を貯める理由が無い
        $this->assertSame(
            ['id', 'kind', 'subject_id', 'reason', 'blocked_on', 'hits', 'created_at', 'updated_at'],
            array_keys((array) $row),
        );
    }

    public function test_同じ日に何度弾いても1行に畳む(): void
    {
        $form = $this->form('contact-us');

        for ($i = 0; $i < 3; $i++) {
            $this->post('/forms/contact-us', $this->input() + [HoneypotCheck::FIELD => 'x']);
        }

        $this->assertSame(1, DB::table(SpamBlocks::TABLE)->where('subject_id', (string) $form->getKey())->count());
        $this->assertSame(3, (int) DB::table(SpamBlocks::TABLE)->where('subject_id', (string) $form->getKey())->value('hits'));
    }

    public function test_短時間に送りすぎると429(): void
    {
        config(['agentcms.forms.rate_limit_per_minute' => 2]);
        $this->form('contact-us');

        // 内容は変える（同じ内容の続けての送信は二度押しとして 1 件にまとめる — 8 周目 R8-3）
        $this->post('/forms/contact-us', ['name' => '一通目', 'email' => 'a@example.test'])->assertRedirect();
        $this->post('/forms/contact-us', ['name' => '二通目', 'email' => 'b@example.test'])->assertRedirect();

        // 3 通目は落とす。人が連打しただけなら「直せる」と分かる応答を返す
        $this->post('/forms/contact-us', ['name' => '三通目', 'email' => 'c@example.test'])->assertStatus(429);

        $this->assertSame(2, FormSubmission::query()->count());
    }

    public function test_turnstileは鍵があるときだけ必須(): void
    {
        $this->form('contact-us');

        // 鍵が無ければ通す（鍵を入れていない project でフォームが死ぬ方が害が大きい）
        $this->post('/forms/contact-us', $this->input())->assertRedirect('/forms/contact-us/complete');

        config(['agentcms.turnstile.secret_key' => 'secret']);
        $this->app->bind(TurnstileVerifier::class, fn (): TurnstileVerifier => new class implements TurnstileVerifier
        {
            public function verify(string $token, ?string $ip = null): bool
            {
                return $token === 'good';
            }
        });

        $this->post('/forms/contact-us', $this->input())
            ->assertRedirect('/forms/contact-us')
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->post('/forms/contact-us', $this->input() + ['cf-turnstile-response' => 'good'])
            ->assertRedirect('/forms/contact-us/complete');
    }

    // ── 個人情報（§G） ──────────────────────────────────────

    public function test_生のipは保存しない(): void
    {
        $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input());

        $row = (array) DB::table('form_submissions')->firstOrFail();

        $this->assertArrayNotHasKey('ip', $row);
        $this->assertSame(64, strlen((string) $row['ip_hash']));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($row, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_pruneはipとuaだけ消して受信は残す(): void
    {
        $form = $this->form('contact-us');
        $this->post('/forms/contact-us', $this->input());

        DB::table('form_submissions')->update(['created_at' => now()->subDays(200)]);

        $this->artisan('agentcms:form-prune')->assertSuccessful();

        $row = DB::table('form_submissions')->where('form_id', $form->getKey())->firstOrFail();

        // 顧客とのやり取りそのものは勝手に消さない（OG11）
        $this->assertNotNull($row->data);
        $this->assertNull($row->ip_hash);
        $this->assertNull($row->user_agent);
    }

    /**
     * **匿名化は `updated_at` を動かさない**（`PruneCommentsCommand` の先例）。
     *
     * 動かすと、一覧の並びと「**人が触った**」の判定が全部ずれる。
     * いま消しているのは連絡先の痕跡だけで、**受信そのものは触っていない**からである。
     *
     * ## 2 段で見る理由
     *
     * 「`updated_at` が動かない」には理由が 2 つある —
     * **匿名化が走ったうえで触らなかった**（見たい方）と、
     * **そもそも対象に入らなかった**（fixture の日付次第で滑り込む）。
     * だから**先に「匿名化が実際に起きた」を確かめて**から、動いていないことを見る。
     */
    public function test_匿名化は更新日時を動かさない(): void
    {
        $this->form('contact-us');
        $this->post('/forms/contact-us', $this->input());

        $touched = now()->subDays(300);

        DB::table('form_submissions')->update([
            'created_at' => now()->subDays(200),
            'updated_at' => $touched,
        ]);

        $this->artisan('agentcms:form-prune')->assertSuccessful();

        $row = DB::table('form_submissions')->firstOrFail();

        // ① **匿名化が実際に起きた**（起きていなければ、この試験は何も見ていない）
        $this->assertNull($row->ip_hash, '匿名化が走っていません');
        $this->assertNull($row->user_agent, '匿名化が走っていません');

        // ② そのうえで、更新日時は動いていない
        $this->assertSame(
            $touched->format('Y-m-d H:i:s'),
            (string) $row->updated_at,
            '匿名化が更新日時を動かしています（一覧の並びと「人が触った」の判定がずれます）',
        );
    }

    /**
     * **日数を入れていなければ、消す側へ倒れない**（OG11）。
     *
     * 受信は顧客とのやり取りそのものである。設定が無い＝「消してよい」ではない。
     */
    public function test_日数の設定が無ければ受信を消さない(): void
    {
        $this->form('contact-us');
        $this->post('/forms/contact-us', $this->input());

        DB::table('form_submissions')->update(['created_at' => now()->subYears(5)]);

        // 既定（`delete_after_days` は 0）のまま走らせる
        $this->artisan('agentcms:form-prune')->assertSuccessful();

        $this->assertSame(1, DB::table('form_submissions')->count(), '受信を勝手に消しています');

        /*
         * **「消えない」だけでは、消す道が塞がっていても緑になる。**
         *
         * 日数を入れたときには消えることまで見て、初めて「**既定が安全側に倒れている**」
         * と言える（設定が効かないだけ、と見分けられない）。
         */
        config(['agentcms.forms.retention.delete_after_days' => 30]);

        $this->artisan('agentcms:form-prune')->assertSuccessful();

        $this->assertSame(0, DB::table('form_submissions')->count(), '日数を入れても消えません');
    }

    public function test_pruneはdry_runなら何も変えない(): void
    {
        $this->form('contact-us');
        $this->post('/forms/contact-us', $this->input());
        DB::table('form_submissions')->update(['created_at' => now()->subDays(200)]);

        $this->artisan('agentcms:form-prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(DB::table('form_submissions')->value('ip_hash'));
    }

    public function test_表示名は並び付きで保存する(): void
    {
        $this->form('contact-us');

        $this->post('/forms/contact-us', $this->input());

        $raw = (array) DB::table('form_submissions')->firstOrFail();
        $data = json_decode((string) $raw['data'], true);

        /*
         * **オブジェクトではなく配列。** MySQL の JSON 型はオブジェクトの
         * キー順を保存しない（キー長 → 辞書順へ正規化する）。並びを
         * オブジェクトへ預けると、受信詳細・CSV・通知メールが
         * form と違う順に出る（SQLite では再現しないので、形で固定する）。
         */
        $this->assertSame(
            [['name', 'お名前'], ['email', 'メールアドレス']],
            $data[FormSubmission::LABELS_KEY],
        );

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame(['name', 'email'], $submission->orderedValueKeys());
    }

    public function test_定義から消えた項目も後ろに残って読める(): void
    {
        $form = $this->form('contact-us');
        $this->post('/forms/contact-us', $this->input());

        // 記録に無い key を混ぜても落とさない
        $submission = FormSubmission::query()->firstOrFail();
        $submission->forceFill([
            'data' => $submission->values() + ['legacy' => '古い項目'] + [
                FormSubmission::LABELS_KEY => FormSubmission::labelPairs(['name' => 'お名前', 'email' => 'メールアドレス']),
            ],
        ])->save();

        $this->assertSame(['name', 'email', 'legacy'], $submission->refresh()->orderedValueKeys());
        $this->assertSame('legacy', $submission->labelFor('legacy'));
        $this->assertNotNull($form);
    }

    // ── 定義の壊れたフォーム ────────────────────────────────

    public function test_項目を出せない束のフォームは送信を受けない(): void
    {
        $form = $this->form('contact-us');
        // 束を下書きへ戻す = 項目は 1 つも出ない（ADR-0015 の fail closed）
        DB::table('field_groups')->where('id', $form->field_group_id)->update(['status' => 'draft']);
        app()->forgetScopedInstances();

        $this->post('/forms/contact-us', $this->input())->assertNotFound();
        $this->assertSame(0, FormSubmission::query()->count());
    }

    // ── 既存 /contact を変えていない（受入条件 11） ─────────────

    public function test_既存の問い合わせ画面はそのまま(): void
    {
        $this->get('/contact')->assertOk();
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @return array<string, string> */
    private function input(): array
    {
        return ['name' => '山田太郎', 'email' => 'taro@example.test'];
    }

    private function form(string $key, string $status = 'published', ?string $autoreplyField = null, ?string $notifyTo = null): Form
    {
        $groupId = $this->fieldGroup();

        $id = DB::table('forms')->insertGetId([
            'key' => $key,
            'name' => 'お問い合わせ',
            'field_group_id' => $groupId,
            'status' => $status,
            'confirm_step' => true,
            'submit_label' => '送信する',
            'autoreply_field' => $autoreplyField,
            'notify_to' => $notifyTo,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Form::query()->findOrFail($id);
    }

    private function fieldGroup(): string
    {
        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => 'form_'.strtolower(substr($id, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
                ['id' => (string) Str::ulid(), 'key' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => $id, 'kind' => 'form', 'value' => 'contact-us',
        ]);

        app()->forgetScopedInstances();

        return $id;
    }
}

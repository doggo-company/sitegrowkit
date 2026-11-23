<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\ContentTypeIdentifier;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Console\Commands\CreateContentTypeCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Template Import が投稿タイプごと持ち込む（R-13 Stage 2）。
 *
 * ここで固定するのは 3 つ。
 *
 * 1. **項目定義は preset から出る** — CLI 側（TypeScript）は preset の id しか持たない。
 *    ここが崩れると Content Type Builder と正本が 2 つになる（F-18）。
 * 2. **既に在る型は飛ばす** — 型の作成は巻き戻さないので、
 *    再実行が「続きから」進めるかどうかが後始末の全部である。
 * 3. **知らない構成では作らない** — 作ってから間違いに気づいても消せない。
 */
final class ImportContentTypeCommandTest extends BuilderTestCase
{
    public function test_presetから項目ごと作られる(): void
    {
        $exit = $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]);

        $this->assertSame(0, $exit, $this->lastOutput);
        $this->assertStringContainsString('"created":["'.$this->typeId.'"]', $this->lastOutput);

        $this->assertFileExists($this->schemaPath.'/'.$this->typeId.'.json');
        $this->assertTrue(DbSchema::hasTable($this->typeId));

        $schema = app(SchemaRegistry::class)->get($this->typeId);

        $this->assertSame('cms', $schema->managedBy);
        $this->assertSame('実績', $schema->label);

        // works preset の項目（`ContentTypeBuilderPresets::worksFields()`）が入っている
        $keys = array_map(static fn ($f): string => $f->key, $schema->fields);

        foreach (['title', 'slug', 'body', 'status', 'published_at', 'main_image_id', 'category', 'completed_at', 'excerpt'] as $key) {
            $this->assertContains($key, $keys, "preset の項目 [{$key}] が無い");
        }
    }

    /**
     * 作った型は**そのまま公開される**（R-13 10 件目）。
     *
     * 公開まで行かないと、取り込んだページは `Route::fallback` に拾われず
     * **`/works` も `/works/{slug}` も 404 のまま**になる（実案件で実測）。
     * plan を承認した時点で「この型で公開面を作る」意思は表明されている。
     */
    public function test_作った型はそのまま公開される(): void
    {
        $this->assertSame(0, $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]), $this->lastOutput);

        app()->forgetScopedInstances();

        // ① 公開台帳（fallback の 1 つ目の条件）
        $this->assertTrue(
            app(ContentTypeLifecyclePolicy::class)->isPublic($this->typeId),
            '作ったのに公開になっていない',
        );

        // ② 入口 segment → 型 の対応（fallback の 2 つ目の条件）
        $this->assertSame(
            $this->typeId,
            app(ContentPermalink::class)->typeForBase($this->typeId),
        );
    }

    /**
     * **人が決めた設定は奪わない。**
     * `content_types` に行が在る = 誰かが保存した = `public: false` はその人の判断。
     */
    public function test_既に設定が在る型の公開状態は変えない(): void
    {
        $this->assertSame(0, $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]), $this->lastOutput);

        // 人が非公開へ戻した状態を作る
        app(ContentTypeSettingsWriter::class)->save(
            $this->typeId,
            ['registration' => json_encode(['public' => false])],
            app(ContentTypeSettingsWriter::class)->currentLockVersion($this->typeId),
        );

        app()->forgetScopedInstances();
        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->isPublic($this->typeId));

        // 同じ要求をもう一度（apply の再実行）
        $this->assertSame(0, $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]), $this->lastOutput);

        app()->forgetScopedInstances();
        $this->assertFalse(
            app(ContentTypeLifecyclePolicy::class)->isPublic($this->typeId),
            '人が非公開にしたものを勝手に公開している',
        );
    }

    /**
     * **作ったが公開で落ちた**状態から、再実行で公開まで進む（R-13 10 件目）。
     *
     * 飛ばすだけだと二度と公開されず、型は在るのに 404 のままになる。
     */
    public function test_公開されていない既存の型は再実行で公開する(): void
    {
        $this->assertSame(0, $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]), $this->lastOutput);

        // 「作ったが公開まで届かなかった」状態へ戻す（設定の行を消す）
        DB::table('content_types')->where('content_type_id', $this->typeId)->delete();
        app()->forgetScopedInstances();
        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->isPublic($this->typeId));

        $this->assertSame(0, $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]), $this->lastOutput);

        app()->forgetScopedInstances();
        $this->assertTrue(
            app(ContentTypeLifecyclePolicy::class)->isPublic($this->typeId),
            '作ったのに公開まで届かない状態から抜け出せない',
        );
    }

    public function test_既に在る型は飛ばす(): void
    {
        $this->assertSame(0, $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]), $this->lastOutput);

        // 同じ要求をもう一度（apply の再実行に相当）
        $exit = $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
        ]);

        $this->assertSame(0, $exit, $this->lastOutput);
        $this->assertStringContainsString('"created":[]', $this->lastOutput);
        $this->assertStringContainsString('"skipped":["'.$this->typeId.'"]', $this->lastOutput);
    }

    public function test_知らない構成では作らない(): void
    {
        $exit = $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'nonexistent'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('構成（preset）が見つかりません', $this->lastOutput);
        $this->assertFileDoesNotExist($this->schemaPath.'/'.$this->typeId.'.json');
        $this->assertFalse(DbSchema::hasTable($this->typeId));
    }

    /**
     * **kit の例の名前（works / news）を、静的に予約しない**（R-13 7 件目 / 指摘 37）。
     *
     * ここまでの試験は `faq1` のような**予約に載っていない id** を使い、preset だけ
     * `works` を指していた。**preset 名が works なので道を通ったように見えた**。
     * 実際には `RESERVED_TABLES` が `works` を静的に予約していて、
     * **利用者のプロジェクトでは必ず断られていた**
     * （「投稿タイプ ID を works にした型を作ってください」という案内が実行不能だった）。
     *
     * ## なぜ「作れる」を丸ごと通さないのか
     *
     * この sandbox は kit の `content/schemas` と `modules/` を**丸ごと写す**ので、
     * `works` は schema も module も table も route も実在する = 利用者の形にならない。
     * 実測すると、断りは静的予約ではなく
     * 「モジュール Works はすでに存在します / テーブル [works] はすでにデータベースに存在します」
     * という**実在に基づく理由**に変わっていた（それが正しい振る舞いである）。
     *
     * ▷ だから見るのは**入口の門だけ**にする。「実在するときだけ断る」は
     *   `ContentTypePlanner` の担当で、そちらは既存の試験が持っている。
     */
    public function test_kitの例の名前は静的に予約されていない(): void
    {
        foreach (['works', 'news', 'products'] as $id) {
            $errors = [];

            $this->assertNotNull(
                ContentTypeIdentifier::tryFrom($id, $errors),
                "[{$id}] が静的に予約されている: ".implode(' / ', $errors),
            );
            $this->assertSame([], $errors);
        }
    }

    /** 土台の表は、まだ migrate されていない環境でも断る */
    public function test_システムが使う表は静的に予約する(): void
    {
        $errors = [];

        $this->assertNull(ContentTypeIdentifier::tryFrom('sessions', $errors));
        $this->assertStringContainsString('システムが使う名前', implode(' ', $errors));
    }

    /** システムが使う名前は、**理由を言って**断る */
    public function test_システムが使う名前は理由を言って断る(): void
    {
        $exit = $this->createTypes([
            ['id' => 'users', 'label' => 'ユーザー', 'preset' => 'post'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('システムが使う名前', $this->lastOutput);
        // **直し方の分からない断りにしない** — errors を出力に含める
        $this->assertStringContainsString('"errors"', $this->lastOutput);
    }

    /** どこまで作れたかを返す（巻き戻さないので、続きから進むのに要る） */
    public function test_途中で止まってもそこまでを返す(): void
    {
        $exit = $this->createTypes([
            ['id' => $this->typeId, 'label' => '実績', 'preset' => 'works'],
            ['id' => 'not valid', 'label' => 'だめ', 'preset' => 'post'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('"created":["'.$this->typeId.'"]', $this->lastOutput);
        $this->assertTrue(DbSchema::hasTable($this->typeId), '作れた分は残す');
    }

    private string $lastOutput = '';

    /** @param list<array<string, mixed>> $types */
    private function createTypes(array $types): int
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode(['types' => $types], JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command = app(CreateContentTypeCommand::class);
        $command->setLaravel(app());

        $exit = $command->run($input, $output);

        $this->lastOutput = $output->fetch();
        fclose($stream);

        return $exit;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Media\VariantCapability;
use AgentCms\Core\Media\VariantFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use RuntimeException;
use Tests\TestCase;

/**
 * 保守コマンド（Phase 9.5-D / SPEC §7.1.2・§5）。
 *
 * どちらも **既定では何も書かない**。取り返しのつかない操作を
 * 「うっかり流したら起きていた」にしない。
 */
final class MediaMaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    /** @param array<string, mixed> $overrides */
    private function image(array $overrides = []): Media
    {
        $path = 'media/'.fake()->uuid().'.png';
        $file = UploadedFile::fake()->image('x.png', 40, 30);
        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        return Media::query()->create(array_merge([
            'name' => '画像',
            'path' => $path,
            'mime' => 'image/png',
            'size' => 100,
            'width' => 40,
            'height' => 30,
        ], $overrides));
    }

    /** @return list<string> */
    private function sortedFiles(string $disk, string $directory): array
    {
        $files = Storage::disk($disk)->allFiles($directory);
        sort($files);

        return $files;
    }

    private function skipUnlessWebpIsPossible(): void
    {
        $this->skipUnlessPossible(VariantFormat::Webp);
    }

    private function skipUnlessPossible(VariantFormat $format): void
    {
        if (! (new VariantCapability)->supports('image/png', $format, 40, 30)) {
            $this->markTestSkipped("この環境では {$format->value} を生成できない");
        }
    }

    // --- agentcms:media-variants ---------------------------------------

    public function test_without_a_mode_it_only_counts(): void
    {
        $media = $this->image(['has_webp' => true]);
        Storage::disk('public')->put(
            preg_replace('/\.png$/', '.webp', $media->path) ?? '',
            'legacy webp',
        );

        $this->artisan('agentcms:media-variants')
            ->expectsOutputToContain('何も書いていません')
            ->assertExitCode(0);

        $this->assertSame(0, MediaVariant::count());
    }

    public function test_backfill_copies_the_legacy_flag_into_a_row_without_moving_files(): void
    {
        $media = $this->image(['has_webp' => true]);
        $legacy = (string) preg_replace('/\.png$/', '.webp', $media->path);
        Storage::disk('public')->put($legacy, 'legacy webp');

        $this->artisan('agentcms:media-variants --backfill')->assertExitCode(0);

        $variant = MediaVariant::sole();
        $this->assertSame('webp', $variant->format);
        // 旧パスのまま。移動は IO を伴い、失敗すると配信が壊れる
        $this->assertSame($legacy, $variant->path);
        Storage::disk('public')->assertExists($legacy);
    }

    public function test_backfill_does_not_invent_a_row_for_a_file_that_is_gone(): void
    {
        // has_webp は立っているが実体が無い。行を作ると <source> が 404 を指す
        $this->image(['has_webp' => true]);

        $this->artisan('agentcms:media-variants --backfill')->assertExitCode(0);

        $this->assertSame(0, MediaVariant::count());
    }

    public function test_backfill_can_be_run_again_without_duplicating(): void
    {
        $media = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $media->path), 'legacy');

        $this->artisan('agentcms:media-variants --backfill')->assertExitCode(0);
        $this->artisan('agentcms:media-variants --backfill')->assertExitCode(0);

        $this->assertSame(1, MediaVariant::count());
    }

    public function test_regenerate_fills_in_what_this_environment_can_make(): void
    {
        $this->skipUnlessWebpIsPossible();
        $media = $this->image();

        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(0);

        $formats = $media->variants()->pluck('format')->all();
        $this->assertContains('webp', $formats);

        foreach ($media->variants()->get() as $variant) {
            Storage::disk('public')->assertExists($variant->path);
        }
    }

    public function test_regenerate_is_idempotent(): void
    {
        $this->skipUnlessWebpIsPossible();
        $media = $this->image();

        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(0);
        $before = $media->variants()->pluck('path')->sort()->values()->all();

        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(0);
        $after = $media->variants()->pluck('path')->sort()->values()->all();

        $this->assertSame($before, $after);
        // 作り直した実体を置き去りにしない
        $this->assertCount(
            count($after) + 1,
            Storage::disk('public')->allFiles('media'),
            '原本 + variant 以外のファイルが残っている',
        );
    }

    /**
     * 作り直しは **欠けている形式だけ**を作る。
     *
     * 既にある形式まで作ると確定パスが同じなので既存の実体を上書きしてしまい、
     * そのあと「余分だから消す」とすると **DB 行が残ったまま実体だけ消える**。
     */
    public function test_regenerate_does_not_disturb_a_variant_that_already_exists(): void
    {
        $this->skipUnlessWebpIsPossible();
        $media = $this->image();

        // WebP だけが在る状態を作る（実体は「触られたら分かる」中身にしておく）
        Storage::disk('public')->put('media/variants/keep/webp.webp', 'これは既存の派生物');
        $existing = MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/keep/webp.webp',
            'mime' => 'image/webp',
            'size' => 21,
            'width' => 40,
            'height' => 30,
        ]);

        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(0);

        $existing->refresh();
        $this->assertSame('media/variants/keep/webp.webp', $existing->path);
        $this->assertSame(
            'これは既存の派生物',
            Storage::disk('public')->get('media/variants/keep/webp.webp'),
            '既存の派生物の中身が書き換わっている',
        );
        $this->assertSame(1, $media->variants()->where('format', 'webp')->count());
    }

    /**
     * 旧実装で登録された画像（同寸の WebP だけを持つ）に、**幅候補だけ**を足す（release-20260909）。
     *
     * この試験が建てる世界: 幅候補が無かった時代の行 — `width` が原本の幅に等しい。
     * その行と実体には触れず、2 回目は何も足さない。
     */
    public function test_regenerate_adds_only_the_missing_widths_to_a_legacy_image(): void
    {
        $this->skipUnlessWebpIsPossible();
        config()->set('agentcms.variants.widths', '20');
        $media = $this->image();

        Storage::disk('public')->put('media/variants/keep/webp.webp', 'これは既存の派生物');
        $existing = MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/keep/webp.webp',
            'mime' => 'image/webp',
            'size' => 21,
            'width' => 40,
            'height' => 30,
        ]);

        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(0);

        $existing->refresh();
        $this->assertSame('これは既存の派生物', Storage::disk('public')->get('media/variants/keep/webp.webp'));

        $webp = $media->variants()->where('format', 'webp')->orderBy('width')->get();
        $this->assertSame([20, 40], $webp->pluck('width')->all());

        $small = $webp->first();
        $this->assertNotNull($small);
        $this->assertStringEndsWith('/webp-20.webp', $small->path);
        $this->assertSame(15, $small->height, '比率を保つ');
        $measured = getimagesize(Storage::disk('public')->path($small->path));
        $this->assertIsArray($measured);
        $this->assertSame([20, 15], [$measured[0], $measured[1]], 'DB の寸法は実体の実寸');

        $before = $media->variants()->count();
        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(0);
        $this->assertSame($before, $media->variants()->count(), '2 回目は何も足さない');
    }

    /**
     * 作ったあとに記録できなかったら、**作ったものだけ**を片付ける。
     * 既存の派生物には触れない。
     */
    public function test_a_failure_to_record_discards_only_the_new_files(): void
    {
        $this->skipUnlessWebpIsPossible();
        $media = $this->image();

        Storage::disk('public')->put('media/variants/keep/webp.webp', 'これは既存の派生物');
        MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/keep/webp.webp',
            'mime' => 'image/webp',
            'size' => 21,
            'width' => 40,
            'height' => 30,
        ]);

        if (! (new VariantCapability)->supports('image/png', VariantFormat::Avif, 40, 30)) {
            $this->markTestSkipped('この環境では AVIF を作れないので、作ってから落ちる場面が作れない');
        }

        // 記録だけを落とす
        /*
         * **列を落として失敗を作らない。** DDL は MySQL で暗黙 commit を起こし、
         * その時点で取引が閉じる（あとの片付けが効かなくなる）。
         * 見たいのは「**記録に失敗したとき、新しく置いた分だけを捨てるか**」なので、
         * **記録そのものを落とす**。
         */
        MediaVariant::creating(static function (): void {
            throw new RuntimeException('派生物の記録に失敗しました（試験）');
        });

        $this->artisan('agentcms:media-variants --regenerate')->assertExitCode(1);

        $this->assertSame(
            'これは既存の派生物',
            Storage::disk('public')->get('media/variants/keep/webp.webp'),
        );
        $this->assertSame(
            [$media->path, 'media/variants/keep/webp.webp'],
            $this->sortedFiles('public', 'media'),
            '記録できなかった実体が残っている',
        );
    }

    /**
     * `--limit` は **1 回の実行の上限**である。モードを 2 つ指定しても 2 倍にならない。
     */
    public function test_the_limit_is_shared_between_both_modes(): void
    {
        $this->skipUnlessWebpIsPossible();

        // 写し替えの対象を 1 件
        $legacy = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $legacy->path), 'legacy');

        // 生成の対象を 1 件
        $fresh = $this->image();

        $this->artisan('agentcms:media-variants --backfill --regenerate --limit=1')->assertExitCode(0);

        // 上限は厳密。1 件書いたらそこで止まる
        $this->assertSame(1, $legacy->variants()->count(), '写し替えの 1 件だけが書かれる');
        $this->assertSame(0, $fresh->variants()->count(), '上限を使い切ったので次の画像へ進まない');
    }

    /**
     * 対象外ばかりの区間で足踏みしない。
     *
     * 「対象かどうか」は行を取ってからでないと分からない（旧ファイルが実在するか）。
     * 書けた件数だけで刻むと、先頭が全部空振りのとき **その先に永久に届かない**。
     */
    public function test_backfill_walks_past_rows_that_turn_out_not_to_be_targets(): void
    {
        // 先頭 2 件は has_webp が立っているのに実体が無い（＝空振り）
        $this->image(['has_webp' => true]);
        $this->image(['has_webp' => true]);

        // 3 件目だけが本当の対象
        $real = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $real->path), 'legacy');

        $this->artisan('agentcms:media-variants --backfill --limit=1')->assertExitCode(0);

        $this->assertSame(1, $real->variants()->count(), '空振りの先にある対象へ届いていない');
    }

    /**
     * 打ち切ったら、**どこから再開すればよいか**を必ず言う。
     * その ID を渡すと、次の実行はその先だけを調べる。
     */
    public function test_a_truncated_run_hands_over_a_cursor_that_moves_forward(): void
    {
        $this->skipUnlessWebpIsPossible();

        $first = $this->image();
        $second = $this->image();

        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--regenerate' => true,
            '--scan' => 1,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString("--after-id={$first->id}", $output);
        $this->assertSame(0, $second->variants()->count());

        // 渡された ID から続けると、その先だけが処理される
        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--regenerate' => true,
            '--after-id' => $first->id,
        ]));

        $resumed = Artisan::output();

        $this->assertGreaterThan(
            0,
            $second->variants()->count(),
            "続きの実行で 2 件目へ届いていない。1 回目: {$output} / 2 回目: {$resumed}",
        );
    }

    public function test_a_failed_run_does_not_poison_the_next_one(): void
    {
        $this->skipUnlessWebpIsPossible();
        $this->image();

        // 失敗させる（記録できない）
        /*
         * **列を落として失敗を作らない。** DDL は MySQL で暗黙 commit を起こし、
         * その時点で取引が閉じる（あとの片付けが効かなくなる）。
         * 見たいのは「**記録に失敗したとき、新しく置いた分だけを捨てるか**」なので、
         * **記録そのものを落とす**。
         */
        MediaVariant::creating(static function (): void {
            throw new RuntimeException('派生物の記録に失敗しました（試験）');
        });
        $this->assertSame(1, Artisan::call('agentcms:media-variants', ['--regenerate' => true]));

        // 数えるだけの実行は、前回の失敗を引きずらない
        $this->assertSame(0, Artisan::call('agentcms:media-variants'));
    }

    /** `--scan` も 1 回の実行の上限。モードを 2 つ指定しても 2 倍調べない */
    public function test_the_scan_budget_is_shared_between_both_modes(): void
    {
        $this->skipUnlessWebpIsPossible();

        $legacy = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $legacy->path), 'legacy');

        $fresh = $this->image();

        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--scan' => 1,
        ]));

        // `--scan` が刻むのは **調べた画像の数**。1 枚目は最後まで処理する
        $this->assertStringContainsString('走査 1 件', Artisan::output());
        $this->assertSame(1, $legacy->variants()->where('format', 'webp')->count());
        $this->assertSame(0, $fresh->variants()->count(), '走査の上限を使い切ったのに先へ進んでいる');
    }

    /**
     * 1 つも作れなかったものを「書けた」と数えない。
     * 過大に数えると `--limit` の意味が崩れる。
     *
     * **能力はある**まま、原本の実体だけを消して `generate()` に空を返させる
     * （能力を落とすと欠けている形式が 0 になり、この分岐まで届かない）。
     */
    public function test_an_image_whose_original_is_gone_is_not_counted_as_done(): void
    {
        $this->skipUnlessWebpIsPossible();

        $media = $this->image();
        Storage::disk('public')->delete($media->path);

        $this->assertSame(0, Artisan::call('agentcms:media-variants', ['--regenerate' => true]));

        $this->assertStringContainsString('生成 0 件', Artisan::output());
        $this->assertSame(0, MediaVariant::count());
    }

    /**
     * 写し替えの空振りが、作り直しの進み具合を押し流さない。
     *
     * モードごとに別の問い合わせを回していた頃は、片方だけ進んだ位置を
     * `--after-id` に渡すことになり、案内どおり再実行しても同じ行で足踏みしていた。
     */
    public function test_a_shared_cursor_moves_past_a_row_that_only_one_mode_looked_at(): void
    {
        $this->skipUnlessWebpIsPossible();

        // 先頭は「has_webp は立っているが実体が無い」= 写し替えの空振り
        $legacy = $this->image(['has_webp' => true]);
        $next = $this->image();

        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--scan' => 1,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString("--after-id={$legacy->id}", $output, '空振りでも位置は進むはず');
        $this->assertSame(0, $next->variants()->count());

        // 案内どおり続ければ、次の画像へ届く
        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--after-id' => $legacy->id,
        ]));

        $this->assertGreaterThan(0, $next->variants()->count(), '案内どおり再実行しても先へ進んでいない');
    }

    /**
     * 上限で画像の途中を止めても、取りこぼさない。
     *
     * cursor は「調べた ID」ではなく「**処理し終えた** ID」なので、途中で止めた画像は
     * 次の実行がもう一度読む。写し替えは既に行があれば何もしないため、
     * 高々 1 画像を読み直すだけで作り直しへ進める。
     */
    public function test_stopping_mid_image_does_not_lose_the_rest_of_its_work(): void
    {
        // 写し替えたあとに残る作業は AVIF なので、AVIF を作れる環境でしか意味が無い
        $this->skipUnlessPossible(VariantFormat::Avif);

        $media = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $media->path), 'legacy');

        // 写し替えの 1 件で上限に達し、作り直しへ進まずに止まる
        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--limit' => 1,
        ]));

        $output = Artisan::output();
        $this->assertSame(1, $media->variants()->count(), '上限を超えて書いている');
        // まだ済んでいないので、cursor はこの画像の手前のまま
        $this->assertStringContainsString('--after-id=0', $output);

        // 案内どおり続ける。写し替えは何もせず、作り直しへ進む
        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--after-id' => 0,
        ]));

        $this->assertGreaterThan(
            1,
            $media->variants()->count(),
            '途中で止めた画像の残りが二度と処理されていない',
        );
    }

    /**
     * 数えるだけの結果が、実際に流したときの件数と食い違わない。
     *
     * 写し替えを数えたのに手元の一覧へ反映しないと、同じ WebP を作り直しでも
     * 「まだ無い」と見て**二重に数えて**しまう。
     */
    public function test_a_dry_run_counts_the_same_work_the_real_run_does(): void
    {
        $legacy = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $legacy->path), 'legacy');

        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--dry-run' => true,
        ]));

        $preview = Artisan::output();

        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
        ]));

        $actual = Artisan::output();

        $this->assertSame(
            $this->counts($preview),
            $this->counts($actual),
            "下見と実行で件数が違う。下見: {$preview} / 実行: {$actual}",
        );
    }

    /**
     * 下見の写し替えは、**幅も持った**仮の行を足さなければならない（release-20260909）。
     *
     * 作り直しは「形式 + 幅」で突き合わせるので、幅の無い仮の行は同寸の WebP を
     * 「まだ無い」と見る。上の試験は AVIF が本物の欠けとして混ざる環境では
     * その誤りを隠してしまう（画像単位で数えるため）ので、ここでは
     * **WebP 以外に欠けが無い世界**を建てる: AVIF の行は既にあり、幅候補は無し。
     */
    public function test_a_dry_run_does_not_count_a_legacy_webp_as_missing_once_backfilled(): void
    {
        config()->set('agentcms.variants.widths', '');
        $legacy = $this->image(['has_webp' => true]);
        Storage::disk('public')->put((string) preg_replace('/\.png$/', '.webp', $legacy->path), 'legacy');
        MediaVariant::query()->create([
            'media_id' => $legacy->id,
            'format' => 'avif',
            'disk' => 'public',
            'path' => 'media/variants/legacy/avif.avif',
            'mime' => 'image/avif',
            'size' => 3,
            'width' => 40,
            'height' => 30,
        ]);

        $this->assertSame(0, Artisan::call('agentcms:media-variants', [
            '--backfill' => true,
            '--regenerate' => true,
            '--dry-run' => true,
        ]));

        $this->assertSame('写し替え 1 件 / 生成 0 件', $this->counts(Artisan::output()));
    }

    /** 出力から「写し替え N 件 / 生成 M 件」だけを取り出す */
    private function counts(string $output): string
    {
        preg_match('/写し替え \d+ 件 \/ 生成 \d+ 件/u', $output, $matches);

        return $matches[0] ?? $output;
    }

    public function test_limit_bounds_one_run(): void
    {
        $this->skipUnlessWebpIsPossible();
        $this->image();
        $this->image();

        $this->artisan('agentcms:media-variants --regenerate --limit=1')->assertExitCode(0);

        $this->assertSame(1, Media::query()->has('variants')->count());
    }

    public function test_dry_run_wins_over_a_mode(): void
    {
        $this->skipUnlessWebpIsPossible();
        $this->image();

        $this->artisan('agentcms:media-variants --regenerate --dry-run')
            ->expectsOutputToContain('何も書いていません')
            ->assertExitCode(0);

        $this->assertSame(0, MediaVariant::count());
    }

    // --- agentcms:media-prune -------------------------------------------

    public function test_prune_reports_without_deleting_by_default(): void
    {
        $this->image();
        Storage::disk('public')->put('media/orphan.png', 'nobody knows me');

        $this->artisan('agentcms:media-prune')
            ->expectsOutputToContain('--force')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('media/orphan.png');
    }

    public function test_prune_removes_only_what_the_database_does_not_know(): void
    {
        $media = $this->image();
        Storage::disk('public')->put('media/orphan.png', 'nobody knows me');
        Storage::disk('public')->put('media/variants/x/.staging-abc.webp', '書きかけ');
        Storage::disk('local')->put('files/orphan.pdf', '%PDF-');

        $this->artisan('agentcms:media-prune --force')->assertExitCode(0);

        Storage::disk('public')->assertExists($media->path);
        Storage::disk('public')->assertMissing('media/orphan.png');
        Storage::disk('public')->assertMissing('media/variants/x/.staging-abc.webp');
        Storage::disk('local')->assertMissing('files/orphan.pdf');
    }

    public function test_prune_keeps_the_legacy_webp_of_a_row_that_still_declares_it(): void
    {
        $media = $this->image(['has_webp' => true]);
        $legacy = (string) preg_replace('/\.png$/', '.webp', $media->path);
        Storage::disk('public')->put($legacy, 'legacy webp');

        $this->artisan('agentcms:media-prune --force')->assertExitCode(0);

        // 写し替えが済むまでは、これは正当な実体である
        Storage::disk('public')->assertExists($legacy);
    }

    public function test_prune_keeps_variants_that_have_a_row(): void
    {
        $media = $this->image();
        Storage::disk('public')->put('media/variants/known/webp.webp', 'variant');

        MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/known/webp.webp',
            'mime' => 'image/webp',
            'size' => 7,
            'width' => 40,
            'height' => 30,
        ]);

        $this->artisan('agentcms:media-prune --force')->assertExitCode(0);

        Storage::disk('public')->assertExists('media/variants/known/webp.webp');
    }

    public function test_prune_stops_at_the_limit_and_says_so(): void
    {
        $this->image();

        foreach (range(1, 3) as $n) {
            Storage::disk('public')->put("media/orphan-{$n}.png", 'x');
        }

        $this->assertSame(0, Artisan::call('agentcms:media-prune', ['--force' => true, '--limit' => 2]));
        $output = Artisan::output();

        $this->assertStringContainsString('2 件を削除しました', $output);
        // 消した分は次回の対象から外れるので、ここは「続きへ進む」でよい
        $this->assertStringContainsString('もう一度実行すれば続きへ進みます', $output);

        $this->assertCount(2, Storage::disk('public')->allFiles('media'));
    }

    /**
     * 上限は「見つけた数」だけでなく **走った量**にも効く。
     *
     * 孤児の数だけを刻んでも、ファイルの列挙と DB の照合を全部やってしまえば
     * 共有ホスティングでは時間切れになる。ここでは「50 個あるのに 1 つで止まる」
     * ではなく、**走査そのものが 1 まとまりで止まる**ことを見る。
     */
    public function test_prune_stops_walking_the_disk_once_the_limit_is_reached(): void
    {
        foreach (range(1, 50) as $n) {
            Storage::disk('public')->put(sprintf('media/orphan-%02d.png', $n), 'x');
        }

        $this->assertSame(0, Artisan::call('agentcms:media-prune', ['--limit' => 1]));
        $output = Artisan::output();

        // 50 個あるが、孤児 1 件で打ち切るので、そこから先は読まない
        $this->assertStringContainsString('孤児 1 件', $output);
        // 何も消していないなら「もう一度流せば続く」は嘘なので、そう言わない
        $this->assertStringContainsString('同じところで止まります', $output);
    }

    public function test_prune_stops_at_the_scan_limit(): void
    {
        foreach (range(1, 10) as $n) {
            Storage::disk('public')->put(sprintf('media/orphan-%02d.png', $n), 'x');
        }

        $this->assertSame(0, Artisan::call('agentcms:media-prune', ['--scan' => 3, '--limit' => 999]));
        $output = Artisan::output();

        $this->assertStringContainsString('走査 3 件 / 孤児 3 件', $output);
        $this->assertStringContainsString('--scan を増やしてください', $output);
    }

    public function test_prune_treats_dry_run_as_stronger_than_force(): void
    {
        Storage::disk('public')->put('media/orphan.png', 'x');

        $this->artisan('agentcms:media-prune --force --dry-run')->assertExitCode(0);

        Storage::disk('public')->assertExists('media/orphan.png');
    }

    public function test_a_delete_that_fails_is_not_counted_as_deleted(): void
    {
        Storage::disk('public')->put('media/orphan.png', 'x');

        $spy = Mockery::mock(Storage::disk('public'))->makePartial();
        $spy->shouldReceive('delete')->andReturn(false);
        Storage::set('public', $spy);

        $this->artisan('agentcms:media-prune --force')
            ->expectsOutputToContain('0 件を削除しました（1 件は削除できませんでした）')
            ->assertExitCode(1);

        // 消えたことにされていない
        Storage::forgetDisk('public');
    }

    public function test_prune_keeps_a_file_stored_on_the_private_disk(): void
    {
        Storage::disk('local')->put('files/kept.pdf', '%PDF-');

        Media::query()->create([
            'kind' => 'file',
            'disk' => 'local',
            'name' => '資料',
            'original_name' => '資料.pdf',
            'path' => 'files/kept.pdf',
            'mime' => 'application/pdf',
            'size' => 5,
            'width' => null,
            'height' => null,
        ]);

        $this->artisan('agentcms:media-prune --force')->assertExitCode(0);

        Storage::disk('local')->assertExists('files/kept.pdf');
    }
}

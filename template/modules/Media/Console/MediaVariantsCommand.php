<?php

declare(strict_types=1);

namespace Modules\Media\Console;

use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\VariantCapability;
use AgentCms\Core\Media\VariantFormat;
use AgentCms\Core\Media\VariantGenerator;
use AgentCms\Core\Media\VariantTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use Throwable;

/**
 * 派生物を後追いで揃える（Phase 9.5-D / SPEC §7.1.2・§10）。
 *
 * 生成は upload のときに同期で試みるが、
 *
 * - 移行直後（`has_webp` の行がまだ `media_variants` に写っていない）
 * - 生成能力が無い環境で登録され、あとから能力が入った
 * - 共有ホスティングで時間切れになった
 *
 * といった取りこぼしが残る。常駐 queue を前提にしないので、
 * **人が明示的に流す**この経路を用意しておく。
 *
 * ## 何もしないのが既定
 *
 * `--backfill` も `--regenerate` も付けなければ、**対象の件数を数えるだけ**である。
 * `--dry-run` を足せば、モードを指定していても数えるだけになる。
 *
 * ## 画像を 1 度だけ辿り、両方のモードをその場で当てる
 *
 * モードごとに別の問い合わせを回すと、進み具合が 2 つになってしまい、
 * **1 本の `--after-id` では言い表せない**（片方だけ進んだ位置を渡すと、
 * もう片方は同じ行を取り直して永久に足踏みする）。
 *
 * そこで **ID の昇順に画像を 1 度だけ辿り**、その画像に対して
 * 写し替えと作り直しの両方を当てる。進み具合も、走査の予算も、書き込みの予算も 1 つになる。
 *
 * | 上限 | 何を刻むか |
 * | --- | --- |
 * | `--scan` | **調べた画像の数**（実行全体で 1 つ） |
 * | `--limit` | **書いた件数**（実行全体で 1 つ）。厳密に守る |
 * | `--after-id` | **最後まで処理し終えた** ID。打ち切ったら次に渡す ID を表示する |
 *
 * cursor が「調べた ID」ではなく「**処理し終えた** ID」なのは、画像の途中で
 * 上限に達したときに取りこぼさないためである。その画像は cursor を進めないので
 * 次の実行がもう一度読む。写し替えは既に行があれば何もしないので、
 * 高々 1 画像を読み直すだけで、上限を厳密に守ったまま先へ進める。
 *
 * 「対象かどうか」は SQL だけでは決まらない（旧ファイルが実在するか、
 * この環境がその形式を作れるか）。だから「書けた件数」ではなく
 * **調べた数**で刻み、位置を持ち越す。
 */
final class MediaVariantsCommand extends Command
{
    /** 1 回に DB から取る行数 */
    private const CHUNK = 100;

    protected $signature = 'agentcms:media-variants
        {--backfill : 旧 has_webp の行を media_variants へ写す（ファイルは動かさない）}
        {--regenerate : 欠けている派生物を作る}
        {--dry-run : 何も書かず、対象の件数だけを表示する}
        {--limit=100 : 1 回の実行で書く上限（実行全体で 1 つ）}
        {--scan=500 : 1 回の実行で調べる画像の上限（実行全体で 1 つ）}
        {--after-id=0 : この ID より大きい画像から調べる（前回の続き）}';

    protected $description = '画像の派生物（AVIF / WebP と、レスポンシブ配信の幅候補）を後追いで揃えます';

    public function handle(VariantGenerator $generator, VariantCapability $capability): int
    {
        // 記録できなかった件数。**局所変数にする** — property にすると
        // 同じ command instance が使い回されたときに前回の結果を持ち越す
        $failures = 0;

        $backfill = (bool) $this->option('backfill');
        $regenerate = (bool) $this->option('regenerate');
        $limit = max(1, (int) $this->option('limit'));
        $scan = max(1, (int) $this->option('scan'));
        $afterId = max(0, (int) $this->option('after-id'));

        // モードを指定していなければ、それ自体が dry-run である
        $noMode = ! $backfill && ! $regenerate;
        $dryRun = (bool) $this->option('dry-run') || $noMode;
        $doBackfill = $backfill || $noMode;
        $doRegenerate = $regenerate || $noMode;

        $backfilled = 0;
        $regenerated = 0;
        $written = 0;
        $scanned = 0;
        $lastId = $afterId;
        $truncated = false;

        Media::query()
            ->where('kind', MediaKind::Image->value)
            ->where('id', '>', $afterId)
            ->with('variants')
            ->chunkById(self::CHUNK, function ($rows) use (
                &$backfilled, &$regenerated, &$written, &$scanned, &$lastId, &$truncated, &$failures,
                $scan, $limit, $dryRun, $doBackfill, $doRegenerate, $generator, $capability,
            ): bool {
                foreach ($rows as $media) {
                    if ($scanned >= $scan || $written >= $limit) {
                        $truncated = true;

                        return false;
                    }

                    $scanned++;

                    if ($doBackfill && $this->backfillOne($media, $dryRun)) {
                        $backfilled++;
                        $written++;
                    }

                    // 上限は **厳密に**守る。途中で止めた画像は cursor を進めないので、
                    // 次の実行が同じ画像をもう一度読む。写し替えは既に行があれば
                    // 何もしないので、続きから作り直しへ進める（取りこぼさない）
                    if ($written >= $limit && $doRegenerate) {
                        $truncated = true;

                        return false;
                    }

                    if ($doRegenerate && $this->regenerateOne($media, $generator, $capability, $dryRun, $failures)) {
                        $regenerated++;
                        $written++;
                    }

                    // ここまで来て初めて「この画像は済んだ」と言える
                    $lastId = (int) $media->getKey();
                }

                return true;
            });

        $report = [];

        if ($doBackfill) {
            $report[] = "写し替え {$backfilled} 件";
        }

        if ($doRegenerate) {
            $report[] = "生成 {$regenerated} 件";
        }

        $summary = implode(' / ', $report);

        $this->line($dryRun ? "対象: {$summary}（何も書いていません）" : $summary);
        $this->line("走査 {$scanned} 件");

        if ($dryRun) {
            $this->comment('実行するには --backfill / --regenerate を付けてください。');
        }

        if ($truncated) {
            // 打ち切ったなら、**どこから再開すればよいか**を必ず言う
            $this->comment("上限で打ち切りました。続きは --after-id={$lastId} を付けて実行してください。");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 旧 `has_webp` の行を `media_variants` へ写す。
     *
     * **ファイルは動かさない。** 移動は IO を伴い、失敗すると配信が壊れる。
     * `media_variants.path` が旧パスを指していれば配信は成立するので、行を作るだけでよい。
     *
     * 実在しないファイルの行は作らない（作ると `<source>` が 404 を指す）。
     */
    private function backfillOne(Media $media, bool $countOnly): bool
    {
        if (! $media->has_webp || $media->variants->contains('format', VariantFormat::Webp->value)) {
            return false;
        }

        $disk = Storage::disk($media->diskName());
        $path = ImageProcessor::webpPath((string) $media->path);

        if (! $disk->exists($path)) {
            return false;
        }

        if ($countOnly) {
            // 数えるだけでも手元の一覧へ**仮に**足す。足さないと、このあとの
            // 作り直しが同じ WebP を「まだ無い」と見て二重に数えてしまう。
            // 幅も持たせる — 作り直しは「形式 + 幅」で突き合わせるので、幅が無いと同寸の
            // WebP を「まだ無い」と見てしまう
            $media->variants->push(new MediaVariant([
                'format' => VariantFormat::Webp->value,
                'width' => (int) $media->width,
                'height' => (int) $media->height,
            ]));

            return true;
        }

        $variant = MediaVariant::query()->create([
            'media_id' => $media->getKey(),
            'format' => VariantFormat::Webp->value,
            'disk' => $media->diskName(),
            'path' => $path,
            'mime' => VariantFormat::Webp->mime(),
            'size' => (int) $disk->size($path),
            'width' => (int) $media->width,
            'height' => (int) $media->height,
        ]);

        // このあと同じ画像へ作り直しを当てるので、手元の一覧にも足しておく
        // （足さないと WebP を「まだ無い」と見て作り直してしまう）
        $media->variants->push($variant);

        return true;
    }

    /**
     * 生成できるのに行が無い target（形式 + 幅）を作る。
     *
     * **欠けている target だけを生成する。** 既にある target まで作ると、確定パスが同じなので
     * 既存の実体を上書きしてしまう。そのあと「余分だから消す」とすると、
     * DB 行が残ったまま実体だけ消えて、`<source>` が 404 を指す。
     *
     * 旧実装で登録された画像（同寸の AVIF / WebP だけを持つ）には、幅候補だけが足される。
     */
    private function regenerateOne(
        Media $media,
        VariantGenerator $generator,
        VariantCapability $capability,
        bool $countOnly,
        int &$failures,
    ): bool {
        if ($media->width === null || $media->height === null) {
            return false;
        }

        $missing = $this->missingTargets($capability, $media);

        if ($missing === []) {
            return false;
        }

        if ($countOnly) {
            return true;
        }

        $descriptors = $generator->generate(
            $media->diskName(),
            (string) $media->path,
            (string) $media->mime,
            (int) $media->width,
            (int) $media->height,
            $missing,
        );

        if ($descriptors === []) {
            // 能力はあるのに 1 つも作れなかった（原本が消えている等）。
            // **書けたことにしない** — 過大に数えると `--limit` の意味が崩れる
            return false;
        }

        try {
            DB::transaction(function () use ($descriptors, $media): void {
                foreach ($descriptors as $descriptor) {
                    MediaVariant::query()->create([...$descriptor, 'media_id' => $media->getKey()]);
                }
            });
        } catch (Throwable $e) {
            // **作ったものだけ**を片付ける。既存の派生物には触れない
            $generator->discard($descriptors);
            $failures++;
            $this->warn("media #{$media->getKey()} の派生物を記録できませんでした: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    /**
     * この環境で作れて、まだ行が無い target（形式 + 幅）。
     *
     * 旧実装が作った同寸の行は `width` に原本の幅を持つので、同寸の target と一致する。
     * その画像に足りないのは幅候補だけになり、**既存の行と実体には触れない**。
     *
     * @return list<VariantTarget>
     */
    private function missingTargets(VariantCapability $capability, Media $media): array
    {
        $missing = [];

        $targets = $capability->targetsFor(
            (string) $media->mime,
            (int) $media->width,
            (int) $media->height,
        );

        foreach ($targets as $target) {
            $exists = $media->variants->contains(
                static fn (MediaVariant $variant): bool => $variant->format === $target->format->value
                    && (int) $variant->width === $target->width,
            );

            if (! $exists) {
                $missing[] = $target;
            }
        }

        return $missing;
    }
}

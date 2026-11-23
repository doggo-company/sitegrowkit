<?php

declare(strict_types=1);

namespace Modules\Media\Console;

use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Media\MediaKind;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use Throwable;

/**
 * DB が知らないファイルを回収する（Phase 9.5-D / SPEC §5・§10・OG6）。
 *
 * 「DB に無いファイル」は害が小さく、「ファイルの無い DB 行」は画面が壊れる。
 * 本 Stage はどちらかを選ぶなら前者へ倒しているので、**孤児は必ず出る**。
 * その掃除がここである。
 *
 * ## 既定は数えるだけ
 *
 * 消すのは `--force` を付けたときだけ（OG6）。`--dry-run` を明示すれば
 * `--force` が付いていても数えるだけになる（強いほうが安全側）。
 *
 * ## 走ることそのものを bound する
 *
 * 共有ホスティングで時間切れにしないのが目的なので、
 * **「見つけた孤児の数」だけを刻んでも足りない**。それでは
 * `--limit=1` でも全ファイルと全 DB 行を読み切ってしまう。
 *
 * | 上限 | 何を刻むか |
 * | --- | --- |
 * | `--scan` | **調べたファイルの数**。ここに達したら列挙をやめる |
 * | `--limit` | **見つけた孤児の数**。ここに達したら列挙をやめる |
 *
 * 列挙は Flysystem の遅延 listing をそのまま辿るので、打ち切れば
 * その先のディレクトリは読まない。DB の照合も **調べた分だけ**を
 * `whereIn` で引く（テーブル全体をメモリへ積まない）。
 *
 * ## 何を「知っている」とみなすか
 *
 * - `media.path`（原本）
 * - `has_webp=true` の行の旧 `.webp` パス（写し替えが済むまでは正当な実体）
 * - `media_variants.path`（派生物）
 *
 * これ以外は孤児である。書き損じた staging（`.staging-*`）もここで回収される。
 */
final class MediaPruneCommand extends Command
{
    /** 1 回の照合で DB へ渡すパスの数 */
    private const BATCH = 200;

    protected $signature = 'agentcms:media-prune
        {--force : 実際に削除する（既定は数えるだけ）}
        {--dry-run : 明示的に「数えるだけ」。--force と同時に指定したときはこちらが勝つ}
        {--limit=500 : 見つける孤児の上限。達したら列挙をやめる}
        {--scan=5000 : 調べるファイルの上限。達したら列挙をやめる}';

    protected $description = 'DB から参照されていないメディアのファイルを回収します';

    public function handle(): int
    {
        // 破壊は明示したときだけ。--dry-run が付いていれば --force より強い
        $delete = (bool) $this->option('force') && ! (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $scanLimit = max(1, (int) $this->option('scan'));

        $scanned = 0;
        $found = 0;
        $deleted = 0;
        $failed = 0;
        $truncated = null;

        foreach ($this->locations() as [$disk, $directory]) {
            foreach ($this->batches($disk, $directory, $scanLimit - $scanned) as $batch) {
                $scanned += count($batch);
                $known = $this->knownAmong($disk, $batch);

                foreach ($batch as $path) {
                    if (isset($known[$path])) {
                        continue;
                    }

                    if ($found >= $limit) {
                        $truncated = 'orphans';

                        break 3;
                    }

                    $found++;
                    $this->line(($delete ? '削除: ' : '対象: ')."[{$disk}] {$path}");

                    if (! $delete) {
                        continue;
                    }

                    if ($this->remove($disk, $path)) {
                        $deleted++;
                    } else {
                        $failed++;
                    }
                }

                if ($scanned >= $scanLimit) {
                    $truncated = 'scan';

                    break 2;
                }
            }
        }

        $this->line($delete
            ? "{$deleted} 件を削除しました".($failed > 0 ? "（{$failed} 件は削除できませんでした）" : '')
            : "{$found} 件が DB から参照されていません（--force を付けると削除します）");

        $this->line("走査 {$scanned} 件 / 孤児 {$found} 件");

        if ($truncated !== null) {
            // 黙って打ち切ると「全部見た」と読めてしまう。
            // **「もう一度流せば続く」とは限らない** — 列挙は毎回先頭からなので、
            // 消していないなら次も同じところで止まる。そこを取り違えさせない
            $this->comment(match (true) {
                $truncated === 'scan' => "走査の上限（{$scanLimit} 件）に達しました。"
                    .'先頭から数えてそこまでしか見ていません。全体を見るには --scan を増やしてください。',
                $delete => "孤児の上限（{$limit} 件）に達しました。"
                    .'消した分は次回の対象から外れるので、もう一度実行すれば続きへ進みます。',
                default => "孤児の上限（{$limit} 件）に達しました。"
                    .'何も消していないので、もう一度実行しても同じところで止まります。'
                    .'--limit を増やすか、--force を付けてください。',
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ファイルを遅延で列挙し、`self::BATCH` ずつ返す。
     *
     * `allFiles()` は全件を配列にしてから返すので使わない。
     * ここで止めれば、その先のディレクトリは**読まれない**。
     *
     * @return Generator<int, list<string>>
     */
    private function batches(string $disk, string $directory, int $remaining): Generator
    {
        if ($remaining < 1) {
            return;
        }

        $batch = [];

        foreach (Storage::disk($disk)->getDriver()->listContents($directory, true) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $batch[] = $item->path();

            if (count($batch) >= min(self::BATCH, $remaining)) {
                $remaining -= count($batch);

                yield $batch;

                $batch = [];

                if ($remaining < 1) {
                    return;
                }
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * この一群のうち、DB が知っているパス。
     *
     * 照合は **調べた分だけ**。テーブル全体を読まない。
     *
     * @param  list<string>  $paths
     * @return array<string, true>
     */
    private function knownAmong(string $disk, array $paths): array
    {
        $known = [];

        foreach (Media::query()->where('disk', $disk)->whereIn('path', $paths)->pluck('path') as $path) {
            $known[(string) $path] = true;
        }

        foreach (MediaVariant::query()->where('disk', $disk)->whereIn('path', $paths)->pluck('path') as $path) {
            $known[(string) $path] = true;
        }

        foreach ($this->legacyOwners($disk, $paths) as $path) {
            $known[$path] = true;
        }

        return $known;
    }

    /**
     * 旧 `.webp`（拡張子置換）の持ち主を探す。
     *
     * 持ち主のパスは「同じ場所・同じ幹・別の拡張子」なので、逆向きには一意に決まらない。
     * 幹で当たりを付けて `has_webp` の行だけを引き、**その行が名乗る**旧パスを
     * 「知っている」に加える。
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function legacyOwners(string $disk, array $paths): array
    {
        $stems = [];

        foreach ($paths as $path) {
            if (str_ends_with($path, '.webp')) {
                $directory = trim(pathinfo($path, PATHINFO_DIRNAME), '.'.DIRECTORY_SEPARATOR);
                $stems[] = ($directory !== '' ? $directory.'/' : '').pathinfo($path, PATHINFO_FILENAME).'.';
            }
        }

        if ($stems === []) {
            return [];
        }

        $owners = Media::query()
            ->where('disk', $disk)
            ->where('has_webp', true)
            ->where(function (Builder $query) use ($stems): void {
                foreach ($stems as $stem) {
                    $query->orWhere('path', 'like', $stem.'%');
                }
            })
            ->pluck('path');

        return array_values($owners
            ->map(static fn (mixed $path): string => ImageProcessor::webpPath((string) $path))
            ->all());
    }

    private function remove(string $disk, string $path): bool
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return true;
            }
        } catch (Throwable $e) {
            $this->warn("[{$disk}] {$path} を削除できませんでした: {$e->getMessage()}");

            return false;
        }

        $this->warn("[{$disk}] {$path} を削除できませんでした");

        return false;
    }

    /**
     * 走査する場所。**種別が知っている場所だけ**を見る。
     *
     * 同じ disk と同じディレクトリを 2 度走査しない（重複して数えない）。
     *
     * @return list<array{0: string, 1: string}> [disk, directory]
     */
    private function locations(): array
    {
        $locations = [];

        foreach (MediaKind::cases() as $kind) {
            $locations[$kind->disk().'|'.$kind->directory()] = [$kind->disk(), $kind->directory()];
        }

        return array_values($locations);
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use AgentCms\Core\Media\MediaKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * backup を作る（Phase 9.11 / STAGE 24B・SPEC §C.2）。
 *
 * ## 順序を固定する
 *
 *   1. 作業ディレクトリ
 *   2. DB を書き出す（streaming）
 *   3. メディアを写す（写した 1 件ごとに sha256 を取る）
 *   4. **DB が参照するメディアが全部写っているか**確かめる — 欠けていたら失敗
 *   5. **秘密の走査** — 見つかったら作業ごと捨てて失敗
 *   6. checksum を manifest に仕上げる
 *   7. zip に固める
 *   8. 作業ディレクトリを消す
 *
 * **途中で失敗したら、作業ディレクトリごと消す。**
 * 中途半端な backup を残さない。「あるのに戻せない」がいちばん危ない。
 *
 * ## 参照の整合（2026-09-09・backup 完全性）
 *
 * DB は 2 の時点、メディアは 3 の時点で写す。その間に画像が消えると、
 * **DB は画像を指しているのに zip に画像が無い** backup ができる。
 * これは「戻したら 404 になる」backup であって、成功ではない。
 * だから 4 で `media` / `media_variants` の行を 1 行ずつ読み、写した先に無ければ理由を返して失敗する。
 */
final class BackupBuilder
{
    /**
     * manifest の版。読む側が知らない版なら復元しない。
     *
     * - 1: `database.jsonl` の checksum だけ。メディアの一覧も checksum も無い
     * - 2: メディア全ファイルの sha256 と、写した root の一覧（空でも列挙）を持つ
     */
    public const FORMAT = 2;

    /** DB dump の中で、メディアの実体を指している表（`disk` + `path` 列） */
    private const REFERENCE_TABLES = ['media', 'media_variants'];

    /** 失敗理由に並べる欠落パスの上限 */
    private const MISSING_SAMPLE = 5;

    /**
     * メディアとして写す場所（WP 一周 6 周目 2026-09-03）。
     *
     * 🔴 **ここは直書きしてはいけない。**
     *
     * 直す前は `public/uploads` と `private/media` を写していた。
     * **製品はそのどちらにも書いていない** — 画像は `public` disk の `media/`、
     * ファイルは `local` disk の `files/` である（`MediaKind`）。
     * 結果、どの案件でも、いつ取っても**メディアは 0 件**だった。
     * `manifest` には `{"files": 0}` と出ていたが、これは
     * 「あなたのサイトに画像が無い」と読める文で、
     * **「あなたの画像を 1 枚も入れていない」とは読めなかった。**
     *
     * 置き場所を知っているのは `MediaKind` だけなので、そこへ聞く。
     * 種別が増えても、ここは何もしなくてよい。
     *
     * @return array<string, string> 実際の絶対パス → backup の中での置き場所
     */
    public static function mediaSources(): array
    {
        $sources = [];

        foreach (MediaKind::cases() as $kind) {
            $disk = $kind->disk();
            $directory = $kind->directory();

            if ($disk === '' || $directory === '') {
                continue;
            }

            /*
             * 中での置き場所は **種別の名前**にする（`media/image` / `media/file`）。
             *
             * 🔴 disk 名を使うと `media/local/…` になり、`SecretScanner` の
             * 「`local` という名前のディレクトリは丸ごと秘密」に引っかかって
             * **backup そのものが失敗する**（実測）。
             * 種別なら、置き場所の設定が変わっても戻す先を引き直せる。
             */
            // disk の実体（`local` は storage/app/private）は Storage に解決させる
            $sources[Storage::disk($disk)->path($directory)] = self::rootFor($kind);
        }

        return $sources;
    }

    /** backup の中での root（`media/<種別>`） */
    public static function rootFor(MediaKind $kind): string
    {
        return 'media/'.$kind->value;
    }

    /**
     * DB の行（`disk` + `path`）が指すファイルの、backup の中での置き場所。
     *
     * 写す対象の置き場所に無いものは null（この backup では扱わない）。
     */
    public static function archivePathFor(string $disk, string $path): ?string
    {
        // 列が空なのは移行前の行だけ（`Media::diskName()` と同じ既定）
        $disk = $disk !== '' ? $disk : (string) config('agentcms.uploads.disk');
        $path = ltrim(str_replace('\\', '/', $path), '/');

        foreach (MediaKind::cases() as $kind) {
            $directory = $kind->directory();

            if ($directory === '' || $kind->disk() !== $disk) {
                continue;
            }

            if (str_starts_with($path, $directory.'/')) {
                return self::rootFor($kind).'/'.substr($path, strlen($directory) + 1);
            }
        }

        return null;
    }

    public function __construct(
        private readonly DatabaseDump $dump,
        private readonly SecretScanner $scanner,
        private readonly BackupLock $lock,
    ) {}

    /**
     * 取得する。
     *
     * **同時に 1 つ**（release R09）。復元と取得が重なると、DB dump の途中でメディアが
     * 入れ替わる backup ができる。プロセスをまたいで `BackupLock` で排他する。
     * 復元の中から呼ばれる（復元前 backup）ときは同じ lock へ再入する。
     *
     * 中断した復元が残っていれば始めない — 途中の状態を backup に写さない。
     *
     * @param  string  $prefix  `pre-restore-` を付けると世代整理の対象外になる（SPEC §F）
     * @param  BackupProgress|null  $progress  段階と実測の進みを受け取る（画面から取るとき — OG3 改訂）。
     *                                         **順序も中身も変えない。** 報告するだけである
     */
    public function create(string $prefix = '', ?BackupProgress $progress = null): BackupManifest
    {
        BackupPaths::ensureRoot();
        RestoreJournal::assertNone();

        return $this->lock->exclusive('create', fn (): BackupManifest => $this->createLocked($prefix, $progress));
    }

    private function createLocked(string $prefix, ?BackupProgress $progress): BackupManifest
    {
        // lock を待つ間に別の復元が中断する場合があるため、取得後にも確認する。
        RestoreJournal::assertNone();

        // 終了時刻を基準にすると、取得中に行われた更新が復元前の案内から漏れる。
        $startedAt = now()->toIso8601String();

        $id = BackupPaths::newId($prefix);
        $workspace = BackupPaths::workspace($id);

        if (! @mkdir($workspace, 0700, true) && ! is_dir($workspace)) {
            throw new BackupFailed('作業ディレクトリを作れません');
        }

        try {
            $progress?->stage(BackupStage::Database);

            $tables = $this->dump->write(
                $workspace.'/database.jsonl',
                static fn (int $done, int $total) => $progress?->advance(BackupStage::Database, $done, $total),
            );

            $progress?->stage(BackupStage::Media);

            $media = $this->copyMedia($workspace, $progress);

            // DB が指しているのに写せていないファイルがあれば、ここで捨てる
            $this->assertReferencedMediaCaptured($workspace);

            /*
             * **ここで捨てる。** メディアの中に鍵や `.env` が紛れていたら、
             * 成果物にせず失敗させる（SPEC §B.3）。
             */
            $progress?->stage(BackupStage::SecretScan);

            $secrets = $this->scanner->scan($workspace);

            if ($secrets !== []) {
                throw new BackupFailed(
                    '秘密情報が含まれるため backup を作りませんでした: '.implode(', ', array_slice($secrets, 0, 5)),
                );
            }

            $manifest = new BackupManifest(
                id: $id,
                format: self::FORMAT,
                createdAt: $startedAt,
                app: [
                    'version' => trim((string) @file_get_contents(base_path('../VERSION'))) ?: 'unknown',
                    'php' => PHP_VERSION,
                    'driver' => DB::connection()->getDriverName(),
                ],
                migrations: self::migrations(),
                tables: $tables,
                media: ['files' => $media['files'], 'bytes' => $media['bytes'], 'roots' => $media['roots']],
                checksums: [
                    'database.jsonl' => hash_file('sha256', $workspace.'/database.jsonl') ?: '',
                    ...$media['checksums'],
                ],
                excluded: [
                    ...array_map(static fn (string $t): string => "table:{$t}", DatabaseDump::EXCLUDED_TABLES),
                    '.env / .env.*',
                    '.agentcms/local/',
                    'storage/framework / storage/logs',
                ],
                sizeBytes: 0,
            );

            file_put_contents($workspace.'/manifest.json', $manifest->toJson());

            $progress?->stage(BackupStage::Archive);

            $this->archive($workspace, BackupPaths::archive($id));

            return $manifest->withSize((int) (@filesize(BackupPaths::archive($id)) ?: 0));
        } catch (\Throwable $e) {
            self::remove($workspace);

            // 成果物が半分できていたら、それも消す
            @unlink(BackupPaths::archive($id));

            throw $e instanceof BackupFailed ? $e : new BackupFailed($e->getMessage(), 0, $e);
        } finally {
            self::remove($workspace);
        }
    }

    /**
     * メディアを写す。
     *
     * 写した 1 件ごとに sha256 を取る（**写した先**を読む — 元は次の瞬間に変わりうる）。
     * root は **ファイルが 0 件でも列挙する**。復元は root の一覧で「空にする」を知る。
     *
     * @return array{files: int, bytes: int, roots: list<string>, checksums: array<string, string>}
     */
    private function copyMedia(string $workspace, ?BackupProgress $progress = null): array
    {
        $files = 0;
        $bytes = 0;
        // 変換後も含めた「写した数」。画面には人が上げた枚数（`files`）ではなくこちらを出す
        // — 進みとして意味があるのは実際に動いた数だからである
        $copied = 0;
        $roots = [];
        $checksums = [];

        foreach (self::mediaSources() as $from => $destination) {
            $roots[] = $destination;

            if (! is_dir($from)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = self::relativeTo($from, $file->getPathname());
                $inArchive = $destination.'/'.$relative;
                $target = $workspace.'/'.$inArchive;

                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }

                if (! copy($file->getPathname(), $target)) {
                    throw new BackupFailed('メディアを写せませんでした');
                }

                $hash = hash_file('sha256', $target);

                if ($hash === false) {
                    throw new BackupFailed('写したメディアの checksum を取れませんでした');
                }

                $checksums[$inArchive] = $hash;

                /*
                 * **数えるのは人が上げた枚数**（WP 一周 9 周目 2026-09-04）。
                 *
                 * 画像を 1 枚上げると、製品は webp と avif も作る。ファイルの個数で
                 * 数えると backup は「メディア 3 件」と言うが、メディア画面は「1 件」で、
                 * **どこにも一致しない数字**が出る。「画像は全部入ったか」を確かめようと
                 * した人が確かめられない（10 枚なら 30 件になる）。
                 *
                 * 変換後は写す — 戻すときに要る。**数えないだけ**である。
                 * 大きさ（`bytes`）は全部ぶんのまま。zip の中身と合っている必要がある。
                 */
                if (! self::isVariant($relative)) {
                    $files++;
                }

                $bytes += (int) (@filesize($target) ?: 0);
                $copied++;

                // 総数は数えていない（数えるだけで大きなサイトを 1 周する）。分からないものは出さない
                $progress?->advance(BackupStage::Media, $copied);
            }
        }

        return ['files' => $files, 'bytes' => $bytes, 'roots' => $roots, 'checksums' => $checksums];
    }

    /**
     * DB が参照するメディアが、写した先に全部あるか。
     *
     * DB dump（2 の時点）とメディア（3 の時点）の間に消えたファイルを見つける。
     * 以前から欠けていたものも同じ理由で止まる — どちらも「戻すと 404 になる backup」であり、
     * 成功と呼べない。**行を消すか、ファイルを戻してから取り直す**のが正しい手順である。
     *
     * dump は 1 行ずつ読む。関係ない表の行は decode すらしない。
     */
    private function assertReferencedMediaCaptured(string $workspace): void
    {
        $handle = fopen($workspace.'/database.jsonl', 'rb');

        if ($handle === false) {
            throw new BackupFailed('書き出した DB を読み返せません');
        }

        $missing = [];
        $total = 0;

        try {
            $table = null;
            /** @var list<string> $columns */
            $columns = [];

            while (($line = fgets($handle)) !== false) {
                $isHeader = str_starts_with($line, '{"table"');

                if ($table === null && ! $isHeader) {
                    continue;
                }

                $decoded = json_decode(trim($line), true);

                if (! is_array($decoded)) {
                    continue;
                }

                if ($isHeader && isset($decoded['table'])) {
                    $name = (string) $decoded['table'];
                    $table = in_array($name, self::REFERENCE_TABLES, true) ? $name : null;
                    $columns = $table === null
                        ? []
                        : array_values(array_map(strval(...), (array) ($decoded['columns'] ?? [])));

                    continue;
                }

                if ($table === null || ! isset($decoded['row']) || ! is_array($decoded['row'])) {
                    continue;
                }

                $values = array_values($decoded['row']);

                if (count($values) !== count($columns)) {
                    continue;
                }

                $row = array_combine($columns, $values);
                $path = (string) ($row['path'] ?? '');
                $inArchive = self::archivePathFor((string) ($row['disk'] ?? ''), $path);

                // 以前の保存先や未対応ディスクの行も、黙ってバックアップから除外しない。
                if ($inArchive === null || $path === '' || ! is_file($workspace.'/'.$inArchive)) {
                    $total++;

                    if (count($missing) < self::MISSING_SAMPLE) {
                        $missing[] = $path !== '' ? $path : '(保存先が空のメディア)';
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if ($total === 0) {
            return;
        }

        throw new BackupFailed(sprintf(
            'DB が参照しているメディアが取得時に見つからないため backup を作りませんでした（取得中に削除されたか、以前から欠けています）: %s%s。該当のメディアを削除するかファイルを戻してから、取り直してください',
            implode(', ', $missing),
            $total > count($missing) ? ' ほか '.($total - count($missing)).' 件' : '',
        ));
    }

    /**
     * 変換後の置き場所か（`media/<種別>/variants/<id>/webp.webp`）。
     *
     * 置き場所は `MediaKind` 側が決めるが、ここが見るのは**写した先の相対経路**である。
     */
    private static function isVariant(string $relative): bool
    {
        return str_starts_with($relative, 'variants/') || str_contains($relative, '/variants/');
    }

    /** zip の中の名前は `/` 区切りに揃える（Windows の `\` をそのまま入れない） */
    private static function relativeTo(string $base, string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($base))), '/');
    }

    /** 作業ディレクトリを 1 つの zip に固める */
    private function archive(string $workspace, string $destination): void
    {
        $zip = new ZipArchive;

        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupFailed('backup ファイルを作れません');
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $zip->addFile($file->getPathname(), self::relativeTo($workspace, $file->getPathname()));
        }

        if (! $zip->close()) {
            throw new BackupFailed('backup ファイルを閉じられません');
        }

        // web から取れない場所に置く（SPEC §G.1）。念のため権限も絞る
        @chmod($destination, 0600);
    }

    /** @return list<string> */
    private static function migrations(): array
    {
        $rows = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();

        return array_values(array_map(strval(...), $rows));
    }

    private static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}

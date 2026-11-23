<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * backup を別の置き場所へ写す・そこから取ってくる（offsite）。
 *
 * ## 手元の置き場所は動かさない
 *
 * 取得と復元は今までどおり `storage/app/private/backups`（`BackupPaths`）で行う。
 * ここがするのは、**できあがった zip の写しを外へ置く**ことと、**外から手元へ戻す**ことだけ。
 * 手元の置き場所を設定で変えられるようにはしない（`BackupPaths` の理由のまま）。
 *
 * ## 置き場所は Laravel の disk で差し込む
 *
 * `agentcms.backup.offsite.disk` に `config/filesystems.php` の disk 名を書く。
 *
 *   - 別のディレクトリ（別のディスク・別のマウント）: `local` driver の disk
 *   - S3 互換（Cloudflare R2・Backblaze B2・MinIO など）: `s3` driver の disk。
 *     **`composer require league/flysystem-aws-s3-v3` が要る**（キットには同梱していない）
 *
 * ## 黙って成功にしない
 *
 * Laravel の disk は既定で `throw => false` — 書けなくても `false` が返るだけである。
 * ここでは戻り値も例外も全部見て、送れなかったら `BackupFailed` を投げる。
 * 送ったあとに大きさを読み直し、checksum（`<id>.zip.sha256`）を横に置く。
 * 取ってくるときは checksum と一致しなければ手元に置かない。
 *
 * ## 1 つの置き場所を複数のサイトで分ける
 *
 * 置き場所の中では `prefix`（サイトごとに変える）の下に並べる。
 * 世代の整理はその prefix の中だけで行う — 隣のサイトの backup を消さない。
 */
final class OffsiteBackups
{
    /** 外に残す世代の既定（手元の 5 世代とは別に数える） */
    public const DEFAULT_KEEP = 14;

    public function configured(): bool
    {
        return $this->diskName() !== '';
    }

    public function diskName(): string
    {
        return trim((string) config('agentcms.backup.offsite.disk', ''));
    }

    /**
     * 置き場所の中の、このサイトの場所。
     *
     * 未設定なら `APP_URL` の host から作る。`/` や `..` は入れさせない —
     * 隣のサイトの場所へ書けるようにしない。
     */
    public function prefix(): string
    {
        $prefix = trim((string) config('agentcms.backup.offsite.prefix', ''));

        if ($prefix === '') {
            $host = (string) parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $prefix = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-');
        }

        if ($prefix === '') {
            $prefix = 'site';
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $prefix) !== 1 || str_contains($prefix, '..')) {
            throw new BackupFailed('外の置き場所の prefix に使えない文字があります（英小文字・数字・. _ - だけ）: '.$prefix);
        }

        return $prefix;
    }

    public function keep(): int
    {
        return max(1, (int) config('agentcms.backup.offsite.keep', self::DEFAULT_KEEP));
    }

    /** 人に見せる置き場所の名前（秘密は含まない） */
    public function describe(): string
    {
        return $this->diskName().':'.$this->prefix().'/';
    }

    /**
     * 写す。送った大きさを読み直し、checksum を横に置くまでが 1 回。
     *
     * @return array{id: string, path: string, bytes: int, sha256: string}
     */
    public function push(string $id): array
    {
        if (! BackupPaths::isValidId($id) || ! is_file(BackupPaths::archive($id))) {
            throw new BackupFailed('その backup は手元にありません: '.$id);
        }

        $local = BackupPaths::archive($id);
        $bytes = (int) filesize($local);
        $sha256 = (string) hash_file('sha256', $local);
        $disk = $this->disk();
        $path = $this->archivePath($id);

        $stream = @fopen($local, 'rb');

        if ($stream === false) {
            throw new BackupFailed('手元の backup を開けません: '.$id);
        }

        try {
            $written = $disk->writeStream($path, $stream);
        } catch (Throwable $e) {
            throw new BackupFailed('外の置き場所へ送れませんでした（'.$this->describe().'）: '.$e->getMessage(), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new BackupFailed('外の置き場所へ送れませんでした（'.$this->describe().'）: 書き込みが拒まれました');
        }

        $remoteBytes = $this->remoteSize($disk, $path);

        if ($remoteBytes !== $bytes) {
            $this->quietDelete($disk, $path);

            throw new BackupFailed(sprintf('外の置き場所へ送れませんでした（%s）: 大きさが合いません（手元 %d / 外 %s）', $this->describe(), $bytes, $remoteBytes === null ? '読めない' : (string) $remoteBytes));
        }

        try {
            $ok = $disk->put($this->checksumPath($id), $sha256."\n");
        } catch (Throwable $e) {
            $ok = false;
        }

        if ($ok === false) {
            $this->quietDelete($disk, $path);

            throw new BackupFailed('外の置き場所へ送れませんでした（'.$this->describe().'）: checksum を置けません');
        }

        return ['id' => $id, 'path' => $path, 'bytes' => $bytes, 'sha256' => $sha256];
    }

    /**
     * 外にある backup の id。新しい順。
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $disk = $this->disk();

        try {
            $files = $disk->files($this->prefix());
        } catch (Throwable $e) {
            throw new BackupFailed('外の置き場所を読めません（'.$this->describe().'）: '.$e->getMessage(), 0, $e);
        }

        $ids = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (! str_ends_with($name, '.zip')) {
                continue;
            }

            $id = substr($name, 0, -4);

            if (BackupPaths::isValidId($id)) {
                $ids[] = $id;
            }
        }

        // id は時刻順に並ぶ形（BackupPaths::newId）。復元前の印は並びに使わない
        usort($ids, static fn (string $a, string $b): int => strcmp(self::sortKey($b), self::sortKey($a)));

        return $ids;
    }

    /** 外にある、いちばん新しい通常の backup（復元前の印のものは除く） */
    public function latest(): ?string
    {
        foreach ($this->ids() as $id) {
            if (! str_starts_with($id, BackupPaths::PRE_RESTORE_PREFIX)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * 外の世代を整理する。**このサイトの prefix の中だけ**、復元前の印のものは対象外。
     *
     * @return list<string> 消した id
     */
    public function prune(?int $keep = null): array
    {
        $keep ??= $this->keep();
        $disk = $this->disk();

        $candidates = array_values(array_filter(
            $this->ids(),
            static fn (string $id): bool => ! str_starts_with($id, BackupPaths::PRE_RESTORE_PREFIX),
        ));

        $removed = [];

        foreach (array_slice($candidates, max(1, $keep)) as $id) {
            try {
                $ok = $disk->delete([$this->archivePath($id), $this->checksumPath($id)]);
            } catch (Throwable $e) {
                throw new BackupFailed('外の古い backup を消せません（'.$id.'）: '.$e->getMessage(), 0, $e);
            }

            if ($ok === false) {
                throw new BackupFailed('外の古い backup を消せません（'.$id.'）');
            }

            $removed[] = $id;
        }

        return $removed;
    }

    /**
     * 外から手元へ取ってくる。checksum と一致したものだけを手元の一覧に入れる。
     *
     * 手元に同じ id が既にあり中身も同じなら、何もしない。
     * 同じ id で中身が違うなら止める（どちらが正しいかを機械が決めない）。
     */
    public function pull(string $id): void
    {
        if (! BackupPaths::isValidId($id)) {
            throw new BackupFailed('その id は使えません: '.$id);
        }

        $disk = $this->disk();
        $expected = $this->remoteChecksum($disk, $id);
        $target = BackupPaths::archive($id);

        if (is_file($target)) {
            if (hash_equals($expected, (string) hash_file('sha256', $target))) {
                return;
            }

            throw new BackupFailed('手元に同じ id で中身の違う backup があります。手で確かめてください: '.$id);
        }

        BackupPaths::ensureRoot();
        $part = BackupPaths::root().'/.'.$id.'.part';
        @unlink($part);

        try {
            $in = $disk->readStream($this->archivePath($id));
        } catch (Throwable $e) {
            throw new BackupFailed('外の置き場所から取れません（'.$id.'）: '.$e->getMessage(), 0, $e);
        }

        if (! is_resource($in)) {
            throw new BackupFailed('外の置き場所にその backup はありません: '.$id);
        }

        $out = @fopen($part, 'xb');

        if ($out === false) {
            fclose($in);

            throw new BackupFailed('手元に書けません: '.$part);
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false || ! hash_equals($expected, (string) hash_file('sha256', $part))) {
            @unlink($part);

            throw new BackupFailed('外の置き場所の backup が、送ったときと違います（壊れているか、書き換えられています）: '.$id);
        }

        @chmod($part, 0600);

        if (! @rename($part, $target)) {
            @unlink($part);

            throw new BackupFailed('手元の一覧へ入れられません: '.$id);
        }
    }

    private function disk(): Filesystem
    {
        if (! $this->configured()) {
            throw new BackupFailed('外の置き場所が設定されていません（AGENTCMS_BACKUP_OFFSITE_DISK）');
        }

        $name = $this->diskName();
        $config = config('filesystems.disks.'.$name);

        if (! is_array($config)) {
            throw new BackupFailed('外の置き場所の disk「'.$name.'」が config/filesystems.php にありません');
        }

        if (($config['driver'] ?? null) === 'local') {
            $this->assertElsewhere((string) ($config['root'] ?? ''));
        }

        try {
            /*
             * 設定の throw が false でも、ここでは例外で受ける — 拒まれた理由
             * （鍵の誤り・バケットが無い等）を、`false` に潰さずに運用者へ出すため。
             */
            return Storage::build(array_merge($config, ['throw' => true, 'report' => false]));
        } catch (Throwable $e) {
            $hint = ($config['driver'] ?? null) === 's3'
                ? '（S3 互換を使うときは composer require league/flysystem-aws-s3-v3 が要ります）'
                : '';

            throw new BackupFailed('外の置き場所の disk「'.$name.'」を開けません'.$hint.': '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * ディレクトリの置き場所が、手元の backup と同じ場所の中になっていないか。
     *
     * 同じ場所に写しても、サーバーが壊れたら一緒に消える。外に置いたつもりで
     * 実は同じ場所、を黙って通さない。
     */
    private function assertElsewhere(string $root): void
    {
        if ($root === '') {
            throw new BackupFailed('外の置き場所のディレクトリが空です（AGENTCMS_BACKUP_OFFSITE_ROOT）');
        }

        $normalized = rtrim($root, '/').'/';
        $local = rtrim(BackupPaths::root(), '/').'/';

        if (str_starts_with($normalized, $local) || str_starts_with($local, $normalized)) {
            throw new BackupFailed('外の置き場所が手元の backup と同じ場所です。別のディスクか S3 互換の置き場所を指定してください');
        }

        /*
         * web から見える場所には置かない（BackupPaths と同じ理由 — URL を推測されただけで
         * 全データが持ち去られる）。
         */
        foreach ([public_path(), storage_path('app/public')] as $exposed) {
            $exposed = rtrim($exposed, '/').'/';

            if (str_starts_with($normalized, $exposed)) {
                throw new BackupFailed('外の置き場所が web から見える場所の中です。公開ディレクトリの外を指定してください');
            }
        }
    }

    private function remoteChecksum(Filesystem $disk, string $id): string
    {
        try {
            $raw = $disk->get($this->checksumPath($id));
        } catch (Throwable $e) {
            $raw = null;
        }

        $sum = strtolower(trim((string) $raw));

        if (preg_match('/^[0-9a-f]{64}$/', $sum) !== 1) {
            throw new BackupFailed('外の置き場所にその backup が無いか、checksum が読めません: '.$id);
        }

        return $sum;
    }

    private function remoteSize(Filesystem $disk, string $path): ?int
    {
        try {
            return $disk->size($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function quietDelete(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable) {
            // 片付けの失敗で元の理由を隠さない
        }
    }

    private function archivePath(string $id): string
    {
        return $this->prefix().'/'.$id.'.zip';
    }

    private function checksumPath(string $id): string
    {
        return $this->archivePath($id).'.sha256';
    }

    private static function sortKey(string $id): string
    {
        return str_starts_with($id, BackupPaths::PRE_RESTORE_PREFIX)
            ? substr($id, strlen(BackupPaths::PRE_RESTORE_PREFIX))
            : $id;
    }
}

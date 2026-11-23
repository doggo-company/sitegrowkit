<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use ZipArchive;

/**
 * ある backup を数え、探し、消す（Phase 9.11 / STAGE 24B・SPEC §F）。
 *
 * 一覧は **zip の中の `manifest.json` だけ**を読む。中身を展開しない。
 * 一覧を開くたびに全部を展開していては、大きな backup ほど画面が重くなる。
 */
final class BackupCatalog
{
    /** 残す世代の既定（OG11） */
    public const DEFAULT_KEEP = 5;

    /**
     * 新しい順。
     *
     * 🔴 **id の文字列比較で並べていた**（〜2026-09-04）。復元前のものは
     * `pre-restore-` が頭に付くので、ASCII で `p` > `2` — **時刻に関係なく先頭に
     * 固まっていた**。実測では、取り直したばかりの backup が 9 時間前の自動 backup
     * 6 件の下、7 番目に並んでいた。
     *
     * 一番上を押せば最新に戻ると思う。実際は 9 時間前に戻る。しかも戻す直前に
     * いまの状態が `pre-restore` として保存されるので、間違いに気づいたときには
     * 一覧がさらに 1 段ずれている。
     *
     * 種類は並びでなく `pre_restore` の印で区別する（画面はそれを出している）。
     *
     * @return list<BackupManifest>
     */
    public function all(): array
    {
        BackupPaths::ensureRoot();

        $files = glob(BackupPaths::root().'/*.zip') ?: [];
        $manifests = [];

        foreach ($files as $file) {
            $id = basename($file, '.zip');
            $manifest = $this->read($id);

            if ($manifest !== null) {
                $manifests[] = $manifest;
            }
        }

        usort($manifests, static fn (BackupManifest $a, BackupManifest $b): int => strcmp($b->takenAt(), $a->takenAt()));

        return $manifests;
    }

    /** 読めなければ null（壊れた zip で一覧ごと落とさない） */
    public function read(string $id): ?BackupManifest
    {
        if (! BackupPaths::isValidId($id)) {
            return null;
        }

        $path = BackupPaths::archive($id);

        if (! is_file($path)) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $raw = $zip->getFromName('manifest.json');
        $zip->close();

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return BackupManifest::fromArray($id, $decoded)
            ->withSize((int) (@filesize($path) ?: 0));
    }

    public function exists(string $id): bool
    {
        return BackupPaths::isValidId($id) && is_file(BackupPaths::archive($id));
    }

    public function delete(string $id): bool
    {
        if (! $this->exists($id)) {
            return false;
        }

        return @unlink(BackupPaths::archive($id));
    }

    /**
     * 世代を整理する。
     *
     * **復元前 backup は対象外。** 事故のあとに「戻す前の状態」が
     * 世代整理で消えていた、を起こさない（SPEC §F）。
     *
     * @return list<string> 消した id
     */
    public function prune(?int $keep = null, ?int $keepDays = null): array
    {
        $keep ??= (int) config('agentcms.backup.keep', self::DEFAULT_KEEP);
        $keepDays ??= (int) config('agentcms.backup.keep_days', 0);

        $candidates = array_values(array_filter(
            $this->all(),
            static fn (BackupManifest $m): bool => ! $m->isPreRestore(),
        ));

        $removed = [];

        if ($keep > 0) {
            foreach (array_slice($candidates, $keep) as $manifest) {
                if ($this->delete($manifest->id)) {
                    $removed[] = $manifest->id;
                }
            }
        }

        if ($keepDays > 0) {
            $limit = now()->subDays($keepDays);

            foreach ($candidates as $manifest) {
                if (in_array($manifest->id, $removed, true) || $manifest->createdAt === '') {
                    continue;
                }

                if (strtotime($manifest->createdAt) < $limit->getTimestamp() && $this->delete($manifest->id)) {
                    $removed[] = $manifest->id;
                }
            }
        }

        return $removed;
    }
}

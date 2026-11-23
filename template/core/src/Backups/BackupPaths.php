<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * backup の置き場所（Phase 9.11 / STAGE 24B・SPEC §G.1）。
 *
 * ## **web から取れない場所に置く。これが本書で最重要の 1 行である。**
 *
 * backup には利用者のパスワード hash と問い合わせの本文が入る。
 * `public/` の下に落ちると、**URL を推測されただけでサイトの全データが持ち去られる**。
 *
 * だから保存先は `local` disk（`storage/app/private`）に固定し、
 * **設定で変えられるようにしない**。変えられる余地があれば、いつか誰かが変える。
 */
final class BackupPaths
{
    /** `local` disk（= `storage/app/private`）の下 */
    public const DIRECTORY = 'backups';

    /** 復元で置き換える前の現物を退避する場所（SPEC §D.5） */
    public const REPLACED = 'backups/.replaced';

    /** 復元の直前に取る backup（世代整理の対象外 — SPEC §F） */
    public const PRE_RESTORE_PREFIX = 'pre-restore-';

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public static function root(): string
    {
        return self::disk()->path(self::DIRECTORY);
    }

    /** その id の成果物（zip） */
    public static function archive(string $id): string
    {
        return self::root().'/'.$id.'.zip';
    }

    /** 取得中の作業ディレクトリ（完了したら消す） */
    public static function workspace(string $id): string
    {
        return self::root().'/'.$id;
    }

    /**
     * 画面から始めた取得の**状態**を置く場所（2026-09-09・OG3 改訂）。
     *
     * zip と同じ private の根の下に置く。一覧は `*.zip` だけを読むので混ざらない。
     * DB に持たないのは、復元が DB を丸ごと入れ替えるからである —
     * 「復元の直前に取った backup の進行記録」が復元で消える、を起こさない。
     */
    public static function runs(): string
    {
        return self::root().'/.runs';
    }

    /** その run の状態ファイル */
    public static function run(string $runId): string
    {
        return self::runs().'/'.$runId.'.json';
    }

    /**
     * 同時に 1 つしか走らせないための印（連打・二重起動を止める）。
     *
     * 中身は run id。`fopen('x')` で**作れた側だけ**が取得を始められる。
     */
    public static function runLock(): string
    {
        return self::runs().'/active.lock';
    }

    public static function ensureRuns(): void
    {
        self::ensureRoot();

        if (! is_dir(self::runs())) {
            @mkdir(self::runs(), 0700, true);
        }
    }

    /**
     * id として使える形か。
     *
     * **ここを緩めるとディレクトリを抜け出せる。** 画面や API から来た文字列は
     * すべてここを通す（`../` を含む id で任意のファイルを消せる・読めるようにしない）。
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $id) === 1;
    }

    /**
     * 新しい id。
     *
     * **並べると時刻順になること**が要る。世代整理は「古いものから捨てる」ので、
     * 順序が狂うと**新しい backup を先に捨てる**。
     * 秒までしか持たないと、続けて取ったときに乱数部分で順序が決まってしまうので、
     * ミリ秒まで入れる。
     */
    public static function newId(string $prefix = ''): string
    {
        return $prefix.now()->format('Ymd-His-v').'-'.substr(bin2hex(random_bytes(4)), 0, 6);
    }

    public static function ensureRoot(): void
    {
        $disk = self::disk();

        if (! $disk->exists(self::DIRECTORY)) {
            $disk->makeDirectory(self::DIRECTORY);
        }
    }
}

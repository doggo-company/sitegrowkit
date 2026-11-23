<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 中断した復元を、**意図と事実から**整える（2026-09-09・release R09）。
 *
 * ## 「全部戻した」とは言わない
 *
 * 途中で消えたプロセスは何も報告できない。分かるのは 2 つだけ:
 *
 *   - journal（`RestoreJournal`）— root ごとに**何をしようとしたか**（展開先・戻す先・退避先と、
 *     戻す先が最初から在ったか）
 *   - DB の印（`backup_restores`）— transaction が**commit されたか**
 *
 * | 印 | 意味 | すること |
 * | --- | --- | --- |
 * | 無い | commit 前に消えた。DB は復元前 | root ごとに実物を見て、復元前の置き方へ戻す |
 * | 在る | commit 後に消えた。DB は復元後 | rename が全部終わっていることを確かめ、cache を捨て、後始末 |
 *
 * ## 何度落ちても同じ結果になる（root 単位の状態）
 *
 * 手（rename）ごとに「起きたか」を見ると判定できない — 「退避 → 展開先を入れる」の
 * 2 手を戻し終えると、展開先にも戻す先にも物が在り、2 手目が「起きた」ようにも見える
 * （Codex の `BackupRecoveryReviewTest` が再現した）。
 *
 * だから root ごとに (展開先 S, 戻す先 T, 退避先 P) の有無を、計画時の「T は在ったか」と
 * 突き合わせて **1 つの状態**に落とす。復元前の置き方（`untouched`）なら何もしない。
 * 戻し終えたら journal に `rolled_back` を**先に**書いてから作業ディレクトリを消す —
 * 消したあとに落ちても、次回は実物を見ずに済む（見れば判定できない）。
 *
 * ## 消さない
 *
 * 退避したメディア（`backups/.replaced/`）も、journal（`history/` へ移す）も消さない。
 * 判断できない状態（`ambiguous`）は触らず、journal を残して人に見せる。
 */
final class RestoreRecovery
{
    public const MARKER_TABLE = 'backup_restores';

    /** 復元前の置き方（展開先に backup、戻す先に現物）。何もしない */
    public const UNTOUCHED = 'untouched';

    /** 現物を退避しただけ（戻す先が空） */
    public const PARKED_ONLY = 'parked_only';

    /** 入れ替え済み（戻す先に backup、退避先に現物） */
    public const SWAPPED = 'swapped';

    /** 判定できない。触らない */
    public const AMBIGUOUS = 'ambiguous';

    public function __construct(private readonly BackupLock $lock) {}

    /** commit の印が在るか（呼び出し側の transaction の外で見ること） */
    public static function committed(string $restoreId): bool
    {
        if (! Schema::hasTable(self::MARKER_TABLE)) {
            return false;
        }

        return DB::table(self::MARKER_TABLE)->where('restore_id', $restoreId)->exists();
    }

    /**
     * いまの状態。**何も変えない。**
     *
     * @return array{
     *   pending: bool,
     *   restore_id: string|null,
     *   backup_id: string|null,
     *   pre_restore_id: string|null,
     *   started_at: string|null,
     *   phase: string|null,
     *   committed: bool|null,
     *   action: string,
     *   roots: list<array{root: string, state: string}>
     * }
     */
    public function status(): array
    {
        $journal = RestoreJournal::pending();

        if ($journal === null) {
            return [
                'pending' => false, 'restore_id' => null, 'backup_id' => null, 'pre_restore_id' => null,
                'started_at' => null, 'phase' => null, 'committed' => null, 'action' => 'none', 'roots' => [],
            ];
        }

        $committed = self::committed($journal->restoreId());

        return [
            'pending' => true,
            'restore_id' => $journal->restoreId(),
            'backup_id' => $journal->backupId(),
            'pre_restore_id' => $journal->preRestoreId(),
            'started_at' => $journal->startedAt(),
            'phase' => $journal->phase(),
            'committed' => $committed,
            'action' => $committed ? 'finish' : 'rollback',
            'roots' => array_map(
                static fn (array $move): array => ['root' => $move['root'], 'state' => self::rootState($move)],
                $journal->moves(),
            ),
        ];
    }

    /**
     * 整える。
     *
     * @return array{action: string, restore_id: string|null, problems: list<string>}
     */
    public function recover(): array
    {
        return $this->lock->exclusive('recover', function (): array {
            $journal = RestoreJournal::pending();

            if ($journal === null) {
                return ['action' => 'none', 'restore_id' => null, 'problems' => []];
            }

            return self::committed($journal->restoreId())
                ? $this->finish($journal)
                : $this->rollback($journal);
        });
    }

    /**
     * commit 後。**ファイルは戻さない。** 終わっていることを確かめて仕上げる。
     *
     * 作業ディレクトリを消したあとに落ちても、次回は「展開先が無い」= 終わっている、で通る。
     *
     * @return array{action: string, restore_id: string|null, problems: list<string>}
     */
    private function finish(RestoreJournal $journal): array
    {
        $problems = [];

        if ($journal->phase() === RestoreJournal::ROLLED_BACK) {
            // 戻したと記録した復元に印が在る。あり得ないので触らない
            $problems[] = 'journal は「戻した」と言っているのに DB は commit 済みです。手で確認が必要です';
        }

        foreach ($journal->moves() as $move) {
            clearstatcache(true, $move['target']);
            clearstatcache(true, $move['source']);

            if (! is_dir($move['target'])) {
                $problems[] = "メディア（{$move['root']}）の置き場所がありません: {$move['target']}";
            }

            if (file_exists($move['source'])) {
                $problems[] = "メディア（{$move['root']}）の展開先が残っています（入れ替えが終わっていません）: {$move['source']}";
            }
        }

        if ($problems !== []) {
            Log::error('backup.restore.recover.finish_blocked', ['restore' => $journal->restoreId(), 'problems' => $problems]);

            return ['action' => 'finish', 'restore_id' => $journal->restoreId(), 'problems' => $problems];
        }

        SettingsStore::forget();
        self::remove($journal->workspace());
        $journal->archive('completed_by_recovery');

        return ['action' => 'finish', 'restore_id' => $journal->restoreId(), 'problems' => []];
    }

    /**
     * commit 前。root ごとに復元前の置き方へ戻す。
     *
     * @return array{action: string, restore_id: string|null, problems: list<string>}
     */
    private function rollback(RestoreJournal $journal): array
    {
        if ($journal->phase() !== RestoreJournal::ROLLED_BACK) {
            $problems = self::undo($journal->moves());

            if ($problems !== []) {
                Log::error('backup.restore.recover.rollback_blocked', ['restore' => $journal->restoreId(), 'problems' => $problems]);

                return ['action' => 'rollback', 'restore_id' => $journal->restoreId(), 'problems' => $problems];
            }

            // 🔴 消す前に「戻した」を残す。消したあとでは実物から判定できない
            $journal->advance(RestoreJournal::ROLLED_BACK);
        }

        self::remove($journal->workspace());
        $journal->archive('rolled_back_by_recovery');

        return ['action' => 'rollback', 'restore_id' => $journal->restoreId(), 'problems' => []];
    }

    /**
     * root ごとに復元前の置き方へ戻す。**何度呼んでも同じ結果**になる。
     *
     * 逆順に、状態を見て必要な rename だけを行う。2 手の途中で落ちても、次回はその続きになる。
     * 判定できない root は触らず理由を返す。
     *
     * @param  list<array{root: string, source: string, target: string, parked: string, target_existed: bool}>  $moves  計画した順
     * @return list<string> 戻せなかった理由。空なら全 root が復元前の置き方
     */
    public static function undo(array $moves): array
    {
        $problems = [];

        foreach (array_reverse($moves) as $move) {
            $state = self::rootState($move);

            if ($state === self::UNTOUCHED) {
                continue;
            }

            if ($state === self::AMBIGUOUS) {
                $problems[] = self::describe($move, '判断できない状態です。手で確認が必要です');

                continue;
            }

            if ($state === self::SWAPPED && ! @rename($move['target'], $move['source'])) {
                $problems[] = self::describe($move, '戻す先から展開先へ戻せませんでした');

                continue;
            }

            // ここで落ちても、次回は parked_only として続きから戻る
            if ($move['target_existed'] && ! @rename($move['parked'], $move['target'])) {
                $problems[] = self::describe($move, '退避先から戻す先へ戻せませんでした');
            }
        }

        foreach ($problems as $problem) {
            Log::error('backup.restore.undo_failed', ['problem' => $problem]);
        }

        return $problems;
    }

    /**
     * その root がいまどの置き方か。実物（展開先 S / 戻す先 T / 退避先 P の有無）と、
     * 計画時に T が在ったかから決める。
     *
     * @param  array{root: string, source: string, target: string, parked: string, target_existed: bool}  $move
     * @return self::UNTOUCHED|self::PARKED_ONLY|self::SWAPPED|self::AMBIGUOUS
     */
    public static function rootState(array $move): string
    {
        clearstatcache(true, $move['source']);
        clearstatcache(true, $move['target']);
        clearstatcache(true, $move['parked']);

        $s = file_exists($move['source']);
        $t = file_exists($move['target']);
        $p = file_exists($move['parked']);

        if ($move['target_existed']) {
            return match (true) {
                $s && $t && ! $p => self::UNTOUCHED,
                $s && ! $t && $p => self::PARKED_ONLY,
                ! $s && $t && $p => self::SWAPPED,
                default => self::AMBIGUOUS,
            };
        }

        return match (true) {
            $s && ! $t && ! $p => self::UNTOUCHED,
            ! $s && $t && ! $p => self::SWAPPED,
            default => self::AMBIGUOUS,
        };
    }

    /** @param  array{root: string, source: string, target: string, parked: string, target_existed: bool}  $move */
    private static function describe(array $move, string $what): string
    {
        return sprintf(
            'メディア（%s）: %s（展開先 %s / 戻す先 %s / 退避先 %s / 戻す先は最初から%s）',
            $move['root'],
            $what,
            file_exists($move['source']) ? '在る' : '無い',
            file_exists($move['target']) ? '在る' : '無い',
            file_exists($move['parked']) ? '在る' : '無い',
            $move['target_existed'] ? '在った' : '無かった',
        );
    }

    private static function remove(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
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

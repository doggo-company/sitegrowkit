<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * backup を確かめる / 戻す（Phase 9.11 / STAGE 24C・SPEC §D）。
 *
 * ## 戻す前に確かめる
 *
 * **復元できない backup を復元しようとして初めて気づく、が最悪。**
 * `verify()` は何も変えずに、戻せるかどうかだけを答える。
 *
 * 確かめるのは manifest が語る中身と zip の実物の一致である（2026-09-09・backup 完全性）:
 *
 *   - manifest に在るファイルが zip に**全部**在り、sha256 が一致する
 *   - manifest に無いファイルが zip に**無い**（足されたものは復元しない）
 *   - zip の中の名前が安全である（`..` / 絶対パス / `\` を展開しない）
 *   - メディアの root が、いまの CMS が知っている種別である
 *
 * 大きなファイルも memory に載せない（zip の stream を hash に流す）。
 *
 * ## format 1 は復元しない
 *
 * この版より前の backup（format 1）はメディアの一覧も checksum も持たない。
 * 画像が 1 枚欠けていても**見つけようが無い**ので、「戻せる」とは言えない。
 * 検証不能なものを復元可能と呼ばない — 取り直してもらう。
 *
 * ## migration がずれていたら戻さない（OG5）
 *
 * `migrations` が一致しないということは、backup の時点と今とで**表の形が違う**。
 * そのまま流せば列が足りない・多い状態になる。`--force` のような抜け道も作らない。
 * 止まったこと自体が「その backup は今の環境向きではない」という情報である。
 *
 * ## DB とメディアは一緒に戻る、または一緒に戻らない
 *
 * メディアの入れ替えは **DB の transaction の中**で行う。入れ替えの途中で rename が
 * 失敗したら、動かした分を元へ戻してから例外を投げ、transaction も戻る。
 * 「DB は戻ったのに画像は前のまま」を作らない。
 *
 * ## プロセスが消えても追える（release R09・2026-09-09）
 *
 * 例外では戻れない止まり方（SIGKILL・電源断・OOM）がある。rename の意図は
 * 動かす前に `RestoreJournal` へ書き、commit の印は同じ transaction の中で
 * `backup_restores` へ入れる。残った journal は HTTP を止め、
 * `agentcms:backup-recover` が「印が在れば仕上げる・無ければ戻す」を行う。
 * 「全部戻した」と言えない止まり方を、言えないままにしない。
 *
 * ## session は同じ transaction の中で切る
 *
 * `users` を入れ直すので、いまログインしている人の前提が変わる。世代番号は
 * **復元前の最大と復元後の最大のどちらより大きく**する — 古い backup の世代 + 1 が
 * いまの世代と一致して、切ったつもりの session が生き残るのを防ぐ。
 * DB と一緒に commit されるので、「DB は戻ったのに session は切れていない」は無い。
 *
 * ## 現物は消さずに退避する
 *
 * メディアを入れ替えるとき、いまのファイルは消さずに移す。
 * 復元そのものが間違いだったときに、手で戻せる余地を残す。
 */
final class BackupRestorer
{
    /** 問題として並べるファイル名の上限 */
    private const SAMPLE = 3;

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly DatabaseDump $dump,
        private readonly BackupBuilder $builder,
        private readonly BackupLock $lock,
        private readonly RestoreProbe $probe,
    ) {}

    /**
     * 戻せるか。**何も変えない。**
     *
     * @return array{ok: bool, problems: list<string>, manifest: BackupManifest|null}
     */
    public function verify(string $id): array
    {
        $manifest = $this->catalog->read($id);

        if ($manifest === null) {
            return ['ok' => false, 'problems' => ['backup を読めません（壊れているか、ありません）'], 'manifest' => null];
        }

        if ($manifest->format !== BackupBuilder::FORMAT) {
            return ['ok' => false, 'problems' => [self::formatProblem($manifest->format)], 'manifest' => $manifest];
        }

        $problems = [];
        $expected = self::expectedEntries($manifest, $problems);

        $zip = new ZipArchive;

        if ($zip->open(BackupPaths::archive($id)) !== true) {
            return ['ok' => false, 'problems' => ['backup を開けません'], 'manifest' => $manifest];
        }

        try {
            $this->compareArchive($zip, $expected, $problems);
        } finally {
            $zip->close();
        }

        $missing = array_values(array_diff($manifest->migrations, self::currentMigrations()));
        $extra = array_values(array_diff(self::currentMigrations(), $manifest->migrations));

        if ($missing !== [] || $extra !== []) {
            $problems[] = sprintf(
                'この backup を取ったときと、いまとで DB の形が違います（backup にだけある変更 %d 件 / いまだけにある変更 %d 件）',
                count($missing),
                count($extra),
            );
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'manifest' => $manifest];
    }

    /**
     * 戻す。
     *
     * **必ず復元前 backup を取る。取れなければ復元しない**（OG6）。
     *
     * @return array{restored: array<string, int>, preRestore: string}
     */
    public function restore(string $id): array
    {
        RestoreJournal::assertNone();

        return $this->lock->exclusive('restore', fn (): array => $this->restoreLocked($id));
    }

    /** @return array{restored: array<string, int>, preRestore: string} */
    private function restoreLocked(string $id): array
    {
        // lock を待つ間に別の復元が中断する場合があるため、取得後にも確認する。
        RestoreJournal::assertNone();

        $check = $this->verify($id);

        if (! $check['ok'] || $check['manifest'] === null) {
            throw new BackupFailed(implode(' / ', $check['problems']));
        }

        $manifest = $check['manifest'];

        /*
         * **ここが最後の綱である。** 復元そのものが間違いだったとき、
         * 戻る先が無くなる。取れなければ復元しない。
         */
        $preRestore = $this->builder->create(BackupPaths::PRE_RESTORE_PREFIX);

        $restoreId = self::freshRestoreId();
        $workspace = BackupPaths::workspace($restoreId);
        $journal = null;

        try {
            $this->extract($id, $workspace);

            $moves = self::planSwap($manifest, $workspace);

            // ここから先は、プロセスが消えても記録が残る
            $journal = RestoreJournal::begin($restoreId, $id, $preRestore->id, $workspace, $moves);

            $restored = DB::transaction(function () use ($workspace, $moves, $journal, $restoreId, $id, $preRestore): array {
                $previousEpoch = (int) DB::table('users')->max('session_epoch');

                $restored = $this->dump->restore($workspace.'/database.jsonl', $this->dump->tables());

                self::invalidateSessions($previousEpoch);

                $journal->advance(RestoreJournal::SWAPPING);

                // 失敗したら例外 → transaction も戻る（動かした分は catch が戻す）
                $this->swapMedia($moves, $journal);

                $journal->advance(RestoreJournal::COMMITTING);

                // commit の印。この行が在る = この transaction は commit された
                DB::table(RestoreRecovery::MARKER_TABLE)->insert([
                    'restore_id' => $restoreId,
                    'backup_id' => $id,
                    'pre_restore_id' => $preRestore->id,
                    'committed_at' => now(),
                ]);

                $this->probe->at(RestoreProbe::BEFORE_COMMIT);

                return $restored;
            });

            $journal->advance(RestoreJournal::COMMITTED);

            $this->probe->at(RestoreProbe::AFTER_COMMIT);

            /*
             * 🔴 **覚えている値を捨てる**（WP 一周 9 周目 2026-09-04 / 効くもの ①）。
             *
             * ここまでで DB は正しく戻っている。それでも画面は古いままだった —
             * `SettingsStore::all()` が `rememberForever` で持っていて、捨てるのは
             * 設定画面からの保存（`put()`）のときだけだったからである。
             * 復元は DB へ直に書くので、その経路を通らない。**期限が無いので永久に残る。**
             *
             * 実測: DB は「沼津どうぶつ病院」に戻っているのに、公開サイトも管理画面も
             * 「あああ間違えた名前」を出し、問い合わせの通知は古い宛先へ届いた。
             *
             * いちばん悪いのはこの先である。オーナーは設定画面を見て「戻っていない」と
             * 思い、**保存を押す**。画面に出ていた古い値がそのまま書かれ、
             * **戻っていた値が消える**。エラーは 1 件も出ない。
             */
            SettingsStore::forget();

            $journal->archive('completed');

            return ['restored' => $restored, 'preRestore' => $preRestore->id];
        } catch (\Throwable $e) {
            /*
             * commit されたかは journal の段階ではなく **DB の印**で決める。
             * 印が在れば DB もメディアも復元後で、残っているのは後始末だけ —
             * ここでファイルを戻してはいけない（DB とずれる）。journal は残し、
             * `agentcms:backup-recover` に仕上げさせる。
             */
            if ($journal !== null && RestoreRecovery::committed($restoreId)) {
                report($e);

                throw new BackupFailed(
                    '復元は DB とメディアまで終わりましたが、後始末の途中で止まりました。サーバーで `'
                    .RestoreJournal::RECOVER_COMMAND.'` を実行してください',
                    0,
                    $e,
                );
            }

            /*
             * transaction は戻っている（または始まっていない）。メディアが入れ替わったまま
             * という形だけがここに残るので、journal の記録を逆順に戻す。
             */
            $left = $journal === null ? [] : RestoreRecovery::undo($journal->moves());

            if ($left === [] && $journal !== null) {
                // 消す前に「戻した」を残す（`RestoreRecovery::rollback` と同じ順序）
                $journal->advance(RestoreJournal::ROLLED_BACK);
                $journal->archive('rolled_back');
            }

            $tail = match (true) {
                $left !== [] => '。メディアの一部を手で戻す必要があります: '.implode(' / ', $left)
                    .'（記録は残しています。整えたあと `'.RestoreJournal::RECOVER_COMMAND.'` を実行してください）',
                $journal !== null => '。DB とメディアは復元前のままです',
                default => '',
            };

            if ($e instanceof BackupFailed) {
                throw new BackupFailed($e->getMessage().$tail, 0, $e);
            }

            // DB 例外の SQL にはメールアドレス等の値が入る。画面には転記しない。
            report($e);
            throw new BackupFailed(
                '復元を完了できませんでした。復元前のバックアップは保存されています。詳細はサーバーログを確認してください'.$tail,
                0,
                $e,
            );
        } finally {
            // journal が残っている（整える前）なら、展開した中身も手がかりとして残す
            if (! RestoreJournal::isPending()) {
                self::remove($workspace);
            }
        }
    }

    /**
     * すべての session を切る。**呼び出し側の transaction の中で呼ぶこと。**
     *
     * 世代番号は「復元前の最大」と「復元後（backup の中）の最大」のどちらよりも 1 大きくする。
     * 単に +1 では、backup の世代 0 に 1 を足したものがいまの世代 1 と一致して、
     * 切ったはずの session が生き残る（`BackupAdminTest` が再現した）。
     *
     * `remember_token` も断ち、database driver なら session の行も消す。
     * どれも同じ transaction で commit される。
     */
    private static function invalidateSessions(int $previousEpoch): void
    {
        $restoredEpoch = (int) DB::table('users')->max('session_epoch');
        $next = max($previousEpoch, $restoredEpoch) + 1;

        DB::table('users')->update([
            'session_epoch' => $next,
            'remember_token' => null,
        ]);

        $table = (string) config('session.table', 'sessions');

        if ((string) config('session.driver') === 'database' && Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }

    // ── 検証 ────────────────────────────────────────────────

    private static function formatProblem(int $format): string
    {
        if ($format === 1) {
            return 'この backup の形式（1）は、いまの CMS では復元できません。メディアの一覧と checksum を持たないため、欠けたファイルがあっても見つけられません。いまの状態から取り直してください';
        }

        return "この backup の形式（{$format}）は、いまの CMS では読めません";
    }

    /**
     * manifest が語る「zip に入っているべきもの」（名前 → sha256）。
     *
     * manifest 自身がおかしい（root が未知・名前が安全でない・checksum の形が違う）ときは
     * 問題として返し、その項目は期待に入れない。
     *
     * @param  list<string>  $problems
     * @return array<string, string>
     */
    private static function expectedEntries(BackupManifest $manifest, array &$problems): array
    {
        $expected = [];
        $roots = [];

        foreach ($manifest->media['roots'] as $root) {
            if (! self::isSafeEntryName($root) || self::targetFor($root) === null) {
                $problems[] = "backup のメディアの置き場所「{$root}」は、いまの CMS が知らない場所です";

                continue;
            }

            $roots[] = $root;
        }

        if ($roots === []) {
            $problems[] = 'backup の目録にメディアの置き場所がありません';
        }

        // 空の種別を省くと、その種別に後から足したファイルを復元で消せなくなる。
        $requiredRoots = array_values(BackupBuilder::mediaSources());
        if (count($roots) !== count(array_unique($roots))
            || array_diff($requiredRoots, $roots) !== [] || array_diff($roots, $requiredRoots) !== []) {
            $problems[] = 'backup のメディアの置き場所に不足または重複があります';
        }

        $badNames = 0;
        $badHashes = 0;
        $outside = 0;

        foreach ($manifest->checksums as $name => $hash) {
            if (! self::isSafeEntryName($name)) {
                $badNames++;

                continue;
            }

            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                $badHashes++;

                continue;
            }

            if ($name !== 'database.jsonl' && ! self::underRoots($name, $roots)) {
                $outside++;

                continue;
            }

            $expected[$name] = $hash;
        }

        if (! isset($expected['database.jsonl'])) {
            $problems[] = 'backup の目録に DB の checksum がありません';
        }

        if ($badNames > 0) {
            $problems[] = "backup の目録に安全でない置き場所の項目があります（{$badNames} 件）";
        }

        if ($badHashes > 0) {
            $problems[] = "backup の目録の checksum が読めません（{$badHashes} 件）";
        }

        if ($outside > 0) {
            $problems[] = "backup の目録に、メディアの置き場所の外を指す項目があります（{$outside} 件）";
        }

        return $expected;
    }

    /**
     * zip の実物を期待と突き合わせる。
     *
     * @param  array<string, string>  $expected
     * @param  list<string>  $problems
     */
    private function compareArchive(ZipArchive $zip, array $expected, array &$problems): void
    {
        $present = [];
        $unsafe = [];
        $unknown = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! is_string($name)) {
                continue;
            }

            if (! self::isSafeEntryName($name)) {
                $unsafe[] = $name;

                continue;
            }

            // ZIP の名前だけでは symlink/特殊ファイルを識別できない。属性も確かめる。
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000) {
                    $unsafe[] = $name;

                    continue;
                }
            }

            if (isset($present[$name])) {
                $unsafe[] = $name;

                continue;
            }

            if ($name === 'manifest.json') {
                continue;
            }

            if (! isset($expected[$name])) {
                $unknown[] = $name;

                continue;
            }

            $present[$name] = true;
        }

        $missing = [];
        $changed = [];

        foreach ($expected as $name => $hash) {
            if (! isset($present[$name])) {
                $missing[] = $name;

                continue;
            }

            if (self::hashEntry($zip, $name) !== $hash) {
                $changed[] = $name;
            }
        }

        if ($unsafe !== []) {
            $problems[] = 'backup の中に安全でない置き場所の項目があります（'.self::sample($unsafe).'）';
        }

        if (in_array('database.jsonl', $missing, true)) {
            $problems[] = 'backup に DB の中身が入っていません';
        }

        if (in_array('database.jsonl', $changed, true)) {
            $problems[] = 'backup の中身が途中で変わっています（checksum が一致しません）';
        }

        $mediaMissing = array_values(array_diff($missing, ['database.jsonl']));
        $mediaChanged = array_values(array_diff($changed, ['database.jsonl']));

        if ($mediaMissing !== []) {
            $problems[] = 'backup に入っているはずのメディアが欠けています（'.self::sample($mediaMissing).'）';
        }

        if ($mediaChanged !== []) {
            $problems[] = 'backup の中のメディアが途中で変わっています（checksum が一致しません: '.self::sample($mediaChanged).'）';
        }

        if ($unknown !== []) {
            $problems[] = 'backup に目録に無いファイルが入っています（'.self::sample($unknown).'）';
        }
    }

    /** zip の 1 項目を stream で hash する（全体を memory に載せない） */
    private static function hashEntry(ZipArchive $zip, string $name): ?string
    {
        $stream = $zip->getStream($name);

        if ($stream === false) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * zip の中の名前として安全か。
     *
     * `..` や絶対パス、`\` 区切り、空の区切り、NUL を含む名前は展開しない。
     * `extractTo()` は名前をそのまま結合するので、**ここで止めなければ外へ書ける**。
     */
    private static function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }

        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $roots */
    private static function underRoots(string $name, array $roots): bool
    {
        foreach ($roots as $root) {
            if (str_starts_with($name, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $names */
    private static function sample(array $names): string
    {
        $rest = count($names) - self::SAMPLE;

        return implode(', ', array_slice($names, 0, self::SAMPLE)).($rest > 0 ? " ほか {$rest} 件" : '');
    }

    // ── 復元 ────────────────────────────────────────────────

    private function extract(string $id, string $workspace): void
    {
        $zip = new ZipArchive;

        if ($zip->open(BackupPaths::archive($id)) !== true) {
            throw new BackupFailed('backup を開けません');
        }

        // 名前の安全性は verify() で確かめてある（ここへ来るのは verify を通ったものだけ）
        if (! $zip->extractTo($workspace)) {
            $zip->close();

            throw new BackupFailed('backup を展開できません');
        }

        $zip->close();
    }

    /**
     * backup の中の root（`media/<種別>`）→ 戻す先の絶対パス。
     *
     * **いま置く場所は `MediaKind` に聞く**（`BackupBuilder::mediaSources()` と同じ情報源）。
     * 2 か所で書くと、片方だけ直したときに「取れているのに戻らない」が起きる。
     * 知らない種別なら null（verify がそこで止める）。
     */
    private static function targetFor(string $root): ?string
    {
        if (! str_starts_with($root, 'media/')) {
            return null;
        }

        $kind = MediaKind::tryFrom(substr($root, strlen('media/')));

        if ($kind === null || $kind->disk() === '' || $kind->directory() === '') {
            return null;
        }

        return Storage::disk($kind->disk())->path($kind->directory());
    }

    /**
     * 入れ替えの手順を組む。**まだ何も動かさない。**
     *
     * root ごとに (展開した先, 戻す先, 退避先) を決め、退避先の親と戻す先の親を先に作る。
     * 展開した先が無い root は空のディレクトリを作る — **空だったものは空に戻す**
     * （backup 後に足されたファイルを残さない）。
     *
     * `target_existed`（戻す先が最初から在ったか）は**ここで**決める。回復（`RestoreRecovery`）は
     * この計画と実物を突き合わせて root の状態を決めるので、動かしたあとでは分からない情報である。
     *
     * @return list<array{root: string, source: string, target: string, parked: string, target_existed: bool}>
     */
    private static function planSwap(BackupManifest $manifest, string $workspace): array
    {
        $stamp = now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        $moves = [];

        foreach ($manifest->media['roots'] as $root) {
            $target = self::targetFor($root);

            if ($target === null) {
                throw new BackupFailed("メディアの置き場所「{$root}」を戻す先が分かりません");
            }

            $source = $workspace.'/'.$root;

            if (! is_dir($source) && ! @mkdir($source, 0755, true)) {
                throw new BackupFailed('復元の作業ディレクトリを作れません');
            }

            $parked = BackupPaths::disk()->path(BackupPaths::REPLACED.'/'.$stamp.'/'.$root);

            if (file_exists($parked)) {
                throw new BackupFailed('退避先が既に使われています。もう一度やり直してください');
            }

            self::ensureDirectory(dirname($parked), 0700);
            self::ensureDirectory(dirname($target), 0755);

            $moves[] = [
                'root' => $root,
                'source' => $source,
                'target' => $target,
                'parked' => $parked,
                'target_existed' => file_exists($target),
            ];
        }

        return $moves;
    }

    /**
     * メディアを入れ替える。
     *
     * **現物は消さずに退避する。** DB とメディアがずれると、
     * リンク切れか、逆に消したはずの画像が復活する。同じ transaction の中で入れ替える。
     *
     * rename の結果は必ず見る。1 つでも失敗したら例外を投げる（呼び出し側の
     * transaction がそれで戻り、catch が journal の記録を逆順に戻す）。
     *
     * @param  list<array{root: string, source: string, target: string, parked: string, target_existed: bool}>  $moves
     */
    private function swapMedia(array $moves, RestoreJournal $journal): void
    {
        foreach ($moves as $move) {
            // 計画どおりに動かす（回復は計画と実物を突き合わせるので、ここで別の判断をしない）
            if ($move['target_existed']) {
                $this->move($move['target'], $move['parked'], $move['root'], $journal);
            }

            $this->move($move['source'], $move['target'], $move['root'], $journal);
        }
    }

    /** 意図を journal へ書いてから動かす（消えても、何をしようとしたかが残る） */
    private function move(string $from, string $to, string $root, RestoreJournal $journal): void
    {
        $journal->step($from, $to);

        if (@rename($from, $to)) {
            $this->probe->at(RestoreProbe::AFTER_MOVE);

            return;
        }

        $reason = error_get_last()['message'] ?? '';

        throw new BackupFailed(
            "メディア（{$root}）を入れ替えられませんでした"
            .($reason !== '' ? '。理由: '.self::withoutPaths($reason) : ''),
        );
    }

    private static function ensureDirectory(string $path, int $mode): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, $mode, true) && ! is_dir($path)) {
            throw new BackupFailed('復元に必要なディレクトリを作れません');
        }
    }

    /**
     * 復元 1 回ぶんの id（作業ディレクトリ・journal・DB の印で同じものを使う）。
     * 同じ名前の作業ディレクトリが残っていても、その中に展開しない。
     */
    private static function freshRestoreId(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $restoreId = BackupPaths::newId('restore-');

            if (! file_exists(BackupPaths::workspace($restoreId))) {
                return $restoreId;
            }
        }

        throw new BackupFailed('復元の作業ディレクトリを確保できません');
    }

    /** 理由の文から絶対パスを落とす（画面に置き場所の手がかりを出さない） */
    private static function withoutPaths(string $message): string
    {
        // `rename(/abs/from,/abs/to): Permission denied` → `Permission denied`
        return (string) preg_replace('/^rename\(.*\): /', '', $message);
    }

    /** @return list<string> */
    private static function currentMigrations(): array
    {
        return array_values(array_map(
            strval(...),
            DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
        ));
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

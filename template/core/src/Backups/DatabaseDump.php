<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB の中身を書き出す / 戻す（Phase 9.11 / STAGE 24B・SPEC §B.4）。
 *
 * ## SQL ではなく JSONL
 *
 * 復元で**生 SQL を実行しない**。ファイルの中身をそのまま DB へ流す仕組みは、
 * backup を差し替えられたときに任意の SQL 実行になる。
 * JSONL なら復元は query builder の `insert()` だけで、実行されるのは**値**だけである。
 *
 * 引用符の方言（識別子と値の quote は driver ごとに違う）も持ち込まずに済む。
 *
 * ## 1 行ずつ流す
 *
 * `cursor()` で 1 行ずつ受け、そのまま書き出す。**全件を memory に載せない。**
 * 共有ホスティングの memory_limit でも大きなサイトを扱えるようにする。
 *
 * ## 入れない表
 *
 * `sessions` を戻すと**他人のログイン状態が復活する**。`jobs` を戻すと
 * **過去のジョブが再実行される**。どちらも「戻したい過去」ではない。
 *
 * `backup_restores` は復元の commit 印（release R09）。復元で入れ替わる側に
 * 置いた印は印にならないので、入れない・消さない。
 */
final class DatabaseDump
{
    /** 戻してはいけない表（SPEC §B.4） */
    public const EXCLUDED_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'backup_restores',
    ];

    /** 1 度に読む行数（memory と往復のつり合い） */
    private const CHUNK = 500;

    /**
     * 書き出す。
     *
     * @param  (callable(int $done, int $total): void)|null  $onTable  1 表を書き終えるたびに呼ぶ（画面の進み）
     * @return array<string, int> 表 → 行数
     */
    public function write(string $path, ?callable $onTable = null): array
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new BackupFailed("書き出し先を開けません: {$path}");
        }

        $counts = [];

        try {
            // 複数の表やページ分割読込の途中に更新が入っても、同じ時点の DB を取得する。
            // MySQL の接続既定が READ COMMITTED でも、この取得だけはスナップショットにする。
            if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            DB::transaction(function () use ($handle, $onTable, &$counts): void {
                $tables = $this->tables();
                $total = count($tables);

                foreach ($tables as $index => $table) {
                    if ($onTable !== null) {
                        $onTable($index, $total);
                    }

                    $columns = Schema::getColumnListing($table);

                    if ($columns === []) {
                        continue;
                    }

                    self::writeLine($handle, ['table' => $table, 'columns' => $columns]);

                    $counts[$table] = 0;

                    /*
                     * **並び順を固定する。** 主キーが無い表もあるので、
                     * 在れば主キー、無ければ最初の列で並べる。
                     * 順序が毎回変わると checksum が一致しない。
                     */
                    $order = in_array('id', $columns, true) ? 'id' : $columns[0];

                    DB::table($table)->orderBy($order)->chunk(self::CHUNK, function ($rows) use ($handle, $columns, $table, &$counts): void {
                        foreach ($rows as $row) {
                            $values = [];

                            foreach ($columns as $column) {
                                $values[] = ((array) $row)[$column] ?? null;
                            }

                            self::writeLine($handle, ['row' => $values]);
                            $counts[$table]++;
                        }
                    });
                }
            });
        } finally {
            fclose($handle);
        }

        return $counts;
    }

    /**
     * 戻す。**呼び出し側の transaction の中で呼ぶこと。**
     *
     * @param  list<string>  $allowedTables  この表以外は無視する（差し替えられた backup で
     *                                       知らない表へ書き込ませない）
     * @return array<string, int> 表 → 入れた行数
     */
    public function restore(string $path, array $allowedTables): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new BackupFailed("読み込み元を開けません: {$path}");
        }

        $counts = [];

        try {
            $this->withoutForeignKeys(function () use ($handle, $allowedTables, &$counts): void {
                $this->replay($handle, $allowedTables, $counts);
            });
        } finally {
            fclose($handle);
        }

        return $counts;
    }

    /**
     * 1 行ずつ読んで入れ直す。
     *
     * **知らない表には書かない。** 差し替えられた backup で、
     * 許していない表へ挿入されないようにする。
     *
     * @param  resource  $handle
     * @param  list<string>  $allowedTables
     * @param  array<string, int>  $counts
     */
    private function replay(mixed $handle, array $allowedTables, array &$counts): void
    {
        $table = null;
        /** @var list<string> $columns */
        $columns = [];
        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                throw new BackupFailed('backup の中身を読めません（壊れています）');
            }

            if (isset($decoded['table'])) {
                $this->flush($table, $buffer, $counts);

                $name = (string) $decoded['table'];
                $table = in_array($name, $allowedTables, true) ? $name : null;
                $columns = $table === null
                    ? []
                    : array_values(array_map(strval(...), (array) ($decoded['columns'] ?? [])));

                if ($table !== null) {
                    // 入れ直す前に空にする（戻すのであって、足すのではない）
                    DB::table($table)->delete();
                    $counts[$table] = 0;
                }

                continue;
            }

            if ($table === null || ! isset($decoded['row'])) {
                continue;
            }

            $values = array_values((array) $decoded['row']);

            if (count($values) !== count($columns)) {
                throw new BackupFailed("列の数が合いません（表: {$table}）");
            }

            $buffer[] = array_combine($columns, $values);

            if (count($buffer) >= self::CHUNK) {
                $this->flush($table, $buffer, $counts);
            }
        }

        $this->flush($table, $buffer, $counts);
    }

    /**
     * @param  list<array<string, mixed>>  $buffer
     * @param  array<string, int>  $counts
     */
    private function flush(?string $table, array &$buffer, array &$counts): void
    {
        if ($table === null || $buffer === []) {
            return;
        }

        DB::table($table)->insert($buffer);
        $counts[$table] = ($counts[$table] ?? 0) + count($buffer);
        $buffer = [];
    }

    /**
     * 書き出す表（除外したものを含まない）。
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = array_map(
            static fn (array $table): string => (string) $table['name'],
            Schema::getTables(),
        );

        sort($tables);

        return array_values(array_filter(
            $tables,
            static fn (string $table): bool => ! in_array($table, self::EXCLUDED_TABLES, true),
        ));
    }

    /**
     * 外部キーを外して実行する。
     *
     * 表を消して入れ直す順序は、参照の向きによっては必ずどこかで引っかかる。
     * 全部入れ終われば整合するので、その間だけ外す。**driver ごとに書き方が違う。**
     */
    private function withoutForeignKeys(callable $callback): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => $connection->statement('SET FOREIGN_KEY_CHECKS=0'),
            'sqlite' => $connection->statement('PRAGMA foreign_keys = OFF'),
            'pgsql' => $connection->statement("SET session_replication_role = 'replica'"),
            default => null,
        };

        try {
            $callback();
        } finally {
            match ($driver) {
                'mysql', 'mariadb' => $connection->statement('SET FOREIGN_KEY_CHECKS=1'),
                'sqlite' => $connection->statement('PRAGMA foreign_keys = ON'),
                'pgsql' => $connection->statement("SET session_replication_role = 'origin'"),
                default => null,
            };
        }
    }

    /** @param  array<string, mixed>  $payload */
    private static function writeLine(mixed $handle, array $payload): void
    {
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            throw new BackupFailed('backup へ書き出せない値があります（文字コードを確認してください）');
        }

        fwrite($handle, $line."\n");
    }
}

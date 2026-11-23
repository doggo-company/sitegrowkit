<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * backup の目録（Phase 9.11 / STAGE 24B・SPEC §B.5）。
 *
 * **`excluded` を持つのは飾りではない。** 復元した人が「何が戻っていないか」を
 * その場で読めないと、`.env` が消えたと勘違いして環境ごと作り直すことになる。
 *
 * ## format 2（2026-09-09・backup 完全性）
 *
 * `checksums` は `database.jsonl` だけでなく **メディアの全ファイル**を持つ。
 * `media.roots` は写した置き場所（`media/image` など）を、**ファイルが 0 件でも**列挙する。
 * 復元はこの 2 つを正本にする — zip に何が入っているべきかを、zip 自身に語らせない。
 */
final readonly class BackupManifest
{
    /**
     * @param  array<string, string>  $app
     * @param  list<string>  $migrations
     * @param  array<string, int>  $tables
     * @param  array{files: int, bytes: int, roots: list<string>}  $media
     * @param  array<string, string>  $checksums  zip の中の置き場所 → sha256
     * @param  list<string>  $excluded
     */
    public function __construct(
        public string $id,
        public int $format,
        public string $createdAt,
        public array $app,
        public array $migrations,
        public array $tables,
        public array $media,
        public array $checksums,
        public array $excluded,
        public int $sizeBytes,
    ) {}

    public function withSize(int $bytes): self
    {
        return new self(
            $this->id, $this->format, $this->createdAt, $this->app, $this->migrations,
            $this->tables, $this->media, $this->checksums, $this->excluded, $bytes,
        );
    }

    /** 復元前の backup か（世代整理の対象外 — SPEC §F） */
    public function isPreRestore(): bool
    {
        return str_starts_with($this->id, BackupPaths::PRE_RESTORE_PREFIX);
    }

    /**
     * 並べ替えに使う「取った時刻」（WP 一周 8 周目 2026-09-04 / 効くもの ②）。
     *
     * 🔴 **id をそのまま比べてはいけない。** id は `20260904-072321-518-e61813` だが、
     * 復元前のものは `pre-restore-` が頭に付く。ASCII で `p` > `2` なので、
     * 文字列で比べると **`pre-restore-*` が時刻に関係なく必ず先に来る**。
     *
     * 接頭辞を外した残りは `Ymd-His-v-乱数` で、**そのまま辞書順が時刻順**になる。
     * `createdAt` は秒までしか持たないので、同じ秒に 2 つ取ったときはこちらが効く。
     */
    public function takenAt(): string
    {
        return $this->isPreRestore()
            ? substr($this->id, strlen(BackupPaths::PRE_RESTORE_PREFIX))
            : $this->id;
    }

    public function rowCount(): int
    {
        return array_sum($this->tables);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'format' => $this->format,
            'created_at' => $this->createdAt,
            'app' => $this->app,
            'database' => ['migrations' => $this->migrations, 'tables' => $this->tables],
            'media' => $this->media,
            'checksums' => $this->checksums,
            'excluded' => $this->excluded,
            'size_bytes' => $this->sizeBytes,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(string $id, array $raw): self
    {
        $database = is_array($raw['database'] ?? null) ? $raw['database'] : [];
        $media = is_array($raw['media'] ?? null) ? $raw['media'] : [];

        return new self(
            id: $id,
            format: (int) ($raw['format'] ?? 0),
            createdAt: (string) ($raw['created_at'] ?? ''),
            app: array_map(strval(...), is_array($raw['app'] ?? null) ? $raw['app'] : []),
            migrations: array_values(array_map(strval(...), is_array($database['migrations'] ?? null) ? $database['migrations'] : [])),
            tables: array_map(intval(...), is_array($database['tables'] ?? null) ? $database['tables'] : []),
            media: [
                'files' => (int) ($media['files'] ?? 0),
                'bytes' => (int) ($media['bytes'] ?? 0),
                // format 1 には無い（空のまま）。無いことを `BackupRestorer::verify` が形式の問題として扱う
                'roots' => array_values(array_map(strval(...), is_array($media['roots'] ?? null) ? $media['roots'] : [])),
            ],
            checksums: array_map(strval(...), is_array($raw['checksums'] ?? null) ? $raw['checksums'] : []),
            excluded: array_values(array_map(strval(...), is_array($raw['excluded'] ?? null) ? $raw['excluded'] : [])),
            sizeBytes: (int) ($raw['size_bytes'] ?? 0),
        );
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 秘密が混ざっていないか（Phase 9.11 / STAGE 24B・SPEC §B.3）。
 *
 * `packages/cli/src/deploy/artifact.ts` の `scanForSecrets()` と同じ考え方。
 *
 * **見つかったら backup を成果物にしない。** 秘密が入ったまま配られる backup より、
 * 「backup が作れなかった」ほうが害が小さい。取り返しがつくのは後者だけである。
 */
final class SecretScanner
{
    /** 名前が一致したら秘密とみなす */
    private const NAMES = [
        '.env',
        'auth.json',
        'admin-credentials.json',
    ];

    /** 名前の先頭が一致したら秘密とみなす */
    private const PREFIXES = ['.env.'];

    /** 拡張子が一致したら秘密とみなす */
    private const SUFFIXES = ['.key', '.pem', '.p12', '.pfx'];

    /** この名前のディレクトリは丸ごと秘密とみなす */
    private const DIRECTORIES = ['local'];

    /**
     * @return list<string> 見つかった相対パス（空なら安全）
     */
    public function scan(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $file) {
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $name = $file->getFilename();

            if ($file->isDir()) {
                if (in_array($name, self::DIRECTORIES, true)) {
                    $found[] = $relative.'/';
                }

                continue;
            }

            if (self::looksSecret($name)) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    private static function looksSecret(string $name): bool
    {
        if (in_array($name, self::NAMES, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

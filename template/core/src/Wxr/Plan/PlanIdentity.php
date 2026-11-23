<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use InvalidArgumentException;

/**
 * `plan` と `apply` が同じものを指しているかを 1 つの値で表す
 * （Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * `apply` はこの hash を照合する。**ファイルを差し替えて apply、をさせない**ため。
 *
 * 載せるのは 2 つだけ:
 *
 * - 中身の sha256 … ファイルが 1 byte でも違えば別物
 * - 正規化した指定 … 同じファイルでも、入れる型や分類が違えば別の取り込み。
 *   メディアを取りに行くかも含む（**外へ出る plan と出ない plan は別物**）
 *
 * **ファイル名は載せない。** 中身も指定も同じで名前だけ違うものは同じ取り込みである。
 * 名前を混ぜると `export.xml` を `export-final.xml` にしただけで apply が拒否され、
 * **利用者は照合そのものを飛ばす癖をつける**。名前は台帳と監査の表示に使うだけで、
 * 同一性は中身と指定で決める。
 *
 * **パスも当然載せない。** 台帳が利用者の環境の地図になってはいけない（§G）。
 */
final class PlanIdentity
{
    /** `import_batches.source` は varchar(120) */
    public const SOURCE_MAX_BYTES = 120;

    /** ファイル名だけ（パスは持たない） */
    public readonly string $source;

    public function __construct(
        public readonly string $fileHash,
        string $path,
        public readonly ImportOptions $options,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $fileHash) !== 1) {
            // 壊れた値のまま畳むと、壊れていることに気づけない
            throw new InvalidArgumentException('fileHash は 64 桁の小文字 16 進です。');
        }

        $this->source = basename($path);
    }

    /**
     * 台帳へ入れる形。**文字の途中で切らない。**
     *
     * `mb_strcut` はバイト数で切りつつ文字の境界を守る（`mb_substr` は文字数なので
     * 120 byte に収まる保証が無い）。壊れたバイト列を DB へ入れない。
     */
    public function storedSource(): string
    {
        if (strlen($this->source) <= self::SOURCE_MAX_BYTES) {
            return $this->source;
        }

        return mb_strcut($this->source, 0, self::SOURCE_MAX_BYTES, 'UTF-8');
    }

    /**
     * hash の元になる文字列。
     *
     * **キーは再帰的に並べる。** トップだけ並べても、入れ子が挿入順で出れば
     * 書き順によって値が変わる。
     */
    public function canonical(): string
    {
        return (string) json_encode(
            [
                'file_hash' => $this->fileHash,
                'options' => $this->options->toCanonical(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonical());
    }
}

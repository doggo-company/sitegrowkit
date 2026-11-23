<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 項目定義を 1 行にまとめて置く（Phase 9.8 / STAGE 21D・SPEC §F.2.0・§F.2.3）。
 *
 * schema が変わらないかぎり定義は同じなので、版ごとに持たず **fingerprint を鍵**に
 * 1 行だけ置く。版はその鍵を指すだけになる。
 *
 * ## 既にある行は必ず突き合わせる
 *
 * 同じ fingerprint なのに中身が違うなら、その版は正しく復元できない。
 * 「たぶん同じだろう」で進めない。食い違ったら typed error で全体を巻き戻す。
 *
 * ## 並走で同じ行を入れようとしたら
 *
 * 先に入れたほうが勝つ。負けた側は**読み直して突き合わせ直す**。
 * 一致すれば続行、しなければ巻き戻す。取り合いにならず収束する。
 */
final class SchemaManifestRepository
{
    public const TABLE = 'content_schema_manifests';

    /** 一意制約に当たったときの SQLSTATE（MySQL / SQLite とも同じ） */
    private const INTEGRITY_VIOLATION = '23000';

    /**
     * 定義を置き、fingerprint を返す。**同じ transaction の中で呼ぶこと。**
     *
     * @throws ManifestIntegrityError 保存済みの内容と食い違った
     */
    public function materialize(string $contentType, ContentSchema $schema): string
    {
        $canonical = SchemaManifest::canonical($schema);
        $encoded = SchemaManifest::encode($canonical);
        $fingerprint = hash('sha256', $encoded);

        $stored = $this->storedManifest($contentType, $fingerprint);

        if ($stored !== null) {
            $this->assertMatches($contentType, $fingerprint, $stored, $encoded);

            return $fingerprint;
        }

        try {
            DB::table(self::TABLE)->insert([
                'content_type' => $contentType,
                'fingerprint' => $fingerprint,
                'manifest' => $encoded,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // 重複以外は隠さない（列が無い・接続が切れた等をここで飲み込まない）
            if ((string) $e->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $e;
            }

            /*
             * 並走した相手が先に入れた。**握りつぶさない** — 読み直して
             * 中身が同じであることを確かめてから続ける。
             *
             * ここは **snapshot ではなく現在の行**を読む。MySQL の REPEATABLE READ は
             * transaction を始めた時点の snapshot を見るので、相手が後から commit した行は
             * 「重複で INSERT に失敗したのに、読み直すと無い」という形になる。
             * そのまま投げ直すと、利用者には理由の分からない競合として返る
             * （MySQL 8.4 の並走で実際に起きた）。
             */
            $stored = $this->storedManifest($contentType, $fingerprint, locking: true);

            if ($stored === null) {
                throw $e;
            }

            $this->assertMatches($contentType, $fingerprint, $stored, $encoded);
        }

        return $fingerprint;
    }

    /**
     * 保存済みの定義。**鍵と中身が一致することまで確かめる。**
     *
     * JSON として読めただけでは信用しない。指紋は canonical の hash なので、
     * 読み戻した中身から**計算し直して**鍵と突き合わせる。合わなければ、
     * その行は（衝突・破損・書き換えのいずれにせよ）その版の定義ではない。
     *
     * 無ければ null を返し、**復元は呼び出し側が fail-closed で止める**。
     * 「詳細は出せませんが復元します」はしない。
     *
     * @return list<array<string, mixed>>|null
     */
    public function find(string $contentType, string $fingerprint): ?array
    {
        $raw = $this->storedManifest($contentType, $fingerprint);

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        // 壊れている行を「空の定義」として扱わない。読めないなら読めないと答える
        if (! is_array($decoded) || ! array_is_list($decoded) || ! self::wellFormed($decoded)) {
            return null;
        }

        /*
         * **鍵は中身から作られている。** 作り直して一致しなければ、その行を信用しない。
         * MySQL の `json` 型はキー順を並べ替えるので、canonical の並びへ戻してから計算する。
         */
        if (hash('sha256', SchemaManifest::encode(self::recanonicalize($decoded))) !== $fingerprint) {
            return null;
        }

        return $decoded;
    }

    /**
     * 定義の形をしているか（最低限の検査）。
     *
     * 形が違うものを「項目が 0 個の定義」として扱うと、**全項目が消えた**ように見え、
     * 何も復元されないまま「成功」してしまう。
     *
     * @param  list<mixed>  $manifest
     */
    private static function wellFormed(array $manifest): bool
    {
        foreach ($manifest as $field) {
            if (! is_array($field)) {
                return false;
            }

            foreach (['identity', 'key', 'type'] as $required) {
                if (! array_key_exists($required, $field)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 保存された定義を canonical の並びへ戻す（キー順・要素順とも）。
     *
     * @param  list<array<string, mixed>>  $manifest
     * @return list<array<string, mixed>>
     */
    private static function recanonicalize(array $manifest): array
    {
        $order = ['identity', 'key', 'type', 'storage', 'required', 'unique', 'from',
            'default', 'max', 'choices', 'validation', 'target', 'children'];

        $canonical = array_map(static function (array $field) use ($order): array {
            $sorted = [];

            foreach ($order as $key) {
                if (array_key_exists($key, $field)) {
                    $sorted[$key] = $key === 'children' && is_array($field[$key])
                        ? self::recanonicalize(array_values($field[$key]))
                        : $field[$key];
                }
            }

            if (isset($sorted['validation']) && is_array($sorted['validation'])) {
                ksort($sorted['validation']);
            }

            return $sorted;
        }, $manifest);

        usort(
            $canonical,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['identity'] ?? ''),
                (string) ($b['identity'] ?? ''),
            ),
        );

        return $canonical;
    }

    /**
     * @param  bool  $locking  snapshot ではなく **いまの行**を読む（並走の負け側で使う）
     */
    private function storedManifest(string $contentType, string $fingerprint, bool $locking = false): ?string
    {
        $query = DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->where('fingerprint', $fingerprint);

        if ($locking) {
            // sqlite では無視される。MySQL では commit 済みの最新行が読める
            $query->sharedLock();
        }

        $value = $query->value('manifest');

        return is_string($value) ? $value : null;
    }

    /**
     * @throws ManifestIntegrityError
     */
    private function assertMatches(
        string $contentType,
        string $fingerprint,
        string $stored,
        string $expected,
    ): void {
        /*
         * **文字列比較では足りない。** MySQL の `json` 型は保存時にオブジェクトの
         * キー順を並べ替えるので、読み戻すと書いたときと並びが違う。
         * バイト列で比べると、正しい行を「壊れている」と誤判定する。
         */
        $storedDecoded = json_decode($stored, true);
        $expectedDecoded = json_decode($expected, true);

        if (! is_array($storedDecoded) || ! is_array($expectedDecoded)
            || ! SchemaManifest::equivalent($storedDecoded, $expectedDecoded)) {
            throw new ManifestIntegrityError($contentType, $fingerprint);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanIdentity;
use AgentCms\Core\Wxr\WxrError;
use Tests\TestCase;

/**
 * plan と apply が同じものを指しているか（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * `apply` は `plan` の結果 hash を照合する。**ファイルを差し替えて apply、が
 * できないようにする**ためである。だから hash は
 *
 * - **同じ意味なら必ず同じ値**（コマンドラインの書き順で変わってはいけない）
 * - **違う意味なら必ず違う値**（取り込み先が違うのに通ってはいけない）
 *
 * の両方を満たす必要がある。書き順で変わると、利用者は
 * 「plan したのに apply が拒否される」に当たり、**確認を飛ばす癖がつく**。
 */
final class PlanIdentityTest extends TestCase
{
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    // ── 同じ意味なら同じ値 ──────────────────────────────────

    public function test_optionの書き順で変わらない(): void
    {
        $one = $this->identity(types: ['post:blog', 'note:diary'], taxonomies: ['category:blog_category', 'post_tag:tag']);
        $other = $this->identity(types: ['note:diary', 'post:blog'], taxonomies: ['post_tag:tag', 'category:blog_category']);

        $this->assertSame($one->hash(), $other->hash());
    }

    public function test_同じ指定を重ねても変わらない(): void
    {
        // `--type=post:blog --type=post:blog` は 1 回書いたのと同じ意味である
        $one = $this->identity(types: ['post:blog']);
        $other = $this->identity(types: ['post:blog', 'post:blog']);

        $this->assertSame($one->hash(), $other->hash());
    }

    public function test_canonicalのキーが再帰的に並ぶ(): void
    {
        $canonical = $this->identity(
            types: ['zebra:z', 'apple:a'],
            taxonomies: ['zoo:z2', 'ant:a2'],
        )->canonical();

        // 入れ子の中まで並んでいること（トップだけ並べても hash は安定しない）
        $this->assertStringContainsString('"taxonomies":{"ant":"a2","zoo":"z2"}', $canonical);
        $this->assertStringContainsString('"types":{"apple":"a","zebra":"z"}', $canonical);
    }

    // ── 違う意味なら違う値 ──────────────────────────────────

    public function test_ファイルが違えば変わる(): void
    {
        $this->assertNotSame(
            $this->identity(fileHash: self::HASH_A)->hash(),
            $this->identity(fileHash: self::HASH_B)->hash(),
        );
    }

    public function test_取り込み先が違えば変わる(): void
    {
        $this->assertNotSame(
            $this->identity(types: ['post:blog'])->hash(),
            $this->identity(types: ['post:news'])->hash(),
        );
    }

    public function test_メディアを取りに行くかどうかで変わる(): void
    {
        // **外へ取りに行く plan と行かない plan は別物である**（§C.6）
        $this->assertNotSame(
            $this->identity(media: false)->hash(),
            $this->identity(media: true)->hash(),
        );
    }

    public function test_名前を変えただけの同じファイルは同じplanになる(): void
    {
        /*
         * **同じ意味なら同じ値。** 中身も取り込み先も同じで、
         * ファイル名だけ違うものは同じ取り込みである。
         * 名前を hash に混ぜると、`export.xml` を `export-final.xml` に
         * しただけで plan が拒否され、**利用者は照合を飛ばす癖をつける**。
         * 名前は台帳と監査の表示に使うだけで、同一性は中身と指定で決める。
         */
        $this->assertSame(
            $this->identity(path: '/tmp/one.xml')->hash(),
            $this->identity(path: '/var/backup/2026/other-name.xml')->hash(),
        );
    }

    // ── 矛盾は黙って解決しない ──────────────────────────────

    public function test_同じ型に違う行き先を指定したら断る(): void
    {
        /*
         * `--type=post:blog --type=post:news` は**どちらが本当か決められない**。
         * 「後に書いた方が勝つ」にすると、書き間違いが黙って通り、
         * **十年分が意図しない型へ入る**。
         */
        $this->expectException(WxrError::class);
        $this->expectExceptionMessageMatches('/post/');

        $this->identity(types: ['post:blog', 'post:news']);
    }

    public function test_同じ分類に違う行き先を指定したら断る(): void
    {
        $this->expectException(WxrError::class);

        $this->identity(taxonomies: ['category:blog_category', 'category:tag']);
    }

    public function test_形になっていない指定を断る(): void
    {
        $this->expectException(WxrError::class);

        // `:` が無い。何をどこへ入れるのか読み取れない
        $this->identity(types: ['post']);
    }

    public function test_空の指定を断る(): void
    {
        $this->expectException(WxrError::class);

        $this->identity(types: ['post:']);
    }

    // ── パスを持たない ──────────────────────────────────────

    public function test_保存するのはファイル名だけでパスを持たない(): void
    {
        /*
         * 台帳は**誰のどこに置いたファイルか**を残さない（§D.2・§G）。
         * 移行の記録が、そのまま利用者の環境の地図にならないようにする。
         */
        $identity = $this->identity(path: '/Users/someone/Desktop/秘密の場所/export.xml');

        $this->assertSame('export.xml', $identity->source);
        $this->assertSame('export.xml', $identity->storedSource());
        // hash の元にも、当然パスは入らない（名前そのものも入らない）
        $this->assertStringNotContainsString('Users', $identity->canonical());
        $this->assertStringNotContainsString('Desktop', $identity->canonical());
        $this->assertStringNotContainsString('export.xml', $identity->canonical());
    }

    public function test_長すぎるファイル名は保存できる形に収める(): void
    {
        /*
         * `import_batches.source` は varchar(120)。
         * **切るときに文字の途中で切らない**（壊れたバイト列を DB へ入れない）。
         */
        $name = str_repeat('あ', 100).'.xml';   // 3 byte * 100 + 4 = 304 byte

        $identity = $this->identity(path: '/tmp/'.$name);

        $this->assertLessThanOrEqual(120, strlen($identity->storedSource()));
        $this->assertTrue(
            mb_check_encoding($identity->storedSource(), 'UTF-8'),
            '文字の途中で切れている',
        );
    }

    public function test_収まる長さのファイル名はそのまま残る(): void
    {
        $identity = $this->identity(path: '/tmp/export.xml');

        $this->assertSame('export.xml', $identity->storedSource());
    }

    public function test_切り詰めで同じ名前に丸まっても中身が違えば別の値になる(): void
    {
        /*
         * 120 byte を超えた先だけが違う 2 つのファイルは、`source` としては
         * 同じ文字列に丸まる。**それでも別物として扱えること**を固定する。
         * 見分けているのは名前ではなく中身（file_hash）である。
         */
        $prefix = str_repeat('あ', 100);

        $one = $this->identity(fileHash: self::HASH_A, path: '/tmp/'.$prefix.'-one.xml');
        $other = $this->identity(fileHash: self::HASH_B, path: '/tmp/'.$prefix.'-other.xml');

        $this->assertSame($one->storedSource(), $other->storedSource(), '前提: 名前は同じに丸まる');
        $this->assertNotSame($one->hash(), $other->hash());
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  list<string>  $types
     * @param  list<string>  $taxonomies
     */
    private function identity(
        string $fileHash = self::HASH_A,
        string $path = '/tmp/export.xml',
        array $types = [],
        array $taxonomies = [],
        bool $media = false,
    ): PlanIdentity {
        return new PlanIdentity(
            fileHash: $fileHash,
            path: $path,
            options: ImportOptions::fromCli($types, $taxonomies, $media),
        );
    }
}

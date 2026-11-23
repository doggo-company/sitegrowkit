<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 分類（taxonomy）の定義を管理画面から扱えるようにする（Phase 9.7 / STAGE 20D・SPEC §H）。
 *
 * **既存の行を 1 件も書き換えない。** 列を足すだけで、既定値は
 * 「これまでどおり」を意味する（`status = active` / `lock_version = 0` / `description` なし）。
 *
 * 設計上の固定事項:
 * - `status` は **投稿タイプと同じ語彙**（`active` / `archived`）。別の言葉を増やさない
 * - `lock_version` は投稿タイプと同じ CAS（`updated_at` を使わない）
 * - **関連付けは別表**。`content/schemas/*.json` の宣言はファイルが正のまま残り、
 *   DB の関連は**足すだけ**（同じ key があればファイルが勝つ — ADR-0015 と同じ向き）
 * - 関連表は `content_type` に FK を張れない（型ごとにテーブルが違う）。
 *   taxonomy 側にだけ FK を張り、**RESTRICT**（既存の taxonomy 表と同じ作法）
 * - index は 767 バイト以内
 */
return new class extends Migration
{
    private const CHECK = "status in ('active','archived')";

    /**
     * ULID 列。**既存の `taxonomies.id` と同じ照合順序にする。**
     *
     * 揃えないと FK が張れない（MySQL は照合順序の違う列を参照できない）。
     * SQLite（テスト）は `ascii_bin` を知らないため修飾子を付けない。
     */
    private function ulid(Blueprint $table, string $name): ColumnDefinition
    {
        $column = $table->char($name, 26);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    public function up(): void
    {
        Schema::table('taxonomies', function (Blueprint $table): void {
            // 管理画面で「何のための分類か」を書けるようにする（表示専用）
            $table->string('description', 200)->nullable()->after('label');
            // 運用状態。利用者が動かす（投稿タイプの `status` と同じ意味）
            $table->string('status', 16)->default('active')->after('hierarchical');
            // 楽観ロック（SPEC §J.5）
            $table->unsignedBigInteger('lock_version')->default(0)->after('managed_by');
        });

        Schema::create('content_type_taxonomies', function (Blueprint $table): void {
            $table->id();
            // 投稿タイプの内部 ID。**FK は張れない**（型ごとにテーブルが違う）
            $table->string('content_type', 64);
            $this->ulid($table, 'taxonomy_id');

            // 投稿タイプ側の制約（TaxonomyDeclaration と同じ 2 つ）
            $table->boolean('multiple')->default(true);
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // 同じ組を二重に持たない。360 バイト
            $table->unique(['content_type', 'taxonomy_id'], 'uq_content_type_taxonomy');
            // 「この投稿タイプに付いている分類」の逆引き。256 バイト
            $table->index('content_type', 'idx_content_type_taxonomy_type');

            // **RESTRICT**（既存の taxonomy 表と同じ。CASCADE を使わない）
            $table->foreign('taxonomy_id', 'fk_content_type_taxonomy')
                ->references('id')->on('taxonomies')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        foreach ($this->supportedChecks() as $name => $expression) {
            DB::statement("alter table `taxonomies` add constraint `{$name}` check ({$expression})");
        }
    }

    /**
     * **利用者が入れたものが在れば止める**（SPEC §K.2）。
     *
     * この migration は 1 行も作っていない。関連付けも cms 管理の分類も、
     * 在るなら利用者が作ったものである。落とすと「付けた分類が外れる」
     * 「無効にした分類が有効に戻る」が黙って起きる。
     */
    public function down(): void
    {
        if (Schema::hasTable('content_type_taxonomies')) {
            $associations = DB::table('content_type_taxonomies')->count();

            if ($associations > 0) {
                throw new RuntimeException(
                    "content_type_taxonomies に {$associations} 件の関連付けが入っているため down を中止しました。".
                    '（この migration は 1 行も作っていないので、在る行はすべて利用者の設定です）',
                );
            }
        }

        if (Schema::hasTable('taxonomies')) {
            $managed = DB::table('taxonomies')->where('managed_by', 'cms')->count();
            $archived = Schema::hasColumn('taxonomies', 'status')
                ? DB::table('taxonomies')->where('status', 'archived')->count()
                : 0;

            if ($managed > 0 || $archived > 0) {
                throw new RuntimeException(
                    "管理画面が作った分類が {$managed} 件、無効にした分類が {$archived} 件あるため down を中止しました。".
                    '（列を落とすと、その状態を説明できなくなります）',
                );
            }
        }

        Schema::dropIfExists('content_type_taxonomies');

        foreach (array_keys($this->supportedChecks()) as $name) {
            DB::statement("alter table `taxonomies` drop constraint `{$name}`");
        }

        Schema::table('taxonomies', function (Blueprint $table): void {
            $table->dropColumn(['description', 'status', 'lock_version']);
        });
    }

    /**
     * この driver で CHECK が効くか（`content_types` の migration と同じ判断）。
     *
     * @return array<string, string>
     */
    private function supportedChecks(): array
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return [];
        }

        $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (str_contains(strtolower($version), 'mariadb')) {
            return version_compare($version, '10.2', '>=') ? ['ck_taxonomy_status' => self::CHECK] : [];
        }

        return version_compare($version, '8.0.16', '>=') ? ['ck_taxonomy_status' => self::CHECK] : [];
    }
};

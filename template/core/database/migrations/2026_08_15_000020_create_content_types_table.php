<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 投稿タイプの登録（Phase 9.7 / STAGE 20B・ADR-0019・SPEC §F.5.1）。
 *
 * **表を作るだけ。1 行も書かない。**
 * 既存のファイル型（`content/schemas/*.json`）を import も backfill もしない。
 * 既存テーブル（`news` / `works` / `product` / `pages` / `field_groups` / `taxonomies`）へ触れない。
 *
 * 設計上の固定事項:
 * - **正本は 1 つ**（SPEC §F.5）。設定用の補助表を別に作らない。同じ値を 2 か所へ書かない
 * - 状態は **2 軸**（SPEC §F.1）。`status` は利用者が動かし、`lifecycle` はシステムが動かす。
 *   1 つの enum に混ぜない
 * - 導出値を持たない。「ファイル型か DB 型か」は
 *   `content/schemas/<id>.json` が在るかで決まるので列にしない
 * - index は 767 バイト以内（共有ホスティング世代の MySQL 互換）
 * - **CHECK は在れば使う**。MySQL 8.0.16 以降でだけ付き、SQLite では付かない。
 *   どちらの driver でも、値の保証は**アプリ側の allowlist と fail-closed な読み取り**が持つ
 *   （`ContentTypeStatus` / `ContentTypeLifecycle` / `ContentTypeRegistrationAllowlist`）
 */
return new class extends Migration
{
    private const CHECKS = [
        'ck_content_type_status' => "status in ('active','archived')",
        'ck_content_type_lifecycle' => "lifecycle in ('ready','provisioning','deprovisioning','tombstoned')",
        'ck_content_type_menu_order' => 'menu_order between 0 and 9999',
    ];

    public function up(): void
    {
        Schema::create('content_types', function (Blueprint $table): void {
            $table->id();

            // 内部 ID。**不変**（SPEC §E）。ファイル型の定義はファイル側にあるので FK は張らない。
            // varchar(64) unique = 256 バイト（utf8mb4 最悪値）
            $table->string('content_type_id', 64);

            // 運用状態。利用者が動かす（SPEC §F.1）
            $table->string('status', 16)->default('active');
            // 登録の進行状態。システムが動かす（SPEC §F.1 / §F.3.2 / §I.3）
            $table->string('lifecycle', 16)->default('ready');

            // 0 = 未設定（既定順の後ろ）。利用者が保存する順位は 1〜9999（SPEC §J.2）
            $table->unsignedInteger('menu_order')->default(0);

            $table->boolean('comments_enabled')->default(false);
            // 9.8（P0-3）が使う。**この Stage では読まない**（SPEC §D.2）
            $table->boolean('revisions_enabled')->default(true);

            // 公開 URL の可変部分。内部 ID とは別（SPEC §E.2）。240 バイト
            $table->string('public_slug', 60)->nullable();

            // allowlist された浅い JSON だけ（SPEC §F.5.1）。深さ 1 / 2KB は アプリ側で検証する
            $table->json('registration')->nullable();
            $table->json('display_overrides')->nullable();

            // 楽観ロック（SPEC §J.5）。`updated_at` は使わない
            $table->unsignedBigInteger('lock_version')->default(0);

            $table->timestamps();

            // tombstone も ID を占有する（SPEC §F.3.6）。これが「ID を既定では再利用させない」の実装
            $table->unique('content_type_id', 'uq_content_type_id');
            // 公開スラッグは投稿タイプ間で一意（SPEC §E.2）。NULL は複数あってよい
            $table->unique('public_slug', 'uq_content_type_public_slug');
            // 一覧の既定の並び（SPEC §J.2）。132 バイト
            $table->index(['status', 'lifecycle', 'menu_order'], 'idx_content_type_state');
        });

        foreach ($this->supportedChecks() as $name => $expression) {
            DB::statement("alter table `content_types` add constraint `{$name}` check ({$expression})");
        }
    }

    /**
     * **行が 1 件でも在れば止める**（SPEC §K.2）。
     *
     * この migration は 1 行も作っていないので、在る行はすべて利用者の設定である。
     * 落とすと「無効にした型が有効に戻る」「公開スラッグが消える」が黙って起きる。
     */
    public function down(): void
    {
        if (! Schema::hasTable('content_types')) {
            return;
        }

        $rows = DB::table('content_types')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "content_types に {$rows} 件の設定が入っているため down を中止しました。".
                '（この migration は 1 行も作っていないので、在る行はすべて利用者の設定です。'.
                'それでも戻すなら、先に設定を書き出すか削除してください）',
            );
        }

        Schema::dropIfExists('content_types');
    }

    /**
     * この driver で CHECK が効くか。
     *
     * MySQL は 8.0.16 以降でのみ CHECK を**強制**する（それ以前は構文を無視する）。
     * 無視される制約を付けて「守られている」ことにしない。
     * SQLite は `alter table ... add constraint` を受け付けないので付けない。
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

        // MariaDB は 10.2 以降で CHECK を強制する。判定を分けずに済ませない
        if (str_contains(strtolower($version), 'mariadb')) {
            return version_compare($version, '10.2', '>=') ? self::CHECKS : [];
        }

        return version_compare($version, '8.0.16', '>=') ? self::CHECKS : [];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media に種別と派生物を持たせる（Phase 9.5-D / ADR-0018・SPEC §2）。
 *
 * ## 既存行の意味は変わらない
 *
 * 追加列の既定値は `image` / `public` / `public` で、既存の画像がまさにその状態である。
 * つまり実質は **列追加だけ**で、既存の行を書き換えない。
 *
 * ## filesystem / config を見ない
 *
 * `has_webp` から `media_variants` への写し替え（backfill）は **ここで行わない**。
 * migration は DB のスキーマとデータだけを扱う。どの disk が設定されているかは
 * 環境で違い、config が変われば同じ migration が違う結果になるからである（SPEC §7.1.1）。
 * 移行は `agentcms:media-variants --backfill`（dry-run 既定）で行う。
 *
 * ## down は fail-closed（SPEC §12.1 / OG2）
 *
 * 一般ファイルの行・variant 行・寸法 NULL の行が **1 件でもあれば何もせず停止**する。
 * `kind` を落とすと `kind=file` の行が image として扱われ、`disk` を落とすと
 * `local` に在るファイルを `public` から探しに行く。**部分 rollback はデータの意味を壊す。**
 * 戻せないことがあるのは仕様である（戻せないより、壊れるほうが悪い）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('kind', 10)->default('image')->after('id');
            $table->string('visibility', 10)->default('public')->after('kind');
            // 画像は public、一般ファイルは local。**同じテーブルで置き場所が分かれる**ので
            // 列で持つ。型からも kind からも導出しない（disk を差し替えたときに
            // 既存行がどこに在るかを言えなくなる）
            $table->string('disk', 32)->default('public')->after('path');
            // 利用者がアップロードした名前。配信時の filename の素にするだけで、
            // **保存パスには使わない**（パスは従来どおり UUID 再生成）
            $table->string('original_name', 180)->nullable()->after('alt');
            // メディアライブラリのタブ表示の既定並び
            $table->index(['kind', 'created_at']);
        });

        // 一般ファイルには寸法が無い
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->change();
            $table->unsignedInteger('height')->nullable()->change();
        });

        Schema::create('media_variants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('media_id');
            $table->string('format', 10);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('created_at')->nullable();

            // media_id 単独の索引は置かない。この unique の左端が media_id なので
            // 「ある media の variant を引く」には足りる。重複した索引は書き込みを遅くする。
            // FK より **先に**宣言することで、MySQL が FK 用の索引を別に作らない
            $table->unique(['media_id', 'format'], 'uq_media_variant_format');
        });

        Schema::table('media_variants', function (Blueprint $table) {
            // RESTRICT（Taxonomy / Field Group と同じ方針）。掃除は Application 層が明示的に行う
            $table->foreign('media_id')
                ->references('id')->on('media')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        $this->assertRollbackIsSafe();

        // 実 MySQL の DDL は transaction で巻き戻せない。だから
        // ①先に独立した table を落とし ②残りを 1 回の変更にまとめる、という順にする。
        // 「途中まで戻って止まる」形を意図的に作らないための順序であって、
        // 完全な原子性を保証するものではない。
        Schema::dropIfExists('media_variants');

        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['kind', 'created_at']);
            $table->dropColumn(['kind', 'visibility', 'disk', 'original_name']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable(false)->change();
            $table->unsignedInteger('height')->nullable(false)->change();
        });
    }

    /**
     * 戻すと意味が壊れるデータが在れば、**何も変更せず**停止する。
     *
     * @throws RuntimeException
     */
    private function assertRollbackIsSafe(): void
    {
        $files = Schema::hasColumn('media', 'kind')
            ? (int) DB::table('media')->where('kind', 'file')->count()
            : 0;

        $variants = Schema::hasTable('media_variants')
            ? (int) DB::table('media_variants')->count()
            : 0;

        $dimensionless = (int) DB::table('media')
            ->whereNull('width')
            ->orWhereNull('height')
            ->count();

        if ($files === 0 && $variants === 0 && $dimensionless === 0) {
            return;
        }

        $reasons = [];

        if ($files > 0) {
            $reasons[] = "一般ファイルが {$files} 件あります（戻すと画像として扱われます）";
        }

        if ($variants > 0) {
            $reasons[] = "画像バリエーションが {$variants} 件あります（戻すと参照が失われます）";
        }

        if ($dimensionless > 0) {
            $reasons[] = "寸法の無いメディアが {$dimensionless} 件あります（width/height を NOT NULL へ戻せません）";
        }

        throw new RuntimeException(
            "この migration は戻せません。\n- ".implode("\n- ", $reasons)
            ."\n\n戻すには、先に該当のメディアを削除してください。"
            .'部分的に戻すとデータの意味が壊れるため、何も変更していません。'
        );
    }
};

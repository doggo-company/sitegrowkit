<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 派生物の同一性を「形式」から「形式 + 幅」へ広げる（レスポンシブ画像 / release-20260909）。
 *
 * ## 既存行は 1 行も書き換えない
 *
 * 既存の行は原本と同寸の format 変換で、`width` に原本の幅が入っている。
 * 旧 unique `(media_id, format)` が守られていた行は、新 unique `(media_id, format, width)`
 * も自動的に満たす。**列の追加も値の変更も無い** — 索引の付け替えだけである。
 *
 * ## 順序: 足してから落とす
 *
 * `media_id` の FK は索引を必要とする。旧 unique を先に落とすと、MySQL は
 * 「FK に必要な索引は落とせない」で止まる。新 unique（左端が `media_id`）を先に作れば
 * FK はそちらへ乗り移り、旧 unique を落とせる。
 *
 * ## down は fail-closed（Phase 9.5-D / OG2 と同じ方針）
 *
 * 同じ media・同じ形式の行が 2 つ以上ある（= 幅候補が作られている）なら、
 * 旧 unique を戻せない。**何もせず停止**する。戻すには先に幅候補の行を消す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_variants', function (Blueprint $table) {
            $table->unique(['media_id', 'format', 'width'], 'uq_media_variant_format_width');
        });

        Schema::table('media_variants', function (Blueprint $table) {
            $table->dropUnique('uq_media_variant_format');
        });
    }

    public function down(): void
    {
        $this->assertRollbackIsSafe();

        Schema::table('media_variants', function (Blueprint $table) {
            $table->unique(['media_id', 'format'], 'uq_media_variant_format');
        });

        Schema::table('media_variants', function (Blueprint $table) {
            $table->dropUnique('uq_media_variant_format_width');
        });
    }

    /**
     * 幅候補の行が 1 件でもあれば、**何も変更せず**停止する。
     *
     * @throws RuntimeException
     */
    private function assertRollbackIsSafe(): void
    {
        if (! Schema::hasTable('media_variants')) {
            return;
        }

        $grouped = DB::table('media_variants')
            ->select('media_id', 'format')
            ->groupBy('media_id', 'format')
            ->havingRaw('COUNT(*) > 1');

        // group した結果の**行数**が欲しい（group ごとの件数ではない）ので副問い合わせで数える
        $duplicated = DB::query()->fromSub($grouped, 'duplicated')->count();

        if ($duplicated === 0) {
            return;
        }

        throw new RuntimeException(
            "この migration は戻せません。\n- 幅ごとの画像バリエーションを持つ画像が {$duplicated} 件あります"
            .'（戻すと同じ形式の行が重複し、旧 unique を作れません）'
            ."\n\n戻すには、先に幅候補の行（原本より小さい width の media_variants）を削除してください。"
            .'部分的に戻すとデータの意味が壊れるため、何も変更していません。'
        );
    }
};

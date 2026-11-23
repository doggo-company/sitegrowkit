<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 転送のルールと 404 の記録（Phase 9.10 / STAGE 23B・SPEC §B.1・§B.6）。
 *
 * ## なぜ hash 列を持つのか
 *
 * `source_path` は 2048 文字で、**MySQL では一意制約を張れない**
 * （utf8mb4 の index は 3072 バイトまで）。前方 N 文字の prefix index にすると、
 * 長い URL で**別のものを重複と判定する**。だから
 * `sha256(match_type + '|' + source_path)` を鍵にする。
 *
 * これは導出値だが、**キャッシュではなく鍵**である（SPEC §B.2）。
 * 値は保存のたびにアプリ側で作り直す（渡された hash を信用しない）。
 *
 * ## down は fail-closed
 *
 * 転送のルールを落とすと、外から来ている URL が**その瞬間に 404 になる**。
 * 404 の記録も、どのリンクが壊れているかの唯一の手がかりである。
 * どちらも 1 行でも在れば、何も変更せずに止まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redirect_rules')) {
            Schema::create('redirect_rules', function (Blueprint $table): void {
                $table->id();
                // exact / prefix。regex は入れない（OG3。ReDoS の的になる）
                $table->string('match_type', 20)->default('exact');
                $table->string('source_path', 2048);
                $table->char('source_hash', 64);
                $table->string('target_url', 2048);
                $table->unsignedSmallInteger('status_code')->default(301);
                $table->boolean('is_enabled')->default(true);
                // 元の query を転送先へ引き継ぐか（SPEC §B.5）
                $table->boolean('keep_query')->default(true);
                /*
                 * どうやってできたルールか。`slug_change` は **値として予約するだけ**で、
                 * 自動生成は V1 では作らない（OG10）。あとから足すときに
                 * 「手で作ったもの」と混ざらないようにしておく。
                 */
                $table->string('created_reason', 20)->default('manual');
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('hit_count')->default(0);
                $table->timestamp('last_hit_at')->nullable();
                // 競合検出（9.8 / OG11 と同じ CAS。updated_at は使わない）
                $table->unsignedBigInteger('lock_version')->default(0);
                $table->timestamps();

                $table->unique('source_hash', 'uq_redirect_source');
                // 引くときは「有効なものだけ」を照合方法で絞る（SPEC §D.1）
                $table->index(['is_enabled', 'match_type'], 'idx_redirect_lookup');
            });
        }

        if (! Schema::hasTable('not_found_logs')) {
            Schema::create('not_found_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('path', 2048);
                $table->char('path_hash', 64);
                /*
                 * **最後の 1 件だけ**持つ（SPEC §B.6）。
                 * 全部貯めると bot の探索で表が膨れ、直すべきリンク切れが埋もれる。
                 */
                $table->string('last_query', 1024)->nullable();
                $table->string('last_referrer', 2048)->nullable();
                $table->string('last_user_agent', 255)->nullable();
                // 生の IP は保存しない。sha256(APP_KEY + '|' + ip)（SPEC §F.2）
                $table->char('last_ip_hash', 64)->nullable();
                $table->unsignedBigInteger('hit_count')->default(0);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                // 直したものに印を付ける（保持期間が変わる — SPEC §F.4）
                $table->timestamp('resolved_at')->nullable();

                $table->unique('path_hash', 'uq_not_found_path');
                $table->index('last_seen_at', 'idx_not_found_recent');
                $table->index(['resolved_at', 'hit_count'], 'idx_not_found_open');
            });
        }
    }

    public function down(): void
    {
        $blockers = [];

        foreach (['redirect_rules' => '転送のルール', 'not_found_logs' => '404 の記録'] as $table => $label) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();

            if ($rows > 0) {
                $blockers[] = "{$table}: {$label}が {$rows} 行";
            }
        }

        if ($blockers !== []) {
            throw new RuntimeException(
                "転送の表を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n落とすと、外から来ている URL がその瞬間に 404 になります。".
                "\nどのリンクが壊れているかの記録も失われます。".
                "\n戻す場合は、転送が不要であることを確かめたうえで、先に中身を片付けてから再実行してください。".
                "\n（この migration は表を 1 つも変更していません）",
            );
        }

        Schema::dropIfExists('not_found_logs');
        Schema::dropIfExists('redirect_rules');
    }
};

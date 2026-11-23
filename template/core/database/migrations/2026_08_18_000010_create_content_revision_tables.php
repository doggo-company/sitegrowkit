<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 投稿の版（Phase 9.8 / STAGE 21D・SPEC §F.1・§F.2.0）。
 *
 * ## 2 つの表に分ける理由
 *
 * 版は「そのときの内容」を持つが、**そのときの項目定義**も要る。定義が無いと、
 * 「この値はどの field のものだったか」「型が変わっていないか」を後から言えない。
 * とはいえ定義は schema が変わらない限り同じなので、版ごとに持つと
 * **同じ JSON が何千行も並ぶ**。だから fingerprint を鍵にして 1 行にまとめる。
 *
 * ## 順序と FK
 *
 * 版が定義を指すので、up は **manifest → revision** の順。down はその逆。
 * 複合 FK（`content_type` + `fingerprint`）を張れるのは、どちらも固定の表だからである
 * — 投稿の本体テーブルは型ごとに動的なので、そちらへは FK を張れない（§F.1）。
 *
 * `fingerprint` 単独を主キーにしない。canonical は schema の中身から作るので、
 * **別々の型がたまたま同じ形なら同じ hash になる**（「title と body だけ」なら起こり得る）。
 * 単独だと型を取り違えた復元が通ってしまう。
 *
 * ## down は fail-closed
 *
 * 版を落とすと**履歴が消える**。ゴミ箱と違って戻す先が無い。
 * 1 件でも在れば、何も変更せずに止まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① 先に定義。版がこれを指す
        if (! Schema::hasTable('content_schema_manifests')) {
            Schema::create('content_schema_manifests', function (Blueprint $table): void {
                $table->string('content_type', 64);
                $table->char('fingerprint', 64);
                $table->json('manifest');
                $table->timestamp('created_at')->nullable();

                $table->primary(['content_type', 'fingerprint']);
            });
        }

        // ② 版
        if (! Schema::hasTable('content_entry_revisions')) {
            Schema::create('content_entry_revisions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('content_type', 64);
                $table->unsignedBigInteger('content_id');
                $table->unsignedInteger('revision');
                // manual / autosave（autosave の実装は P0-B2。列だけ先に用意する）
                $table->string('kind', 16)->default('manual');
                $table->json('snapshot');
                $table->char('schema_fingerprint', 64);
                // baseline | create | update | publish | unpublish | revision_restore
                $table->string('reason', 24);
                $table->string('created_interface', 20);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();

                // 同じ entry に同じ版番号を 2 つ作らない（採番の競合を DB で止める）
                $table->unique(['content_type', 'content_id', 'revision'], 'content_entry_revisions_entry_revision_unique');
                $table->index(['content_type', 'content_id', 'kind', 'created_at'], 'content_entry_revisions_entry_kind_index');
                // 掃除が「この定義を指す版が在るか」を索けるようにする（§H.4）
                $table->index(['content_type', 'schema_fingerprint'], 'content_entry_revisions_manifest_index');

                /*
                 * **定義を先に消せないようにする。** 消せてしまうと、版は残っているのに
                 * 「どの field だったか」が分からない行になる。掃除はアプリ側でも
                 * 参照を確認するが、ここは最後の安全網である。
                 */
                $table->foreign(['content_type', 'schema_fingerprint'], 'content_entry_revisions_manifest_foreign')
                    ->references(['content_type', 'fingerprint'])
                    ->on('content_schema_manifests')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $blockers = [];

        foreach (['content_entry_revisions' => '版', 'content_schema_manifests' => '項目定義'] as $table => $label) {
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
                "版の表を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n落とすと投稿の履歴が失われます。ゴミ箱と違って戻す先がありません。".
                "\n戻す場合は、履歴が不要であることを確かめたうえで、先に中身を片付けてから再実行してください。".
                "\n（この migration は表を 1 つも変更していません）",
            );
        }

        // FK があるので **版 → 定義**の順で落とす
        Schema::dropIfExists('content_entry_revisions');
        Schema::dropIfExists('content_schema_manifests');
    }
};

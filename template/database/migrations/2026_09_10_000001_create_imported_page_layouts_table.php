<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 取り込んだページと、その原本の枠（layout）の結び付き（R03 / B-42）。
 *
 * ## なぜ表が要るのか
 *
 * 取り込みは原本の枠を `layouts/preserved/<slug>.blade.php` に書き出す。
 * その枠を**どのページに当てるか**を slug（ファイルの有無）で決めると、
 *
 * - 取り込みの前から在った同名のページにも新しい枠が当たる（`kept` で「触らない」と言った保証が破れる）
 * - slug を変えた瞬間に枠が外れる
 *
 * ので、**取り込みが実際に作ったページの不変 id** と枠の名前を、ページの作成と同じ取引で残す。
 * 枠が当たるのはここに行があるページだけ。管理画面で作ったページ・WordPress から移したページには
 * 行が無いので、kit 既定の layout のまま。複製は枠だけを引き継ぐ（`owned_view` は null）。
 *
 * ## project 側の表
 *
 * 取り込みは project 層の機能（`ImportPageCommand` / `DocumentPageView`）なので、表もここに置く。
 * core の `pages` に列は足さない。バックアップは全表を写すので、この表も一緒に戻る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_page_layouts', function (Blueprint $table): void {
            $table->id();
            // ページが完全に消えたら結び付きも消える（ゴミ箱は行が残るだけなので影響しない）
            $table->foreignId('page_id')->unique()->constrained('pages')->cascadeOnDelete();
            // view の名前（`layouts.preserved.home`）。ファイルの有無は描く瞬間に見る
            $table->string('view', 190);
            /*
             * **元の URL の所有者**だけが持つ（値は `view` と同じ）。複製は null。
             *
             * 枠（`view`）は複製にも写るが、元の URL（`/company` や `/`）を出す権利は
             * 取り込みが作った 1 枚だけ。複製が同じ view を持つので `view` では所有者を
             * 決められない。unique は「1 つの枠に所有者は 1 枚」を DB で保証する
             * （MySQL / SQLite とも NULL は unique に数えない）。
             */
            $table->string('owned_view', 190)->nullable()->unique();
            // 由来の原本（`index.html`）。表示と後始末の説明に使う
            $table->string('source', 190)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_page_layouts');
    }
};

<?php

declare(strict_types=1);

namespace AgentCms\Core\Contracts;

use AgentCms\Core\Application\OperationContext;

/**
 * Core からメディアを預け入れ / 引き取らせる口（ADR-0020）。
 *
 * ## なぜ interface なのか
 *
 * メディアを保存する口と消す口は **モジュール側**にある
 * （`Modules\Media\Application\UploadMedia` / `DeleteMedia`）。
 * `boundaries.md` は `core/` から `modules/` への依存を **MUST で禁じている**ので、
 * Core はこの interface だけを見る。実装と binding は project 側に置く。
 *
 * **依存の向きは project → core のまま**である。
 *
 * ## 検査は 1 つも減らない
 *
 * 実装は既存の upload 経路を通る。`UploadValidator`（拡張子と中身 MIME の突き合わせ）・
 * `FileInspector`・`SvgSanitizer` は**そこで働く**。この interface は
 * 「検査を飛ばすための薄い層」ではなく、**依存の向きを直すためだけの層**である。
 *
 * ## bind されていなければ、呼ぶ側が断る
 *
 * 実装が無い環境では**黙って飛ばさない**（§A.3）。メディアを飛ばした取り込みは
 * 「成功したが画像が 1 枚も無い」状態で残り、それに気づくのは数日後になる。
 * 呼ぶ側（`MediaImporter`）は `null` binding を受けたら止める。
 */
interface MediaIntake
{
    /**
     * ローカルの実体を預け、**こちら側の id と URL**を返す。受け付けられなければ `null`。
     *
     * URL を返すのは Core が Media のモデルに触らないためであり、もう 1 つ
     * 実務的な理由がある — 画像と一般ファイルで**返すべき URL が別**なので、
     * どちらを返すかは Media を持っている側が決める。
     *
     * `$filename` は取得先が決めた名前で、**種類の判定には使わない**
     * （実装側の検査が拡張子と中身の食い違いを弾く）。
     *
     * @return array{id: string, url: string}|null
     */
    public function store(string $path, string $filename, OperationContext $context): ?array;

    /**
     * 預けたものを引き取らせる（取り消し）。**まだ使われていれば `false`**。
     *
     * 使用中かどうかを決めるのは実装側が呼ぶ判定者（`MediaUsageInspector`）である。
     * 呼ぶ側が数え直さない — 数え方が 2 つになると、参照の種類が増えた日に
     * **片方だけ古い数え方のまま**になる。
     */
    public function forget(string $mediaId, OperationContext $context): bool;

    /**
     * 既に預けてあるものの URL。無ければ `null`。
     *
     * **2 度目の取り込みのために在る。** 同じ添付が既に入っているとき、取りには行かないが
     * 本文の置換には URL が要る。これが無いと、後から足された記事の本文だけが
     * **元の URL のまま残る**（黙って劣化する形になる）。
     */
    public function urlOf(string $mediaId): ?string;
}

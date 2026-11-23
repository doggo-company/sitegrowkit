<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Contracts\MediaIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Media\Models\Media;

/**
 * Core からのメディアの預け入れ / 引き取りを、既存の口へ繋ぐ（ADR-0020）。
 *
 * ## ここは薄い。薄いことが仕事である
 *
 * 検査も保存も削除も `UploadMedia` / `DeleteMedia` が行う。
 * この class が持つのは「Core の言葉」と「Media の言葉」の変換だけで、
 * **判断を 1 つも持たない**。判断を持ち始めたら、それは Media 側の口に足すべきものである。
 *
 * ## 削除は `DeleteMedia` を通す。**2 つ目の削除経路を作らない**
 *
 * メディアの削除は 1 行では終わらない — variants の行・旧実装が作った `.webp`・
 * disk をまたぐ実体、の順序がある（`DeleteMedia::filesOf()`）。ここで同じ手順を
 * 書き写すと、**variants の扱いを変えた日に片方だけ古いまま**になる。
 *
 * `DeleteMedia` は人向けの確認（`ConfirmationGate`）を求めるので、返ってきた token を
 * そのまま返して 2 度目を呼ぶ。**確認を無かったことにしているのではない** —
 * 取り消しの確認は `--i-have-a-backup` が既に取っており（§D.3）、ここはその 1 つの
 * 操作の内側である。使用中かどうかの判定（`MediaUsageInspector`）は
 * **1 度目にも 2 度目にも働く**ので、使われているメディアはここを通らない。
 *
 * 分類項目の取り消し（`TermReverter`）は逆に `DeleteTerm` を通さず自分で消している。
 * 違いは**消す手順の重さ**である — 項目は 1 行、メディアは行と実体が複数ある。
 */
final class UploadMediaIntake implements MediaIntake
{
    public function __construct(
        private readonly UploadMedia $upload,
        private readonly DeleteMedia $delete,
    ) {}

    public function store(string $path, string $filename, OperationContext $context): ?array
    {
        /*
         * `test: true` が変えるのは `is_uploaded_file()` の検査と `move()` の実装だけで、
         * どちらも「HTTP POST で届いたか」というトランスポートの話である。
         * `UploadValidator` / `FileInspector` / `SvgSanitizer` は `isValid()` を呼ばず、
         * 実体・MIME・大きさ・名前だけを見る — **検査は 1 つも変わらない**。
         * 検証を飛ばす引数は使わない。
         */
        try {
            $result = $this->upload->execute(
                new UploadedFile($path, $filename, null, null, test: true),
                $context,
            );
        } catch (ValidationException) {
            /*
             * **検査が断ったことを「受け付けなかった」に翻訳する。**
             *
             * 上の口は画面から呼ばれる前提なので、拒否を例外で返す
             * （`ImageProcessor::inspect()` の「画像として読み込めない」など）。
             * 画面ではそれが入力エラーとして出るが、取り込みでは**1 件の失敗**であって、
             * 十年分の取り込みを落とす理由ではない（§C.6）。
             *
             * **`Throwable` では受けない。** 受けると、書き込みに失敗した・disk が無い、
             * といった本物の故障まで「この添付は駄目でした」に化ける。
             */
            return null;
        }

        if ($result->status !== OperationStatus::Success) {
            return null;
        }

        $media = $result->data['media'] ?? null;
        $id = is_array($media) ? (string) ($media['id'] ?? '') : '';

        if ($id === '') {
            return null;
        }

        $url = $this->urlOf($id);

        return $url === null ? null : ['id' => $id, 'url' => $url];
    }

    public function forget(string $mediaId, OperationContext $context): bool
    {
        $id = (int) $mediaId;

        $first = $this->delete->execute($id, $context);

        if ($first->status === OperationStatus::Success) {
            return true;
        }

        if ($first->status !== OperationStatus::ConfirmationRequired || $first->confirmation === null) {
            // 使用中（conflict）・見つからない。**残す側**へ倒す
            return false;
        }

        $second = $this->delete->execute(
            $id,
            $context->withConfirmationToken($first->confirmation->token),
        );

        return $second->status === OperationStatus::Success;
    }

    /**
     * こちら側の URL。**画像と一般ファイルで返す先が違う。**
     *
     * `toPickerArray()` の `url` は画像のときだけ値が入るので、そこだけを見ると
     * PDF や zip の置換先が `null` になる。どちらを返すかは Media を持つ側が決める。
     */
    public function urlOf(string $mediaId): ?string
    {
        $media = Media::query()->find((int) $mediaId);

        if ($media === null) {
            return null;
        }

        return $media->isImage() ? $media->url() : $media->downloadUrl();
    }
}

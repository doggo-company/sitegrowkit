<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ContentExport;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** サーバー上の管理者が内容交換用WXRを作る。公開・設定変更は行わない。 */
final class ExportContentCommand extends Command
{
    protected $signature = 'agentcms:content-export';

    protected $description = '記事・ページをWordPress交換用XMLへ書き出す（非公開保存）';

    public function handle(ContentExport $export): int
    {
        $id = strtolower((string) Str::ulid());
        try {
            $count = $export->write($id, function (int $count): void {
                if ($count % 100 === 0) {
                    $this->line($count.' 件を書き出し中');
                }
            });
        } catch (Throwable) {
            // モデルの値やDB認証情報を例外経由で画面へ出さない。
            $this->error('書き出しを完了できませんでした。保存先の空き容量、権限、記事の文字とページの版を確認してください');

            return self::FAILURE;
        }
        $this->info($count.' 件を書き出しました: '.ContentExport::path($id));
        $this->line('投稿タイプはWordPressの投稿へ変換します。画像はURL参照です。デザイン・履歴・フォーム・設定はバックアップで引き継いでください。');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Console;

use AgentCms\Core\Wxr\WxrError;
use AgentCms\Core\Wxr\WxrReader;
use AgentCms\Core\Wxr\WxrSummary;
use Illuminate\Console\Command;

/**
 * WXR に何が入っているかを見る（Phase 9.14 / STAGE 27B・SPEC §D.1）。
 *
 * ## DB を 1 行も読まない
 *
 * ここで答えるのは**ファイルの中身だけ**である。
 * 「こちらへ入るかどうか」は `agentcms:wxr-plan`（27C）が答える。
 * 分けているのは、**受け取ったファイルを、繋ぐ前に確かめられる**ようにするためである。
 *
 * ## 取り込めないものも数える
 *
 * メニュー項目・未知の型・shortcode は取り込まない（SPEC §A.2）。
 * ページは release-20260909 から `pages` へ入る（記事の型とは別）。
 * だからこそ**件数を出す**。移行は 1 回きりで、
 * 気づかなかったものは永久に気づかれない。
 */
final class InspectWxrCommand extends Command
{
    protected $signature = 'agentcms:wxr-inspect
        {file : WordPress の書き出し XML}
        {--json : 結果を JSON で出力する}';

    protected $description = 'WXR に何が入っているかを調べます（データベースは読みません）';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $reader = new WxrReader($path);
        $summary = new WxrSummary;

        try {
            $reader->assertReadable();

            foreach ($reader->termDefinitions() as $definition) {
                $summary->addDefinition($definition->taxonomy);
            }

            foreach ($reader->items() as $item) {
                $summary->add($item);
            }
        } catch (WxrError $e) {
            return $this->reportError($e);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['ok' => true, 'fingerprint' => $reader->fingerprint()] + $summary->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->report($summary);

        return self::SUCCESS;
    }

    private function report(WxrSummary $summary): void
    {
        $data = $summary->toArray();

        $this->info("item {$data['items']} 件 / コメント {$data['comments']} 件");

        $this->newLine();
        $this->line('種類:');

        foreach ($data['postTypes'] as $type => $count) {
            // 取り込まないものは、その場で理由を添える
            $note = match ($type) {
                'page' => '  ← ページとして取り込みます（pages へ。分類・コメントは付きません）',
                'attachment' => '  ← --media を付けたときだけ取り込みます',
                'nav_menu_item' => '  ← 取り込みません（メニューは対象外）',
                default => '',
            };

            $this->line(sprintf('  %-16s %6d%s', $type, $count, $note));
        }

        $this->newLine();
        $this->line('状態:');

        foreach ($data['statuses'] as $status => $count) {
            $this->line(sprintf('  %-16s %6d', $status, $count));
        }

        if ($data['termDomains'] !== []) {
            $this->newLine();
            $this->line('分類（受け皿がこちらに要ります）:');

            /*
             * 🔴 **定義と参照を分けて出す**（WP 一周 2026-09-03 / 実物で踏んだ）。
             *
             * 「項目 N 種」だけを出していたので、**定義が 1 件も無い書き出し**でも
             * 「4 種」と出ていた。読んだ人は定義が在ると思う。気づくのは plan まで
             * 進んで `term_definition_missing` が出たときで、そこまで来ないと分からない。
             *
             * ## 文言の変更（2026-09-04）
             *
             * 以前はここで「その名前の定義がファイルにありません」と言い、
             * 「取り込めません」と止めていた。**これは事実と違う。**
             * 記事側の `<category domain nicename>` には名前が入っている
             * （属性 `nicename`・CDATA が表示名）。無いのは**親子関係だけ**である。
             *
             * 実物の書き出し（沼津の案件）は `<wp:category>` を 1 件も持たない形で
             * 存在する。「書き出し直してください」は、サイトが既に閉じている案件・
             * 前の制作会社が鍵を持っている案件では実行できない。だから
             * **止めずに、平らな項目として作ることを既定にした**（`agentcms:wxr-plan`
             * / `agentcms:wxr-apply` の既定の動き）。ここでは事前に**それを言うだけ**にする。
             */
            $missing = [];

            foreach ($data['termDomains'] as $domain => $count) {
                $terms = $data['terms'][$domain] ?? 0;
                $defined = $data['termDefinitions'][$domain] ?? 0;

                $this->line(sprintf(
                    '  %-16s 延べ %d 件 / 参照 %d 種 / 定義 %d 件',
                    $domain,
                    $count,
                    $terms,
                    $defined,
                ));

                if ($defined === 0) {
                    $missing[] = $domain;
                }
            }

            if ($missing !== []) {
                $this->newLine();
                $this->comment('分類の定義ブロックがありません（親子関係は分かりません）: '.implode(' / ', $missing));
                $this->line('  記事側には名前が入っているので、平らな項目として取り込めます。');
                $this->line('  agentcms:wxr-plan / agentcms:wxr-apply は既定でこれを作ります（親子は付きません）。');
                $this->line('  従来どおり止めたいときは --strict-term-definitions を付けてください。');
            }
        }

        if ($data['shortcodes'] !== []) {
            $this->newLine();
            $this->warn('shortcode が残っています（展開も削除もしません）:');

            foreach ($data['shortcodes'] as $name => $count) {
                $this->line(sprintf('  [%s] %d 件', $name, $count));
            }

            $this->line('  取り込むと、これらは文字のまま本文に残ります。');
        }

        if ($data['largeBodies'] > 0) {
            $this->newLine();
            $this->warn("本文が 1MB を超える item が {$data['largeBodies']} 件あります。");
        }
    }

    /** `fail()` は Command が持っているので、別の名前にする */
    private function reportError(WxrError $e): int
    {
        $this->error($e->getMessage());

        if ($e->hint !== '') {
            $this->line($e->hint);
        }

        if ($e->sourceLine !== null) {
            $this->line("（{$e->sourceLine} 行目付近）");
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->toArray()],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        return self::FAILURE;
    }
}

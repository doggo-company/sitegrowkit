<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PublicationReadiness;
use Illuminate\Console\Command;

/**
 * 公開してよい状態かを、公開の手前で確かめる。
 *
 * **デプロイ手順に組み込むためのもの**なので、
 * 残っているものが 1 つでもあれば **exit code 1 で落ちる**。
 * 通らないと本番へ出せない、という形にしておかないと止まらない。
 */
final class PublicationReadinessCommand extends Command
{
    protected $signature = 'site:readiness';

    protected $description = '仮のまま公開しようとしていないか確かめる（残っていれば落ちる）';

    public function handle(PublicationReadiness $readiness): int
    {
        $findings = $readiness->check();

        if ($findings === []) {
            $this->info('公開できる状態です。原稿待ちの言葉も、埋まっていない設定もありません。');

            return self::SUCCESS;
        }

        $this->error(count($findings).' 件、公開の前に埋めるものがあります。');
        $this->newLine();

        $this->table(
            ['どこ', '何が残っているか'],
            array_map(
                static fn (array $f): array => [$f['where'], $f['what']],
                $findings,
            ),
        );

        $this->newLine();
        $this->line('▷ 原稿・電話番号・問い合わせ先が届いたら、埋めてもう一度これを実行してください。');

        return self::FAILURE;
    }
}

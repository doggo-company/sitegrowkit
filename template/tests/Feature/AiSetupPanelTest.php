<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ダッシュボードから AI 連携へ辿れること（Stage 1）。
 *
 * ## ここが見るのは「配られる側」だけ
 *
 * 画面が持つ「最初のプロンプト」の**正本は `docs/AI_AGENTS.md`** だが、
 * `docs/` は生成プロジェクトへ配られないので、**その照合はここでは成立しない**
 * （kit 側 `packages/cli/tests/ai-setup-prompt.test.ts` が持つ）。
 *
 * このテストは**利用者のプロジェクトでもそのまま走る**ものだけを見る。
 */
final class AiSetupPanelTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, "{$relative} が無い");

        return (string) file_get_contents($path);
    }

    /**
     * **カードから開ける。**
     *
     * これまでは `href: null` / `linkLabel: null` で、**押せなかった**。
     * 仕組みは `agentcms setup` に在ったのに、**そこへ辿る道が無かった**。
     */
    public function test_はじめにのカードからai連携を開ける(): void
    {
        $panel = $this->source('resources/js/components/GettingStartedPanel.vue');

        /*
         * **置いてあることと、読み込んでいることの両方を見る。**
         *
         * `<script setup>` では、import し忘れた component は
         * **未知の要素として黙って何も描かれない**（build も型検査も通ってしまう）。
         * 使用だけを見ると、**開かないパネルを緑と言う**ことになる。
         */
        $this->assertStringContainsString(
            "import AiSetupPanel from '@/components/AiSetupPanel.vue';",
            $panel,
            'パネルを読み込んでいない（使っていても描かれない）',
        );
        $this->assertStringContainsString('<AiSetupPanel', $panel, 'パネルを置いていない');

        /*
         * **押せる状態になっていること** — AI の導線に押す手段がある。
         *
         * 文言は「AI でカスタマイズする」から「AI エージェントと繋ぐには」へ変わり、
         * 5 歩の中ではなくパネルの下へ移った（2026-09-03）。
         * **順にこなす道の途中に置くと、そこで止まる人が出る**ため。
         * 見たいのは文言ではなく「押せること」なので、そこだけを見る。
         */
        $this->assertMatchesRegularExpression(
            '/AI エージェントと繋ぐには.{0,400}?(action|onOpen|open)/su',
            $panel,
            'AI の導線に押す手段が無い（文字だけのまま）',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 「次はサーバーに出す」の手順に、手順書 §4（デプロイ設定を作る）を先頭で入れる（直し 4 回目）。
 *
 * `agentcms deploy` の setup / doctor / plan / staging は、どれも最初に
 * `.agentcms/deploy/<target>.json` を読み、無ければ止まる。設定を作る段が抜けた案内のとおりに
 * doctor から打つと、最初の一手で止まる。README_FIRST の同じ順番は kit 側
 * （packages/cli/tests/readme-deploy-steps.test.ts）が見る — template/tests は kit の親を読めない。
 */
final class Round4DeployGuideTest extends TestCase
{
    public function test_readiness_screen_puts_the_deploy_settings_step_before_any_deploy_command(): void
    {
        $vue = (string) file_get_contents(resource_path('js/pages/admin/readiness/Index.vue'));
        $steps = $this->between($vue, 'data-testid="deploy-steps"', '</ol>');

        $this->assertStringContainsString('手順書 §4', $steps);
        $this->assertSettingsFirst($steps);
    }

    private function assertSettingsFirst(string $text): void
    {
        $settings = mb_strpos($text, '.agentcms/deploy/staging.example.json');
        $this->assertNotFalse($settings, 'デプロイ設定（staging.example.json のコピー）の段が無い');

        foreach (['agentcms deploy setup', 'agentcms deploy doctor', 'agentcms deploy plan', 'agentcms deploy staging'] as $command) {
            $at = mb_strpos($text, $command);
            $this->assertNotFalse($at, "{$command} が無い");
            $this->assertLessThan($at, $settings, "{$command} がデプロイ設定より先に出ている");
        }
    }

    private function between(string $text, string $from, string $to): string
    {
        $start = mb_strpos($text, $from);
        $this->assertNotFalse($start, "{$from} が見つからない");
        $end = mb_strpos($text, $to, $start);
        $this->assertNotFalse($end, "{$to} が見つからない");

        return mb_substr($text, $start, $end - $start);
    }
}

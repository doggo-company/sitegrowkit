<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Application\Activity\ActivityLabels;
use App\Support\Capability;
use App\Support\Role;
use Tests\TestCase;

/**
 * PHASE WP-BASELINE / STAGE #18A — 画面文言の日本語化（SPEC §2-16）。
 *
 * `SettingsLocalizationTest` / `AuthLocalizationTest` と同じ作り方で
 * **画面のソースを直接見る**（実行時の権限や状態に左右されない）。
 */
final class UserAdminLocalizationTest extends TestCase
{
    /** @var list<string> */
    private const SCREENS = [
        'resources/js/pages/admin/users/Index.vue',
        'resources/js/pages/admin/users/Create.vue',
        'resources/js/pages/admin/users/Edit.vue',
        'resources/js/pages/admin/users/Activity.vue',
        'resources/js/pages/admin/activity/Index.vue',
        'resources/js/components/ActivityTable.vue',
        'resources/js/components/ActivityFilters.vue',
    ];

    public function test_利用者と操作履歴の画面が日本語である(): void
    {
        $source = $this->sources();

        foreach ([
            '利用者', '利用者を招待', '操作履歴', '権限', '状態', 'メールアドレス',
            '名前', '最終ログイン', '招待メールを再送', '無効化する', '再び有効にする',
            '絞り込む', 'CSV で書き出す', '実行者', '結果', '経路', '対象',
            '前へ', '次へ',
        ] as $label) {
            $this->assertStringContainsString($label, $source, "日本語表示「{$label}」が見つからない");
        }

        /*
         * 見るのは **利用者の目に入る文**だけ。単語 1 つで判定すると
         * `changeRole` / `canDeactivate` のような識別子に当たってしまう。
         */
        foreach ([
            'Invite user', '>Deactivate<', 'Activity log', 'Export CSV',
            'Last login', '>Previous<', '>Next<', '>Users<', '>Role<', '>Status<',
        ] as $english) {
            $this->assertStringNotContainsString(
                $english,
                preg_replace('/<script setup lang="ts">.*?<\/script>/s', '', $source) ?? $source,
                "英語表示「{$english}」が残っている",
            );
        }
    }

    /** ロールと権限の表示名がすべて日本語で用意されている */
    public function test_ロールと権限の表示名が日本語である(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertMatchesRegularExpression('/[ぁ-んァ-ヶ一-龠]/u', $role->label(), $role->value);
            $this->assertMatchesRegularExpression('/[ぁ-んァ-ヶ一-龠]/u', $role->description(), $role->value);
        }

        foreach (Capability::cases() as $capability) {
            $this->assertMatchesRegularExpression('/[ぁ-んァ-ヶ一-龠]/u', $capability->label(), $capability->value);
        }
    }

    /** 履歴の操作名・結果・経路が日本語になっている */
    public function test_履歴の表示名が日本語である(): void
    {
        $this->assertSame('コンテンツを作成', ActivityLabels::operation('content.create'));
        $this->assertSame('ログイン失敗', ActivityLabels::operation('auth.login.failed'));
        $this->assertSame('権限なし', ActivityLabels::result('forbidden'));
        $this->assertSame('AI 連携', ActivityLabels::interface('mcp'));

        // 未知の operation は id をそのまま出す（黙って消さない）
        $this->assertSame('unknown.op', ActivityLabels::operation('unknown.op'));
    }

    /** 画面は履歴の値を **テキストとして**描画する（生 HTML を差し込まない） */
    public function test_履歴の描画で生htmlを差し込まない(): void
    {
        $this->assertStringNotContainsString('v-html=', $this->sources());
        $this->assertStringNotContainsString('innerHTML', $this->sources());
    }

    private function sources(): string
    {
        return implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents(base_path($path)),
            self::SCREENS,
        ));
    }
}

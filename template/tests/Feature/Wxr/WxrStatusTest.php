<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\PlanIssue;
use AgentCms\Core\Wxr\Status\WxrStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WordPress の公開状態を、こちらの 2 値へ写す（Phase 9.14 / STAGE 27C・SPEC §C.8）。
 *
 * ## 知らない状態を公開側へ倒さない
 *
 * こちらの `status` は `draft` と `published` の 2 つしかない。
 * 読めなかったものを `published` にすると、**他人のサイトの非公開だった記事が
 * こちらで公開される**。取り消すまでの間、それは世界に出ている。
 *
 * ## 元の値は捨てない
 *
 * 畳んだ後の 2 値だけを持つと、十年分の中から
 * **もともと非公開だったのか、ゴミ箱だったのか**が永久に読めなくなる。
 */
final class WxrStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statuses(): array
    {
        return [
            '公開' => ['publish', 'published'],
            '下書き' => ['draft', 'draft'],
            '非公開' => ['private', 'draft'],
            '承認待ち' => ['pending', 'draft'],
            '予約公開' => ['future', 'draft'],
            'ゴミ箱' => ['trash', 'draft'],
            'revision や添付の内部状態' => ['inherit', 'draft'],
            '未保存の器' => ['auto-draft', 'draft'],
            'プラグインが足した状態' => ['acf-disabled', 'draft'],
            '知らない状態' => ['whatever_this_is', 'draft'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_公開だけが公開になる(string $source, string $expected): void
    {
        $this->assertSame($expected, WxrStatus::map($source)->value);
    }

    public function test_公開以外はすべて下書きへ倒す(): void
    {
        /*
         * **知らない状態を推測して公開しない**（§C.8.2）。
         * 白い一覧に無いものを通す作りだと、プラグインが足した状態が
         * 1 つ増えるたびに公開側へ漏れる。
         */
        foreach (self::statuses() as [$source, $expected]) {
            if ($source === 'publish') {
                continue;
            }

            $this->assertSame('draft', WxrStatus::map($source)->value, $source);
        }
    }

    public function test_公開だけを公開と読む(): void
    {
        // 前後の空白や大文字は WordPress の値として正しくない。**寄せて読まない**
        foreach (['Publish', 'PUBLISH', ' publish', 'publish '] as $notPublish) {
            $this->assertSame('draft', WxrStatus::map($notPublish)->value, $notPublish);
        }
    }

    // ── 元の値をそのまま残す ────────────────────────────────

    public function test_元の値はそのまま残る(): void
    {
        foreach (['publish', 'private', 'auto-draft', 'acf-disabled'] as $source) {
            $this->assertSame($source, WxrStatus::preserve($source));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadable(): array
    {
        return [
            '空' => [''],
            '空白だけ' => ['   '],
            '改行を含む' => ["publish\ndraft"],
            '空白を含む' => ['not published'],
            '記号を含む' => ['publish;drop'],
            '日本語' => ['公開'],
            '桁を超える' => [str_repeat('a', 21)],
        ];
    }

    /**
     * 読めない値は**保存せず止める**（§C.8.4）。
     *
     * 切り詰めれば元へ戻せず、置き換えれば別の値を捏造し、
     * 黙って落とせばその 1 件だけ出自が消える。3 つとも取れない。
     */
    #[DataProvider('unreadable')]
    public function test_識別子の形でない状態は残せない(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);

        WxrStatus::preserve($source);
    }

    public function test_ちょうど桁に収まる状態は残せる(): void
    {
        // `wp_posts.post_status` は varchar(20)
        $this->assertSame(str_repeat('a', 20), WxrStatus::preserve(str_repeat('a', 20)));
    }

    public function test_桁を超える値を切り詰めも置き換えも黙殺もしない(): void
    {
        /*
         * **3 つとも取れない**（§C.8.4）。
         * 切り詰めれば元へ戻せず、置き換えれば別の値を捏造し、
         * 黙って落とせばその 1 件だけ出自が消える。
         */
        $tooLong = str_repeat('a', 21);

        $this->assertFalse(WxrStatus::canPreserve($tooLong));

        try {
            WxrStatus::preserve($tooLong);
            $this->fail('桁を超える値が残せてしまいました');
        } catch (InvalidArgumentException $e) {
            // 切り詰めていない
            $this->assertStringNotContainsString(str_repeat('a', 20), $e->getMessage());
            // 置き換えていない（別の状態名を返していない）
            $this->assertStringNotContainsString('draft', $e->getMessage());
        }
    }

    public function test_ハイフンを含む正規の状態を弾かない(): void
    {
        /*
         * `auto-draft` は WordPress core の正規の状態である。
         * 他所で使っている `/^[a-z][a-z0-9_]{0,31}$/` を流用すると
         * **ハイフンが無いのでこれを弾く**。§C.8.4 の形が正である。
         *
         * コメント側の `post-trashed`（§C.4）も同じ形なので、
         * ハイフンを通せないと投稿とコメントの両方で取りこぼす。
         */
        foreach (['auto-draft', 'post-trashed'] as $source) {
            $this->assertSame($source, WxrStatus::preserve($source));
        }
    }

    public function test_未保存の器は通って畳まれて残る(): void
    {
        /*
         * **3 つの経路をひと続きで確かめる。** どれか 1 つでも欠けると、
         * 「形は通るが畳めない」「畳めるが残らない」が起きる。
         */
        $this->assertTrue(WxrStatus::canPreserve('auto-draft'));
        $this->assertSame('draft', WxrStatus::map('auto-draft')->value);
        $this->assertSame('auto-draft', WxrStatus::preserve('auto-draft'));
    }

    public function test_大文字の値は残せるが公開にはしない(): void
    {
        /*
         * **畳む前に小文字へ寄せない。**
         *
         * `Publish` は識別子の形としては正しいので**そのまま残せる**。
         * だが WordPress の値としては正しくないので、こちらが知っている
         * 状態ではない。寄せて `publish` と読むのは
         * **知らない大文字の値を publish だと推測して公開する**ことで、
         * §C.8.2 が名指しで禁じている当て推量そのものである。
         */
        $this->assertSame('Publish', WxrStatus::preserve('Publish'));
        $this->assertSame('draft', WxrStatus::map('Publish')->value);
    }

    public function test_読めない状態は1件目で止めず全件集める(): void
    {
        /*
         * §A.3 のとおり、見つけた順に投げると「最初の 1 件」しか出せず、
         * **直しては実行しを繰り返させる**。読めない値も同じで、
         * 何件・どの id かを 1 回で受け取れるようにする。
         *
         * `canPreserve()` が投げずに答えるのは、そのためである。
         */
        $findings = new PlanFindings;

        foreach ([
            ['101', 'publish'],
            ['102', 'not published'],
            ['103', 'private'],
            ['104', str_repeat('a', 21)],
            ['105', ''],
        ] as [$postId, $source]) {
            if (! WxrStatus::canPreserve($source)) {
                $findings->add(PlanIssue::SourceStatusUnreadable, $postId, 'post');
            }
        }

        $found = $findings->toArray()['source_status_unreadable'];

        $this->assertSame(3, $found['total']);
        $this->assertSame(
            ['102', '104', '105'],
            array_column($found['shown'], 'external_id'),
        );
    }

    public function test_読めない値でも写像そのものは止まらない(): void
    {
        /*
         * 写像と保存は別の話である。読めない値は「公開ではない」と読めるので
         * 下書きへ倒せる。止めるのは**残せないこと**の方で、
         * そちらは findings が担う。
         */
        $this->assertSame('draft', WxrStatus::map('not published')->value);
    }
}

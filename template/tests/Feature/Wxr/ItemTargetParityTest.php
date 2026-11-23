<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\PlanIssue;
use AgentCms\Core\Wxr\WxrItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 「どこへ入るか」と「なぜ入らないか」がずれないこと（Phase 9.14 / STAGE 27D-3）。
 *
 * ## なぜこの検査が要るのか
 *
 * `plan` と `apply` は**同じ item を同じ先へ**入れなければならない。
 * それぞれが自前で決めると、片方だけ直したときに
 * **plan が言ったのと違う場所へ入る**。しかも plan hash はそれを見つけられない —
 * hash は plan 側の判断から作るので、**両方が同じ誤りをすれば一致してしまう**。
 *
 * だから決めるのは `ItemTarget::of()` 1 か所で、`plan` も `apply` もそこを呼ぶ。
 *
 * ## それでも 2 つ残るもの
 *
 * `of()` は理由を返さない（`apply` は理由を数えないので、持たせると二度数える）。
 * そのため `plan` 側に「なぜ入らないか」を確かめる道が残り、
 * **確かめる順が `of()` とずれると、入らないのに理由が出ない（または逆）**が起きる。
 * ここはその 2 つの一致を固定する網である。
 */
final class ItemTargetParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string|null, 4: PlanIssue|null}>
     */
    public static function items(): array
    {
        return [
            // 種類 / 外部 id / --media / 入る先（null なら入らない）/ 入らない理由
            '普通の記事' => ['news', '11', false, 'news', null],
            'ページ' => ['page', '11', false, 'page', null],
            'ページ（id が桁を超える）' => ['page', str_repeat('1', 65), false, null, PlanIssue::ExternalIdTooLong],
            'メニュー項目' => ['nav_menu_item', '11', false, null, PlanIssue::SkippedNavMenuItem],
            '添付（media 無し）' => ['attachment', '11', false, null, PlanIssue::SkippedAttachment],
            '添付（media 有り）' => ['attachment', '11', true, 'media', null],
            '知らない型' => ['does_not_exist', '11', false, null, PlanIssue::UnknownType],
            '外部 id が桁ぎりぎり' => ['news', '11111111111111111111', false, 'news', null],
            '外部 id が桁を超える' => ['news', '111111111111111111111111111111111111111111111111111111111111111111', false, null, PlanIssue::ExternalIdTooLong],
        ];
    }

    #[DataProvider('items')]
    public function test_入る先の判定と理由の判定がずれない(
        string $postType,
        string $postId,
        bool $media,
        ?string $expected,
        ?PlanIssue $reason,
    ): void {
        $item = $this->item($postType, $postId);
        $options = ImportOptions::fromCli([], [], $media);

        $target = app(ItemTarget::class)->of($item, $options);

        $this->assertSame($expected, $target === null ? null : $target['target'], $postType);

        /*
         * **入らないときは、必ず理由が 1 つ決まる。**
         * `plan` は `of()` が `null` を返したときにだけ理由を数えるので、
         * ここで理由が決まらなければ「入らないのに何も報告されない」になる。
         */
        if ($target === null) {
            /*
             * **理由が在ることだけを見ても足りない。**
             * どの理由かまで固定しないと、`of()` の判定を 1 つ落としたときに
             * 別の理由へ滑って**同じ「入らない」で通ってしまう**
             * （ページの除外を落とすと「知らない型」として入らなくなる、など）。
             */
            $this->assertSame($reason, $this->reasonFor($item, $options), $postType);
        }
    }

    public function test_桁を超える外部idは入らない(): void
    {
        // 台帳の `external_id` は 64 byte
        $item = $this->item('news', str_repeat('1', 65));

        $this->assertNull(app(ItemTarget::class)->of($item, ImportOptions::fromCli([], [], false)));
        $this->assertNotNull($this->reasonFor($item, ImportOptions::fromCli([], [], false)));
    }

    public function test_対応先を指定しても入れない型は入らない(): void
    {
        /*
         * **§A.2 は指定より強い。**
         *
         * `--type=nav_menu_item:news` と書けば既知の型へ向くので、
         * 除外の判定を落とすと**メニュー項目が記事として入ってしまう**。
         * 「入れないと決まっている」は、利用者の指定で覆せてはならない。
         */
        $item = $this->item('nav_menu_item', '11');
        $options = ImportOptions::fromCli(['nav_menu_item:news'], [], false);

        $this->assertNull(app(ItemTarget::class)->of($item, $options));
        $this->assertSame(PlanIssue::SkippedNavMenuItem, $this->reasonFor($item, $options));
    }

    public function test_ページは指定しても記事の型へ向かない(): void
    {
        /*
         * release-20260909 / wxr-pages: ページの入る先は `pages` に固定。
         * `--type=page:news` は効かず、Page として入る（記事にはならない）。
         */
        $item = $this->item('page', '11');
        $options = ImportOptions::fromCli(['page:news'], [], false);

        $target = app(ItemTarget::class)->of($item, $options);

        $this->assertNotNull($target);
        $this->assertSame(ImportKind::Page, $target['kind']);
        $this->assertSame(ItemTarget::PAGE_TARGET, $target['target']);
    }

    public function test_桁を超える取り込み先は入らない(): void
    {
        // 台帳の `target_type` は 64 byte
        $item = $this->item('post', '11');
        $options = ImportOptions::fromCli(['post:'.str_repeat('a', 65)], [], false);

        $this->assertNull(app(ItemTarget::class)->of($item, $options));
    }

    public function test_入る先が決まるなら理由は要らない(): void
    {
        /*
         * 逆向きも固定する。**入るのに理由が出る**と、
         * 取り込みは成立しているのに「入らなかった」と報告される。
         */
        $item = $this->item('news', '11');
        $options = ImportOptions::fromCli([], [], false);

        $this->assertNotNull(app(ItemTarget::class)->of($item, $options));
    }

    public function test_添付は投稿タイプではなくmediaへ向く(): void
    {
        $target = app(ItemTarget::class)->of(
            $this->item('attachment', '11'),
            ImportOptions::fromCli([], [], true),
        );

        $this->assertNotNull($target);
        $this->assertSame(ImportKind::Media, $target['kind']);
    }

    /** `PlanBuilder` が理由を決める道を、外から確かめられる形で呼ぶ */
    private function reasonFor(WxrItem $item, ImportOptions $options): mixed
    {
        $method = new \ReflectionMethod(PlanBuilder::class, 'reasonFor');

        return $method->invoke(app(PlanBuilder::class), $item, $options);
    }

    private function item(string $postType, string $postId): WxrItem
    {
        return new WxrItem(
            postId: $postId,
            postType: $postType,
            status: 'publish',
            title: '記事',
            slug: 'kiji',
            content: '本文',
            excerpt: '',
            publishedAt: null,
            parentId: null,
            attachmentUrl: null,
        );
    }
}

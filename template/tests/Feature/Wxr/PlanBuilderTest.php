<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\FlatTermDefinitionSource;
use AgentCms\Core\Wxr\Ledger\ExistingTermLookup;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use AgentCms\Core\Wxr\Plan\PageFields;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\PlanScan;
use AgentCms\Core\Wxr\Plan\TargetStateResolver;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use AgentCms\Core\Wxr\WxrTermDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 「これから何が起きるか」を組み立てる（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## ここは DB を読むが、1 行も書かない
 *
 * `plan` を見ただけでサイトの状態が変わってはいけない。
 *
 * ## 1 件目で止めない
 *
 * 取り込めない理由は**全部集めてから**返す。見つけた順に投げると、
 * 利用者は直しては実行しを何十回も繰り返すことになる（§A.3）。
 */
final class PlanBuilderTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-plan-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        /*
         * **取り込み先は「公開できる」状態にしておく。**
         *
         * §C.2 は「入れる型が editorial でない、または公開できない状態なら止める」。
         * 設定行が無い型は `public` が既定 false なので、そのままだと
         * どの取り込みも止まる。ここで確かめたいのはそこではない。
         */
        foreach (['post', 'news'] as $type) {
            $this->publish($type);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    // ── 対応先の解決 ────────────────────────────────────────

    public function test_同じ名前の型があれば指定なしで使う(): void
    {
        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'post').'</item>'));

        $this->assertTrue($plan->isUsable());
        $this->assertSame(['create' => 1], $plan->counts());
    }

    public function test_省略と明示指定が同じ意味になる(): void
    {
        /*
         * `--type` を書かずに post→post と解決したときと、
         * `--type=post:post` と明示したときは**同じ取り込み**である。
         * hash が違うと、書き方を変えただけで apply が拒否される。
         */
        $path = $this->write('same.xml', $this->wxr('<item>'.$this->entry('11', 'post').'</item>'));

        $this->assertSame(
            $this->planFor($path)->fingerprint(),
            $this->planFor($path, types: ['post:post'])->fingerprint(),
        );
    }

    public function test_対応先の無い型は止める(): void
    {
        // §C.2「無ければ止める」。数えたうえで止める（§F）
        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'wp_block').'</item>'));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());
        $this->assertSame(1, $plan->findings()['unknown_type']['total']);
    }

    public function test_仕様で入れないものは止めずに数える(): void
    {
        /*
         * メニュー項目は**入れないと決まっている**（§A.2）。
         * 対応先が無くて入れられないのとは別に数える。
         * ページは release-20260909 から入る（`pages` へ）ので、ここでは数えない。
         */
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post').'</item>'
            .'<item>'.$this->entry('12', 'page').'</item>'
            .'<item>'.$this->entry('13', 'nav_menu_item').'</item>'
            .'<item>'.$this->entry('14', 'attachment').'</item>',
        ));

        $this->assertTrue($plan->isUsable());
        $this->assertArrayNotHasKey('skipped_page', $plan->findings());
        $this->assertSame(2, $plan->counts()['create'] ?? 0);
        $this->assertSame(1, $plan->findings()['skipped_nav_menu_item']['total']);
        $this->assertSame(1, $plan->findings()['skipped_attachment']['total']);
    }

    public function test_使われなかった指定を報告するが止めない(): void
    {
        $plan = $this->plan(
            $this->wxr('<item>'.$this->entry('11', 'post').'</item>'),
            types: ['post:post', 'note:post'],
        );

        $this->assertTrue($plan->isUsable());
        $this->assertSame(1, $plan->findings()['unused_type_mapping']['total']);
    }

    public function test_添付はmediaを付けたときだけ取り込む予定に入る(): void
    {
        /*
         * 添付は**投稿タイプへは入らない**（§C.1）。`attachment` という型を
         * 探しに行くと「そんな型は無い」になる。Media として畳む。
         *
         * **plan はここで外へ 1 度も出ない。** 実際に取りに行くのは 27E。
         */
        $xml = $this->wxr(
            '<item>'.$this->entry('11', 'post').'</item>'
            .'<item>'.$this->entry('14', 'attachment')
            .'<wp:attachment_url>https://old.example.test/a.png</wp:attachment_url></item>',
        );

        $without = $this->plan($xml);
        $with = $this->planWithMedia($xml);

        $this->assertTrue($without->isUsable());
        $this->assertSame(1, $without->findings()['skipped_attachment']['total']);
        $this->assertSame(['create' => 1], $without->counts());

        $this->assertTrue($with->isUsable());
        $this->assertArrayNotHasKey('skipped_attachment', $with->findings());
        $this->assertSame(['create' => 2], $with->counts());

        // 取りに行くかどうかは**別の取り込み**である
        $this->assertNotSame($without->fingerprint(), $with->fingerprint());
    }

    public function test_添付のurlはplanのどこにも出さない(): void
    {
        // 外部の在り処は、画面にも台帳にも hash の元にも出さない
        $plan = $this->planWithMedia($this->wxr(
            '<item>'.$this->entry('14', 'attachment')
            .'<wp:attachment_url>https://old.example.test/secret.png</wp:attachment_url></item>',
        ));

        $this->assertStringNotContainsString(
            'old.example.test',
            (string) json_encode([$plan->findings(), $plan->counts(), $plan->fingerprint()]),
        );
    }

    // ── 取り込むが、先に言う ────────────────────────────────

    public function test_shortcodeを含む記事を移行の前に報告する(): void
    {
        /*
         * shortcode は**文字として残す**（§C.5）。だが移行先では動かない。
         * 「移行したら壊れていた」ではなく、**移行する前に**言う。
         */
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post', body: '<p>本文</p>[gallery ids="1,2"]').'</item>',
        ));

        $this->assertTrue($plan->isUsable(), '取り込みは止めない');
        $this->assertSame(1, $plan->findings()['shortcode_present']['total']);
    }

    public function test_shortcodeの本文をplanに持ち込まない(): void
    {
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post', body: '秘密の本文です[gallery]').'</item>',
        ));

        $this->assertStringNotContainsString(
            '秘密の本文',
            (string) json_encode([$plan->findings(), $plan->counts()]),
        );
    }

    public function test_入れ先の無いmetaを先に報告する(): void
    {
        /*
         * `wp_meta` の受け皿が型に無ければ、その meta は入らない（§C.7）。
         * **黙って落とさない。**
         */
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post')
            .'<wp:postmeta><wp:meta_key><![CDATA[subtitle]]></wp:meta_key>'
            .'<wp:meta_value><![CDATA[副題]]></wp:meta_value></wp:postmeta></item>',
        ));

        $this->assertTrue($plan->isUsable());
        $this->assertSame(1, $plan->findings()['meta_dropped']['total']);
    }

    public function test_大きい本文を先に報告する(): void
    {
        /*
         * 取り込みはする。だが**移行のあとで「表示が重い」と気づく**前に言う。
         */
        $huge = str_repeat('あ', 400_000);   // 約 1.2MB

        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post', body: $huge).'</item>',
        ));

        $this->assertTrue($plan->isUsable(), '取り込みは止めない');
        $this->assertSame(1, $plan->findings()['large_body']['total']);
    }

    public function test_使われなかった分類の指定も報告する(): void
    {
        // `--taxonomy` に書いたのに WXR へ 1 件も無い、は書き間違いの可能性が高い
        $plan = $this->plan(
            $this->wxr('<item>'.$this->entry('11', 'post').'</item>'),
            taxonomies: ['nowhere:news_category'],
        );

        $this->assertTrue($plan->isUsable());
        $this->assertSame(1, $plan->findings()['unused_taxonomy_mapping']['total']);
    }

    // ── 1 件目で止めない ────────────────────────────────────

    public function test_複数の問題を1件目で止めずに全部出す(): void
    {
        /*
         * **直しては実行し、を繰り返させない。**
         * 型も分類も足りない WXR で、両方が 1 回の実行で出ること。
         */
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'wp_block').'</item>'
            .'<item>'.$this->entry('12', 'wp_navigation').'</item>'
            .'<item>'.$this->entry('13', 'post')
            .'<category domain="unknown_tax" nicename="x"><![CDATA[X]]></category></item>',
        ));

        $findings = $plan->findings();

        $this->assertFalse($plan->isUsable());
        $this->assertSame(2, $findings['unknown_type']['total']);
        $this->assertArrayHasKey('missing_taxonomy', $findings);
    }

    // ── 受け皿の状態を取り違えない ──────────────────────────

    public function test_定義が無いのと同期していないのを区別する(): void
    {
        /*
         * **次にやることが違う。**
         * 定義が無いならファイルを作るところから。同期していないだけなら
         * `agentcms:taxonomy-sync` を走らせるだけでよい。
         * 混ぜると、既に在るファイルをもう一度作ろうとすることになる。
         */
        $xml = $this->wxr(
            '<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>',
        );

        // 定義はあるが未同期
        $notSynced = $this->plan($xml);

        $this->assertArrayHasKey('taxonomy_not_synced', $notSynced->findings());
        $this->assertArrayNotHasKey('missing_taxonomy', $notSynced->findings());

        // 定義そのものが無い
        $missing = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post')
            .'<category domain="nowhere" nicename="a"><![CDATA[A]]></category></item>',
        ));

        $this->assertArrayHasKey('missing_taxonomy', $missing->findings());
        $this->assertArrayNotHasKey('taxonomy_not_synced', $missing->findings());
    }

    public function test_型が宣言していない分類への割り当てを止める(): void
    {
        /*
         * **黙って落とさない。** 割り当ては書かれているのに入れる先が無い。
         * 落とすと、分類の付いていない記事が十年分できる。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'post')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>',
        ));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['taxonomy_not_declared_for_type']['total']);
    }

    public function test_受け皿が揃っていれば通る(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'a', 'A')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>',
        ));

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    // ── 分類項目の親子と重複 ────────────────────────────────

    /**
     * 🔴 **分類に定義が 1 件も無いときは、いまは平らな項目として作る**
     * （WP 一周 2026-09-04・`FlatTermDefinitionSource`）。
     *
     * 以前はここで止めていたが、実物の書き出し（`<wp:category>` を
     * 1 件も持たない形）が実在し、「書き出し直してください」は
     * サイトが既に閉じている案件では実行できないと分かったので既定を変えた。
     * 旧い判断は `test_割り当てに定義が無ければ止める_strict指定時` に残す。
     */
    public function test_分類の定義が1件も無ければ平らな項目を作る(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>',
        ));

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($plan->fingerprint());
        $this->assertArrayNotHasKey('term_definition_missing', $plan->findings());

        // **止めない。だが黙って作らない。** どの分類を平らにしたかは分かる
        $this->assertSame(1, $plan->findings()['flat_taxonomy_term_created']['total']);
        $this->assertSame(
            ['news_category' => 1],
            $plan->findings()['flat_taxonomy_term_created']['by_source'],
        );
    }

    /**
     * `--strict-term-definitions` を付ければ、旧い判断（止める）に戻せる。
     */
    public function test_割り当てに定義が無ければ止める_strict指定時(): void
    {
        /*
         * **黙って落とさない。** 割り当ては書かれているのに、
         * その項目が `<channel>` 直下に定義されていない。
         * 落とすと、分類の付いていない記事が十年分できる。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $path = $this->write('strict.xml', $this->wxr(
            '<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>',
        ));

        // 4 番目の引数（`flattenMissingTermDefinitions`）を false にする＝ `--strict-term-definitions`
        $plan = app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], [], false, false));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());

        // 定義が無いのだから term_id は無い。**記事の側**で指す
        $this->assertSame(
            [['external_id' => '11', 'source_type' => 'term']],
            $plan->findings()['term_definition_missing']['shown'],
        );
    }

    /**
     * 同じ分類に**他の定義はある**のに、この記事が指す項目だけ定義が無いときは、
     * 分類そのものに定義が無いのとは違い、**従来どおり止める**
     * （書き出しが壊れている・途中で切れているなどの疑いがある）。
     */
    public function test_同じ分類に他の定義があっても一部の割り当てだけ定義が無ければ止める(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'b', 'B')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>',
        ));

        $this->assertFalse($plan->isUsable());
        $this->assertArrayNotHasKey('flat_taxonomy_term_created', $plan->findings());
        $this->assertSame(
            [['external_id' => '11', 'source_type' => 'term']],
            $plan->findings()['term_definition_missing']['shown'],
        );
    }

    public function test_定義が後ろに書かれていても割り当ては通る(): void
    {
        /*
         * **XML に書かれた順に頼らない。**
         *
         * 定義と item は別々の走査で読むので、item が先に書かれていても
         * 照合するときには定義が出そろっている。順に頼る作りにすると、
         * 書き出し方が違うだけの WXR で**全部の割り当てが「定義が無い」になる**。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="a"><![CDATA[A]]></category></item>'
            .$this->termDefinition('1', 'news_category', 'a', 'A'),
        ));

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_読み替えた後の受け皿で定義と割り当てを照合する(): void
    {
        /*
         * `--taxonomy=category:news_category` を付けると、割り当ての側は
         * `news_category` を指す。定義の側だけ `category` のままにすると、
         * **指定を付けた途端に全部の割り当てが「定義が無い」になる**。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan(
            $this->wxr(
                $this->termDefinition('1', 'category', 'a', 'A')
                .'<item>'.$this->entry('11', 'news')
                .'<category domain="category" nicename="a"><![CDATA[A]]></category></item>',
            ),
            taxonomies: ['category:news_category'],
        );

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_どの記事も使っていない定義でも受け皿は要る(): void
    {
        /*
         * §C.3 は「既存の分類へ項目を足す」であって、**使われていないから
         * 消す、という決定はどこにも無い**。受け皿が無ければ止める。
         */
        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'does_not_exist', 'a', 'A')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['missing_taxonomy']['total']);
    }

    public function test_親子が壊れていれば指紋を出さない(): void
    {
        // 辿ると戻る親を入れてから気づいても、元の形は分からない
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'a', 'A', 'b')
            .$this->termDefinition('2', 'news_category', 'b', 'B', 'a')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());
        $this->assertSame(2, $plan->findings()['term_parent_cycle']['total']);
    }

    public function test_綴りを当てただけなら指紋は出す(): void
    {
        /*
         * 日本語のサイトでは**当てるのが普通の経路**である。
         * ここで止めると、日本語の WXR はどれも取り込めない。
         * URL が元と変わることは言い、止めはしない。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'お知らせ', 'お知らせ')
            .$this->termDefinition('2', 'news_category', 'お知らせ', 'お知らせ')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="お知らせ"><![CDATA[お知らせ]]></category></item>',
        ));

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($plan->fingerprint());

        $findings = $plan->findings();

        $this->assertSame(1, $findings['term_slug_generated']['total']);
        $this->assertSame(1, $findings['term_definition_repeated']['total']);
    }

    public function test_分類項目を読んでも1行も書かない(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $path = $this->write('terms.xml', $this->wxr(
            $this->termDefinition('1', 'news_category', 'お知らせ', 'お知らせ')
            .$this->termDefinition('2', 'news_category', 'b', 'B', 'お知らせ')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="b"><![CDATA[B]]></category></item>',
        ));

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->planFor($path);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    // ── 既にあるものへは書かない ────────────────────────────

    public function test_台帳に無ければ作る(): void
    {
        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $plan->counts()['create'] ?? 0);
    }

    public function test_台帳にあって対象も健全なら触らない(): void
    {
        /*
         * **V1 は既にあるものへ書かない**（`PlanAction` の docblock）。
         * 台帳は「取り込みの後に人が直したか」を見分ける材料を持たないので、
         * 更新すると**移行後に手を入れた記事を黙って上書きする**。
         */
        $this->ledger('content', 'news', '11', $this->news());

        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $plan->counts()['skip_existing'] ?? 0);
        $this->assertSame(0, $plan->counts()['create'] ?? 0);
    }

    public function test_台帳にあるのに対象が消えていれば止める(): void
    {
        $this->ledger('content', 'news', '11', '999999');

        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());
        $this->assertSame(1, $plan->findings()['ledger_target_missing']['total']);
        // **止まっても行動は積む**（数が合わなくなる）
        $this->assertSame(1, $plan->counts()['conflict'] ?? 0);
    }

    public function test_対象がゴミ箱にあれば止める(): void
    {
        $this->ledger('content', 'news', '11', $this->news(trashed: true));

        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['ledger_target_trashed']['total']);
        $this->assertSame(1, $plan->counts()['conflict'] ?? 0);
    }

    public function test_別の型に入っていれば止める(): void
    {
        $this->ledger('content', 'post', '11', $this->news());

        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['ledger_conflict']['total']);
        $this->assertSame(1, $plan->counts()['conflict'] ?? 0);
    }

    // ── 分類項目とコメントの行動 ────────────────────────────

    public function test_分類項目を作る予定に入れる(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        // 記事 1 件と分類項目 1 件
        $this->assertSame(2, $plan->counts()['create'] ?? 0);
    }

    public function test_同じ綴りの項目が既にあれば作らずに使う(): void
    {
        /*
         * §C.3 は「既存の分類へ**項目だけ**足す」である。既にある項目には
         * 足しようがないので、**作らずに使う**。作っていないものを
         * 取り消しで消してはならないので、`create` とは別に数える。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->existingTerm('news_category', 'oshirase');

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        $this->assertSame(1, $plan->counts()['reuse'] ?? 0);
        // 記事の分だけが create
        $this->assertSame(1, $plan->counts()['create'] ?? 0);
    }

    public function test_重なった定義は代表を使い回す(): void
    {
        /*
         * 台帳は `wp:term_id` ごとに 1 行を持つので、重なった id にも行が要る。
         * だが**対象を作るのは代表 1 件だけ**なので、2 件目は作らない。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .$this->termDefinition('2', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        $this->assertSame(1, $plan->counts()['reuse'] ?? 0);
        $this->assertSame(2, $plan->counts()['create'] ?? 0);
    }

    public function test_台帳にある分類項目は触らない(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->ledger('term', 'news_category', '1', $this->existingTerm('news_category', 'oshirase'));

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        $this->assertSame(1, $plan->counts()['skip_existing'] ?? 0);
        $this->assertSame(0, $plan->counts()['reuse'] ?? 0);
    }

    public function test_コメントを作る予定に入れる(): void
    {
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news').$this->comment('501').'</item>',
        ));

        // 記事 1 件とコメント 1 件
        $this->assertSame(2, $plan->counts()['create'] ?? 0);
    }

    public function test_記事が済んでいてもコメントは別に数える(): void
    {
        /*
         * **半端に終わった取り込みを続けるとき、「記事は入っているが
         * コメントは入っていない」が普通に起こる。**
         * 記事の状態でコメントを決めると、その分が黙って落ちる。
         */
        $this->ledger('content', 'news', '11', $this->news());

        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news').$this->comment('501').'</item>',
        ));

        $this->assertSame(1, $plan->counts()['skip_existing'] ?? 0);
        $this->assertSame(1, $plan->counts()['create'] ?? 0);
    }

    public function test_コメントには使い回しが無い(): void
    {
        // 既存のコメントへ寄せる意味は無い（同じ文面でも別の投稿である）
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news').$this->comment('501').'</item>',
        ));

        $this->assertArrayNotHasKey('reuse', $plan->counts());
    }

    public function test_保管された項目へ割り当てが向いたら止める(): void
    {
        /*
         * **分類の保管と、項目の保管は別の守りが要る。**
         *
         * `taxonomy_archived` は分類そのものを見る守りで、項目の保管は見ていない。
         * `ContentTaxonomyAssignments` は**新しく付ける**保管項目を 1 件ずつ拒む
         * （既に付いているものは残す）。取り込みの割り当ては必ず新規なので、
         * ここで止めないと**記事が 1 本ずつ検証エラーになる**。
         * §F の「止めるは plan の段階で全部出す」に反する。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->existingTerm('news_category', 'oshirase', archived: true);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>',
        ));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());

        $found = $plan->findings()['term_archived'];

        $this->assertSame(1, $found['total']);
        // **記事の側**で指す（どの記事が分類を失うかが分かる）
        $this->assertSame(
            [['external_id' => '11', 'source_type' => 'term']],
            $found['shown'],
        );
    }

    public function test_保管された項目を指す記事の数だけ数える(): void
    {
        // 「直す先は 1 つだから 1 件」に畳むと、**何本の記事が分類を失うか**が出ない
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->existingTerm('news_category', 'oshirase', archived: true);

        $body = $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ');

        for ($i = 1; $i <= 120; $i++) {
            $body .= '<item>'.$this->entry((string) $i, 'news')
                .'<category domain="news_category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>';
        }

        $found = $this->plan($this->wxr($body))->findings()['term_archived'];

        $this->assertSame(120, $found['total']);
        $this->assertCount(PlanFindings::DETAIL_CAP, $found['shown']);
    }

    public function test_誰も指していない保管項目では止めない(): void
    {
        /*
         * **止める理由は、利用者が対処できるものでなければならない。**
         *
         * 誰も指していない保管項目で止めると、記事は 1 本も失われないのに
         * 移行だけが止まる。しかも直す先が無い（その項目は取り込みと無関係である）。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->existingTerm('news_category', 'oshirase', archived: true);

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news').'</item>',
        ));

        $this->assertArrayNotHasKey('term_archived', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_保管されていない項目は従来どおり使い回す(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->existingTerm('news_category', 'oshirase');

        $plan = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>',
        ));

        $this->assertArrayNotHasKey('term_archived', $plan->findings());
        $this->assertSame(1, $plan->counts()['reuse'] ?? 0);
    }

    public function test_分類の保管と項目の保管を混ぜない(): void
    {
        /*
         * 直す先が違う。分類の側はその分類を使えるようにする（または対応先を変える）、
         * 項目の側はその項目を戻すか割り当て先を変える。
         */
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->existingTerm('news_category', 'oshirase', archived: true);
        DB::table('taxonomies')->where('key', 'news_category')->update(['status' => 'archived']);

        $found = $this->plan($this->wxr(
            $this->termDefinition('1', 'news_category', 'oshirase', 'お知らせ')
            .'<item>'.$this->entry('11', 'news')
            .'<category domain="news_category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>',
        ))->findings();

        $this->assertSame(1, $found['taxonomy_archived']['total']);
        $this->assertSame(1, $found['term_archived']['total']);
    }

    // ── 窓を跨いでも取りこぼさない ──────────────────────────

    /**
     * @return list<array{0: int}>
     */
    public static function windowSizes(): array
    {
        // 1000 で割り切れない**端数**が黙って抜ける経路を、桁で塞ぐ
        return [[1], [999], [1000], [1001]];
    }

    #[DataProvider('windowSizes')]
    public function test_窓の端数まで数える(int $count): void
    {
        $body = '';

        for ($i = 1; $i <= $count; $i++) {
            $body .= '<item>'.$this->entry((string) $i, 'news').'</item>';
        }

        $plan = $this->plan($this->wxr($body));

        $this->assertSame($count, $plan->counts()['create'] ?? 0);
    }

    public function test_窓の切れ目は結果を変えない(): void
    {
        /*
         * 行動は畳んで hash になるので、**並びが変われば別の plan になる**。
         * 窓は読み取りの都合であって取り込みの意味ではないので、
         * どこで切っても同じ指紋にならなければならない。
         *
         * 同じ WXR を 2 回通して比べるだけでは足りない — 窓ごとに並べ替える実装でも
         * **2 回とも同じように並べ替わる**ので通ってしまう。
         * 窓の大きさを変えて比べる。
         */
        $body = '';

        for ($i = 1; $i <= 7; $i++) {
            $body .= '<item>'.$this->entry((string) $i, 'news').'</item>';
        }

        $path = $this->write('window.xml', $this->wxr($body));

        $wide = $this->planFor($path)->fingerprint();

        $narrow = (new class(app(ContentTypeResolver::class), app(TargetStateResolver::class), app(LedgerReconciler::class), app(ItemTarget::class), app(ExistingTermLookup::class), app(FlatTermDefinitionSource::class), app(PageFields::class)) extends PlanBuilder
        {
            // 7 件を 3 / 3 / 1 の 3 つの窓に割る
            protected const WINDOW = 3;
        })->build($path, ImportOptions::fromCli([], [], false))->fingerprint();

        $this->assertNotNull($wide);
        $this->assertSame($wide, $narrow);
    }

    public function test_照合そのものが窓の大きさを断る(): void
    {
        /*
         * いまは必ず `LedgerLookup::inspect()` を通るので実質守られているが、
         * それは**呼び出しの順番に頼った守り**である。
         * 別の経路から呼ばれた瞬間に効かなくなるので、照合の側でも切る。
         *
         * 例外の文面で**どちらの層が断ったか**を見分ける。
         * 照合側の検査を外すと、下の層が断るので例外は出るが、文面が変わる。
         */
        $keys = [];

        for ($i = 1; $i <= 1001; $i++) {
            $keys[] = new LedgerKey(ImportKind::Content, 'news', (string) $i);
        }

        try {
            app(LedgerReconciler::class)->reconcile($keys, new PlanFindings);
            $this->fail('1001 件が通ってしまいました');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('照合', $e->getMessage());
        }
    }

    public function test_件数を増やしても問い合わせは比例しない(): void
    {
        // 1 件ずつ引くと、十年分では数万回になる
        $few = $this->queriesForItems(10);
        $many = $this->queriesForItems(400);

        $this->assertSame($few, $many, '問い合わせが件数に比例しています');
    }

    public function test_記事と添付は台帳の別の種類として引かれる(): void
    {
        /*
         * WordPress の `wp:post_id` は `wp_posts` 全体で一意なので、同じ WXR の中で
         * 記事と添付が同じ id を持つことはない（reader もそれを断る）。
         * ぶつかり得るのは**前の取り込みが残した台帳**との間である。
         *
         * 添付として 11 番を入れた台帳が残っているところへ、記事の 11 番を持つ
         * WXR が来る。種類を鍵から外すと、**記事が「もう入っている」と読まれて飛ばされる**。
         */
        $this->ledger('media', 'media', '11', '1');

        $plan = $this->plan($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertSame(1, $plan->counts()['create'] ?? 0);
        $this->assertSame(0, $plan->counts()['skip_existing'] ?? 0);
    }

    public function test_添付も台帳を見て飛ばす(): void
    {
        $this->ledger('media', 'media', '11', $this->media());

        $plan = $this->planWithMedia($this->wxr(
            '<item>'.$this->entry('11', 'attachment')
            .'<wp:attachment_url>https://example.test/a.jpg</wp:attachment_url></item>',
        ));

        $this->assertSame(1, $plan->counts()['skip_existing'] ?? 0);
        $this->assertSame(0, $plan->counts()['create'] ?? 0);
    }

    // ── 公開状態 ────────────────────────────────────────────

    public function test_公開でない状態は下書きへ落として数える(): void
    {
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news', 'private').'</item>'
            .'<item>'.$this->entry('12', 'news', 'trash').'</item>'
            .'<item>'.$this->entry('13', 'news', 'trash').'</item>',
        ));

        $found = $plan->findings()['status_downgraded_to_draft'];

        $this->assertSame(3, $found['total']);
        $this->assertSame(['private' => 1, 'trash' => 2], $found['by_source']);
        // 取り込みは止めない
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_落ちていないものは数えない(): void
    {
        /*
         * `draft` → `draft` も `publish` → `published` も**落ちていない**（§C.8.5）。
         * 数に入れると、利用者が見るのは「失われた情報の量」ではなく
         * ただの下書きの総数になる。
         */
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news', 'publish').'</item>'
            .'<item>'.$this->entry('12', 'news', 'draft').'</item>',
        ));

        $this->assertArrayNotHasKey('status_downgraded_to_draft', $plan->findings());
    }

    public function test_読めない状態は止める(): void
    {
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news', 'not published').'</item>'
            .'<item>'.$this->entry('12', 'news', 'publish').'</item>',
        ));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());
        $this->assertSame(1, $plan->findings()['source_status_unreadable']['total']);
        // **止まっても行動は積む**（status は行動を変えない）
        $this->assertSame(2, $plan->counts()['create'] ?? 0);
    }

    public function test_読めない状態を1件目で止めず全部数える(): void
    {
        $plan = $this->plan($this->wxr(
            '<item>'.$this->entry('11', 'news', 'not published').'</item>'
            .'<item>'.$this->entry('12', 'news', 'also broken').'</item>',
        ));

        $this->assertSame(2, $plan->findings()['source_status_unreadable']['total']);
    }

    // ── 効かなかった指定も言う ──────────────────────────────

    public function test_入れない型への指定が無視されたことを言う(): void
    {
        /*
         * §A.2 は利用者の指定より強いので `--type=nav_menu_item:news` は効かない。
         * だが**効かなかったことを言わない**と、利用者は入ったつもりで待つ。
         *
         * `skipped_nav_menu_item` は「入れなかった」であって、指定の話ではない。
         * `unused_type_mapping` にも載らない — WXR に実在したので
         * 「使われた」側に数えられるためである。**3 つ目の状態がここにある。**
         */
        $plan = $this->plan(
            $this->wxr('<item>'.$this->entry('11', 'nav_menu_item').'</item>'),
            types: ['nav_menu_item:news'],
        );

        $found = $plan->findings();

        $this->assertSame(1, $found['excluded_type_mapping']['total']);
        $this->assertSame('nav_menu_item', $found['excluded_type_mapping']['shown'][0]['external_id']);
        $this->assertSame(1, $found['skipped_nav_menu_item']['total']);
        $this->assertArrayNotHasKey('unused_type_mapping', $found);

        // **取り込み自体は成立する**ので止めない
        $this->assertTrue($plan->isUsable(), json_encode($found, JSON_UNESCAPED_UNICODE));
    }

    public function test_ページは記事の型へ向けられない(): void
    {
        /*
         * release-20260909 / wxr-pages: ページは `pages` へ入る。
         * `--type=page:news` と書いても記事にはならない — 入る先は 1 つに固定。
         * 指定は効かず、`page` は Page として数えられる。
         */
        $plan = $this->plan(
            $this->wxr('<item>'.$this->entry('11', 'page').'</item>'),
            types: ['page:news'],
        );

        $this->assertArrayNotHasKey('skipped_page', $plan->findings());
        $this->assertSame(1, $plan->counts()['create'] ?? 0);
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_定義にだけ現れる分類も指紋の材料に載る(): void
    {
        /*
         * **これが赤くなるときは、apply 側の関係の組み直しが成り立たなくなっている。**
         *
         * apply は `plan` の `TermGraph` を持ち回らず、同じ入力から組み直す。
         * それが安全なのは、**関係に効く `--taxonomy` の指定が必ず指紋に載る**からで、
         * その根拠は `PlanScan::effective()` の分類が `sourceTaxonomies`
         * （定義と割り当ての**両方**から立つ）から作られていることである。
         *
         * ここが割り当てからしか立たない集合に変わると、
         * **どの item も使っていない分類の指定が指紋から抜け**、
         * plan と違う関係で apply が走り得る。
         *
         * 指紋そのものを比べる形では**この違いを捕まえられない** — 指定が無い側は
         * 受け皿が無くて plan ごと止まり、指紋が null になるので、
         * 実装が壊れていても「違う」で通ってしまう。材料の側を直接見る。
         */
        $scan = new PlanScan;

        // 定義にだけ現れる分類（どの item も使っていない）
        $scan->addTermDefinition(
            new WxrTermDefinition('1', 'category', 'oshirase', 'お知らせ', null),
            'news_category',
        );

        $effective = $scan->effective(ImportOptions::fromCli([], ['category:news_category'], false));

        $this->assertSame(['category' => 'news_category'], $effective->taxonomies);
    }

    // ── 途中で差し替えられたら止める ────────────────────────

    public function test_解析中にファイルが差し替わったら止める(): void
    {
        /*
         * 指紋と中身は別々に開く。その間に差し替えられると
         * **「指紋は前のファイル・中身は後のファイル」の plan** ができる。
         */
        $path = $this->write('swap.xml', $this->wxr('<item>'.$this->entry('11', 'post').'</item>'));

        $swapped = $this->wxr('<item>'.$this->entry('99', 'post').'</item>');

        /*
         * 差し込み点は `protected` にしてある。`Closure` を公開の引数にすると
         * **本番の口にテスト用の穴が開く**ので、ここでだけ差し替える。
         */
        $builder = new class(app(ContentTypeResolver::class), app(TargetStateResolver::class), app(LedgerReconciler::class), app(ItemTarget::class), app(ExistingTermLookup::class), app(FlatTermDefinitionSource::class), app(PageFields::class)) extends PlanBuilder
        {
            public string $path = '';

            public string $swapped = '';

            protected function afterItem(): void
            {
                // 読んでいる最中に中身が変わる
                file_put_contents($this->path, $this->swapped);
            }
        };

        $builder->path = $path;
        $builder->swapped = $swapped;

        $plan = $builder->build($path, ImportOptions::fromCli([], [], false));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());
        $this->assertArrayHasKey('source_changed_during_plan', $plan->findings());
    }

    // ── 1 行も書かない ──────────────────────────────────────

    public function test_planは1行も書かない(): void
    {
        $path = $this->write('read-only.xml', $this->wxr('<item>'.$this->entry('11', 'post').'</item>'));

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->planFor($path);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    public function test_書き込みの検知そのものが成立している(): void
    {
        /*
         * **通ることしか確かめない検査は保証にならない。**
         * わざと書いてみて、監視も中身の比較も**赤くなれること**を確かめる。
         */
        $before = $this->snapshot();
        $writes = $this->watchWrites();

        DB::table('content_types')->where('content_type_id', 'post')
            ->update(['menu_order' => 7, 'updated_at' => now()]);

        $this->assertNotSame([], $writes(), '書き込みを見逃している');
        $this->assertNotSame($before, $this->snapshot(), '中身の変化を見逃している');
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @param list<string> $types */
    private function plan(string $xml, array $types = [], array $taxonomies = []): WxrPlan
    {
        return $this->planFor($this->write('plan.xml', $xml), $types, $taxonomies);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $taxonomies
     */
    private function planFor(string $path, array $types = [], array $taxonomies = []): WxrPlan
    {
        return app(PlanBuilder::class)->build($path, ImportOptions::fromCli($types, $taxonomies, false));
    }

    private function planWithMedia(string $xml): WxrPlan
    {
        return app(PlanBuilder::class)->build(
            $this->write('media.xml', $xml),
            ImportOptions::fromCli([], [], true),
        );
    }

    /** 取り込み先として使える（公開できる）状態にする */
    private function publish(string $type): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $type],
            [
                'status' => 'active',
                'public_slug' => $type,
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** `<channel>` 直下の**定義**（割り当てとは別物 — §C.3） */
    private function termDefinition(
        string $termId,
        string $taxonomy,
        string $slug,
        string $name,
        string $parentSlug = '',
    ): string {
        return '<wp:term>'
            ."<wp:term_id>{$termId}</wp:term_id>"
            ."<wp:term_taxonomy>{$taxonomy}</wp:term_taxonomy>"
            ."<wp:term_slug>{$slug}</wp:term_slug>"
            ."<wp:term_name><![CDATA[{$name}]]></wp:term_name>"
            ."<wp:term_parent>{$parentSlug}</wp:term_parent>"
            .'</wp:term>';
    }

    private function entry(string $id, string $type, string $status = 'publish', ?string $body = null): string
    {
        // 本文が空だと `required_field_missing` で止まる（§F）。多くのテストはそこを見ていない
        $body ??= "本文 {$id}";

        return "<title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            ."<wp:post_type>{$type}</wp:post_type><wp:status>{$status}</wp:status>"
            .'<content:encoded><![CDATA['.$body.']]></content:encoded>';
    }

    /** 台帳へ 1 件記録する（前の取り込みの跡） */
    private function ledger(string $kind, string $targetType, string $externalId, string $targetId): void
    {
        $batch = strtoupper(bin2hex(random_bytes(13)));

        DB::table('import_batches')->insert([
            'id' => $batch,
            'source' => 'export.xml',
            'file_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'options' => '{}',
            'status' => 'completed',
            'counts' => '{}',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        DB::table('import_records')->insert([
            'batch_id' => $batch,
            'external_id' => $externalId,
            'kind' => $kind,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by_import' => true,
            'created_at' => now(),
        ]);
    }

    private function news(bool $trashed = false): string
    {
        $id = (string) DB::table('news')->insertGetId([
            'title' => '記事',
            'slug' => 'kiji-'.Str::lower(Str::random(8)),
            'body' => '本文',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($trashed) {
            DB::table('news')->where('id', $id)->update(['deleted_at' => now()]);
        }

        return $id;
    }

    /** item の中のコメント 1 件 */
    private function comment(string $id): string
    {
        return "<wp:comment><wp:comment_id>{$id}</wp:comment_id>"
            .'<wp:comment_author><![CDATA[読者]]></wp:comment_author>'
            .'<wp:comment_content><![CDATA[こんにちは]]></wp:comment_content>'
            .'<wp:comment_approved>1</wp:comment_approved></wp:comment>';
    }

    /** こちらの分類に既にある項目 */
    private function existingTerm(string $taxonomy, string $slug, bool $archived = false): string
    {
        $taxonomyId = (string) DB::table('taxonomies')->where('key', $taxonomy)->value('id');
        $id = Str::ulid()->toString();

        DB::table('taxonomy_terms')->insert([
            'id' => $id,
            'taxonomy_id' => $taxonomyId,
            'name' => '項目',
            'slug' => $slug,
            'archived_at' => $archived ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function media(): string
    {
        return (string) DB::table('media')->insertGetId([
            'name' => '写真',
            'path' => 'uploads/a.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
            'width' => 1,
            'height' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return int その件数を plan したときに飛んだ問い合わせの数 */
    private function queriesForItems(int $count): int
    {
        $body = '';

        for ($i = 1; $i <= $count; $i++) {
            $body .= '<item>'.$this->entry((string) $i, 'news').'</item>';
        }

        $path = $this->write('queries-'.$count.'.xml', $this->wxr($body));

        // 型の定義の読み込みは件数に比例しない。数える前に温める
        $this->planFor($path);

        $seen = 0;
        DB::listen(static function () use (&$seen): void {
            $seen++;
        });

        $this->planFor($path);

        return $seen;
    }

    private function wxr(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}

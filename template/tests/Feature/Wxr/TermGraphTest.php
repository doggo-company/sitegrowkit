<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Taxonomy\TermSlug;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TermGraph;
use AgentCms\Core\Wxr\WxrTermDefinition;
use LogicException;
use Tests\TestCase;

/**
 * 分類項目の親子と重複（Phase 9.14 / STAGE 27C・SPEC §C.3）。
 *
 * ## ここで止めないと、あとから直せない
 *
 * 親が居ない・自分が親・辿ると戻る、のどれも取り込み自体は「動く」。
 * 動いたうえで、**入れ子だったものが十年分フラットに並ぶ**か、
 * 親を辿る処理が止まらなくなる。どちらも入れ終わってからでは戻せない。
 *
 * ## 名前も綴りも findings へ出さない
 *
 * 明細は画面にも台帳にも出る。他人のサイトの分類名には個人名も屋号も入る。
 * 出すのは WXR 側の `wp:term_id`（定義の理由）と `wp:post_id`（割り当ての理由）だけで、
 * 突き合わせにはそれで足りる。
 *
 * ## 2 段階で読む
 *
 * 定義と item は別々の走査で読む。定義の走査を終えてから封をしてしまえば、
 * **割り当ては 1 件も抱えずにその場で照合できる**。
 * 抱えると、割り当ての種類の数だけメモリが増える。
 */
final class TermGraphTest extends TestCase
{
    /**
     * この綴りの要約（`sha256("wxr-term-slug\0p3")`）は先頭から 13 byte 目（0 起点で 12）に `\0` を含む。
     * 畳んだ記録の区切りと同じ値なので、切り分け方の誤りがここで出る。
     */
    private const SLUG_WITH_NUL_DIGEST = 'p3';

    private PlanFindings $findings;

    // ── 割り当てに定義が無い ────────────────────────────────

    public function test_割り当てられているのに定義が無い(): void
    {
        $graph = new TermGraph;

        $found = $this->verify($graph, [['news_category', 'oshirase', '101']]);

        $this->assertSame(1, $found['term_definition_missing']['total']);
        // 定義が無いのだから term_id は無い。**記事の側**で指す
        $this->assertSame(
            [['external_id' => '101', 'source_type' => 'term']],
            $found['term_definition_missing']['shown'],
        );
    }

    public function test_定義があれば割り当ては通る(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'oshirase', 'お知らせ'));

        $this->assertSame([], $this->verify($graph, [['news_category', 'oshirase', '101']]));
    }

    public function test_定義と割り当ての照合は正規化した綴りで行う(): void
    {
        /*
         * `TermSlug::canonicalize()` は小文字化と前後の空白除去だけを行う。
         * 照合をここに合わせないと、**大文字で書かれた割り当てだけが
         * 「定義が無い」と言われる**。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'oshirase', 'お知らせ'));

        $this->assertSame([], $this->verify($graph, [['news_category', ' OSHIRASE ', '101']]));
    }

    public function test_別の受け皿の定義では割り当てを満たさない(): void
    {
        $graph = new TermGraph;
        $graph->define('works_category', $this->term('12', 'category', 'oshirase', 'お知らせ'));

        $this->assertArrayHasKey(
            'term_definition_missing',
            $this->verify($graph, [['news_category', 'oshirase', '101']]),
        );
    }

    public function test_同じ定義の無い綴りを何百の記事が指しても全部数える(): void
    {
        /*
         * **壊れた割り当ての件数を数える。** 「直す先は 1 つだから 1 件」に畳むと、
         * 何本の記事が分類を失うのかが出ない。
         * 明細は絞るが、総数と省いた件数は必ず言う。
         */
        $assignments = [];

        for ($i = 1; $i <= 200; $i++) {
            $assignments[] = ['news_category', 'nowhere', (string) (100 + $i)];
        }

        $missing = $this->verify(new TermGraph, $assignments)['term_definition_missing'];

        $this->assertSame(200, $missing['total']);
        $this->assertCount(PlanFindings::DETAIL_CAP, $missing['shown']);
        $this->assertSame(200 - PlanFindings::DETAIL_CAP, $missing['omitted']);
    }

    public function test_割り当てを何万件受けても数えるだけ(): void
    {
        // **1 件も抱えない。** 抱えると割り当ての種類の数だけメモリが増える
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'oshirase', 'お知らせ'));

        $this->findings = new PlanFindings;
        $graph->seal($this->findings);

        for ($i = 1; $i <= 100_000; $i++) {
            $graph->assign('news_category', 'oshirase', (string) $i, $this->findings);
        }

        $this->assertSame([], $this->findings->toArray());
    }

    // ── 親 ──────────────────────────────────────────────────

    public function test_親として名指しされた定義が無い(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'child', '子', 'nowhere'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_parent_missing']['total']);
        $this->assertSame('12', $found['term_parent_missing']['shown'][0]['external_id']);
    }

    public function test_自分を親にしている(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'me', '自分', 'me'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_parent_self']['total']);

        /*
         * **自分を親にしているものを、循環としても数えない。**
         * 1 件の間違いが 2 つの理由で出ると、直す側は 2 か所を探す。
         */
        $this->assertArrayNotHasKey('term_parent_cycle', $found);
        $this->assertArrayNotHasKey('term_parent_missing', $found);
    }

    public function test_綴りを正規化して初めて自分になる場合も見つける(): void
    {
        // 綴りの照合は正規化した値で行う。**片方だけ生のまま比べない**
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'me', '自分', '  ME  '));

        $this->assertSame(1, $this->verify($graph)['term_parent_self']['total']);
    }

    /**
     * 畳んだ記録は 1 本の文字列で、`\0` で区切っている。
     * 親の要約は 32 byte の**生の値**なので、その中に `\0` が入ることがある
     * （どの位置でも 256 分の 1 で起きる）。
     *
     * 区切りで切り分けるときに数を限らないと、**親の要約が途中で切れて**
     * 別の値になる。親は見つからなくなり、ぶら下がっていたものが
     * 十年分フラットに並ぶ。しかも取り込みは成功に見える。
     */
    public function test_親の要約に区切りと同じ値が入っていても親を辿れる(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', self::SLUG_WITH_NUL_DIGEST, '親'));
        $graph->define('news_category', $this->term(
            '2', 'category', 'child', '子', self::SLUG_WITH_NUL_DIGEST,
        ));

        $this->assertSame([], $this->verify($graph));
    }

    public function test_親の要約に区切りが入っていても同じ親だと分かる(): void
    {
        // 切れた要約どうしを比べると、**同じ親を別の親だと読む**
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', self::SLUG_WITH_NUL_DIGEST, '親'));
        $graph->define('news_category', $this->term('2', 'category', 'a', 'A', self::SLUG_WITH_NUL_DIGEST));
        $graph->define('news_category', $this->term('3', 'category', 'a', 'A', self::SLUG_WITH_NUL_DIGEST));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_definition_repeated']['total']);
        $this->assertArrayNotHasKey('term_definition_conflict', $found);
    }

    public function test_親の要約に区切りが入っていても循環を見つけられる(): void
    {
        /*
         * 往復するだけでは足りない。**同じ byte 列が返る**ことと、
         * その値で親を引き当てて輪だと分かることは別である。
         * 切れた要約は誰にも当たらないので、輪は「親が居ない」に化ける。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term(
            '1', 'category', self::SLUG_WITH_NUL_DIGEST, 'A', 'partner',
        ));
        $graph->define('news_category', $this->term(
            '2', 'category', 'partner', 'B', self::SLUG_WITH_NUL_DIGEST,
        ));

        $found = $this->verify($graph);

        $this->assertSame(2, $found['term_parent_cycle']['total']);
        $this->assertArrayNotHasKey('term_parent_missing', $found);
    }

    public function test_区切りと同じ値を含む要約を実際に選んでいる(): void
    {
        /*
         * 上の 2 つは、**この綴りの要約が区切りと同じ値を含むこと**が前提である。
         * 鍵の作り方を変えると前提が消え、2 つとも「通っているが何も試していない」
         * 検査になる。前提そのものをここで固定する。
         */
        $this->assertStringContainsString(
            "\0",
            hash('sha256', "wxr-term-slug\0".self::SLUG_WITH_NUL_DIGEST, true),
            '前提が崩れています。区切りと同じ値を含む要約になる綴りを選び直してください。',
        );
    }

    public function test_2つで循環している(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A', 'b'));
        $graph->define('news_category', $this->term('2', 'category', 'b', 'B', 'a'));

        $found = $this->verify($graph);

        // 輪に入っているもの**だけ**を数える
        $this->assertSame(2, $found['term_parent_cycle']['total']);
        $this->assertSame(
            ['1', '2'],
            array_column($found['term_parent_cycle']['shown'], 'external_id'),
        );
    }

    public function test_3つで循環している(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A', 'c'));
        $graph->define('news_category', $this->term('2', 'category', 'b', 'B', 'a'));
        $graph->define('news_category', $this->term('3', 'category', 'c', 'C', 'b'));

        $this->assertSame(3, $this->verify($graph)['term_parent_cycle']['total']);
    }

    public function test_輪へ入っていくだけのものは循環に数えない(): void
    {
        /*
         * `tail` は輪の外にある。輪の中の 2 件だけを数える。
         * 外側まで数えると「3 件直せ」と言われて、直す先が 1 つ余る。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A', 'b'));
        $graph->define('news_category', $this->term('2', 'category', 'b', 'B', 'a'));
        $graph->define('news_category', $this->term('9', 'category', 'tail', 'T', 'a'));

        $this->assertSame(2, $this->verify($graph)['term_parent_cycle']['total']);
    }

    public function test_同じ受け皿へまとめた別の分類の項目を親にできる(): void
    {
        /*
         * `--taxonomy=category:news_category --taxonomy=post_tag:news_category` と
         * **明示して**まとめた以上、その先は 1 つの名前空間である。
         * 元の分類が違うことを理由に親子を切ると、まとめた意味が無くなる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'oya', '親'));
        $graph->define('news_category', $this->term('2', 'post_tag', 'ko', '子', 'oya'));

        $this->assertSame([], $this->verify($graph));
    }

    public function test_受け皿が違えば同じ綴りでも別の項目になる(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'oshirase', 'お知らせ'));
        $graph->define('works_category', $this->term('2', 'category', 'oshirase', '実績'));

        $this->assertSame([], $this->verify($graph));
    }

    // ── 重複の 3 通り ───────────────────────────────────────

    public function test_同じterm_idが2度出てきたら中身が同じでも止める(): void
    {
        /*
         * 台帳は `external_id` で 1 件ずつ覚える。同じ id の 2 定義を
         * 「同じものだから」と通すと、**2 件目は台帳から不可視**になり、
         * 取り消しでも再実行でもその 1 件だけ扱えなくなる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'a', 'A'));
        $graph->define('news_category', $this->term('12', 'category', 'a', 'A'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_id_duplicate']['total']);
        // **同じ id は 1 つの理由で出す。**重複としても数え直さない
        $this->assertArrayNotHasKey('term_definition_repeated', $found);
    }

    public function test_受け皿が違っても同じterm_idは通さない(): void
    {
        /*
         * 台帳の鍵は受け皿ごとではない。受け皿が違うから別物、として通すと、
         * **どちらか一方が台帳から不可視**になる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12', 'category', 'a', 'A'));
        $graph->define('works_category', $this->term('12', 'category', 'b', 'B'));

        $this->assertSame(1, $this->verify($graph)['term_id_duplicate']['total']);
        $this->assertTrue($this->findings->blocksPlan());
    }

    public function test_id以外がすべて一致するなら1つ作れば足りる(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A', 'p'));
        $graph->define('news_category', $this->term('2', 'category', 'a', 'A', 'p'));
        $graph->define('news_category', $this->term('9', 'category', 'p', 'P'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_definition_repeated']['total']);
        // 代表は WXR の先頭。**後から来た方**を重複として指す
        $this->assertSame('2', $found['term_definition_repeated']['shown'][0]['external_id']);
        $this->assertFalse($this->findings->blocksPlan());
    }

    public function test_名前が違えば同じ項目だと決められない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A'));
        $graph->define('news_category', $this->term('2', 'category', 'a', 'B'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_definition_conflict']['total']);
        $this->assertArrayNotHasKey('term_definition_repeated', $found);
    }

    public function test_親が違えば同じ項目だと決められない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A', 'p'));
        $graph->define('news_category', $this->term('2', 'category', 'a', 'A'));
        $graph->define('news_category', $this->term('9', 'category', 'p', 'P'));

        $this->assertSame(1, $this->verify($graph)['term_definition_conflict']['total']);
    }

    public function test_同じ受け皿へまとめた結果ぶつかったものも食い違いとして出す(): void
    {
        /*
         * 元は別の分類なので WXR の中では正しい。**まとめたこちらの都合**で
         * ぶつかっているので、黙ってどちらかを採らない。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'news', 'お知らせ'));
        $graph->define('news_category', $this->term('2', 'post_tag', 'news', 'ニュース'));

        $this->assertSame(1, $this->verify($graph)['term_definition_conflict']['total']);
    }

    public function test_名前に見えない文字が入っていても明細は壊れない(): void
    {
        /*
         * 名前は findings へ 1 文字も出さない。出す実装に変わると
         * **改行や見えない文字がそのまま画面と台帳へ流れる**ので、
         * ここで例外になるか、明細に混ざるかで気づけるようにしておく。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', "A\u{200B}"));
        $graph->define('news_category', $this->term('2', 'category', 'a', 'A'));

        $found = $this->verify($graph);

        $this->assertSame(
            [['external_id' => '2', 'source_type' => 'term']],
            $found['term_definition_conflict']['shown'],
        );
    }

    public function test_割り当ての綴りに見えない文字が入っていても明細は壊れない(): void
    {
        $found = $this->verify(new TermGraph, [['news_category', "お知らせ\u{200B}", '101']]);

        $this->assertSame(
            [['external_id' => '101', 'source_type' => 'term']],
            $found['term_definition_missing']['shown'],
        );
    }

    // ── 保存する綴りを決める ────────────────────────────────

    public function test_日本語の綴りからは保存できる綴りを作る(): void
    {
        /*
         * `Str::slug('お知らせ')` は**空文字**を返す。そのままでは保存できないので
         * WXR の term_id 由来の綴りを当てる。
         * **黙って当てない** — Admin から変えられることを先に言う。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('7', 'category', 'お知らせ', 'お知らせ'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_slug_generated']['total']);
        $this->assertSame('7', $found['term_slug_generated']['shown'][0]['external_id']);
        // 作った綴りは**報告するだけ**で、取り込みは止めない
        $this->assertFalse($this->findings->blocksPlan());
    }

    public function test_percentで綴られたものからも保存できる綴りを作る(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term(
            '7', 'category', '%e3%81%8a%e7%9f%a5%e3%82%89%e3%81%9b', 'お知らせ',
        ));

        $this->assertSame(1, $this->verify($graph)['term_slug_generated']['total']);
    }

    public function test_とても長いpercentの綴りもそれ自体では止めない(): void
    {
        /*
         * 綴りが長いことは**こちらで直せる**（当てればよい）。
         * ここで止めると、日本語のサイトはどれも取り込めなくなる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term(
            '7', 'category', str_repeat('%e3%81%8a', 30), 'お知らせ',
        ));

        $this->assertSame(['term_slug_generated'], array_keys($this->verify($graph)));
        $this->assertFalse($this->findings->blocksPlan());
    }

    public function test_そのまま使える綴りには手を触れない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('7', 'category', 'press-release', 'プレスリリース'));

        $this->assertArrayNotHasKey('term_slug_generated', $this->verify($graph));
    }

    public function test_作った綴りが同じ受け皿でぶつかる(): void
    {
        /*
         * 当てる綴りは term_id の**下 8 桁**から作る。
         * `12345678` と `10012345678` は下 8 桁が同じなので、
         * 別の項目なのに同じ綴りになる。**片方が消える**ので止める。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('12345678', 'category', 'ニュース', 'ニュース'));
        $graph->define('news_category', $this->term('10012345678', 'category', 'お知らせ', 'お知らせ'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_target_slug_conflict']['total']);
        $this->assertSame('10012345678', $found['term_target_slug_conflict']['shown'][0]['external_id']);
    }

    public function test_名前から作った綴りが同じ受け皿でぶつかる(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'こんにちは', 'Hello World'));
        $graph->define('news_category', $this->term('2', 'category', 'ばんは', 'hello  world'));

        $this->assertSame(1, $this->verify($graph)['term_target_slug_conflict']['total']);
    }

    public function test_そのまま使える綴りと作った綴りがぶつかる(): void
    {
        /*
         * 片方は WXR に書かれていた綴り、片方はこちらが当てた綴り。
         * **出所が違っても、同じ受け皿に同じ URL は 2 つ置けない。**
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'term-12345678', '既存'));
        $graph->define('news_category', $this->term('12345678', 'category', 'ニュース', 'ニュース'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_target_slug_conflict']['total']);
        $this->assertSame('12345678', $found['term_target_slug_conflict']['shown'][0]['external_id']);
    }

    public function test_受け皿が違えば作った綴りがぶつかっても構わない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'こんにちは', 'Hello World'));
        $graph->define('works_category', $this->term('2', 'category', 'ばんは', 'hello  world'));

        $this->assertArrayNotHasKey('term_target_slug_conflict', $this->verify($graph));
    }

    public function test_同じ定義が2度あっても作る綴りは1つ(): void
    {
        /*
         * 代表 1 件だけが受け皿に載る。2 件目まで綴りを当てると、
         * **同じ項目が自分自身とぶつかった**ことになって止まる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'お知らせ', 'お知らせ'));
        $graph->define('news_category', $this->term('2', 'category', 'お知らせ', 'お知らせ'));

        $found = $this->verify($graph);

        $this->assertSame(1, $found['term_definition_repeated']['total']);
        $this->assertSame(1, $found['term_slug_generated']['total']);
        $this->assertSame('1', $found['term_slug_generated']['shown'][0]['external_id']);
        $this->assertArrayNotHasKey('term_target_slug_conflict', $found);
        $this->assertFalse($this->findings->blocksPlan());
    }

    public function test_名前が桁に収まらない(): void
    {
        // `taxonomy_terms.name` は varchar(120)。切り詰めて入れない
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('7', 'category', 'a', str_repeat('あ', 121)));

        $this->assertSame(1, $this->verify($graph)['term_name_too_long']['total']);
        $this->assertTrue($this->findings->blocksPlan());
    }

    public function test_ちょうど桁に収まる名前は通す(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('7', 'category', 'a', str_repeat('あ', 120)));

        $this->assertArrayNotHasKey('term_name_too_long', $this->verify($graph));
    }

    public function test_作った綴りは必ず保存できる形になる(): void
    {
        /*
         * `term_storage_slug_invalid` は `TermSlug::generate()` の**約束**が
         * 破れたときだけ出る。約束そのものをここで固定しておく。
         * 破れたらこの検査が先に赤くなり、取り込みが黙って壊れない。
         */
        foreach (['お知らせ', '', '   ', str_repeat('あ', 300), 'Hello World'] as $name) {
            $this->assertTrue(
                TermSlug::isValid(TermSlug::generate($name, '12345')),
                '作った綴りが保存できない形になっています',
            );
        }
    }

    // ── 数が多すぎるとき ────────────────────────────────────

    public function test_十万件までは確かめ切る(): void
    {
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS; $i++) {
            $graph->define('news_category', $this->term((string) $i, 'category', 't'.$i, 'T'.$i));
        }

        $this->assertSame([], $this->verify($graph));
    }

    public function test_十万件を超えたら他の理由を出さずに止める(): void
    {
        /*
         * **中途半端に確かめた結果を出さない。** 「循環は無い」と言えないのに
         * 循環の理由が出ないと、利用者は確かめたうえで無かったのだと読む。
         */
        $graph = $this->overflowing();

        // 越えた後は割り当ても数えない（守るのは 1 つの理由だけ）
        $found = $this->verify($graph, [['news_category', 'nowhere', '101']]);

        $this->assertSame(['too_many_term_definitions'], array_keys($found));
        $this->assertTrue($this->findings->blocksPlan());
    }

    public function test_十万件の一本鎖でも辿り切れる(): void
    {
        /*
         * 親を再帰で辿ると、鎖が長いだけで**呼び出しが積み上がって落ちる**。
         * 落ちた plan は「確かめられなかった」ではなく、ただ死んだように見える。
         */
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS; $i++) {
            $graph->define('news_category', $this->term(
                (string) $i, 'category', 't'.$i, 'T'.$i, $i > 1 ? 't'.($i - 1) : null,
            ));
        }

        $this->assertSame([], $this->verify($graph));
    }

    public function test_十万件が丸ごと輪になっていても数え切れる(): void
    {
        /*
         * **輪を報告する道は、ここでしか通らない。**
         *
         * 一本鎖は 1 件も報告しないので、報告そのものの重さを見ていない。
         * 十万件が輪になると findings へ十万回入る。明細は 50 件で頭打ちになるが、
         * **総数は伏せない**（§A.3）ので、数える側は十万回働く。
         * ここが落ちると、輪を見つけたのに報告できないという最悪の形になる。
         */
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS; $i++) {
            // 最後の 1 件が先頭を親にして輪が閉じる
            $parent = $i === 1 ? 't'.TermGraph::MAX_DEFINITIONS : 't'.($i - 1);

            $graph->define('news_category', $this->term(
                (string) $i, 'category', 't'.$i, 'T'.$i, $parent,
            ));
        }

        $cycle = $this->verify($graph)['term_parent_cycle'];

        $this->assertSame(TermGraph::MAX_DEFINITIONS, $cycle['total']);
        $this->assertCount(PlanFindings::DETAIL_CAP, $cycle['shown']);
        $this->assertSame(
            TermGraph::MAX_DEFINITIONS - PlanFindings::DETAIL_CAP,
            $cycle['omitted'],
        );
    }

    // ── 段取りそのもの ──────────────────────────────────────

    public function test_封をした後は定義を足せない(): void
    {
        /*
         * 封の後に足せると、**確かめていない定義が受け皿に載る**。
         * WXR の中身ではなく組み立ての順番の誤りなので、数えずに投げる。
         */
        $graph = new TermGraph;
        $graph->seal(new PlanFindings);

        $this->expectException(LogicException::class);

        $graph->define('news_category', $this->term('1', 'category', 'a', 'A'));
    }

    public function test_封をする前に割り当ては照合できない(): void
    {
        /*
         * 封の前に照合すると、**まだ読んでいない定義を「無い」と言う**。
         * 十年分の割り当てが一斉に「定義が無い」になる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'a', 'A'));

        $this->expectException(LogicException::class);

        $graph->assign('news_category', 'a', '101', new PlanFindings);
    }

    public function test_二度封をしても同じことを二度言わない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'me', '自分', 'me'));

        $first = new PlanFindings;
        $graph->seal($first);

        $second = new PlanFindings;
        $graph->seal($second);

        $this->assertSame(1, $first->toArray()['term_parent_self']['total']);
        $this->assertSame([], $second->toArray());
    }

    // ── 明細は絞るが、総数は伏せない ────────────────────────

    public function test_明細は絞っても総数は出す(): void
    {
        $graph = new TermGraph;

        for ($i = 1; $i <= 201; $i++) {
            $graph->define('news_category', $this->term((string) $i, 'category', 'a', 'A'));
        }

        $repeated = $this->verify($graph)['term_definition_repeated'];

        $this->assertSame(200, $repeated['total']);
        $this->assertCount(PlanFindings::DETAIL_CAP, $repeated['shown']);
        $this->assertSame(200 - PlanFindings::DETAIL_CAP, $repeated['omitted']);
    }

    public function test_理由が複数あればすべて出す(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'me', '自分', 'me'));
        $graph->define('news_category', $this->term('2', 'category', 'lost', '迷子', 'nowhere'));
        $graph->define('news_category', $this->term('3', 'category', 'ja', str_repeat('あ', 121)));

        $this->assertSame([
            'term_definition_missing',
            'term_name_too_long',
            'term_parent_missing',
            'term_parent_self',
        ], array_keys($this->verify($graph, [['news_category', 'undefined-one', '101']])));
    }

    public function test_どの記事にも使われていない定義でも消さない(): void
    {
        /*
         * §C.3 は「既存の分類へ項目を足す」であって、
         * **使われていないから消す、という決定はどこにも無い**。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'category', 'unused', '未使用'));

        $this->assertSame([], $this->verify($graph));
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function term(
        string $termId,
        string $taxonomy,
        string $slug,
        string $name,
        ?string $parentSlug = null,
    ): WxrTermDefinition {
        return new WxrTermDefinition($termId, $taxonomy, $slug, $name, $parentSlug);
    }

    private function overflowing(): TermGraph
    {
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS; $i++) {
            $graph->define('news_category', $this->term((string) $i, 'category', 't'.$i, 'T'.$i));
        }

        // 越えさせるものは、それ自体が壊れている（自分を親にしている）
        $graph->define('news_category', $this->term('999999', 'category', 'boom', 'B', 'boom'));

        return $graph;
    }

    /**
     * 定義を確かめて封をし、そのあとで割り当てを照合する。
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $assignments
     * @return array<string, array{total: int, shown: list<array{external_id: string, source_type: string}>, omitted: int}>
     */
    private function verify(TermGraph $graph, array $assignments = []): array
    {
        $this->findings = new PlanFindings;

        $graph->seal($this->findings);

        foreach ($assignments as [$target, $nicename, $postId]) {
            $graph->assign($target, $nicename, $postId, $this->findings);
        }

        return $this->findings->toArray();
    }
}

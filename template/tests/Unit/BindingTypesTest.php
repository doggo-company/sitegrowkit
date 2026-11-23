<?php

declare(strict_types=1);

namespace Tests\Unit;

use AgentCms\Core\Document\BindingTypes;
use AgentCms\Core\Schema\Field;
use PHPUnit\Framework\TestCase;

/**
 * 型整合表そのもの（Phase 9.6 / P0-C5・SPEC §4.9）。
 *
 * ## なぜ validator 越しではなく、表を直に見るのか
 *
 * 表には**同梱の component からは届かない行**がある。`enum` を受け取る prop は
 * 1 つも `bindable` に入っていないので、validator 越しに試すと
 * **enum の行だけ一度も通らない**（緑だが、何も確かめていない状態になる）。
 *
 * 表は入力と出力しか持たない純粋な関数なので、**ここで直に確かめる**。
 * 生成 project が bindable な enum prop を足した日に、初めて実地で使われる行である。
 */
final class BindingTypesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function prop(string $type, array $extra = []): array
    {
        return array_merge(['type' => $type], $extra);
    }

    // ── 型が合う / 合わない ──────────────────────────────

    public function test_文字の_field_は_string_の_prop_へ流せる(): void
    {
        foreach (['text', 'textarea', 'slug', 'status', 'email', 'tel'] as $type) {
            $this->assertTrue(
                BindingTypes::accepts($type, $this->prop('string')),
                "[{$type}] が string の prop へ流せません",
            );
        }
    }

    public function test_型の合わないものは流せない(): void
    {
        $this->assertFalse(BindingTypes::accepts('text', $this->prop('media')));
        $this->assertFalse(BindingTypes::accepts('image', $this->prop('string')));
        $this->assertFalse(BindingTypes::accepts('number', $this->prop('string')));
        $this->assertFalse(BindingTypes::accepts('boolean', $this->prop('string')));
    }

    /** `button.href` の prop 型は url であって string ではない */
    public function test_url_の_field_は_url_の_prop_へ流せる(): void
    {
        $this->assertTrue(BindingTypes::accepts('url', $this->prop('url')));
        $this->assertTrue(BindingTypes::accepts('url', $this->prop('string')));
        $this->assertFalse(BindingTypes::accepts('text', $this->prop('url')));
    }

    public function test_メディアは_media_の_prop_へだけ流せる(): void
    {
        $this->assertTrue(BindingTypes::accepts('image', $this->prop('media')));
        $this->assertTrue(BindingTypes::accepts('media', $this->prop('media')));
        $this->assertFalse(BindingTypes::accepts('media', $this->prop('url')));
    }

    /** 設計トークンは人が選ぶもので、記事の値が入る場所ではない */
    public function test_token_の_prop_へはどの_field_も流せない(): void
    {
        foreach (['text', 'richtext', 'number', 'boolean', 'image', 'url', 'select'] as $type) {
            $this->assertFalse(
                BindingTypes::accepts($type, $this->prop('token')),
                "[{$type}] が token の prop へ流せてしまいます",
            );
        }
    }

    // ── 複数値 ──────────────────────────────────────────

    public function test_複数値の_field_は単一値_binding_で受けない(): void
    {
        foreach (['images', 'file', 'checkbox', 'relation', 'relations', 'group', 'repeater'] as $type) {
            $this->assertFalse(
                BindingTypes::accepts($type, $this->prop('string')),
                "複数値の [{$type}] が単一値 binding で通ります",
            );
            $this->assertTrue(BindingTypes::knows($type), "[{$type}] を表が知りません");
        }
    }

    // ── richtext の双方向 ───────────────────────────────

    public function test_richtext_は_html_を宣言した_prop_へだけ流せる(): void
    {
        $html = $this->prop('string', ['format' => 'html']);

        $this->assertTrue(BindingTypes::accepts('richtext', $html));
        // 型は同じ string でも、印の無い prop へは落とさない
        $this->assertFalse(BindingTypes::accepts('richtext', $this->prop('string')));
    }

    public function test_html_を宣言した_prop_へは_richtext_だけが流せる(): void
    {
        $html = $this->prop('string', ['format' => 'html']);

        foreach (['text', 'textarea', 'slug', 'status', 'email', 'tel', 'url'] as $type) {
            $this->assertFalse(
                BindingTypes::accepts($type, $html),
                "[{$type}] が html の prop へ流せてしまいます",
            );
        }
    }

    // ── enum は値集合まで見る ───────────────────────────

    public function test_select_は選択肢が_prop_の値集合の部分集合なら流せる(): void
    {
        $prop = $this->prop('enum', ['values' => ['a', 'b', 'c']]);

        $this->assertTrue(BindingTypes::accepts('select', $prop, ['a', 'b']));
        $this->assertTrue(BindingTypes::accepts('radio', $prop, ['a', 'b', 'c']));
        $this->assertFalse(BindingTypes::accepts('select', $prop, ['a', 'z']));
    }

    /** 何を受けるか宣言していない enum は、受けられるかを示せない（fail closed） */
    public function test_値集合を宣言していない_enum_には流せない(): void
    {
        $this->assertFalse(BindingTypes::accepts('select', $this->prop('enum'), ['a']));
        $this->assertFalse(BindingTypes::accepts('select', $this->prop('enum', ['values' => []]), ['a']));
    }

    /** 部分集合であることを示せない Field も通さない */
    public function test_選択肢を宣言していない_field_は_enum_へ流せない(): void
    {
        $this->assertFalse(BindingTypes::accepts('select', $this->prop('enum', ['values' => ['a']])));
    }

    public function test_boolean_は_2_値ちょうどの_enum_にだけ入る(): void
    {
        $this->assertTrue(BindingTypes::accepts('boolean', $this->prop('enum', ['values' => ['on', 'off']])));
        $this->assertFalse(BindingTypes::accepts('boolean', $this->prop('enum', ['values' => ['a', 'b', 'c']])));
        $this->assertTrue(BindingTypes::accepts('boolean', $this->prop('boolean')));
    }

    // ── 型までの判定（画面へ渡す候補の材料）────────────

    /**
     * **`enum` の prop では緩い側へ倒す。**
     *
     * 選択肢が部分集合かどうかは Field ごとに違うので、Field を決めずに数えるときに
     * 厳しく判定すると**通るはずの Field が画面の候補から消える**。
     * 選ばせて validator が断る方が、選べないより良い。
     */
    public function test_型までの判定は_enum_で緩い側へ倒す(): void
    {
        $prop = $this->prop('enum', ['values' => ['a', 'b']]);

        $this->assertTrue(BindingTypes::acceptsType('select', $prop));
        // 値集合まで見る側は、部分集合でなければ断る
        $this->assertFalse(BindingTypes::accepts('select', $prop, ['z']));
        $this->assertTrue(BindingTypes::accepts('select', $prop, ['a']));
    }

    /** 型の側の規則は、緩い方でも変わらない */
    public function test_型までの判定でも型と_html_の規則は効く(): void
    {
        $this->assertFalse(BindingTypes::acceptsType('text', $this->prop('media')));
        $this->assertFalse(BindingTypes::acceptsType('images', $this->prop('string')));
        $this->assertFalse(BindingTypes::acceptsType('text', $this->prop('string', ['format' => 'html'])));
        $this->assertTrue(BindingTypes::acceptsType('richtext', $this->prop('string', ['format' => 'html'])));
    }

    // ── 表が Field::TYPES を覆っていること（F-18）──────

    /**
     * **表から漏れた型は、静かに素通しになる。**
     *
     * `DocumentValidator` は「表が知らない型は検査を飛ばす」ので、
     * 表から 1 行落ちても**どの試験も赤くならず、その型だけ C5 の穴が開き直る**。
     * そして「Field に型が増える」方は必ず起きる — 足した人が表を知らなければ、
     * その型は**最初から検査の外**にある。
     *
     * だからここで**両側から**固定する。
     */
    public function test_field_の型はすべて表に載っている(): void
    {
        foreach (Field::types() as $type) {
            $this->assertTrue(
                BindingTypes::knows($type),
                "[{$type}] が BindingTypes の表にありません（Field に型を足したら、表にも足してください）",
            );
        }
    }

    public function test_表に_field_が知らない型は載っていない(): void
    {
        foreach (BindingTypes::knownTypes() as $type) {
            $this->assertContains(
                $type,
                Field::types(),
                "BindingTypes の表にある [{$type}] は Field の型ではありません（消し忘れか綴り違いです）",
            );
        }
    }

    public function test_表と_field_の型は同じ数だけある(): void
    {
        $this->assertSame(
            count(Field::types()),
            count(array_unique(BindingTypes::knownTypes())),
            '表と Field::TYPES の数が食い違っています（重複か、片側の足し忘れです）',
        );
    }

    // ── 表が知らない型 ──────────────────────────────────

    public function test_表が知らない型は知らないと言う(): void
    {
        $this->assertFalse(BindingTypes::knows('created_at'));
        $this->assertFalse(BindingTypes::knows(''));
        $this->assertTrue(BindingTypes::knows('text'));
    }

    /**
     * `page` の解決先と対になっていること。
     *
     * ここが `BindingResolver::value()` の `page` の match とずれると、
     * 「保存はできるが何も出ない」binding が戻ってくる。
     */
    public function test_page_が解決できる_field_は_title_と_slug_だけ(): void
    {
        $this->assertSame(['title', 'slug'], array_keys(BindingTypes::PAGE_FIELDS));
        $this->assertSame('text', BindingTypes::PAGE_FIELDS['title']['type']);
        $this->assertSame('slug', BindingTypes::PAGE_FIELDS['slug']['type']);
    }
}

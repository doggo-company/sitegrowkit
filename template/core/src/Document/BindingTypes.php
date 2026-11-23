<?php

declare(strict_types=1);

namespace AgentCms\Core\Document;

/**
 * どの Field を、どの prop へ流してよいか（Phase 9.6 / P0-C5・SPEC §4.9 の表が正本）。
 *
 * ## ここが 1 か所である理由
 *
 * Editor（事前に候補を絞る）と `DocumentValidator`（最後の砦）が**同じ表**を読む。
 * 2 か所に書くと、片方だけ直したときに「UI では選べないのに API では通る」
 * （あるいはその逆）が生まれる。**表は、ここにしかない。**
 *
 * ## 型では足りないものが 1 つだけある
 *
 * `richtext.html` と `heading.text` は**どちらも `type: "string"`** で、違うのは
 * prop の名前と `max` だけである。だから richtext の行だけは型で書けない。
 * **prop の側に `format: "html"` を宣言させて**、それを見る。
 *
 * Core が `richtext.html` という**特定 component の名前を知らずに済む**のが理由である。
 * 生成 project は component を足せるので、名前を書くと配った先で増えたものを表せない。
 *
 * ## 型が引けないときの扱いは、**わざと非対称にしてある**
 *
 * binding の source によって、Field の型が引けたり引けなかったりする
 * （`entry` は schema から引ける。`page` は title / slug だけ。`settings` は引けない）。
 *
 * - **`format: "html"` の prop は閉じる** — richtext だと**分かっているもの**しか通さない
 * - **それ以外の prop は開く** — 型が引けなければ検査を飛ばす
 *
 * **この非対称は故意である。** html を受ける prop は、生成 project 側が
 * clean せずに raw で出す component を書けてしまう場所で、**防御の重ねの最後の環**にあたる。
 * 他の prop で起きるのは「意味の壊れ」までで、そこまで閉じると
 * settings からの binding が全滅する（行き過ぎ）。
 *
 * @see DocumentValidator::validateBinding()
 */
final class BindingTypes
{
    /**
     * Field 型 → 受け入れ可能な prop 型。
     *
     * **`token` はどの行にも出てこない。** 設計トークンは人が選ぶもので、
     * 記事の値が入る場所ではない（SPEC §4.9）。
     *
     * @var array<string, list<string>>
     */
    private const ACCEPTS = [
        // 文字
        'text' => ['string'],
        'textarea' => ['string'],
        'slug' => ['string'],
        'status' => ['string'],
        'email' => ['string'],
        'tel' => ['string'],
        // richtext は format: "html" の string prop だけ（isHtmlProp で見る）
        'richtext' => ['string'],
        // `button.href` の prop 型は url であって string ではない
        'url' => ['url', 'string'],
        // 数・真偽
        'number' => ['number'],
        'boolean' => ['boolean', 'enum'],
        // 日付は文字列として流す（表示整形は renderer 側）
        'date' => ['string'],
        'datetime' => ['string'],
        // 選択
        'select' => ['enum', 'string'],
        'radio' => ['enum', 'string'],
        // メディア
        'image' => ['media'],
        'media' => ['media'],
    ];

    /**
     * **単一値 binding では受けない** Field 型（複数値・入れ子・参照）。
     *
     * 一覧は `collection` binding が扱う。ここへ来るのは「一覧を 1 つの欄へ流す」
     * という、表示は出るが意味の壊れた binding である。
     *
     * 参照（`relation` / `page`）は値が **id の整数**で、prop へ流しても
     * **数字が出るだけ**である。単一値なので上の理由には当たらないが、
     * 受けてよい prop 型が無いので**同じ側**へ置く。
     *
     * @var list<string>
     */
    private const MULTI_VALUE = ['images', 'file', 'checkbox', 'relation', 'relations', 'page', 'group', 'repeater'];

    /**
     * `source: "page"` が解決できる field → その Field 型と表示名。
     *
     * **`BindingResolver::value()` の `page` の match と対になっている。**
     * あちらが解決しないものをここに足すと、「保存はできるが何も出ない」binding に戻る。
     *
     * 表示名も**ここに置く**。集合と名前を別の場所に分けると、足した人が片方しか
     * 直さず、名前の無い項目が出る（分けた版は PHPStan が「その逃げ道は通らない」と
     * 証明してくれた — 通らない逃げ道は、無い方がよい）。
     *
     * @var array<string, array{type: string, label: string}>
     */
    public const PAGE_FIELDS = [
        'title' => ['type' => 'text', 'label' => 'ページ名'],
        'slug' => ['type' => 'slug', 'label' => 'スラッグ'],
    ];

    /** HTML を受け取ると宣言した prop か（型では区別できない richtext のための印）。 */
    public static function isHtmlProp(mixed $propSpec): bool
    {
        return is_array($propSpec) && ($propSpec['format'] ?? null) === 'html';
    }

    /**
     * 表が知っている Field 型の一覧。
     *
     * **`Field::types()` と一致していること**を試験で固定するために出している。
     * 表から漏れた型は `knows()` が false を返し、`DocumentValidator` は
     * **検査を飛ばす** — つまり**その型だけ静かに素通しになる**（F-18）。
     *
     * @return list<string>
     */
    public static function knownTypes(): array
    {
        return [...array_keys(self::ACCEPTS), ...self::MULTI_VALUE];
    }

    /** この Field 型を表が知っているか。知らないものは検査を飛ばす側へ回す。 */
    public static function knows(string $fieldType): bool
    {
        return isset(self::ACCEPTS[$fieldType]) || in_array($fieldType, self::MULTI_VALUE, true);
    }

    /**
     * この Field を、この prop へ流してよいか。**型と値集合の両方**を見る。
     *
     * @param  array<string, mixed>  $propSpec  `propsSchema` の 1 項目
     * @param  list<string>  $choices  Field の選択肢（select / radio のみ意味を持つ）
     */
    public static function accepts(string $fieldType, array $propSpec, array $choices = []): bool
    {
        if (! self::acceptsType($fieldType, $propSpec)) {
            return false;
        }

        return ($propSpec['type'] ?? null) !== 'enum' || self::fitsEnum($fieldType, $propSpec, $choices);
    }

    /**
     * **型までで判定する**（値集合は見ない）。
     *
     * Field を 1 つも決めずに「この prop には何が流せるか」を数えるための入口で、
     * 画面へ渡す `accepts` はこれで作る（`ComponentDefinition::accepts()`）。
     *
     * **`enum` の prop では緩い側へ倒してある。** 選択肢が部分集合かどうかは
     * Field ごとに違うので、ここで厳しく数えると**通るはずの Field が
     * 画面の候補から消える**。選ばせて validator が断る方が、選べないより良い
     * （Editor は事前・validator は最後の砦）。
     *
     * @param  array<string, mixed>  $propSpec
     */
    public static function acceptsType(string $fieldType, array $propSpec): bool
    {
        // 複数値は単一値 binding では受けない
        if (in_array($fieldType, self::MULTI_VALUE, true)) {
            return false;
        }

        // 双方向。richtext は html prop へだけ、html prop へは richtext だけ
        if (self::isHtmlProp($propSpec) !== ($fieldType === 'richtext')) {
            return false;
        }

        $propType = is_string($propSpec['type'] ?? null) ? $propSpec['type'] : '';

        return in_array($propType, self::ACCEPTS[$fieldType] ?? [], true);
    }

    /**
     * enum の prop は、**値集合まで見ないと意味がない**。
     *
     * 型だけ合わせて通すと、prop が受け付けない値を Field が出し続ける
     * binding ができる（表示は出るが、その prop の約束は破れている）。
     *
     * @param  array<string, mixed>  $propSpec
     * @param  list<string>  $choices
     */
    private static function fitsEnum(string $fieldType, array $propSpec, array $choices): bool
    {
        $values = is_array($propSpec['values'] ?? null) ? $propSpec['values'] : [];

        // 値集合を宣言していない enum は、何を受けるか分からない（fail closed）
        if ($values === []) {
            return false;
        }

        // boolean は 2 値ちょうどの enum にしか入らない
        if ($fieldType === 'boolean') {
            return count($values) === 2;
        }

        // select / radio は Field の選択肢が prop の値集合の**部分集合**であること。
        // 選択肢を宣言していない Field は、部分集合であることを示せない
        if ($choices === []) {
            return false;
        }

        $declared = array_map(strval(...), $values);

        foreach ($choices as $choice) {
            if (! in_array((string) $choice, $declared, true)) {
                return false;
            }
        }

        return true;
    }
}

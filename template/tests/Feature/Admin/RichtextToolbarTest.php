<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use AgentCms\Core\Html\Purifier;
use Tests\TestCase;

/**
 * 書式ボタンが出す tag は、**公開でも残る**（Phase 9.15 / STAGE 28D・SPEC §D）。
 *
 * ## なぜこの検査が要るのか
 *
 * ボタンが `<div>` を出せば、編集画面では入力欄に見えているのに
 * **公開すると消える**。編集する人にとって、それは**画面が嘘をついた**ことになる。
 *
 * 「allowlist から採ること」は口約束では守れない。ボタンを 1 つ足すときに
 * allowlist を見る人はいない。**ずれたら赤くなる形**にしておく。
 */
final class RichtextToolbarTest extends TestCase
{
    /**
     * ボタンの定義を持つファイル。
     *
     * `richtext.ts` は HTML 編集の書式ボタン（28D）、`richtext-editor.ts` は
     * 見たまま編集のボタン（2026-09-09・R02）。**どちらも同じ約束**なので、
     * 両方を読む。片方だけ読むと、もう片方が allowlist から外れても緑のまま。
     */
    private const TOOLBARS = [
        __DIR__.'/../../../resources/js/lib/richtext.ts',
        __DIR__.'/../../../resources/js/lib/richtext-editor.ts',
    ];

    /**
     * 中身で挟むだけでは足りない tag と、その最小の形。
     *
     * 属性が要るものはここで与える — 属性込みで残ることまで見ないと、
     * 「名前は allowlist にあるが、書いた形は消える」を取り逃がす。
     * `a` は見たまま編集が出す形（href 付き）で見る。
     */
    private const VOID_SAMPLES = [
        'img' => '<img src="/storage/media/a.png" alt="せつめい">',
        'a' => '<a href="https://example.com/">なかみ</a>',
    ];

    /**
     * **ボタンが出す tag は、すべて allowlist の中にある。**
     */
    public function test_ボタンが出すtagはすべてallowlistの中にある(): void
    {
        $allowed = Purifier::allowedTags();
        $tags = $this->toolbarTags();

        $this->assertNotSame([], $tags, 'ボタンが 1 つも読み取れていない（検査が空振りしている）');

        foreach ($tags as $tag) {
            $this->assertContains($tag, $allowed, "書式ボタンが <{$tag}> を出すが、公開では消える");
        }
    }

    /**
     * **本当に残ることを、sanitize を通して確かめる。**
     *
     * 名前の一致だけでは足りない。allowlist の書き方（属性の指定など）を
     * 読み違えていても、名前は一致してしまう。
     */
    public function test_ボタンが出すhtmlはsanitizeを通っても残る(): void
    {
        $purifier = app(Purifier::class);

        foreach ($this->toolbarTags() as $tag) {
            /*
             * 空要素は中身で挟めない（WP 一周 7 周目 2026-09-03）。
             *
             * `img` を足したときに、`<img>なかみ</img>` を作って落ちた。
             * **試験の作り方の問題**であって、ボタンの問題ではない。
             * 必須の属性もここで与える（`src` の無い `img` は落ちる）。
             */
            if (isset(self::VOID_SAMPLES[$tag])) {
                $this->assertStringContainsString(
                    "<{$tag} ",
                    $purifier->clean(self::VOID_SAMPLES[$tag]),
                    "<{$tag}> が sanitize で消えた",
                );

                continue;
            }

            // list は中身が要る（空だと AutoFormat.RemoveEmpty が落とす）
            $html = in_array($tag, ['ul', 'ol'], true)
                ? "<{$tag}><li>なかみ</li></{$tag}>"
                : "<{$tag}>なかみ</{$tag}>";

            $this->assertStringContainsString(
                "<{$tag}>",
                $purifier->clean($html),
                "<{$tag}> が sanitize で消えた",
            );
        }
    }

    /**
     * 見たまま編集が**扱えるとみなす** tag（`richtext-editor.ts` の `VISUAL_EDITOR_TAGS`）は、
     * **公開で残る tag と同じ集合**である。
     *
     * ## なぜ集合ごと見るのか
     *
     * 上の 2 つはボタンが出す tag しか見ない。だが編集画面はもう 1 つ判断をしている —
     * 「この本文は見たままで開けるか」である。その集合が allowlist より**広い**と、
     * 見たままで編集できたものが公開で消える。**狭い**と、公開では残るのに
     * 「取り除かれます」と嘘の帯を出す。どちらも画面が嘘をつく。
     *
     * 古い書き方（`b` / `i` / `strike` / `del`）は編集時に `strong` / `em` / `s` へ
     * 読み替えられるので、**別名として除く**。
     */
    public function test_見たまま編集が扱える集合はallowlistと同じ(): void
    {
        $aliases = ['b', 'i', 'strike', 'del'];

        $visual = array_values(array_diff($this->visualEditorTags(), $aliases));
        $allowed = Purifier::allowedTags();

        sort($visual);
        sort($allowed);

        $this->assertNotSame([], $visual, '集合が 1 つも読み取れていない（検査が空振りしている）');
        $this->assertSame(
            $allowed,
            $visual,
            '見たまま編集が扱える tag と、公開で残る tag がずれている',
        );
    }

    /**
     * `VISUAL_EDITOR_TAGS` の中身を読む。
     *
     * @return list<string>
     */
    private function visualEditorTags(): array
    {
        $source = (string) file_get_contents(self::TOOLBARS[1]);

        if (preg_match('/VISUAL_EDITOR_TAGS[^=]*=\s*new Set\(\[(.*?)\]\)/s', $source, $block) !== 1) {
            $this->fail('VISUAL_EDITOR_TAGS が読み取れない（名前か形が変わった）');
        }

        preg_match_all("/'([a-z0-9]+)'/", $block[1], $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * ボタンの定義から tag 名を読む。
     *
     * **注釈を先に落とす。** docblock に書いた `<div>` のような例が
     * 定義として数えられると、**検査が本文ではなく説明文を見る**ことになる。
     *
     * @return list<string>
     */
    private function toolbarTags(): array
    {
        $tags = [];

        foreach (self::TOOLBARS as $file) {
            $source = (string) file_get_contents($file);
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $source = (string) preg_replace('#//[^\n]*#', '', $source);

            // `tag: null`（undo / redo）は引用符が無いので、ここには掛からない
            preg_match_all("/tag:\s*'([a-z0-9]+)'/", $source, $matches);
            $tags = [...$tags, ...$matches[1]];
        }

        return array_values(array_unique($tags));
    }
}

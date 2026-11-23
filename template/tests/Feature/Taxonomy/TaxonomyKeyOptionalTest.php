<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\CreateTaxonomyDefinition;
use AgentCms\Core\Taxonomy\Taxonomy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 分類を**名前だけで作れる**ようにする（WP 一周 6 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * 「分類を追加」の**最初の欄**が `分類キー（変更できません）` で、
 * `^[a-z][a-z0-9_]*$` の必須だった。表示名に「診療科目」と入れて作成を押しても、
 * ブラウザの検証で止まる — **英字のキーを自分で考えないと 1 歩も進めない**。
 *
 * 製品は他の場所では「機械の名前を書かせない」方針で揃えてきた:
 * 投稿の URL は日付から（PR #7）、ページの URL は空でよく（PR #2）、
 * 分類の**項目**の綴りも「空のままで自動生成」。**分類そのものだけ**が残っていた。
 *
 * ## キーは URL に出る
 *
 * 分類ごとの一覧（`/blog/{キー}/{項目}`）を入れたので、キーは訪問者にも見える。
 * だから**英字の名前からは名前どおりに作る**（`Press Release` → `press_release`）。
 * 日本語だけの名前からは作れないので `taxonomy_1` へ倒す
 * （`-` はキーに使えないため `_`）。
 *
 * 作ったあとに変えられないのは**これまでどおり**である。
 * 画面は作る前に直せることと、URL に出ることを言う。
 */
final class TaxonomyKeyOptionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_名前だけで作れる(): void
    {
        $result = $this->create(['label' => '診療科目']);

        $this->assertTrue($result, '名前だけでは作れなかった');
        $this->assertSame('taxonomy_1', Taxonomy::query()->value('key'));
    }

    public function test_英字の名前はそのままキーになる(): void
    {
        $this->create(['label' => 'Press Release']);

        $this->assertSame('press_release', Taxonomy::query()->value('key'));
    }

    public function test_日本語の名前が続いたら通し番号が増える(): void
    {
        $this->create(['label' => '診療科目']);
        $this->create(['label' => '地域']);

        $this->assertSame(
            ['taxonomy_1', 'taxonomy_2'],
            Taxonomy::query()->orderBy('created_at')->pluck('key')->all(),
        );
    }

    /** 自分で書いたキーは、そのまま使う */
    public function test_自分で書いたキーは尊重する(): void
    {
        $this->create(['key' => 'shinryo_kamoku', 'label' => '診療科目']);

        $this->assertSame('shinryo_kamoku', Taxonomy::query()->value('key'));
    }

    /** 名前から作ったキーが埋まっていたら、通し番号へ逃がす */
    public function test_同じ英字の名前が2つ目なら通し番号になる(): void
    {
        $this->create(['label' => 'News']);
        $this->create(['label' => 'News']);

        $this->assertSame(
            ['news', 'taxonomy_1'],
            Taxonomy::query()->orderBy('created_at')->pluck('key')->all(),
        );
    }

    /** 🔴 **名前も無ければ作れない。** 何も無いところから名前は作れない */
    public function test_名前が無ければ作れない(): void
    {
        $this->assertFalse($this->create(['label' => '']));
    }

    /** 形の違うキーを書いたときは、これまでどおり弾く（黙って直さない） */
    public function test_形の違うキーは弾く(): void
    {
        $this->assertFalse($this->create(['key' => 'Shinryo-Kamoku', 'label' => '診療科目']));
    }

    /** @param array<string, mixed> $input */
    private function create(array $input): bool
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        return app(CreateTaxonomyDefinition::class)->execute(
            $input,
            OperationContext::admin((int) $admin->getKey(), (string) $admin->name),
        )->isSuccess();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\PlanIssue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 取り込めないものを**全部**言う（Phase 9.14 / STAGE 27C・SPEC §A.3）。
 *
 * ## 1 件目で止めない
 *
 * 型が無い、分類が無い、id が長すぎる。これらを見つけた順に 1 件ずつ投げると、
 * 利用者は**直しては実行し、また別の理由で止まる**を何十回も繰り返すことになる。
 * 十年分の移行でそれをやらせるのは、実質的に移行させないのと同じである。
 *
 * ## 黙って省略しない
 *
 * 数千件を全部並べても読めない。だが「先頭 50 件」だけを出して総数を伏せると、
 * **利用者は 50 件直せば終わると思う**。出す件数は絞り、
 * **総数と省略した件数は必ず言う**。
 */
final class PlanFindingsTest extends TestCase
{
    public function test_見つけたものを理由ごとにまとめる(): void
    {
        $findings = new PlanFindings;

        $findings->add(PlanIssue::UnknownType, '11', 'post');
        $findings->add(PlanIssue::UnknownType, '12', 'post');
        $findings->add(PlanIssue::MissingTaxonomy, 'news', 'category');

        $summary = $findings->toArray();

        $this->assertSame(2, $summary['unknown_type']['total']);
        $this->assertSame(1, $summary['missing_taxonomy']['total']);
    }

    public function test_理由は名前の順に並ぶ(): void
    {
        $findings = new PlanFindings;

        $findings->add(PlanIssue::MissingTaxonomy, 'news', 'category');
        $findings->add(PlanIssue::UnknownType, '11', 'post');

        // 見つけた順に並ぶと、同じ結果が実行のたびに別物に見える
        $this->assertSame(['missing_taxonomy', 'unknown_type'], array_keys($findings->toArray()));
    }

    public function test_出すのは外部idと元の種類と理由だけ(): void
    {
        /*
         * **本文も著者名もここへ来ない。** 明細は画面にも台帳にも出るので、
         * 他人の個人情報を写す経路にしない。
         */
        $findings = new PlanFindings;
        $findings->add(PlanIssue::UnknownType, '11', 'post');

        $this->assertSame(
            [['external_id' => '11', 'source_type' => 'post']],
            $findings->toArray()['unknown_type']['shown'],
        );
    }

    // ── 黙って省略しない ────────────────────────────────────

    public function test_多すぎるときは総数と省略数を必ず言う(): void
    {
        $findings = new PlanFindings;

        foreach (range(1, PlanFindings::DETAIL_CAP + 7) as $id) {
            $findings->add(PlanIssue::UnknownType, (string) $id, 'post');
        }

        $summary = $findings->toArray()['unknown_type'];

        $this->assertCount(PlanFindings::DETAIL_CAP, $summary['shown']);
        $this->assertSame(PlanFindings::DETAIL_CAP + 7, $summary['total']);
        // **省略したことを言わないと、利用者は 50 件直せば終わると思う**
        $this->assertSame(7, $summary['omitted']);
    }

    public function test_収まるときは省略数が0になる(): void
    {
        $findings = new PlanFindings;
        $findings->add(PlanIssue::UnknownType, '11', 'post');

        $this->assertSame(0, $findings->toArray()['unknown_type']['omitted']);
    }

    public function test_上限を超えても総数は数え続ける(): void
    {
        // 明細を捨てても**数えるのはやめない**
        $findings = new PlanFindings;

        foreach (range(1, 5000) as $id) {
            $findings->add(PlanIssue::UnknownType, (string) $id, 'post');
        }

        $this->assertSame(5000, $findings->toArray()['unknown_type']['total']);
    }

    // ── 明細に何でも入れさせない ────────────────────────────

    public function test_改行や制御文字を明細に入れられない(): void
    {
        /*
         * 明細は画面へ 1 行 1 件で出る。改行を通すと表示が崩れるだけでなく、
         * **本文をそのまま流し込む経路**になる。
         */
        $this->expectException(InvalidArgumentException::class);

        (new PlanFindings)->add(PlanIssue::UnknownType, "11\n本文がここに入ってしまう", 'post');
    }

    public function test_元の種類はwxrの識別子の形しか受けない(): void
    {
        // 端末の escape も、長い説明文も、ここへは入らない
        $this->expectException(InvalidArgumentException::class);

        (new PlanFindings)->add(PlanIssue::UnknownType, '11', "\e[31mpost\e[0m");
    }

    public function test_長すぎる外部idは生の値を持たない(): void
    {
        /*
         * **「長すぎる」を報告するために長い値を 50 件持つのは本末転倒である。**
         * 台帳に収まらない id は、本文級の長さで来ることがある。
         * 見分けが付く印だけを残し、生の値は捨てる。
         */
        $long = str_repeat('あ', 500);

        $findings = new PlanFindings;
        $findings->add(PlanIssue::ExternalIdTooLong, $long, 'post');

        $shown = $findings->toArray()['external_id_too_long']['shown'][0]['external_id'];

        $this->assertStringNotContainsString('あ', $shown);
        $this->assertLessThanOrEqual(PlanFindings::EXTERNAL_ID_DISPLAY_MAX, strlen($shown));
        // 同じ値なら同じ印になる（別物と取り違えない）
        $this->assertSame($shown, $this->shownFor($long));
        $this->assertNotSame($shown, $this->shownFor(str_repeat('い', 500)));
    }

    // ── plan を出してよいかどうか ────────────────────────────

    public function test_何も無ければplanを出してよい(): void
    {
        $this->assertFalse((new PlanFindings)->blocksPlan());
    }

    public function test_取り込めないものがあればplanを出さない(): void
    {
        /*
         * **警告つきで hash を出さない。** 出せば利用者はそれを apply へ貼る。
         * 「問題はあるが通した plan」を一度でも許すと、次からは全部それになる。
         */
        $findings = new PlanFindings;
        $findings->add(PlanIssue::UnknownType, '11', 'post');

        $this->assertTrue($findings->blocksPlan());
    }

    public function test_指定したのに使われなかった対応は報告するが止めない(): void
    {
        /*
         * `--type=note:diary` と書いたのに WXR に `note` が 1 件も無い、は
         * **書き間違いの可能性が高いので言う**。だが取り込み自体は成立するので、
         * 止めはしない。
         */
        $findings = new PlanFindings;
        $findings->add(PlanIssue::UnusedTypeMapping, 'note', 'note');

        $this->assertFalse($findings->blocksPlan());
        $this->assertSame(1, $findings->toArray()['unused_type_mapping']['total']);
    }

    public function test_止める理由と止めない理由が混ざっても止める(): void
    {
        $findings = new PlanFindings;
        $findings->add(PlanIssue::UnusedTypeMapping, 'note', 'note');
        $findings->add(PlanIssue::MissingTaxonomy, 'news', 'category');

        $this->assertTrue($findings->blocksPlan());
    }

    // ── 内訳 ────────────────────────────────────────────────

    public function test_内訳を渡した理由には内訳が付く(): void
    {
        /*
         * 「3,000 件を下書きにしました」だけでは、それが**もともと非公開だったのか、
         * ゴミ箱だったのか**が分からない。十年分を入れる前にそこを判断させる（§C.8.5）。
         */
        $findings = new PlanFindings;

        $findings->add(PlanIssue::StatusDowngradedToDraft, '101', 'post', 'private');
        $findings->add(PlanIssue::StatusDowngradedToDraft, '102', 'post', 'trash');
        $findings->add(PlanIssue::StatusDowngradedToDraft, '103', 'post', 'trash');

        $found = $findings->toArray()['status_downgraded_to_draft'];

        $this->assertSame(3, $found['total']);
        // **内訳の並びは名前順。**見つけた順に並ぶと、同じ結果が別物に見える
        $this->assertSame(['private' => 1, 'trash' => 2], $found['by_source']);
    }

    public function test_内訳を渡さない理由の出力は変わらない(): void
    {
        /*
         * 既存の理由の出力へ空の内訳を足すと、**何も変わっていないのに
         * 出力が変わる**。読む側の期待値を動かさない。
         */
        $findings = new PlanFindings;
        $findings->add(PlanIssue::LargeBody, '101', 'post');

        $this->assertSame(
            ['total' => 1, 'shown' => [['external_id' => '101', 'source_type' => 'post']], 'omitted' => 0],
            $findings->toArray()['large_body'],
        );
    }

    public function test_内訳の鍵にも形を求める(): void
    {
        /*
         * 内訳の鍵は画面にも台帳にも出る。**本文が鍵として流れ込む経路**を作らない。
         */
        $findings = new PlanFindings;

        $this->expectException(InvalidArgumentException::class);

        $findings->add(PlanIssue::StatusDowngradedToDraft, '101', 'post', "非公開\nです");
    }

    public function test_明細が上限に達しても内訳は数え続ける(): void
    {
        // 明細は絞る。**総数と内訳は伏せない**（§A.3）
        $findings = new PlanFindings;

        for ($i = 1; $i <= 120; $i++) {
            $findings->add(PlanIssue::StatusDowngradedToDraft, (string) $i, 'post', 'trash');
        }

        $found = $findings->toArray()['status_downgraded_to_draft'];

        $this->assertCount(PlanFindings::DETAIL_CAP, $found['shown']);
        $this->assertSame(['trash' => 120], $found['by_source']);
    }

    public function test_どの理由が止めるのかを一覧で固定する(): void
    {
        /*
         * **新しい理由を足したときに、既定で「止めない」にならないようにする。**
         * 止めるかどうかを決め忘れた理由が通ると、
         * 取り込めないものが混ざったまま apply される。
         */
        $blocking = array_values(array_filter(
            array_map(static fn (PlanIssue $i): ?string => $i->blocksPlan() ? $i->value : null, PlanIssue::cases()),
        ));

        $this->assertSame([
            'comment_field_too_long',
            'comment_parent_cycle',
            'comment_status_unreadable',
            'external_id_too_long',
            'ledger_conflict',
            'ledger_target_missing',
            'ledger_target_trashed',
            'missing_taxonomy',
            'page_body_too_large',
            'page_body_unrepresentable',
            'page_schedule_unreadable',
            'page_title_too_long',
            'required_field_missing',
            'required_taxonomy_missing',
            'source_changed_during_plan',
            'source_status_unreadable',
            'target_id_too_long',
            'target_type_too_long',
            'taxonomy_archived',
            'taxonomy_hierarchy_mismatch',
            'taxonomy_managed_by_mismatch',
            'taxonomy_not_declared_for_type',
            'taxonomy_not_synced',
            'term_archived',
            'term_definition_conflict',
            'term_definition_missing',
            'term_id_duplicate',
            'term_name_too_long',
            'term_parent_cycle',
            'term_parent_missing',
            'term_parent_self',
            'term_storage_slug_invalid',
            'term_target_slug_conflict',
            'too_many_term_definitions',
            'type_cannot_create',
            'type_comments_disabled',
            'type_not_editorial',
            'type_not_public',
            'type_not_reachable',
            'unknown_type',
            'unmappable_required_field',
        ], $this->sorted($blocking));
    }

    public function test_入れないと決めたものと入れられないものを混ぜない(): void
    {
        /*
         * ページやメニュー項目は**仕様として入れない**（§A.2）ので止めない。
         * 対応先が無くて入れられない（`UnknownType`）のとは別物である。
         *
         * **同じ数え方にすると、どちらなのか分からなくなる。**
         * 「12 件入りませんでした」だけでは、設定を直せば入るのか、
         * 元から対象外なのかが読めない。
         */
        foreach ([PlanIssue::SkippedNavMenuItem, PlanIssue::SkippedAttachment] as $skipped) {
            $this->assertFalse($skipped->blocksPlan(), $skipped->value.' は止めない');
        }

        $this->assertTrue(PlanIssue::UnknownType->blocksPlan());
    }

    public function test_取り込むが先に言うものは止めない(): void
    {
        // shortcode は文字として残す（§C.5）。動かないことを**移行の前に**言う
        foreach ([PlanIssue::ShortcodePresent, PlanIssue::MetaDropped, PlanIssue::LargeBody] as $reported) {
            $this->assertFalse($reported->blocksPlan(), $reported->value.' は止めない');
        }
    }

    public function test_途中でファイルが差し替わったら止める(): void
    {
        /*
         * 指紋と中身は別々に開くので、その間に差し替えられると
         * **「指紋は前のファイル・中身は後のファイル」の plan** ができる。
         * それを apply へ渡せば、照合は通るのに入るものが違う。
         */
        $this->assertTrue(PlanIssue::SourceChangedDuringPlan->blocksPlan());
    }

    public function test_同じ分類項目の重複は3つに分けて扱う(): void
    {
        /*
         * (a) 同じ `wp:term_id` が 2 回 … **中身が同じでも止める**。
         *     台帳は id で覚えるので、2 件目が不可視になる
         * (b) id は違うが分類・slug・名前・親が全部一致 … 1 つ作って使い回せる
         * (c) 同じ slug で中身が食い違う … どちらを採るか決められない
         */
        $this->assertTrue(PlanIssue::TermIdDuplicate->blocksPlan());
        $this->assertFalse(PlanIssue::TermDefinitionRepeated->blocksPlan());
        $this->assertTrue(PlanIssue::TermDefinitionConflict->blocksPlan());
    }

    /** 明細へ実際に出た外部 id */
    private function shownFor(string $externalId): string
    {
        $findings = new PlanFindings;
        $findings->add(PlanIssue::ExternalIdTooLong, $externalId, 'post');

        return $findings->toArray()['external_id_too_long']['shown'][0]['external_id'];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}

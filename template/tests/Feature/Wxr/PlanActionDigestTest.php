<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanActionDigest;
use AgentCms\Core\Wxr\Plan\RelevantTargetState;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 「これから何が起きるか」を 1 つの値にまとめる（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## なぜ本文を持たないのか
 *
 * 十年分の記事を canonical な配列に組み立てると、**メモリが持たない**。
 * そして本文・著者名・メールアドレスは**他人の個人情報**である。
 * それを台帳や画面出力へ写すのは、移行の副作用として一番やってはいけない。
 *
 * だから持つのは「何を・どこへ・どうするか」の骨だけで、
 * 1 件ずつ**逐次 hash** して畳み込む。件数と digest だけが外へ出る。
 *
 * ## なぜ順番に意味を持たせるのか
 *
 * 同じ集合でも順番が違えば、途中で落ちたときに残るものが変わる。
 * WXR に書かれている順で畳むので、**同じファイルなら必ず同じ値**になる。
 */
final class PlanActionDigestTest extends TestCase
{
    public function test_同じ手順なら同じ値になる(): void
    {
        $this->assertSame(
            $this->digestOf($this->threeActions())->digest(),
            $this->digestOf($this->threeActions())->digest(),
        );
    }

    public function test_順番が違えば違う値になる(): void
    {
        /*
         * 集合が同じでも、**途中で落ちたときに残るものが変わる**。
         * 「同じことをする plan」とは言えない。
         */
        $forward = $this->threeActions();
        $reversed = array_reverse($forward);

        $this->assertNotSame(
            $this->digestOf($forward)->digest(),
            $this->digestOf($reversed)->digest(),
        );
    }

    public function test_1件でも変われば違う値になる(): void
    {
        $changed = $this->threeActions();
        // 作る予定だったものが、既にあるので飛ばす予定に変わった
        $changed[0][3] = PlanAction::SkipExisting;

        $this->assertNotSame(
            $this->digestOf($this->threeActions())->digest(),
            $this->digestOf($changed)->digest(),
        );
    }

    public function test_区切りをまたいで同じ値にならない(): void
    {
        /*
         * 素朴に連結すると `a` + `bc` と `ab` + `c` が同じ文字列になる。
         * **境界が曖昧な連結は、別々の plan を同じ値にする。**
         */
        $one = $this->digestOf([['content', 'a', 'bc', PlanAction::Create, null, new RelevantTargetState]]);
        $other = $this->digestOf([['content', 'ab', 'c', PlanAction::Create, null, new RelevantTargetState]]);

        $this->assertNotSame($one->digest(), $other->digest());
    }

    public function test_行動ごとの件数を数える(): void
    {
        $digest = $this->digestOf([
            ['content', '11', 'post', PlanAction::Create, null, new RelevantTargetState],
            ['content', '12', 'post', PlanAction::Create, null, new RelevantTargetState],
            ['content', '13', 'post', PlanAction::SkipExisting, '5', new RelevantTargetState],
            // term の外部 id は **wp:term_id**（slug は突き合わせの状態として持つ）
            ['term', '3', 'blog_category', PlanAction::Conflict, null, new RelevantTargetState],
            ['term', '7', 'blog_tag', PlanAction::Reuse, '9', new RelevantTargetState(matchedSlug: 'launch')],
        ]);

        $this->assertSame(
            ['conflict' => 1, 'create' => 2, 'reuse' => 1, 'skip_existing' => 1],
            $digest->counts(),
        );
    }

    public function test_既にある受け皿の再利用を作成と区別する(): void
    {
        /*
         * 分類の項目は「既にあるので使う」が正常な結果である（§C.3）。
         * これを `create` と混ぜると**作った件数が水増しされ**、
         * `skip_existing` と混ぜると「台帳にあるから触らない」と区別がつかなくなる。
         * 取り消しのときに**何を消してよいか**が変わるので、別の名前で数える。
         */
        $reuse = $this->digestOf([
            ['term', '3', 'blog_category', PlanAction::Reuse, '9', new RelevantTargetState(matchedSlug: 'news')],
        ]);
        $create = $this->digestOf([
            ['term', '3', 'blog_category', PlanAction::Create, null, new RelevantTargetState(matchedSlug: 'news')],
        ]);

        $this->assertSame(['reuse' => 1], $reuse->counts());
        $this->assertNotSame($create->digest(), $reuse->digest());
    }

    public function test_件数は行動の名前で並ぶ(): void
    {
        // 出す順が実行順で揺れると、**同じ結果が別物に見える**
        $digest = $this->digestOf([
            ['term', '3', 'blog_category', PlanAction::Conflict, null, new RelevantTargetState],
            ['content', '11', 'post', PlanAction::Create, null, new RelevantTargetState],
        ]);

        $this->assertSame(['conflict', 'create'], array_keys($digest->counts()));
    }

    public function test_何もしないplanにも値がある(): void
    {
        // 0 件は「壊れている」ではない。**空を特別扱いしない**
        $empty = $this->digestOf([]);

        $this->assertSame(64, strlen($empty->digest()));
        $this->assertSame([], $empty->counts());
        $this->assertNotSame($empty->digest(), $this->digestOf($this->threeActions())->digest());
    }

    // ── 畳み方そのものを固定する ────────────────────────────

    public function test_畳み方を変えたら値が変わる(): void
    {
        /*
         * **実装だけで閉じない。**
         *
         * 1 件の切れ目（要素数の前置）を消しても、いまの要素数では
         * どのテストも落ちない。だが `RelevantTargetState` に項目が増えれば、
         * 「N 要素が 2 件」と「2N 要素が 1 件」が同じバイト列になり得る。
         * 件数が違うのに値が同じになったとき、**気づく手段が無くなる**。
         *
         * だから既知の値を固定しておく。畳み方を変えたら必ずここが赤くなり、
         * 「変えてよい変更か」を人が判断することになる。
         * 値を更新するときは `PlanFingerprint::CONTRACT_VERSION` も上げること
         * （古い hash を通さないため）。
         */
        $digest = $this->digestOf([
            ['content', '11', 'post', PlanAction::Create, null, new RelevantTargetState],
            ['term', '3', 'blog_category', PlanAction::Reuse, '9', new RelevantTargetState(
                matchedSlug: 'news',
            )],
        ]);

        $this->assertSame(
            'cbd246d115478932e5cc27d7bace97784924458237c123918db1ad099ed9798d',
            $digest->digest(),
        );
    }

    // ── 個人情報と本文を持ち込まない ────────────────────────

    public function test_状態は決め打った項目しか受け取らない(): void
    {
        /*
         * **持てない形にしておく。** 引数の名前を固定しても、
         * そこが自由な文字列なら本文でもメールでも入ってしまう。
         * 入れられる項目そのものを型で決める。
         */
        $state = (new \ReflectionMethod(PlanActionDigest::class, 'add'))->getParameters()[5];

        $this->assertSame(RelevantTargetState::class, (string) $state->getType());
    }

    public function test_状態に改行や制御文字を入れられない(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RelevantTargetState(matchedSlug: "news\n本文がここに入ってしまう");
    }

    public function test_状態に自由なラベルを入れられない(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 状態の名前ではなく、説明文を詰めようとした場合
        new RelevantTargetState(targetStatus: '著者は山田太郎です');
    }

    public function test_負のlock版を受け取らない(): void
    {
        /*
         * 版は「こちらで何回更新されたか」なので負にならない。
         * 受けてしまうと、**あり得ない状態を hash に畳んで**別の plan を作れる。
         */
        $this->expectException(InvalidArgumentException::class);

        new RelevantTargetState(lockVersion: -1);
    }

    public function test_0回更新のlock版は通る(): void
    {
        // 一度も更新されていないのは正しい状態。0 を弾かない
        $this->assertSame(['0', '', '0', '', ''], (new RelevantTargetState(lockVersion: 0))->canonical());
    }

    public function test_更新時刻は正規化した形しか受け取らない(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 表記の揺れがそのまま hash の揺れになる
        new RelevantTargetState(updatedAt: '2026-08-18 09:00:00');
    }

    public function test_使ってよい状態はそのまま通る(): void
    {
        $state = new RelevantTargetState(
            hasImportRecord: true,
            targetStatus: 'published',
            lockVersion: 3,
            updatedAt: '2026-08-18T00:00:00Z',
            matchedSlug: 'first-post',
        );

        $this->assertSame(['1', 'published', '3', '2026-08-18T00:00:00Z', 'first-post'], $state->canonical());
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @return list<array{0: string, 1: string, 2: string, 3: PlanAction, 4: ?string, 5: RelevantTargetState}> */
    private function threeActions(): array
    {
        return [
            ['content', '11', 'post', PlanAction::Create, null, new RelevantTargetState],
            ['content', '12', 'post', PlanAction::SkipExisting, '5', new RelevantTargetState(
                hasImportRecord: true,
                lockVersion: 3,
            )],
            // term の外部 id は slug ではなく **WXR の wp:term_id**。slug は突き合わせの状態
            ['term', '3', 'blog_category', PlanAction::Create, null, new RelevantTargetState(
                matchedSlug: 'news',
            )],
        ];
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: PlanAction, 4: ?string, 5: RelevantTargetState}> $actions */
    private function digestOf(array $actions): PlanActionDigest
    {
        $digest = new PlanActionDigest;

        foreach ($actions as [$kind, $externalId, $targetType, $action, $targetId, $state]) {
            $digest->add($kind, $externalId, $targetType, $action, $targetId, $state);
        }

        return $digest;
    }
}

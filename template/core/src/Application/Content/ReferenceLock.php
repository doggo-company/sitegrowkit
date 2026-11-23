<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 参照先を transaction の中で押さえ直す（Phase 9.8 / STAGE 21C・SPEC §G.5・§G.5.1）。
 *
 * ## transaction の外で確かめただけでは足りない
 *
 * 「参照先が在ること」を transaction の前に確かめても、開くまでのあいだに
 * 消える。動的な投稿タイプには **DB の外部キーを張れない**ので、DB は守ってくれない。
 * 通ってしまえば、**存在しない ID を指す参照**がそのまま保存される。
 *
 * だから transaction の中で `lockForUpdate` し、**在ることをもう一度確かめる**。
 * こうすると、参照を作る側と（将来の）purge が同じ行の上で直列化される。
 *
 * ## 順序を決める理由
 *
 * 参照は複数の型・複数の ID に及ぶ。順序が決まっていないと、2 つの transaction が
 * 互いの行を待って止まる。**常に `(content_type, id)` の昇順**でロックし、
 * **自分の entry も同じ 1 本の並びに混ぜる**。自分を先に、次に参照先を、という
 * 二段構えにすると、逆順で来た相手と循環する。
 *
 * 型ごとの分岐は書かない。並べ替えの規則が 1 つあれば足りる。
 */
final class ReferenceLock
{
    /** deadlock を掴んだときのやり直し上限（SPEC §G.5.1） */
    public const MAX_RETRIES = 2;

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ContentReferences $references,
    ) {}

    /**
     * transaction を張って走らせ、deadlock なら**やり直す**（SPEC §G.5.1）。
     *
     * やり直しても部分的な書き込みは残らない。transaction ごと巻き戻してから
     * 最初からやるだけである。上限を超えたら competition として conflict を返す
     * — 黙って諦めたり、片方だけ書いたりしない。
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws ContentWriteAbort
     * @throws QueryException deadlock 以外の DB エラー、または再試行の上限超過
     */
    public function attempt(Closure $work): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction($work);
            } catch (QueryException $e) {
                $attempt++;

                if ($attempt > self::MAX_RETRIES || ! $this->isDeadlock($e)) {
                    throw $e;
                }

                // 相手が先に進めるよう、同じ順序で取り直す
                report($e);
            }
        }
    }

    /**
     * deadlock だったか。
     *
     * MySQL は 40001 / 1213、SQLite は「database is locked」で知らせる。
     * driver ごとの分岐をここ 1 か所に閉じ込める。
     */
    private function isDeadlock(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return $code === '40001'
            || str_contains($message, 'deadlock')
            || str_contains($message, 'database is locked');
    }

    /**
     * 入力に含まれる参照を **(content_type, id) 昇順**で押さえ、存在を確かめる。
     *
     * @param  array<string, mixed>  $values
     * @param  array{type: string, id: int}|null  $self  自分の entry（同じ並びへ混ぜる）
     * @return array<string, list<string>> 欠けていた参照。空なら妥当
     */
    public function lock(ContentSchema $schema, array $values, ?array $self = null): array
    {
        $targets = $this->references->targets($schema, $values);

        if ($self !== null) {
            // 自分も 1 本の並びに混ぜる（自分 → 参照先の二段にしない）
            $targets[] = ['type' => $self['type'], 'id' => $self['id'], 'path' => null];
        }

        // **これが deadlock を防いでいる唯一の仕掛けである**
        usort($targets, static function (array $a, array $b): int {
            return $a['type'] === $b['type']
                ? $a['id'] <=> $b['id']
                : strcmp($a['type'], $b['type']);
        });

        $errors = [];
        $checked = [];

        foreach ($targets as $target) {
            $key = $target['type'].'#'.$target['id'];

            if (isset($checked[$key])) {
                continue;
            }

            $checked[$key] = true;

            if (! $this->schemas->has($target['type'])) {
                continue;
            }

            $table = $this->schemas->get($target['type'])->table;

            /*
             * **生の query builder で引く。** ゴミ箱に入っている参照先も
             * 「在る」と数える — trash はデータを失わないので、参照が壊れたわけではない
             * （OG4）。ここで除くと、ゴミ箱へ入れた瞬間に他の投稿が保存できなくなる。
             */
            $found = DB::table($table)
                ->where('id', $target['id'])
                ->lockForUpdate()
                ->value('id');

            if ($found === null && $target['path'] !== null) {
                $errors[$target['path']][] = $this->schemas->get($target['type'])->label
                    .'に存在しない参照が含まれています（ID: '.$target['id'].'）';
            }
        }

        return $errors;
    }
}

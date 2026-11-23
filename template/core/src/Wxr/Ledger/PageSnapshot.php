<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * ページの「いまの姿」を 1 つの文字列に畳む（release-20260909 / wxr-pages）。
 *
 * ## 時刻で判定しない
 *
 * `updated_at` は秒精度なので、取り込んだ**同じ秒**に人（や AI）が直した編集を
 * 見逃す。取り込んだ直後にこれを台帳へ残し、取り消すときに同じ関数で
 * 今の姿を取って**文字列として一致するか**だけを見る。
 * 一致しなければ何かが変わっている — 何が変わったかは問わず、残す。
 *
 * ## 版は id・revision・本文の hash
 *
 * 版を足した・差し替えた・消した、のどれでも一致しなくなる。
 * 本文そのものは台帳へ入れない（hash で足りる。台帳が本文の複製にならない）。
 */
final class PageSnapshot
{
    /** 行の値のうち、人が触ると変わるもの。`updated_at` は入れない（時刻で判定しない） */
    private const PAGE_COLUMNS = [
        'title', 'slug', 'status', 'managed_by', 'seo',
        'draft_version_id', 'published_version_id', 'published_at', 'trashed_at',
    ];

    /** 今の姿。ページの行が無ければ `null` */
    public static function of(int $pageId): ?string
    {
        $page = DB::table('pages')->where('id', $pageId)->first();

        if ($page === null) {
            return null;
        }

        $row = [];

        foreach (self::PAGE_COLUMNS as $column) {
            $value = $page->{$column} ?? null;
            $row[$column] = $value === null ? null : (string) $value;
        }

        $versions = DB::table('page_versions')
            ->where('page_id', $pageId)
            ->orderBy('revision')
            ->get(['id', 'revision', 'document'])
            ->map(static fn (object $v): array => [
                'id' => (string) $v->id,
                'revision' => (string) $v->revision,
                'hash' => hash('sha256', (string) $v->document),
            ])
            ->all();

        // 本文が同じでも、取り込み後にトップやメニューへ採用されたページは消さない。
        // キャッシュされた設定ではなく、削除判定時のDBを読む。
        $front = json_decode((string) DB::table('settings')->where('key', 'front_page_id')->value('value'), true);
        $references = [
            'front_page' => is_scalar($front) && (string) $front === (string) $pageId,
            'menu_items' => DB::table('menu_items')->where('page_id', $pageId)->orderBy('id')->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        ];

        return (string) json_encode(['page' => $row, 'versions' => $versions, 'references' => $references], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

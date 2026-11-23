<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use AgentCms\Core\Schema\SchemaRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 戻す前に「**何が失われるか**」を数える（WP 一周 9 周目 2026-09-04）。
 *
 * ## なぜ件数の増減では足りないか
 *
 * 確認は表ごとの行数の増減だけを出していた。実測で出たのはこの 2 行である:
 *
 * ```
 * audit_logs: 13 件 → 9 件
 * not_found_logs: 1 件 → 0 件
 * ```
 *
 * 機械の名前で、しかも**いちばん気にならないもの**しか出ていない。
 *
 * 🔴 **もっと悪いのは、出ないほうである。** 行数が変わらない変更は 1 行も出ない。
 * 記事の誤字を直した・公開の状態を変えた・サイト名を入れ替えた —
 * どれも行数は同じなので確認は**何も言わない**。それでも戻せば中身は置き換わる。
 *
 * **戻せない操作の前に、失われるものを見せる。** それがここの役目である。
 *
 * ## 数え方
 *
 * backup を取った時刻より後に**足された・書き換えられた**行を数える。
 * 時刻の列（`updated_at`、無ければ `created_at`）を持たない表は、
 * 変わったかどうかを言えないので**黙って外す**（構成・索引の表しか無い）。
 */
final readonly class RestoreImpact
{
    /**
     * 人が知っている名前。
     *
     * ここに無い表は「そのほかの記録」へまとめる。**機械の名前は人に見せない。**
     * 投稿タイプの表（`post` など）は定義から名前を引くので、ここには書かない。
     */
    private const LABELS = [
        'pages' => 'ページ',
        'page_versions' => 'ページの版',
        'media' => 'メディア',
        'settings' => 'サイト設定',
        'menus' => 'メニュー',
        'menu_items' => 'メニューの項目',
        'forms' => 'フォーム',
        'form_submissions' => 'フォームの受信',
        'contact_submissions' => 'お問い合わせ',
        'content_comments' => 'コメント',
        'content_comment_settings' => 'コメントの設定',
        'taxonomies' => '分類',
        'taxonomy_terms' => '分類の項目',
        'taxonomy_term_assignments' => '分類の割り当て',
        'content_types' => '投稿タイプ',
        'content_type_taxonomies' => '投稿タイプと分類の関連',
        'field_groups' => 'カスタムフィールド',
        'reusable_components' => '再利用コンポーネント',
        'redirect_rules' => 'リダイレクト',
        'users' => '利用者',
        'notification_preferences' => '通知の設定',
    ];

    /**
     * 記録の表。**戻っても失うものが無い**ので数えない。
     *
     * ここを混ぜると「そのほかの記録 12 件」がいつも出て、
     * 本当に気にすべき 1 行が埋もれる。
     */
    private const LOGS = [
        'audit_logs',
        'not_found_logs',
        'import_batches',
        'import_records',
        'spam_blocks',
        'migrations',
        'content_schema_manifests',
        'content_entry_revisions',
        'field_group_revisions',
        'passkeys',
        'push_subscriptions',
        'media_variants',
        'contact_submission_migrations',
    ];

    /*
     * 🔴 **記号は自分で書かない。** 画面はこの配列を `<ul><li>` で描くので、
     * 頭に `・` を付けると点が 2 つ並ぶ（9 周目の実測で気づいた）。
     */

    /** 題として出す列（先に見つかったものを使う） */
    private const TITLE_COLUMNS = ['title', 'name', 'label'];

    public function __construct(private SchemaRegistry $schemas) {}

    /**
     * `$takenAt`（backup の取得時刻・ISO8601）より後の変更を、人の言葉で。
     *
     * @param  list<string>  $tables  戻す対象の表
     * @return list<string>
     */
    public function since(string $takenAt, array $tables): array
    {
        // DB の日時は秒精度。同じ秒の変更も取りこぼさず、保守的に含める。
        $boundary = CarbonImmutable::parse($takenAt)
            ->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');

        $lines = [];
        $others = 0;

        foreach ($tables as $table) {
            if (in_array($table, self::LOGS, true)) {
                continue;
            }

            /** @var list<string> $columns */
            $columns = array_values(Schema::getColumnListing($table));
            $timeColumn = in_array('updated_at', $columns, true)
                ? 'updated_at'
                : (in_array('created_at', $columns, true) ? 'created_at' : null);

            if ($timeColumn === null) {
                continue;
            }

            $changed = DB::table($table)->where($timeColumn, '>=', $boundary);
            $count = (int) $changed->count();

            if ($count === 0) {
                continue;
            }

            $label = $this->label($table);

            if ($label === null) {
                $others += $count;

                continue;
            }

            // 設定は key と value の組なので、件数も題も人には読めない
            if ($table === 'settings') {
                $lines[] = 'サイト設定';

                continue;
            }

            $lines[] = $label.' '.$count.' 件'.$this->titles($table, $columns, $timeColumn, $boundary, $count);
        }

        if ($others > 0) {
            $lines[] = 'そのほかの記録 '.$others.' 件';
        }

        if ($lines === []) {
            return ['取得日時以降の更新記録は見つかりません。削除や更新日時に記録されない変更も、取得時点に戻ります'];
        }

        return [
            'この backup（'.$this->readable($takenAt).'）の取得日時以降に更新された記録です。復元すると取得時点に戻ります:',
            ...$lines,
        ];
    }

    /**
     * 代表の題を 2 つまで。残りは件数で言う。
     *
     * @param  list<string>  $columns
     */
    private function titles(string $table, array $columns, string $timeColumn, string $boundary, int $count): string
    {
        $column = null;

        foreach (self::TITLE_COLUMNS as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $column = $candidate;
                break;
            }
        }

        if ($column === null) {
            return '';
        }

        $titles = DB::table($table)
            ->where($timeColumn, '>=', $boundary)
            ->orderByDesc($timeColumn)
            ->limit(2)
            ->pluck($column)
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        if ($titles === []) {
            return '';
        }

        $rest = $count - count($titles);

        return '（'.implode(' / ', $titles).($rest > 0 ? ' ほか '.$rest.' 件' : '').'）';
    }

    /** 投稿タイプの表は定義から名前を引く（`post` を「post」と出さない） */
    private function label(string $table): ?string
    {
        if (isset(self::LABELS[$table])) {
            return self::LABELS[$table];
        }

        return $this->schemas->has($table) ? $this->schemas->get($table)->label : null;
    }

    /** 人が読む形（`2026-09-04 11:37`）。確認の見出しもここから取る */
    public function readable(string $takenAt): string
    {
        return CarbonImmutable::parse($takenAt)
            ->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i');
    }
}

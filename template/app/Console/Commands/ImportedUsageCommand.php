<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use App\Console\Concerns\SpeaksJson;
use App\Models\ImportedPageLayout;
use Illuminate\Console\Command;

/**
 * 取り込みが生成した部品・枠が、いまどのページに使われているかを答える（読み取り専用）。
 *
 * ## なぜ要るのか
 *
 * `agentcms template apply` は、新しい Plan に無い前回の生成物を整理（prune）する。
 * 別のテンプレートを再取り込みしたとき、`content/components/imported_*.json` と
 * `layouts/preserved/*.blade.php` がその対象になるが、**それを使っているページが在ると
 * 公開面が壊れる**（登録の外れた部品は描かれず、枠は kit 既定へ落ちる）。
 * CLI は消す前にここへ聞き、使われているものは残す。
 *
 * ## 何を「使っている」と数えるか
 *
 * - 部品: いずれかのページの **どの版**（下書き・公開中・履歴から復元できる過去の版）の document にも、その `type` のノードが在れば使っている
 * - 枠: `imported_page_layouts` にその view を指す行が在る
 *
 * ゴミ箱の中のページも数える（復元できるので、消してよい理由にならない）。
 *
 * ## 入出力
 *
 * 入力は stdin の JSON（argv に載せない）。
 *
 * ```json
 * {"components": ["imported_home_hero"], "layouts": ["layouts.preserved.home"]}
 * ```
 *
 * 出力は stdout に JSON 1 行。値は**使っているページの slug の一覧**（空なら未使用）。
 *
 * ```json
 * {"status": "success", "components": {"imported_home_hero": ["home"]}, "layouts": {"layouts.preserved.home": ["home"]}}
 * ```
 *
 * CLI 専用の内部経路。`OperationCatalog` にも MCP tool にも API にも足さない。
 */
final class ImportedUsageCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:imported-usage';

    protected $description = 'Template Import 用: 取り込んだ部品・枠を使っているページを答える（読み取り専用）';

    private const ALLOWED_KEYS = ['components', 'layouts'];

    /** 1 回に問える数の上限（改変された記録から巨大な問い合わせを流さない） */
    private const MAX_ITEMS = 500;

    public function handle(): int
    {
        $raw = $this->readStdin();
        $input = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($input)) {
            return $this->refuse('標準入力の JSON を読み取れませんでした');
        }

        $unknown = array_diff(array_keys($input), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            return $this->refuse('受け付けないキーがあります: '.implode(', ', $unknown));
        }

        $components = $this->names($input['components'] ?? []);
        $layouts = $this->names($input['layouts'] ?? []);

        if ($components === null || $layouts === null) {
            return $this->refuse('components / layouts は文字列の並びで渡してください（上限 '.self::MAX_ITEMS.'）');
        }

        return $this->emit([
            'status' => 'success',
            'components' => $this->componentUsage($components),
            'layouts' => $this->layoutUsage($layouts),
        ], failure: false);
    }

    /**
     * @return list<string>|null
     */
    private function names(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_ITEMS) {
            return null;
        }

        $names = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '' || strlen($item) > 190) {
                return null;
            }

            $names[] = $item;
        }

        return array_values(array_unique($names));
    }

    /**
     * 部品の type ごとに、使っているページの slug。
     *
     * @param  list<string>  $types
     * @return array<string, list<string>>
     */
    private function componentUsage(array $types): array
    {
        /** @var array<string, array<string, true>> $found */
        $found = [];

        foreach ($types as $type) {
            $found[$type] = [];
        }

        if ($types === []) {
            return [];
        }

        $wanted = array_fill_keys($types, true);

        Page::query()->orderBy('id')->chunk(50, function ($pages) use ($wanted, &$found): void {
            /** @var Page $page */
            foreach ($pages as $page) {
                /*
                 * **版の履歴も全部見る。**下書きと公開中の版だけでは足りない —
                 * 「版の履歴から復元」で古い版が戻ると、そこに載っていた部品が要る。
                 * 復元できる版に在るものは「使っている」。
                 */
                PageVersion::query()
                    ->where('page_id', $page->id)
                    ->orderBy('id')
                    ->chunk(20, static function ($versions) use ($wanted, $page, &$found): void {
                        /** @var PageVersion $version */
                        foreach ($versions as $version) {
                            DocumentTree::walk($version->document, static function (array $node) use ($wanted, $page, &$found): void {
                                $type = $node['type'] ?? null;

                                if (is_string($type) && isset($wanted[$type])) {
                                    $found[$type][$page->slug] = true;
                                }
                            });
                        }
                    });
            }
        });

        return array_map(static fn (array $slugs): array => array_keys($slugs), $found);
    }

    /**
     * 枠の view ごとに、結び付けられているページの slug。
     *
     * @param  list<string>  $views
     * @return array<string, list<string>>
     */
    private function layoutUsage(array $views): array
    {
        $usage = [];

        foreach ($views as $view) {
            $usage[$view] = [];
        }

        if ($views === []) {
            return [];
        }

        $rows = ImportedPageLayout::query()
            ->whereIn('view', $views)
            ->join('pages', 'pages.id', '=', 'imported_page_layouts.page_id')
            ->orderBy('pages.id')
            ->get(['imported_page_layouts.view as view', 'pages.slug as slug']);

        foreach ($rows as $row) {
            $view = (string) $row->getAttribute('view');
            $slug = (string) $row->getAttribute('slug');

            if (! in_array($slug, $usage[$view] ?? [], true)) {
                $usage[$view][] = $slug;
            }
        }

        return $usage;
    }
}

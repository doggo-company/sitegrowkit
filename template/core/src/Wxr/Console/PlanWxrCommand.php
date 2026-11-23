<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Console;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\ConsumerRegistry;
use AgentCms\Core\Taxonomy\ConsumerRegistryWriter;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use AgentCms\Core\Wxr\WxrError;
use Illuminate\Console\Command;
use Throwable;

/**
 * 取り込む前に、何が起きるかを見る（Phase 9.14 / STAGE 27C・SPEC §D.3）。
 *
 * ## なぜ要るのか
 *
 * `agentcms:wxr-apply` は `--plan-hash` を必須のゲートにしていて、値が無いと
 * 「`php artisan agentcms:wxr-plan <file>` を実行してください」と案内する。
 * **その案内どおりに打つと `Command "agentcms:wxr-plan" is not defined` になっていた。**
 * hash を得ていたのはテストだけで、`PlanBuilder` を PHP から直に叩いていた。
 * 買った人はそれをできないので、取り込みは案内された道の途中で行き止まりだった。
 *
 * ## 読むだけ。行を 1 つも書かない
 *
 * `inspect` は**ファイルだけ**を見る。ここは**ファイルとこちらの状態の両方**を見て、
 * 「入るもの・入らないもの・止める理由」を出す。それでも書き込みは 1 行も無い。
 *
 * ## 判断を増やさない
 *
 * `apply` と**同じ `PlanBuilder` を同じ `ImportOptions` で**叩くだけである。
 * ここに独自の検査を足すと、plan が通したのに apply が止まる（またはその逆）が起きる。
 * 出す hash も `WxrPlan::fingerprint()` そのままで、こちらでは作らない。
 *
 * ## 取り込めないときは hash を出さない
 *
 * 出せば利用者はそれを apply へ貼る。「問題はあるが通した plan」を一度でも許すと、
 * 次からは全部それになる。使えない plan の `fingerprint()` は元から `null` である
 * （`WxrPlan` の構築時に縛られている）。
 */
final class PlanWxrCommand extends Command
{
    /** 明細を出す上限。**件数は全部出すが、明細は絞る** */
    private const DETAIL_LIMIT = 5;

    /** `--create-taxonomies` が何をしたか。**末尾の案内はこれで言い分ける** */
    private const RECEPTACLES_CREATED = 'created';

    private const RECEPTACLES_NOTHING_TO_DO = 'nothing';

    private const RECEPTACLES_NO_MAPPING = 'no_mapping';

    /**
     * WordPress の分類の、**見せる名前**と親子の有無（WP 一周 2026-09-06 / F-18）。
     *
     * ## なぜ対応表を持つのか
     *
     * 前は `label` にこちら側の key をそのまま書いていた。結果、公開記事の下に
     * **「wp_category お知らせ」**と出る。`label` は画面に出る言葉なので、
     * 機械の綴りを入れると**買った人が最初に見る所が壊れて見える**。
     *
     * **WXR は分類の見せる名前を持っていない。** `wp:cat_name` / `wp:tag_name` は
     * 分類項目（「お知らせ」）の名前であって、分類そのもの（「カテゴリー」）の
     * 名前ではない。だから WXR から読むことはできず、**WordPress 側の名前**
     * （`--taxonomy=` の左）から引くのが、手元にある唯一の手がかりである。
     *
     * ## 親子の有無もここで決まる
     *
     * WordPress のタグは**平ら**である。`hierarchical: true` で作ると、
     * 管理画面に親の欄が出て、タグに親子があるかのように見える。
     * 分類ごとに違うものを 1 つの既定で書いていたのが誤りだった。
     *
     * ## 知らない分類のとき
     *
     * 見せる名前は `--taxonomy=` の左（＝その人が自分の WordPress で呼んでいる名前）を
     * 使う。key よりは読める言葉で、こちらが勝手に作った言葉でもない。
     * 親子は **持てる方**（true）に倒す — 平らに倒すと、入れ子だった分類が
     * **黙って平らになる**。逆は手で false にするだけで済む。
     *
     * @var array<string, array{label: string, hierarchical: bool}>
     */
    private const WORDPRESS_TAXONOMIES = [
        'category' => ['label' => 'カテゴリー', 'hierarchical' => true],
        'post_tag' => ['label' => 'タグ', 'hierarchical' => false],
    ];

    protected $signature = 'agentcms:wxr-plan
        {file : WordPress の書き出し XML}
        {--type=* : WXR の post_type とこちらの型の対応（例 post:news）}
        {--taxonomy=* : WXR の分類とこちらの分類の対応（例 category:news_category）}
        {--media : 添付も取り込む前提で数える}
        {--create-taxonomies : 足りない受け皿（分類の定義と、入れ先の型への宣言）を作る}
        {--strict-term-definitions : 分類の定義が1件も無い場合でも平らな項目を作らず、従来どおり止める}
        {--json : 結果を JSON で出力する}';

    protected $description = '取り込むと何が起きるかを調べ、apply へ渡す plan-hash を出します（何も書きません）';

    public function handle(PlanBuilder $builder): int
    {
        $path = (string) $this->argument('file');

        $options = ImportOptions::fromCli(
            array_values((array) $this->option('type')),
            array_values((array) $this->option('taxonomy')),
            (bool) $this->option('media'),
            ! (bool) $this->option('strict-term-definitions'),
        );

        try {
            $plan = $builder->build($path, $options);
        } catch (WxrError $e) {
            return $this->reportError($e);
        }

        $receptacles = $this->option('create-taxonomies')
            ? $this->createReceptacles($options)
            : null;

        if ($this->option('json')) {
            return $this->reportJson($plan);
        }

        return $this->reportText($plan, $receptacles);
    }

    /**
     * 足りない受け皿を作る（WP 一周 2026-09-03 / 実物で詰まった所）。
     *
     * ## plan は読むだけ、の約束は守る
     *
     * **このフラグを付けたときだけ**書く。付けなければ 1 バイトも書かない。
     * 書いたものは必ず一覧で出す — 黙って足すと、次に開いた人が
     * 「誰がこれを置いたのか」を追えなくなる。
     *
     * ## 上書きしない
     *
     * 既にあるファイルには触らない。手で直したものを消さないためである。
     *
     * ## なぜ要るのか
     *
     * 分類つきの WXR を取り込むには、`content/taxonomies/<キー>.json` と
     * `content/schemas/<型>.json` の `taxonomies` 宣言の**両方**が要る。
     * どちらも管理画面ではできない。**WordPress から来た人がやることではない。**
     */
    private function createReceptacles(ImportOptions $options): string
    {
        if ($options->taxonomies === []) {
            $this->newLine();
            $this->line('--create-taxonomies を付けましたが、--taxonomy= の対応づけがありません。');
            $this->line('  例: --taxonomy=category:wp_category');

            return self::RECEPTACLES_NO_MAPPING;
        }

        $written = [];

        // **WordPress 側の名前ごと**に回す（見せる名前と親子の有無がそこで決まる）
        foreach ($options->taxonomies as $source => $key) {
            $path = base_path('content/taxonomies/'.$key.'.json');

            if (file_exists($path)) {
                continue;
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }

            $known = self::WORDPRESS_TAXONOMIES[$source]
                ?? ['label' => $source, 'hierarchical' => true];

            file_put_contents($path, json_encode([
                'key' => $key,
                // **画面に出る言葉。** key をそのまま入れない（docblock）
                'label' => $known['label'],
                'hierarchical' => $known['hierarchical'],
                'managed_by' => 'code',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);

            $written[] = 'content/taxonomies/'.$key.'.json';
        }

        foreach (array_values(array_unique($options->types)) as $type) {
            foreach (array_values(array_unique($options->taxonomies)) as $key) {
                if ($this->declareOnSchema($type, $key)) {
                    $written[] = 'content/schemas/'.$type.'.json';
                }
            }
        }

        if ($this->syncConsumerRegistry($options)) {
            $written[] = ConsumerRegistry::LABEL;
        }

        $this->newLine();

        if ($written === []) {
            $this->line('受け皿は揃っています（作るものはありませんでした）。');

            return self::RECEPTACLES_NOTHING_TO_DO;
        }

        $this->info('受け皿を作りました:');

        foreach (array_values(array_unique($written)) as $file) {
            $this->line('  + '.$file);
        }

        $this->newLine();
        $this->line('データベースへ反映してから、もう一度 plan を実行してください:');
        $this->line('  php artisan agentcms:taxonomy-sync');

        return self::RECEPTACLES_CREATED;
    }

    /**
     * 書いた正から、AI 向けの索引を作り直す（WP 一周 2026-09-06 / 移行が verify で止まっていた所）。
     *
     * ## なぜここでやるのか
     *
     * `agentcms verify` の分類ゲート（`agentcms:taxonomy-check`）は、索引
     * （`.agentcms/registry/taxonomies.json`）と `content/schemas/*.json` の宣言が
     * 食い違うと落ちる。**上の 2 つの書き込みが、まさにその宣言を変えている。**
     * 索引を置き去りにすると、`docs/MIGRATE_FROM_WORDPRESS.md` の手順を
     * そのとおりに進めた人が、最後の verify で必ず止まる（実測 2026-09-06）。
     *
     * 🔴 **`agentcms:taxonomy-sync` ではこれを直せない。** あれは
     * `content/taxonomies/*.json` を **DB へ**同期するもので、`content/schemas/` を
     * 読まない。索引が持つ `consumers`（どの型が使うか）を知らないのだから、
     * 書けるはずがない。索引の元になる宣言を書いているのはここだけである。
     *
     * ## registry は作り直しても捨てない
     *
     * 触るのは `--taxonomy=` で渡された key だけ。それ以外の項目には手を出さない
     * （この道具が起こしていない乖離を黙って直さない。verify が人に知らせる）。
     *
     * ## 読み直す
     *
     * `TaxonomyDefinitionRegistry` / `SchemaRegistry` は container の中で
     * **読んだ結果を持ち続ける**。上でファイルを書いた後なので、
     * 注入されたものを使うと**書く前の姿**が見える。だからここで作り直して読む。
     * `declared()` は overlay を通さないので、注入版と同じ値になる。
     */
    private function syncConsumerRegistry(ImportOptions $options): bool
    {
        $keys = array_values(array_unique($options->taxonomies));

        if ($keys === []) {
            return false;
        }

        try {
            $definitions = (new TaxonomyDefinitionRegistry(
                (string) config('agentcms.taxonomy_path')
            ))->all();

            $schemas = new SchemaRegistry((string) config('agentcms.schema_path'));

            $consumers = [];

            foreach ($schemas->declared() as $schema) {
                foreach ($schema->taxonomyKeys() as $key) {
                    $consumers[$key][] = $schema->id;
                }
            }
        } catch (Throwable $e) {
            // 定義が不正 / schema が壊れている、は taxonomy-check が名指しで言う。
            // ここで二重に言わず、索引だけ触らずに戻る（plan の結果は出す）
            report($e);

            return false;
        }

        $targets = array_intersect_key($definitions, array_flip($keys));

        if ($targets === []) {
            return false;
        }

        return (new ConsumerRegistryWriter(
            (string) config('agentcms.taxonomy_registry_path')
        ))->upsert($targets, $consumers);
    }

    /** 入れ先の型に分類を宣言する。足したら true */
    private function declareOnSchema(string $type, string $key): bool
    {
        $path = base_path('content/schemas/'.$type.'.json');

        if (! file_exists($path)) {
            return false;
        }

        /** @var array<string, mixed>|null $schema */
        $schema = json_decode((string) file_get_contents($path), true);

        if (! is_array($schema)) {
            return false;
        }

        /** @var list<array<string, mixed>> $declared */
        $declared = is_array($schema['taxonomies'] ?? null) ? $schema['taxonomies'] : [];

        if (in_array($key, array_column($declared, 'key'), true)) {
            return false;
        }

        $declared[] = ['key' => $key, 'multiple' => true, 'required' => false];
        $schema['taxonomies'] = $declared;

        file_put_contents(
            $path,
            json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL,
        );

        return true;
    }

    private function reportText(WxrPlan $plan, ?string $receptacles = null): int
    {
        $this->report($plan);

        if (! $plan->isUsable()) {
            $this->newLine();
            $this->error('この WXR は取り込めません。上の理由を先に片づけてください。');
            $this->guidance($plan, $receptacles);

            return self::FAILURE;
        }

        $this->explainFlatTaxonomies($plan);

        $this->newLine();
        $this->info('取り込めます。次はこの値を apply へ渡してください:');
        $this->line('  '.(string) $plan->fingerprint());
        $this->newLine();
        $this->line('  php artisan agentcms:backup');

        foreach ($this->applyCommandLines($plan) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * **そのままコピペして通る apply の行**（WP 一周 2026-09-06 / F-19）。
     *
     * ## 何が起きていたか
     *
     * ここは以前、指定を 1 つも載せずにこう出していた:
     *
     * ```
     * php artisan agentcms:wxr-apply <file> --i-have-a-backup \
     *   --plan-hash=f0b6b031...
     * ```
     *
     * `<file>` は打ち直しが要ると分かる。**`--type` / `--taxonomy` が抜けているのは
     * 見て分からない。** そのまま打つと apply は `missing_taxonomy` /
     * `taxonomy_not_declared_for_type` で止まる。分類はちゃんと在るのに
     * 「無い」と言われるので、**指定の付け忘れだと気づけない**。
     * （plan が空の対応表で作った受け皿を、apply も空の対応表で見に行くため、
     * WXR 側の名前 `category` をそのままこちらの分類 key として探しにいく。）
     *
     * `docs/MIGRATE_FROM_WORDPRESS.md` §5 は「3 と同じものを渡してください」と
     * 書いていた。**手順書がそう書いているのに、機械が印字する行がそれを破っていた。**
     * 人は手順書より目の前の行をコピペする。
     *
     * ## だから plan が受け取ったものを、そのまま載せる
     *
     * 打ち直す所を残さない。ファイルも指定も、この plan を作ったときの実物を書く。
     * 並びと書き方は手順書 §5 の例に揃えてある（見比べられるように）。
     *
     * @return list<string>
     */
    private function applyCommandLines(WxrPlan $plan): array
    {
        // 手順書 §5 と同じ並び: --i-have-a-backup → --plan-hash → --type → --taxonomy → 残り
        $options = [
            '--i-have-a-backup',
            '--plan-hash='.(string) $plan->fingerprint(),
        ];

        foreach (array_values((array) $this->option('type')) as $type) {
            $options[] = '--type='.self::shellToken((string) $type);
        }

        foreach (array_values((array) $this->option('taxonomy')) as $taxonomy) {
            $options[] = '--taxonomy='.self::shellToken((string) $taxonomy);
        }

        if ((bool) $this->option('media')) {
            $options[] = '--media';
        }

        if ((bool) $this->option('strict-term-definitions')) {
            $options[] = '--strict-term-definitions';
        }

        $lines = ['  php artisan agentcms:wxr-apply '.self::shellToken((string) $this->argument('file')).' \\'];
        $last = array_key_last($options);

        foreach ($options as $index => $option) {
            $lines[] = '    '.$option.($index === $last ? '' : ' \\');
        }

        return $lines;
    }

    /**
     * shell へ貼っても 1 語のまま届く形にする。
     *
     * 空白の入った道（`~/Desktop/wp export.xml`）を裸で出すと、貼った人の所では
     * **2 つの引数になって別の理由で止まる**。ここで起きる失敗は「コピペしたのに
     * 動かない」なので、原因に辿り着けない。安全な字だけの語はそのまま出す
     * （引用符を付けると、読み比べるときに手順書の例と違って見える）。
     */
    private static function shellToken(string $value): string
    {
        if ($value !== '' && preg_match('#\A[A-Za-z0-9_@%+=:,./-]+\z#', $value) === 1) {
            return $value;
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function reportJson(WxrPlan $plan): int
    {
        $this->line((string) json_encode(
            [
                'ok' => $plan->isUsable(),
                'plan_hash' => $plan->fingerprint(),
                'counts' => $plan->counts(),
                'findings' => $plan->findings(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $plan->isUsable() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 詰まったときの次の 1 歩（WP 一周 5 周目 2026-09-03）。
     *
     * ## 固定文をやめた理由
     *
     * ここは以前、状態を見ずに毎回こう出していた:
     *
     * > `--create-taxonomies` を付けて、もう一度この plan を実行すると作ります。
     *
     * **いま `--create-taxonomies` を付けて実行した回にも、同じ 3 行が出る。**
     * 受け皿を作り終えたあとも、`taxonomy-sync` を済ませたあとも出続ける。
     * 案内どおりに打って同じ所へ戻ってくるので、**進んでいるのか分からない**。
     *
     * ## 順番
     *
     * `term_definition_missing` を**最初に見る**。これは書き出し直しが要るもので、
     * `--create-taxonomies` では 1 つも減らない。ここを後回しにすると、
     * 受け皿を整えたあとで「結局やり直し」になる。
     *
     * ## 文言の変更（2026-09-04）
     *
     * 以前はここで「その名前の定義がファイルにありません」と言っていたが、これは
     * **事実と違う**。記事側の `<category domain nicename>` には名前が入っている
     * （属性 `nicename`・CDATA が表示名）。無いのは**親子関係だけ**である。
     *
     * 分類に定義が **1 件も無い**場合は、いまは既定で平らな項目として取り込む
     * （`flat_taxonomy_term_created` — 上の「気をつけること」を参照）。
     * ここへ来るのは、**同じ分類に他の定義はあるのに、この割り当てだけ**
     * 対応する定義が見当たらない場合で、書き出しが壊れている・
     * 途中で切れているなどの疑いがあるので、従来どおり書き出し直しを求める。
     */
    private function guidance(WxrPlan $plan, ?string $receptacles): void
    {
        $findings = $plan->findings();

        /*
         * 🔴 **これだけは受け皿では片づかない。** 同じ分類に他の定義はあるのに、
         * この割り当てだけ、対応する定義が書き出しに見当たらない
         * （分類そのものに定義が 1 件も無い場合は `flat_taxonomy_term_created` になる）。
         */
        if (isset($findings['term_definition_missing'])) {
            $this->line('  記事が指している分類の項目に、対応する定義が見当たりません。');
            $this->line('  同じ分類の他の項目には定義があるのに、これだけ抜けています。');
            $this->line('  元の WordPress の ツール → エクスポート で「すべてのコンテンツ」を選んで');
            $this->line('  書き出し直してください（受け皿を作っても、これは減りません）。');

            return;
        }

        // いま作った回。**もう付いているものを勧め直さない**
        if ($receptacles === self::RECEPTACLES_CREATED) {
            $this->line('  受け皿はいま作りました。上の agentcms:taxonomy-sync を実行してから、');
            $this->line('  もう一度この plan を実行してください。');

            return;
        }

        // フラグは付いているが、対応づけが無い。足りないのはフラグではない
        if ($receptacles === self::RECEPTACLES_NO_MAPPING) {
            $this->line('  上の例のように --taxonomy= を付けて、もう一度実行してください。');

            return;
        }

        if (isset($findings['missing_taxonomy'])) {
            $this->line('  WXR にある分類の受け皿が、こちらにありません。');
            $this->line('  --create-taxonomies と --taxonomy= を付けて実行すると作ります:');
            $this->line('    --create-taxonomies --taxonomy=<WXR 側>:<こちら側>');
            $this->line('  そのあと agentcms:taxonomy-sync を実行してから、また plan を実行してください。');

            return;
        }

        if (isset($findings['taxonomy_not_declared_for_type'])) {
            $this->line('  分類はありますが、入れ先の型に宣言されていません。');
            $this->line('  --create-taxonomies に --type=<WXR の型>:<こちらの型> も付けて実行してください');
            $this->line('  （手で書くなら content/schemas/<型>.json の taxonomies です）。');

            return;
        }

        $this->line('  出ている理由ごとの直しかたは、docs/MIGRATE_FROM_WORDPRESS.md の');
        $this->line('  「3. 下見をする」にある表を見てください。');
    }

    /**
     * 取り込む**前に**、平らな項目を作ることを名指しで言う（WP 一周 2026-09-04）。
     *
     * `report()` は理由の符号（`flat_taxonomy_term_created`）と件数を出すだけなので、
     * 読んだ人が「これは何のことか」を自分で調べる必要がある。ここでは
     * **親子は分からない・平らに作る**を、apply へ進む前にはっきり言う。
     */
    private function explainFlatTaxonomies(WxrPlan $plan): void
    {
        $findings = $plan->findings();

        if (! isset($findings['flat_taxonomy_term_created'])) {
            return;
        }

        $bySource = $findings['flat_taxonomy_term_created']['by_source'] ?? [];
        $taxonomies = implode(' / ', array_keys($bySource));

        $this->newLine();
        $this->comment('分類の親子関係が分かりません（書き出しに定義ブロックがありません）: '.$taxonomies);
        $this->line('  親子は作らず、平らな項目として作ります（既定の動き）。');
        $this->line('  従来どおり止めたいときは --strict-term-definitions を付けてください。');
    }

    private function report(WxrPlan $plan): void
    {
        $counts = $plan->counts();

        if ($counts === []) {
            $this->line('入るものはありません。');
        } else {
            $this->info('入るもの:');

            foreach ($counts as $action => $count) {
                $this->line(sprintf('  %-24s %6d 件', $action, $count));
            }
        }

        $findings = $plan->findings();

        if ($findings === []) {
            return;
        }

        $this->newLine();
        $this->line('気をつけること:');

        foreach ($findings as $reason => $found) {
            $this->line(sprintf('  %-28s %6d 件', $reason, $found['total']));

            $listed = array_slice($found['shown'], 0, self::DETAIL_LIMIT);

            foreach ($listed as $item) {
                $this->line("      - {$item['source_type']} / {$item['external_id']}");
            }

            /*
             * 🔴 **並べた件数から数える**（WP 一周 5 周目 2026-09-03）。
             *
             * ここは `$found['omitted']` をそのまま出していた。あれは
             * **`shown` に載せた件数（最大 50）を引いた残り**であって、
             * この画面が実際に並べた 5 件ではない。実物では
             * `105 件` の下に 5 件並べて「… ほか 55 件」と出ており、
             * **45 件がどこにも出てこなかった**。
             */
            $rest = $found['total'] - count($listed);

            if ($rest > 0) {
                $this->line("      … ほか {$rest} 件");
            }
        }
    }

    /** `fail()` は Command が持っているので、別の名前にする（inspect と揃える） */
    private function reportError(WxrError $e): int
    {
        $this->error($e->getMessage());

        if ($e->hint !== '') {
            $this->line($e->hint);
        }

        if ($e->sourceLine !== null) {
            $this->line("（{$e->sourceLine} 行目付近）");
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->toArray()],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        return self::FAILURE;
    }
}

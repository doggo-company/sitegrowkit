<?php

declare(strict_types=1);

namespace Modules\Contact\Console;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Contact\Models\ContactSubmission;

/**
 * 旧お問い合わせの受信を、フォームの受信へ移す（R-12 Stage 2 / B）。
 *
 * ## 既定は数えるだけ
 *
 * 移すのは `--force` を付けたときだけ。`--dry-run` を明示すれば `--force` が
 * 付いていても数えるだけになる（**強いほうが安全側** — `agentcms:media-prune` と同じ作法）。
 * 受信は問い合わせ主とのやり取りそのものなので、**うっかり流して増える**形にしない。
 *
 * ## 原本は消さない
 *
 * 旧 `contact_submissions` は **Stage 3 まで残す**（R-12 裁定 1）。
 * 移したあとも原本が自然なバックアップとして在る状態で前進する。
 * 二重投入を防ぐのは `contact_submission_migrations` の主キーである。
 *
 * ## 生の IP は持ち込まない
 *
 * 裁定 1 は「**生 IP は hash して移す**」と決めている。新側は `ip_hash` しか持たない。
 *
 * ▷ **借金**: この `sha256(APP_KEY + '|' + ip)` は repo で **4 か所目**である
 * （`NotFoundLogger` / `PublicCommentController` / `PublicFormController` と同じ式）。
 * **寄せるには core を触る**ので、`form` 型・B-27 と同じく
 * **v1.1 の「次に core を触る回」で 1 か所へ寄せる**（R-12 の行に記録済み）。
 *
 * ## 対応は推測しない
 *
 * 旧は 5 列固定（`name` / `email` / `company` / `subject` / `message`）、新はフォームの項目である。
 * **同じ key の項目が在ればそこへ**、無ければ `--map 旧列:項目key` で指定する。
 *
 * ▷ **どこへも行き先が無い列は、捨てずに入れる。** 問い合わせ主が書いたものだからである。
 * `FormSubmission::orderedValueKeys()` が「**記録に無い key は後ろへ回して落とさない**」と
 * 決めているので、定義に無い key で入れても受信詳細でも CSV でも読める。
 * 見出しは**旧 schema の `label`**（`content/schemas/contact.json`）を使う — ここで文言を作らない。
 */
final class ContactMigrateCommand extends Command
{
    /** 旧テーブルの入力列（運用カラムと `ip` は移し方が別） */
    private const LEGACY_FIELDS = ['name', 'email', 'company', 'subject', 'message'];

    /** 一度に読む旧受信の数（全部メモリへ積まない） */
    private const CHUNK = 200;

    protected $signature = 'agentcms:contact-migrate
        {--form=contact : 移行先のフォーム識別子}
        {--map=* : 旧列と項目の対応（例 --map subject:inquiry_type）。省略すると同じ key の項目へ}
        {--force : 実際に移す（既定は数えるだけ）}
        {--dry-run : 明示的に「数えるだけ」。--force と同時に指定したときはこちらが勝つ}';

    protected $description = '旧お問い合わせの受信をフォームの受信へ移す（原本は消さない）';

    public function handle(FormFields $fields, SchemaRegistry $schemas): int
    {
        $formKey = (string) $this->option('form');
        $form = Form::query()->where('key', $formKey)->first();

        if ($form === null) {
            $this->components->error("識別子が「{$formKey}」のフォームがありません。先に作ってください。");

            return self::FAILURE;
        }

        $labels = $fields->labelsFor($form);

        if ($labels === []) {
            $this->components->error(
                "フォーム「{$formKey}」に項目がありません（項目の束が空か、割り当てられていません）。"
            );

            return self::FAILURE;
        }

        $mapping = $this->mapping($labels);

        if ($mapping === null) {
            return self::FAILURE;
        }

        $this->reportMapping($mapping, $labels, $schemas);

        $done = (int) DB::table('contact_submission_migrations')->count();
        $total = (int) ContactSubmission::query()->count();
        $todo = (int) ContactSubmission::query()
            ->whereNotIn('id', DB::table('contact_submission_migrations')->select('contact_submission_id'))
            ->count();

        $this->newLine();
        $this->components->twoColumnDetail('旧受信', (string) $total.' 件');
        $this->components->twoColumnDetail('移行済み', (string) $done.' 件');
        $this->components->twoColumnDetail('これから移す', (string) $todo.' 件');

        // `--dry-run` は `--force` に勝つ（強いほうが安全側）
        if (! $this->option('force') || $this->option('dry-run')) {
            $this->newLine();
            $this->components->info('数えただけです。移すときは --force を付けてください（原本は消しません）。');

            return self::SUCCESS;
        }

        if ($todo === 0) {
            $this->components->info('移すものはありません。');

            return self::SUCCESS;
        }

        $moved = $this->migrate($form, $labels, $mapping, $schemas);

        $this->newLine();
        $this->components->info("{$moved} 件を移しました。**旧テーブルはそのまま残しています**（Stage 3 まで原本）。");

        return self::SUCCESS;
    }

    /**
     * 旧列 → 新しい key の対応。**推測しない** — 同じ key が在るときだけ自動で結ぶ。
     *
     * @param  array<string, string>  $labels  フォームの項目 key => 表示名
     * @return array<string, string>|null 旧列 => 入れる key（null は指定が壊れている）
     */
    private function mapping(array $labels): ?array
    {
        $mapping = [];

        foreach (self::LEGACY_FIELDS as $legacy) {
            /*
             * 既定は**同じ key**。フォームにその項目が在ればそこへ入り、
             * 無ければ「定義に無い key」としてそのまま入る（**捨てない**）。
             * どちらになるかは `reportMapping()` が先に言う。
             */
            $mapping[$legacy] = $legacy;
        }

        /** @var list<string> $overrides */
        $overrides = (array) $this->option('map');

        foreach ($overrides as $raw) {
            $parts = explode(':', (string) $raw, 2);

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                $this->components->error("--map の書き方が違います（「旧列:項目key」の形）: {$raw}");

                return null;
            }

            [$legacy, $target] = $parts;

            if (! in_array($legacy, self::LEGACY_FIELDS, true)) {
                $this->components->error(
                    "--map の左側「{$legacy}」は旧テーブルの列ではありません（".implode(' / ', self::LEGACY_FIELDS).'）'
                );

                return null;
            }

            if (! isset($labels[$target])) {
                $this->components->error(
                    "--map の右側「{$target}」はフォームの項目にありません（".implode(' / ', array_keys($labels)).'）'
                );

                return null;
            }

            $mapping[$legacy] = $target;
        }

        return $mapping;
    }

    /**
     * どの列がどこへ行くかを先に見せる（`--force` の前に読めるように）。
     *
     * @param  array<string, string>  $mapping
     * @param  array<string, string>  $labels
     */
    private function reportMapping(array $mapping, array $labels, SchemaRegistry $schemas): void
    {
        $this->components->info('旧列と項目の対応');

        foreach ($mapping as $legacy => $target) {
            $known = isset($labels[$target]);

            $this->components->twoColumnDetail(
                $legacy,
                $known
                    ? $target.'（'.$labels[$target].'）'
                    : $target.'（'.$this->legacyLabel($schemas, $legacy).'・**フォームの項目には無い**）'
            );
        }

        $unknown = array_filter(
            $mapping,
            static fn (string $target): bool => ! isset($labels[$target]),
        );

        if ($unknown !== []) {
            $this->components->warn(
                '行き先の項目が無い列は、**捨てずにその key のまま入れます**（受信詳細と CSV では読めます）。'
                .'項目へ入れたい場合は --map で指定してください。'
            );
        }
    }

    /** 旧 schema が持つ表示名（ここで文言を作らない） */
    private function legacyLabel(SchemaRegistry $schemas, string $key): string
    {
        $schema = $schemas->get('contact');

        return $schema->hasField($key) ? $schema->field($key)->label : $key;
    }

    /**
     * 移す。**1 件ずつ取引にする** — 途中で落ちても、移した分と記録は必ず揃う。
     *
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $mapping
     */
    private function migrate(Form $form, array $labels, array $mapping, SchemaRegistry $schemas): int
    {
        $moved = 0;

        ContactSubmission::query()
            ->whereNotIn('id', DB::table('contact_submission_migrations')->select('contact_submission_id'))
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($form, $labels, $mapping, $schemas, &$moved): void {
                foreach ($rows as $row) {
                    $moved += $this->migrateOne($row, $form, $labels, $mapping, $schemas);
                }
            });

        return $moved;
    }

    /**
     * 1 件。**受信の作成と記録の作成を同じ取引に入れる。**
     *
     * 片方だけ成功すると、二重投入か取りこぼしのどちらかになる。
     *
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $mapping
     */
    private function migrateOne(
        ContactSubmission $row,
        Form $form,
        array $labels,
        array $mapping,
        SchemaRegistry $schemas,
    ): int {
        $values = [];
        /** @var array<string, string> $usedLabels */
        $usedLabels = [];

        foreach ($mapping as $legacy => $target) {
            $value = $row->getAttribute($legacy);

            // 空の任意項目（company など）は入れない。**空文字を「答えた」ことにしない**
            if ($value === null || $value === '') {
                continue;
            }

            $values[$target] = $value;
            // 見出しは、フォームの項目に在ればそれ。無ければ**旧 schema の label**
            $usedLabels[$target] = $labels[$target] ?? $this->legacyLabel($schemas, $legacy);
        }

        return DB::transaction(function () use ($row, $form, $values, $usedLabels): int {
            $submission = FormSubmission::query()->create([
                'form_id' => $form->getKey(),
                'data' => $values + [
                    FormSubmission::LABELS_KEY => FormSubmission::labelPairs($usedLabels),
                ],
                // 旧と新の enum は 4 値とも同じ（`SubmissionStatus` が「同じ語彙を使う」と決めている）
                'status' => $row->getAttribute('status')->value,
                'admin_notified_at' => $row->getAttribute('admin_notified_at'),
                'admin_notification_error' => $row->getAttribute('admin_notification_error'),
                'autoreply_sent_at' => $row->getAttribute('autoreply_sent_at'),
                'autoreply_error' => $row->getAttribute('autoreply_error'),
                // 裁定 1: 生 IP は持ち込まない
                'ip_hash' => $this->ipHash($row->getAttribute('ip')),
                // 旧は user_agent を持っていない。**空を作らない**（持っていなかった、が正しい）
                'user_agent' => null,
            ]);

            /*
             * **受信日時は写す。** 移行した日にすると「いつ届いたか」が壊れる。
             * `created_at` は `create()` が今の時刻で埋めるので、後から入れ直す。
             */
            $submission->forceFill([
                'created_at' => $row->getAttribute('created_at'),
                'updated_at' => $row->getAttribute('updated_at'),
            ])->saveQuietly();

            DB::table('contact_submission_migrations')->insert([
                'contact_submission_id' => (int) $row->getKey(),
                'form_submission_id' => (int) $submission->getKey(),
                'migrated_at' => now(),
            ]);

            return 1;
        });
    }

    /**
     * `sha256(APP_KEY + '|' + ip)`。
     *
     * **repo で 4 か所目**（上の docblock の借金）。式を変えると
     * 「同じ人からの再送」を数える他の面と揃わなくなるので、**1 文字も変えない**。
     */
    private function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === ''
            ? null
            : hash('sha256', (string) config('app.key').'|'.$ip);
    }
}

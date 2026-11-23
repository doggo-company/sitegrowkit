<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Forms\FormValueText;

/**
 * 受信を CSV で書き出す（Phase 9.12 / STAGE 25D・SPEC §D.3・OG7）。
 *
 * ## 外へ出す操作なので、必ず監査に残す
 *
 * 読み取りだが、**手元の端末へ個人情報の束が出ていく**。
 * 誰がいつ何件持ち出したかが分からない状態にはしない。
 *
 * `classification()` は `Read`（権限は `ContactRead` に揃える — §E.5）だが、
 * **監査には `ExternalEffect` として記録する**。`Read` は量が多いので既定では
 * 記録しない設計であり（`OperationClassification::shouldAudit()`）、
 * そのままだと持ち出しの記録だけが消える。起きたことは
 * 「読み取り」ではなく「系の外へ出した」なので、記録上はそう書く。
 *
 * ## 見出しは受信の `__labels` から作る
 *
 * 定義から引くと、項目を改名・削除したあとで**過去の受信の見出しが変わる**。
 * 届いたときに画面へ出ていた名前で出す（OG4）。
 * 受信ごとに項目が違い得るので、**全件の見出しを集めてから列を決める**。
 *
 * ## `ip_hash` は出さない
 *
 * 画面に出さないものを、書き出しでだけ出す理由が無い（`FormPresenter`）。
 *
 * ## 数式として実行させない
 *
 * 中身は**未認証の相手が書いたもの**である。`=`・`+`・`-`・`@` で始まる値を
 * そのまま出すと、表計算ソフトが数式として解釈する。開いた人の手元で
 * 外部への通信や別ファイルの参照が走り得るので、先頭に `'` を足して**文字列に固定**する。
 */
final class ExportSubmissions implements Operation
{
    /** Excel が UTF-8 と分かるようにする（付けないと日本語が化ける） */
    public const BOM = "\u{FEFF}";

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'form.submission.export';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $key, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $form = GetForm::find($key);

        if ($form === null) {
            return Result::notFound("フォーム [{$key}] は存在しません");
        }

        /** @var list<FormSubmission> $submissions */
        $submissions = FormSubmission::query()
            ->where('form_id', $form->getKey())
            ->orderBy('id')
            ->get()
            ->all();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                [
                    'filename' => "{$form->key}-submissions.csv",
                    'csv' => self::BOM.self::csv($submissions),
                ],
                ['key' => $form->key, 'count' => count($submissions)],
            ),
            resourceType: 'form',
            resourceId: $form->key,
            classification: OperationClassification::ExternalEffect,
        );
    }

    /**
     * 表計算ソフトに数式として解釈させない。
     *
     * 引用符で囲んだだけでは足りない。Excel は `"=1+1"` を数式として読む。
     */
    private static function cell(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'".$value : $value;
    }

    /**
     * @param  list<FormSubmission>  $submissions
     */
    private static function csv(array $submissions): string
    {
        /** @var array<string, string> $columns key => 見出し */
        $columns = [];

        foreach ($submissions as $submission) {
            // **届いたときの並び**で列を決める（保存順は当てにならない）
            foreach ($submission->orderedValueKeys() as $fieldKey) {
                // **最初に見た見出しを使う**。あとの受信で改名されていても、
                // 列を増やして表を歪めるより 1 本にまとめる方が読める
                $columns[$fieldKey] ??= $submission->labelFor($fieldKey);
            }
        }

        $rows = [array_merge(['ID', '受信日時', '状態'], array_values($columns))];

        foreach ($submissions as $submission) {
            $values = $submission->values();
            $row = [
                (string) $submission->getKey(),
                $submission->created_at?->format('Y-m-d H:i:s') ?? '',
                $submission->status,
            ];

            foreach (array_keys($columns) as $fieldKey) {
                $row[] = self::cell(FormValueText::of(null, $values[$fieldKey] ?? null));
            }

            $rows[] = $row;
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            // escape は空にする（PHP 既定の `\\` は RFC 4180 に無く、8.4 で非推奨）
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}

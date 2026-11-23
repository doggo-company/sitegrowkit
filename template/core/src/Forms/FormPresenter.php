<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

/**
 * フォームと受信を外へ出す形（Phase 9.12 / STAGE 25B）。
 *
 * 受信は **`ip_hash` を出さない**。個人を追う材料をそのまま画面や API へ渡す理由が無い
 * （在ることは分かれば足りる — 9.10 の 404 記録と同じ扱い）。
 */
final class FormPresenter
{
    /** @return array<string, mixed> */
    public static function form(Form $form, ?string $fieldProblem = null, int $submissions = 0): array
    {
        return [
            'id' => (int) $form->getKey(),
            'key' => $form->key,
            'name' => $form->name,
            'description' => $form->description,
            'field_group_id' => $form->field_group_id,
            // なぜ項目が出ないのかを画面で言えるようにする
            'field_problem' => $fieldProblem,
            'status' => $form->status,
            'status_label' => $form->status()->label(),
            'confirm_step' => $form->confirm_step,
            'submit_label' => $form->submit_label,
            'complete_message' => $form->complete_message,
            'notify_to' => $form->notify_to,
            'autoreply_field' => $form->autoreply_field,
            'autoreply_subject' => $form->autoreply_subject,
            'autoreply_body' => $form->autoreply_body,
            'autoreply_signature' => $form->autoreply_signature,
            'mail_from_name' => $form->mail_from_name,
            'reply_to' => $form->reply_to,
            'submissions' => $submissions,
            'lock_version' => $form->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function submission(FormSubmission $submission): array
    {
        $values = $submission->values();

        return [
            'id' => (int) $submission->getKey(),
            'form_id' => $submission->form_id,
            'status' => $submission->status,
            'status_label' => SubmissionStatus::tryFrom($submission->status)?->label() ?? $submission->status,
            // **そのときの表示名と、そのときの並びで出す。**
            // 保存順（検証が返した順）に頼ると、画面の並びと違うものが出る
            'fields' => array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'label' => $submission->labelFor($key),
                    'value' => $values[$key] ?? null,
                ],
                $submission->orderedValueKeys(),
            ),
            'admin_notified_at' => $submission->admin_notified_at?->toIso8601String(),
            'admin_notification_error' => $submission->admin_notification_error,
            'autoreply_sent_at' => $submission->autoreply_sent_at?->toIso8601String(),
            'autoreply_error' => $submission->autoreply_error,
            // 生の IP も hash も出さない。在ることだけ分かれば足りる
            'has_ip' => $submission->ip_hash !== null,
            'created_at' => $submission->created_at?->toIso8601String(),
        ];
    }

    /**
     * 一覧に出す短い形（本文を全部積まない）。
     *
     * @return array<string, mixed>
     */
    public static function submissionSummary(FormSubmission $submission): array
    {
        $values = $submission->values();
        $first = null;

        foreach ($submission->orderedValueKeys() as $key) {
            $value = $values[$key];

            if (is_scalar($value) && (string) $value !== '') {
                $first = $submission->labelFor($key).': '.mb_substr((string) $value, 0, 60);

                break;
            }
        }

        return [
            'id' => (int) $submission->getKey(),
            'status' => $submission->status,
            'status_label' => SubmissionStatus::tryFrom($submission->status)?->label() ?? $submission->status,
            'summary' => $first,
            'notified' => $submission->admin_notified_at !== null,
            'notify_error' => $submission->admin_notification_error !== null,
            'created_at' => $submission->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * フォーム 1 本（Phase 9.12 / STAGE 25B・SPEC §B.1）。
 *
 * **Mass Assignment から意図的に外してある**: `key` / `lock_version`。
 * `key` は公開 URL に出る識別子で、変えると**配ってしまった URL が死ぬ**。
 * 「フォームの編集」と同じ経路で通してよい変更ではない。
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $field_group_id
 * @property string $status
 * @property bool $confirm_step
 * @property string $submit_label
 * @property string|null $complete_message
 * @property string|null $notify_to
 * @property string|null $autoreply_field
 * @property string|null $autoreply_subject
 * @property string|null $autoreply_body
 * @property string|null $autoreply_signature
 * @property string|null $mail_from_name
 * @property string|null $reply_to
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Form extends Model
{
    protected $table = 'forms';

    protected $fillable = [
        'name',
        'description',
        'field_group_id',
        'status',
        'confirm_step',
        'submit_label',
        'complete_message',
        'notify_to',
        'autoreply_field',
        'autoreply_subject',
        'autoreply_body',
        'autoreply_signature',
        'mail_from_name',
        'reply_to',
    ];

    protected $casts = [
        'confirm_step' => 'boolean',
        'lock_version' => 'integer',
    ];

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id');
    }

    public function status(): FormStatus
    {
        return FormStatus::tryFrom($this->status) ?? FormStatus::Draft;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Contact\Models;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Model;

final class ContactSubmission extends Model implements SchemaModel
{
    use HasContentSchema;

    protected $table = 'contact_submissions';

    // schema 外の運用カラム（ADR-0005）
    protected $fillable = ['status', 'admin_notified_at', 'admin_notification_error', 'autoreply_sent_at', 'autoreply_error', 'ip'];

    /**
     * **生の IP は配列にしない**（R-12 Stage 2 / B-43 の残り）。
     *
     * B-43 で AI と外部 API の口は塞いだが、**管理画面の Inertia props には
     * `toArray()` 素通しで生 IP が乗ったままだった** — 画面は表示していないので
     * 誰も気づけないが、`data-page` 属性として HTML に出ている。
     *
     * R-12 裁定 1 は「**生 IP は hash して移す**」と決めており、新側（`form_submissions` /
     * `comments`）は既に `ip_hash` しか持たない。**旧側だけが生のまま外へ出る形**を
     * 残す理由が無い。落とすのは**配列にするときだけ**なので、
     * `$model->ip` は今までどおり読める — Stage 2 の移行が hash を作るのに要る。
     *
     * 列そのものは消さない。**旧 `contact_submissions` は Stage 3 まで原本として残す**
     * （裁定 1）。
     *
     * @var list<string>
     */
    protected $hidden = ['ip'];

    protected $casts = [
        'status' => ContactStatus::class,
        'admin_notified_at' => 'datetime',
        'autoreply_sent_at' => 'datetime',
    ];

    public static function schemaId(): string
    {
        return 'contact';
    }

    /** 通知の成否は導出値（ADR-0005: 永続 enum に混ぜない） */
    public function notificationState(): string
    {
        return match (true) {
            $this->admin_notified_at !== null => 'notification_sent',
            $this->admin_notification_error !== null => 'notification_failed',
            default => 'notification_pending',
        };
    }
}

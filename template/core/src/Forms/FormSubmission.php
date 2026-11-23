<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 受信 1 件（Phase 9.12 / STAGE 25B・SPEC §B.2・§B.5）。
 *
 * `data` には入力値のほかに **`__labels`（そのときの表示名）** が入る。
 * 項目を消したり改名したりしたあとでも、届いた内容を読めるようにするためである。
 *
 * @property int $id
 * @property int $form_id
 * @property array<string, mixed> $data
 * @property string $status
 * @property Carbon|null $admin_notified_at
 * @property string|null $admin_notification_error
 * @property Carbon|null $autoreply_sent_at
 * @property string|null $autoreply_error
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
final class FormSubmission extends Model
{
    /**
     * 表示名を入れておく鍵（入力項目の key と衝突しないよう `__` で始める）。
     *
     * **中身は `[[key, 表示名], ...]` の並び付き**である。オブジェクトにしない。
     * MySQL の JSON 型は**オブジェクトのキー順を保存しない**（キー長 → 辞書順へ
     * 正規化する）ので、`{"name": ..., "email": ...}` では届いたときの並びが失われ、
     * 受信詳細・CSV・通知メールが form と違う順に出る。配列なら順序が残る。
     */
    public const LABELS_KEY = '__labels';

    protected $table = 'form_submissions';

    protected $guarded = ['id'];

    protected $casts = [
        'data' => 'array',
        'admin_notified_at' => 'datetime',
        'autoreply_sent_at' => 'datetime',
    ];

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    /**
     * 入力値だけ（表示名は含まない）。
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->data;

        unset($data[self::LABELS_KEY]);

        return $data;
    }

    /**
     * そのとき画面に出ていた表示名（**並びも保つ**）。
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $raw = $this->data[self::LABELS_KEY] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $labels = [];

        foreach ($raw as $pair) {
            if (! is_array($pair) || ! is_string($pair[0] ?? null)) {
                continue;
            }

            $labels[$pair[0]] = (string) ($pair[1] ?? $pair[0]);
        }

        return $labels;
    }

    /**
     * 入力値の key を**届いたときの並び**で返す。
     *
     * `values()` の並びは当てにならない（MySQL がキー順を正規化する）。
     * 記録に無い key は後ろへ回して**落とさない** — 定義が変わったあとの値も読めるようにする。
     *
     * @return list<string>
     */
    public function orderedValueKeys(): array
    {
        $values = $this->values();

        $ordered = array_values(array_filter(
            array_keys($this->labels()),
            static fn (string $key): bool => array_key_exists($key, $values),
        ));

        return [...$ordered, ...array_values(array_diff(array_keys($values), $ordered))];
    }

    /**
     * 保存する形へ直す。
     *
     * @param  array<string, string>  $labels
     * @return list<array{0: string, 1: string}>
     */
    public static function labelPairs(array $labels): array
    {
        $pairs = [];

        foreach ($labels as $key => $label) {
            $pairs[] = [$key, $label];
        }

        return $pairs;
    }

    /** その項目の見出し（記録が無ければ key をそのまま） */
    public function labelFor(string $key): string
    {
        return $this->labels()[$key] ?? $key;
    }
}
